<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Publication;

use PDO;
use Utsuri\PortableCms\Conventional\Compiler\ContentFeedSnapshot;

/** Activation metadata commits with the release; filesystem discovery is a resumable outbox. */
final readonly class MariaDbFeedRepository implements FeedRepository
{
    public const MAX_ACTIVATIONS = 1000;
    public const MAX_FILES = 20000;
    public const MAX_BYTES = 2 * 1024 * 1024 * 1024;
    public const MAX_ACTIVATION_BYTES = 16 * 1024 * 1024;

    public function __construct(private PDO $pdo) {}

    public function dirty(): bool
    {
        return (int) $this->pdo->query('SELECT dirty FROM cms_feed_state WHERE singleton_id = 1')->fetchColumn() === 1;
    }

    public function floor(): int
    {
        return (int) $this->pdo->query('SELECT cursor_floor FROM cms_feed_state WHERE singleton_id = 1')->fetchColumn();
    }

    public function synchronized(): void
    {
        $this->transaction(function (): void {
            $this->pdo->exec("UPDATE cms_feed_snapshots SET files_json = '[]' WHERE purged = 1");
            $this->pdo->exec('UPDATE cms_feed_state SET dirty = 0 WHERE singleton_id = 1');
        });
    }

    public function assertAllowed(?FeedTransition $transition): void
    {
        if ($transition === null) {
            return;
        }
        $query = $this->pdo->prepare('SELECT purged FROM cms_feed_snapshots WHERE id = :id');
        $query->execute(['id' => $transition->manifest['snapshotId']]);
        if ((int) $query->fetchColumn() === 1) {
            throw new PublicationFailure('feed_snapshot_purged');
        }
    }

    /** Called only inside the owning release transaction and singleton lock. */
    public function activated(Release $release, ?FeedTransition $transition, int $sequence, \DateTimeImmutable $now): void
    {
        if (!$this->pdo->inTransaction()) {
            throw new \LogicException('Feed activation requires the release transaction');
        }
        $previous = $this->pdo->query('SELECT snapshot_id FROM cms_feed_activations ORDER BY sequence DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        if ($transition === null && !is_array($previous)) {
            return;
        }
        $this->assertAllowed($transition);
        $this->pruneLocked($now);
        if ((int) $this->pdo->query('SELECT COUNT(*) FROM cms_feed_activations')->fetchColumn() >= self::MAX_ACTIVATIONS) {
            throw new PublicationFailure('feed_retention_capacity');
        }
        $next = $transition?->manifest['snapshotId'];
        if ($transition !== null) {
            $this->pdo->prepare('INSERT IGNORE INTO cms_feed_snapshots (id, manifest_json, files_json) VALUES (:id, :manifest, :files)')->execute(['id' => $next, 'manifest' => ContentFeedSnapshot::json($transition->manifest), 'files' => ContentFeedSnapshot::json($transition->files)]);
        }
        $before = is_array($previous) ? $previous['snapshot_id'] : null;
        $oldRecords = $this->records($before);
        $newRecords = $transition?->manifest['records'] ?? [];
        $old = self::index($oldRecords);
        $new = self::index($newRecords);
        $changes = ['added' => [], 'changed' => [], 'removed' => []];
        foreach ($new as $key => $record) {
            $kind = !isset($old[$key]) ? 'added' : ($old[$key]['sha256'] !== $record['sha256'] ? 'changed' : null);
            if ($kind !== null) {
                unset($record['sha256']);
                $changes[$kind][] = $record;
            }
        }
        foreach ($old as $key => $record) {
            if (!isset($new[$key])) {
                unset($record['sha256']);
                $changes['removed'][] = $record;
            }
        }
        $document = ['formatVersion' => 1, 'releaseId' => $release->id, 'sequence' => $sequence, 'activatedAt' => $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'), 'snapshotId' => $next, 'replacedSnapshotId' => $before, 'rollback' => $release->rollback, 'changes' => $changes];
        $json = ContentFeedSnapshot::json($document);
        if ($this->activationBytes() + strlen($json) > self::MAX_ACTIVATION_BYTES) {
            throw new PublicationFailure('feed_retention_capacity');
        }
        $this->pdo->prepare('INSERT INTO cms_feed_activations (sequence, release_id, snapshot_id, activated_at, document_json) VALUES (:sequence, :release, :snapshot, :at, :document)')->execute(['sequence' => $sequence, 'release' => $release->id, 'snapshot' => $next, 'at' => self::time($now), 'document' => $json]);
        $retained = $this->retention()->files;
        if (count($retained) > self::MAX_FILES || array_sum(array_column($retained, 'byteSize')) > self::MAX_BYTES) {
            throw new PublicationFailure('feed_retention_capacity');
        }
        $this->pdo->exec('UPDATE cms_feed_state SET dirty = 1 WHERE singleton_id = 1');
    }

    /** @return list<FeedActivation> */
    public function activations(): array
    {
        if ($this->activationBytes() > self::MAX_ACTIVATION_BYTES) {
            throw new PublicationFailure('feed_retention_capacity');
        }
        return array_map(static fn(array $row): FeedActivation => new FeedActivation((int) $row['sequence'], $row['release_id'], $row['snapshot_id'], $row['document_json']), $this->pdo->query('SELECT sequence, release_id, snapshot_id, document_json FROM cms_feed_activations ORDER BY sequence LIMIT 1000')->fetchAll(PDO::FETCH_ASSOC));
    }

    private function activationBytes(): int
    {
        return (int) $this->pdo->query('SELECT COALESCE(SUM(OCTET_LENGTH(document_json)), 0) FROM cms_feed_activations')->fetchColumn();
    }

    public function retention(): FeedRetention
    {
        $rows = $this->pdo->query('SELECT id, OCTET_LENGTH(files_json) AS file_bytes, purged FROM cms_feed_snapshots WHERE purged = 1 OR id IN (SELECT snapshot_id FROM cms_feed_activations)')->fetchAll(PDO::FETCH_ASSOC);
        $read = $this->pdo->prepare('SELECT files_json FROM cms_feed_snapshots WHERE id = :id');
        $files = [];
        $purged = [];
        $purgedFiles = [];
        $bytes = 0;
        foreach ($rows as $row) {
            if ((int) $row['file_bytes'] > ContentFeedSnapshot::MAX_JSON_BYTES) {
                throw new PublicationFailure('feed_retention_capacity');
            }
            if ((int) $row['purged'] === 1 && (int) $row['file_bytes'] <= 2) {
                $purged[] = $row['id'];
                continue;
            }
            $read->execute(['id' => $row['id']]);
            $inventory = json_decode($read->fetchColumn(), true, 64, JSON_THROW_ON_ERROR);
            if ((int) $row['purged'] === 1) {
                $purged[] = $row['id'];
                foreach ($inventory as $file) {
                    $purgedFiles[$file['path']] = $file;
                }
            } else {
                foreach ($inventory as $file) {
                    if (isset($files[$file['path']]) && $files[$file['path']] != $file) {
                        throw new PublicationFailure('feed_immutable_file_conflict');
                    }
                    if (!isset($files[$file['path']])) {
                        $bytes += $file['byteSize'];
                        if (count($files) >= self::MAX_FILES || $bytes > self::MAX_BYTES) {
                            throw new PublicationFailure('feed_retention_capacity');
                        }
                    }
                    $files[$file['path']] = $file;
                }
            }
        }
        ksort($files, SORT_STRING);
        return new FeedRetention($files, $purged, $purgedFiles);
    }

    public function prune(\DateTimeImmutable $now): void
    {
        $this->transaction(fn() => $this->pruneLocked($now));
    }

    private function pruneLocked(\DateTimeImmutable $now): void
    {
        $rows = $this->pdo->query('SELECT sequence, activated_at FROM cms_feed_activations ORDER BY sequence LIMIT 1000')->fetchAll(PDO::FETCH_ASSOC);
        $cutoff = self::time($now->modify('-14 days'));
        $floor = $this->floor();
        foreach ($rows as $position => $row) {
            if ($position >= count($rows) - 10 || $row['activated_at'] >= $cutoff) {
                break;
            }
            $floor = (int) $row['sequence'];
        }
        if ($floor > $this->floor()) {
            $this->pdo->prepare('DELETE FROM cms_feed_activations WHERE sequence <= :floor')->execute(['floor' => $floor]);
            $this->pdo->exec('DELETE FROM cms_feed_snapshots WHERE purged = 0 AND id NOT IN (SELECT snapshot_id FROM cms_feed_activations WHERE snapshot_id IS NOT NULL)');
            $this->pdo->prepare('UPDATE cms_feed_state SET cursor_floor = :floor, dirty = 1 WHERE singleton_id = 1')->execute(['floor' => $floor]);
        }
    }

    public function purge(string $snapshot, string $actor, string $audit, \DateTimeImmutable $now): void
    {
        ContentFeedSnapshot::directoryFor($snapshot);
        $this->transaction(function () use ($snapshot, $actor, $audit, $now): void {
            $head = $this->pdo->query('SELECT pending_release FROM cms_publication_state WHERE singleton_id = 1')->fetchColumn();
            if ($head !== null) {
                throw new PublicationConflict();
            }
            $query = $this->pdo->prepare('UPDATE cms_feed_snapshots SET purged = 1 WHERE id = :id AND purged = 0');
            $query->execute(['id' => $snapshot]);
            if ($query->rowCount() === 0) {
                $known = $this->pdo->prepare('SELECT purged FROM cms_feed_snapshots WHERE id = :id');
                $known->execute(['id' => $snapshot]);
                if ((int) $known->fetchColumn() !== 1) {
                    throw new PublicationFailure('feed_snapshot_unknown');
                }
                return;
            }
            if ((int) $this->pdo->query('SELECT COUNT(*) FROM cms_feed_snapshots WHERE purged = 1')->fetchColumn() > 10000) {
                throw new PublicationFailure('feed_purge_capacity');
            }
            $this->pdo->exec('UPDATE cms_feed_state SET dirty = 1 WHERE singleton_id = 1');
            $this->pdo->prepare('INSERT INTO cms_audit_events (id, actor_user_id, event_type, metadata_json, occurred_at) VALUES (:id, :actor, :event, :metadata, :at)')->execute(['id' => $audit, 'actor' => $actor, 'event' => 'feed_snapshot_purged', 'metadata' => ContentFeedSnapshot::json(['snapshotId' => $snapshot]), 'at' => self::time($now)]);
        });
    }

    /** @return list<array<string, mixed>> */
    private function records(?string $snapshot): array
    {
        if ($snapshot === null) {
            return [];
        }
        $query = $this->pdo->prepare('SELECT manifest_json FROM cms_feed_snapshots WHERE id = :id');
        $query->execute(['id' => $snapshot]);
        $json = $query->fetchColumn();
        return is_string($json) ? json_decode($json, true, 64, JSON_THROW_ON_ERROR)['records'] : [];
    }

    /** @param list<array<string, mixed>> $records
     * @return array<string, array<string, mixed>>
     */
    private static function index(array $records): array
    {
        $index = [];
        foreach ($records as $record) {
            $index[$record['typeId'] . ':' . $record['locale'] . ':' . $record['id']] = $record;
        }
        return $index;
    }

    private function transaction(\Closure $operation): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->query('SELECT singleton_id FROM cms_publication_state WHERE singleton_id = 1 FOR UPDATE')->fetchColumn();
            $operation();
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    private static function time(\DateTimeImmutable $now): string
    {
        return $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
