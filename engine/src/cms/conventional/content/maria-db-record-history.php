<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Content;

use Utsuri\PortableCms\Conventional\I18n\InvalidInput;

/** Pruning uses the same owning-record lock as saves, publication and language rewrites. */
final readonly class MariaDbRecordHistory implements RecordHistoryRepository
{
    public const DELETIONS_PER_BATCH = 100;

    private const HISTORICAL = 'v.id <> r.current_revision_id AND (r.published_revision_id IS NULL OR v.id <> r.published_revision_id)';

    public function __construct(private \PDO $pdo) {}

    public function load(): RecordHistorySettings
    {
        $saved = $this->settings(false);
        $query = $this->pdo->prepare('SELECT id FROM cms_records WHERE history_cleanup_revision = :revision LIMIT 1');
        $query->execute(['revision' => $saved->revision]);
        return new RecordHistorySettings($saved->revision, $saved->limit, $saved->pending || $query->fetchColumn() !== false);
    }

    /** Revision writers acquire policy before record locks, in the same order as cleanup. */
    public function lockPolicy(): RecordHistorySettings
    {
        if (!$this->pdo->inTransaction()) {
            throw new \LogicException('History policy locking requires a transaction');
        }
        return $this->settings(false, true);
    }

    private function settings(bool $lock, bool $share = false): RecordHistorySettings
    {
        $row = $this->pdo->query('SELECT revision, history_limit, pending FROM cms_record_history_settings WHERE id = 1' . ($lock ? ' FOR UPDATE' : ($share ? ' LOCK IN SHARE MODE' : '')))->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new \RuntimeException('Record history settings are missing');
        }
        return new RecordHistorySettings((int) $row['revision'], (int) $row['history_limit'], (bool) $row['pending']);
    }

    public function retained(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM cms_record_revisions')->fetchColumn();
    }

    public function excess(int $limit): int
    {
        RecordHistorySettings::validateLimit($limit);
        $query = $this->pdo->prepare('SELECT COALESCE(SUM(GREATEST(CAST(n AS SIGNED) - :limit, 0)), 0) FROM (SELECT COUNT(*) AS n FROM cms_records r JOIN cms_record_revisions v ON v.record_id = r.id WHERE ' . self::HISTORICAL . ' GROUP BY r.id) AS histories');
        $query->execute(['limit' => $limit]);
        return (int) $query->fetchColumn();
    }

    public function save(int $limit, int $expected, ?int $confirmedCount, string $actor, string $auditId, \DateTimeImmutable $now): void
    {
        RecordHistorySettings::validateLimit($limit);
        $this->pdo->beginTransaction();
        try {
            $saved = $this->settings(true);
            if ($saved->revision !== $expected) {
                throw new InvalidInput('history.changed');
            }
            if ($limit === $saved->limit) {
                $this->pdo->commit();
                return;
            }
            $lowering = $limit < $saved->limit;
            $count = $lowering ? $this->excess($limit) : 0;
            if ($lowering && $confirmedCount !== $count) {
                throw new InvalidInput('history.changed');
            }
            $update = $this->pdo->prepare('UPDATE cms_record_history_settings SET revision = :revision, history_limit = :limit, pending = :pending WHERE id = 1');
            $update->execute(['revision' => $saved->revision + 1, 'limit' => $limit, 'pending' => $lowering ? 1 : 0]);
            $this->audit($auditId, $actor, 'record_history_limit_saved', ['limit' => $limit, 'previousLimit' => $saved->limit, 'removalCount' => $count], $now);
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function batch(string $actor, string $auditId, \DateTimeImmutable $now): void
    {
        $this->pdo->beginTransaction();
        try {
            $saved = $this->settings(true);
            $queued = $this->pdo->prepare('SELECT id FROM cms_records WHERE history_cleanup_revision = :revision ORDER BY id LIMIT 1');
            $queued->execute(['revision' => $saved->revision]);
            $id = $queued->fetchColumn();
            $queued->closeCursor();
            if ($id === false && $saved->pending) {
                $query = $this->pdo->prepare('SELECT r.id FROM cms_records r JOIN cms_record_revisions v ON v.record_id = r.id WHERE ' . self::HISTORICAL . ' GROUP BY r.id HAVING COUNT(*) > :limit ORDER BY r.id LIMIT 1');
                $query->execute(['limit' => $saved->limit]);
                $id = $query->fetchColumn();
                $query->closeCursor();
            }
            if (is_string($id)) {
                $this->pruneRecord($id, $actor, $auditId, $now, $saved);
            } else {
                $this->pdo->exec('UPDATE cms_record_history_settings SET pending = FALSE WHERE id = 1');
            }
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    /** Caller owns the transaction. IDs and time come from the initiating command, never this adapter. */
    public function pruneRecord(string $recordId, string $actor, string $auditId, \DateTimeImmutable $now, RecordHistorySettings $policy): int
    {
        if (!$this->pdo->inTransaction()) {
            throw new \LogicException('Revision pruning requires a transaction');
        }
        $lock = $this->pdo->prepare('SELECT current_revision_id, published_revision_id FROM cms_records WHERE id = :id FOR UPDATE');
        $lock->execute(['id' => $recordId]);
        $heads = $lock->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($heads)) {
            return 0;
        }
        // Locking read observes the latest committed heads/history even in an outer snapshot transaction.
        $query = $this->pdo->prepare('SELECT id FROM cms_record_revisions WHERE record_id = :record AND id <> :current AND (:published IS NULL OR id <> :kept) ORDER BY revision_number DESC LIMIT ' . (self::DELETIONS_PER_BATCH + 1) . ' OFFSET ' . $policy->limit . ' FOR UPDATE');
        $query->execute(['record' => $recordId, 'current' => $heads['current_revision_id'], 'published' => $heads['published_revision_id'], 'kept' => $heads['published_revision_id']]);
        $ids = $query->fetchAll(\PDO::FETCH_COLUMN);
        $count = min(count($ids), self::DELETIONS_PER_BATCH);
        $progress = $this->pdo->prepare('UPDATE cms_records SET history_cleanup_revision = :revision WHERE id = :id');
        $progress->execute(['revision' => count($ids) > $count ? $policy->revision : null, 'id' => $recordId]);
        if ($ids === []) {
            return 0;
        }
        // Delete the oldest first, including when an upgrade left more than one batch of excess history.
        $oldest = $this->pdo->prepare('SELECT id FROM cms_record_revisions WHERE record_id = :record AND id <> :current AND (:published IS NULL OR id <> :kept) ORDER BY revision_number ASC LIMIT ' . $count . ' FOR UPDATE');
        $oldest->execute(['record' => $recordId, 'current' => $heads['current_revision_id'], 'published' => $heads['published_revision_id'], 'kept' => $heads['published_revision_id']]);
        $delete = $this->pdo->prepare('DELETE FROM cms_record_revisions WHERE record_id = :record AND id = :id');
        foreach ($oldest->fetchAll(\PDO::FETCH_COLUMN) as $id) {
            $delete->execute(['record' => $recordId, 'id' => $id]);
        }
        $this->audit($auditId, $actor, 'record_revisions_pruned', ['recordId' => $recordId, 'removed' => $count], $now);
        return $count;
    }

    public function missingPreviewRevisions(string $previewId): int
    {
        $query = $this->pdo->prepare('SELECT snapshot_json FROM cms_previews WHERE id = :id');
        $query->execute(['id' => $previewId]);
        $json = $query->fetchColumn();
        if (!is_string($json)) {
            return 0;
        }
        $snapshot = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($snapshot) || !is_array($snapshot['records'] ?? null)) {
            throw new \RuntimeException('Stored preview references are invalid');
        }
        $query = $this->pdo->prepare('SELECT id FROM cms_record_revisions WHERE id = :revision AND record_id = :record');
        $missing = 0;
        foreach ($snapshot['records'] as $record) {
            if (!is_array($record) || !is_string($record['revisionId'] ?? null) || !is_string($record['id'] ?? null)) {
                throw new \RuntimeException('Stored preview reference is invalid');
            }
            $query->execute(['revision' => $record['revisionId'], 'record' => $record['id']]);
            $missing += $query->fetchColumn() === false ? 1 : 0;
        }
        return $missing;
    }

    /** @param array<string, int|string> $metadata */
    private function audit(string $id, string $actor, string $event, array $metadata, \DateTimeImmutable $now): void
    {
        $query = $this->pdo->prepare('INSERT INTO cms_audit_events (id, actor_user_id, event_type, metadata_json, occurred_at) VALUES (:id, :actor, :event, :metadata, :time)');
        $query->execute(['id' => $id, 'actor' => $actor, 'event' => $event, 'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR), 'time' => $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u')]);
    }
}
