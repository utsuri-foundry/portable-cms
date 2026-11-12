<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Restore;

use PDO;

/** The journal survives process death; authoritative rows commit in one transaction. */
final class MariaDbRestoreRepository implements RestoreRepository
{
    /** @param list<string> $migrationPaths */
    public function __construct(private readonly PDO $pdo, private readonly array $migrationPaths)
    {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        $pdo->exec("SET time_zone = '+00:00'");
    }

    public function inspect(RestorePlan $plan, string $destinationIdentity, string $actorId): string
    {
        $this->ready($actorId);
        $state = $this->state(false);
        if ($state !== null) {
            $this->matches($state, $plan, $destinationIdentity, $actorId);
            if ($state['status'] === 'completed') {
                return 'completed';
            }
        }
        $this->empty(false);
        return $state === null ? 'empty' : 'preparing';
    }

    public function reserve(RestorePlan $plan, string $destinationIdentity, string $actorId, \DateTimeImmutable $now): string
    {
        $this->begin();
        try {
            $this->ready($actorId);
            $state = $this->state(true);
            if ($state !== null) {
                $this->matches($state, $plan, $destinationIdentity, $actorId);
                $this->pdo->commit();
                return (string) $state['status'];
            }
            $this->empty(true);
            $provenance = array_intersect_key($plan->export->metadata(), array_flip(['sourcePublicUrlContext', 'publishedSlugHistory']));
            $this->insert('INSERT INTO cms_restore_state (singleton_id, export_id, export_sha256, destination_sha256, profile_sha256, status, provenance_json, actor_user_id, started_at) VALUES (1, :export, :sha256, :destination, :profile, \'preparing\', :provenance, :actor, :started)', [
                'export' => $plan->export->metadata()['exportId'], 'sha256' => $plan->sha256, 'destination' => $destinationIdentity,
                'profile' => $plan->package->checksum, 'provenance' => self::json($provenance === [] ? (object) [] : $provenance), 'actor' => $actorId, 'started' => self::time($now),
            ]);
            $this->audit($plan, $actorId, $now, 'normalized_restore_started');
            $this->pdo->commit();
            return 'preparing';
        } catch (\Throwable $error) {
            $this->rollback();
            throw $error;
        }
    }

    public function commit(RestorePlan $plan, string $destinationIdentity, string $actorId, \DateTimeImmutable $now): void
    {
        $this->begin();
        try {
            $this->ready($actorId);
            $state = $this->state(true) ?? throw new RestoreRejected('missing_restore_reservation');
            $this->matches($state, $plan, $destinationIdentity, $actorId);
            if ($state['status'] === 'completed') {
                $this->pdo->commit();
                return;
            }
            $this->empty(true);
            $writer = new MariaDbRestoreWriter($this->pdo);
            (new \Utsuri\PortableCms\Conventional\Database\MariaDbSiteSequence($this->pdo))->restore($plan->export->metadata()['siteSequence'] ?? 0);
            $writer->write($plan, $actorId, $now);
            $this->insert('UPDATE cms_restore_state SET status = \'completed\', completed_at = :completed WHERE singleton_id = 1', ['completed' => self::time($now)]);
            $this->audit($plan, $actorId, $now, 'normalized_restore_completed');
            $this->pdo->commit();
        } catch (\Throwable $error) {
            $this->rollback();
            throw $error;
        }
    }

    private function ready(string $actorId): void
    {
        $user = $this->pdo->prepare("SELECT id FROM cms_users WHERE id = :id AND role IN ('owner', 'administrator') AND status = 'active' AND must_change_password = 0");
        $user->execute(['id' => $actorId]);
        if ($user->fetchColumn() !== $actorId) {
            throw new RestoreRejected('administrator_required');
        }
        $ledger = $this->pdo->query('SELECT id, checksum, status FROM cms_migrations')->fetchAll(PDO::FETCH_ASSOC);
        $applied = array_column($ledger, null, 'id');
        if (count($applied) !== count($this->migrationPaths)) {
            throw new RestoreRejected('migration_ledger_incompatible');
        }
        foreach ($this->migrationPaths as $path) {
            $row = $applied[pathinfo($path, PATHINFO_FILENAME)] ?? null;
            if ($row === null || $row['status'] !== 'complete' || $row['checksum'] !== hash_file('sha256', $path)) {
                throw new RestoreRejected('migrations_not_ready');
            }
        }
    }

    private function empty(bool $lock): void
    {
        // Constant table names only. SERIALIZABLE range locks fence concurrent saves.
        foreach (['cms_records', 'cms_record_revisions', 'cms_record_relationship_index', 'cms_media', 'cms_media_derivatives', 'cms_jobs', 'cms_media_upload_sessions', 'cms_previews', 'cms_design_registries', 'cms_design_registry_revisions', 'cms_editorial_terminology', 'cms_editorial_terminology_revisions', 'cms_restore_authorship', 'cms_website_publication', 'cms_site_languages', 'cms_site_wording', 'cms_site_wording_revisions'] as $table) {
            $row = $this->pdo->query('SELECT 1 FROM ' . $table . ' LIMIT 1' . ($lock ? ' FOR UPDATE' : ''))->fetchColumn();
            if ($row !== false) {
                throw new RestoreRejected('destination_not_empty');
            }
        }
    }

    /** @return array<string, mixed>|null */
    private function state(bool $lock): ?array
    {
        $row = $this->pdo->query('SELECT * FROM cms_restore_state WHERE singleton_id = 1' . ($lock ? ' FOR UPDATE' : ''))->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @param array<string, mixed> $state */
    private function matches(array $state, RestorePlan $plan, string $destinationIdentity, string $actorId): void
    {
        if ($state['export_id'] !== $plan->export->metadata()['exportId'] || $state['export_sha256'] !== $plan->sha256
            || $state['destination_sha256'] !== $destinationIdentity || $state['profile_sha256'] !== $plan->package->checksum || $state['actor_user_id'] !== $actorId) {
            throw new RestoreRejected('different_restore_already_reserved');
        }
    }

    private function begin(): void
    {
        if ($this->pdo->inTransaction()) {
            throw new \LogicException('Restore owns its transaction');
        }
        $this->pdo->exec('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');
        $this->pdo->beginTransaction();
    }

    private function rollback(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    private function audit(RestorePlan $plan, string $actorId, \DateTimeImmutable $now, string $event): void
    {
        $id = substr(hash('sha256', $event . ':' . $plan->export->metadata()['exportId']), 0, 32);
        $this->insert('INSERT INTO cms_audit_events (id, actor_user_id, event_type, metadata_json, occurred_at) VALUES (:id, :actor, :event, :metadata, :occurred)', [
            'id' => $id, 'actor' => $actorId, 'event' => $event,
            'metadata' => self::json(['exportId' => $plan->export->metadata()['exportId'], 'exportSha256' => $plan->sha256, 'recordCount' => $plan->export->count('records')]), 'occurred' => self::time($now),
        ]);
    }

    /** @param array<string, mixed> $values */
    private function insert(string $sql, array $values): void
    {
        $this->pdo->prepare($sql)->execute($values);
    }

    private static function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private static function time(\DateTimeImmutable $value): string
    {
        return $value->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
