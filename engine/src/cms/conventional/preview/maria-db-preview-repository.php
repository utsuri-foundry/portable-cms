<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Preview;

use PDO;
use Throwable;

final class MariaDbPreviewRepository implements PreviewCleanupRepository, EditorPreviewRepository
{
    /** @param (\Closure(PreviewSnapshot): void)|null $preflight */
    public function __construct(private readonly PDO $pdo, private readonly ?\Utsuri\PortableCms\Conventional\Design\RegistryDocument $designDefaults = null, private readonly ?string $profileChecksum = null, private readonly ?\Closure $preflight = null, private readonly ?\Utsuri\PortableCms\Conventional\Compiler\PublicUrlContext $publicUrlContext = null, private readonly ?\Utsuri\PortableCms\Conventional\Compiler\PublishedSlugHistory $publishedSlugHistory = null, private readonly ?\Utsuri\PortableCms\Conventional\Publication\HoldingSnapshotSource $holdingSource = null)
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }

    public function latestReadyPreview(): ?PreviewStatus
    {
        $id = (new MariaDbNewestReadyPreview($this->pdo))->id(true);
        return $id === null ? null : $this->find($id);
    }

    public function capturedRecord(string $previewId, string $recordId, \Utsuri\PortableCms\Conventional\Content\ProfileManifest $profile): bool
    {
        if ($this->profileChecksum === null) {
            return false;
        }
        $query = $this->pdo->prepare("SELECT JSON_CONTAINS(JSON_EXTRACT(snapshot_json, '$.records[*].id'), JSON_QUOTE(:record)) FROM cms_previews WHERE id = :id AND profile_id = :profile AND profile_version = :version AND JSON_UNQUOTE(JSON_EXTRACT(snapshot_json, '$.profileChecksum')) = :checksum");
        $query->execute(['record' => $recordId, 'id' => $previewId, 'profile' => $profile->id, 'version' => $profile->version, 'checksum' => $this->profileChecksum]);
        return (int) $query->fetchColumn() === 1;
    }

    public function reusableDraftPreview(\Utsuri\PortableCms\Conventional\Content\ProfileManifest $profile, \DateTimeImmutable $now): ?PreviewStatus
    {
        $this->pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->pdo->beginTransaction();
        try {
            $candidates = [$this->latestReadyPreview()];
            $pending = $this->pdo->query("SELECT id FROM cms_previews WHERE build_kind = 'website' AND include_drafts = 1 AND status IN ('queued', 'building') ORDER BY created_at DESC, id DESC LIMIT 1")->fetchColumn();
            if (is_string($pending)) {
                $candidates[] = $this->find($pending);
            }
            $lastChange = $this->pdo->query("SELECT MAX(changed_at) FROM (SELECT updated_at AS changed_at FROM cms_records UNION ALL SELECT occurred_at FROM cms_site_changes WHERE kind = 'record_removed') AS kept_changes")->fetchColumn();
            foreach ($candidates as $candidate) {
                if ($candidate === null || (is_string($lastChange) && $candidate->createdAt < self::databaseDate($lastChange))) {
                    continue;
                }
                $query = $this->pdo->prepare('SELECT snapshot_json FROM cms_previews WHERE id = :id AND profile_id = :profile AND profile_version = :version');
                $query->execute(['id' => $candidate->id, 'profile' => $profile->id, 'version' => $profile->version]);
                $json = $query->fetchColumn();
                if (!is_string($json)) {
                    continue;
                }
                // A content comparison also catches equal clocks, deletions and changes to wording,
                // languages, design, identity and selected media. Never infer freshness from IDs alone:
                // automatic keeping can change a revision before an exact capture seals it.
                $stored = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
                $current = json_decode(self::encodeSnapshot($this->freezeSnapshot($profile->id, $profile->version, $profile->locales, $now, true, $candidate->includePreparation)), true, 64, JSON_THROW_ON_ERROR);
                if (!is_array($stored) || !is_array($current)) {
                    throw new \RuntimeException('Stored preview snapshot is invalid');
                }
                unset($stored['createdAt'], $current['createdAt']);
                if ($stored === $current) {
                    $this->pdo->commit();
                    return $candidate;
                }
            }
            $this->pdo->commit();
            return null;
        } catch (PreviewPreflightFailure) {
            // The ordinary enqueue owns durable preflight diagnostics and its refusal audit.
            $this->pdo->rollBack();
            return null;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    /** @param list<string> $locales */
    public function enqueue(
        string $previewId,
        string $jobId,
        string $auditId,
        string $actorUserId,
        string $profileId,
        string $profileVersion,
        array $locales,
        \DateTimeImmutable $now,
        bool $includeDrafts = false,
        bool $includePreparation = false,
    ): PreviewStatus {
        foreach ([$previewId, $jobId, $auditId, $actorUserId] as $id) {
            self::assertId($id);
        }
        $time = self::databaseTime($now);
        $this->pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->pdo->beginTransaction();
        try {
            // Lock before creating the repeatable-read view: saves cannot change a selected head.
            $this->pdo->query('SELECT id FROM cms_records ORDER BY id LIMIT 2001 FOR UPDATE')->fetchAll(PDO::FETCH_COLUMN);
            if ($includeDrafts && $this->holdingSource === null) {
                $this->pdo->exec("UPDATE cms_records SET editing_session_hash = NULL WHERE workflow_state <> 'archived'");
            }
            $snapshot = $this->holdingSource?->freeze($profileId, $profileVersion, $locales, $now) ?? $this->freezeSnapshot($profileId, $profileVersion, $locales, $now, $includeDrafts, $includePreparation);
            if ($this->preflight !== null) {
                ($this->preflight)($snapshot);
            }
            $snapshotJson = self::encodeSnapshot($snapshot);
            $payload = json_encode(
                ['formatVersion' => 1, 'previewId' => $previewId],
                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            );
            $job = $this->pdo->prepare(<<<'SQL'
INSERT INTO cms_jobs (
    id, job_type, status, payload_json, attempts, max_attempts,
    available_at, created_at, updated_at
) VALUES (
    :id, 'preview_build', 'queued', :payload, 0, 3,
    :available_at, :created_at, :updated_at
)
SQL);
            $job->execute([
                'id' => $jobId,
                'payload' => $payload,
                'available_at' => $time,
                'created_at' => $time,
                'updated_at' => $time,
            ]);
            $preview = $this->pdo->prepare(<<<'SQL'
INSERT INTO cms_previews (
    id, profile_id, profile_version, status, snapshot_json, manifest_json, include_drafts, include_preparation,
    job_id, error_code, created_by, created_at, updated_at, build_kind
) VALUES (
    :id, :profile_id, :profile_version, 'queued', :snapshot_json, NULL, :include_drafts, :include_preparation,
    :job_id, NULL, :created_by, :created_at, :updated_at, :build_kind
)
SQL);
            $preview->execute([
                'id' => $previewId,
                'profile_id' => $profileId,
                'profile_version' => $profileVersion,
                'snapshot_json' => $snapshotJson,
                'include_drafts' => $includeDrafts ? 1 : 0,
                'include_preparation' => $snapshot->preparationLocales === [] ? 0 : 1,
                'job_id' => $jobId,
                'created_by' => $actorUserId,
                'created_at' => $time,
                'updated_at' => $time,
                'build_kind' => $snapshot->holdingPage === null ? 'website' : 'holding',
            ]);
            $this->audit($auditId, $actorUserId, 'preview_queued', [
                'profileId' => $profileId,
                'previewId' => $previewId,
                'jobId' => $jobId,
                'recordCount' => count($snapshot->records),
                'includeDrafts' => $includeDrafts ? 1 : 0,
                'buildKind' => $snapshot->holdingPage === null ? 'website' : 'holding',
                ...($snapshot->preparationLocales === [] ? [] : ['preparationLocales' => implode(',', $snapshot->preparationLocales)]),
            ], $time);
            $this->pdo->commit();
            return new PreviewStatus($previewId, 'queued', $jobId, null, null, $now, $now, $includeDrafts, $snapshot->holdingPage === null ? 'website' : 'holding', includePreparation: $snapshot->preparationLocales !== []);
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function list(int $limit): array
    {
        $limit = max(1, min(100, $limit));
        $kind = $this->holdingSource === null ? 'website' : 'holding';
        $rows = $this->pdo->query(<<<SQL
SELECT p.id, p.status, p.job_id, p.error_code, p.manifest_json, p.created_at, p.updated_at, p.include_drafts, p.include_preparation, p.build_kind, j.progress_json
FROM cms_previews p JOIN cms_jobs j ON j.id = p.job_id
WHERE p.build_kind = '{$kind}' ORDER BY p.created_at DESC, p.id DESC LIMIT {$limit}
SQL)->fetchAll(PDO::FETCH_ASSOC);
        return array_map(self::hydrateStatus(...), $rows);
    }

    public function find(string $previewId): ?PreviewStatus
    {
        if (preg_match('/\A[a-f0-9]{32}\z/', $previewId) !== 1) {
            return null;
        }
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT p.id, p.status, p.job_id, p.error_code, p.manifest_json, p.created_at, p.updated_at, p.include_drafts, p.include_preparation, p.build_kind, j.progress_json
FROM cms_previews p JOIN cms_jobs j ON j.id = p.job_id WHERE p.id = :id
SQL);
        $statement->execute(['id' => $previewId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? self::hydrateStatus($row) : null;
    }

    public function cleanupPage(?PreviewCursor $before, int $limit, \DateTimeImmutable $now): array
    {
        $limit = max(1, min(21, $limit));
        $predicate = $before === null ? '' : ' AND (p.created_at < :before_time OR (p.created_at = :same_time AND p.id < :before_id))';
        $statement = $this->pdo->prepare(self::cleanupQuery() . " WHERE p.build_kind = 'website'{$predicate} ORDER BY p.created_at DESC, p.id DESC LIMIT {$limit}");
        $parameters = ['now' => self::databaseTime($now)];
        if ($before !== null) {
            $parameters += ['before_time' => self::databaseTime($before->createdAt), 'same_time' => self::databaseTime($before->createdAt), 'before_id' => $before->id];
        }
        $statement->execute($parameters);
        return array_map(self::hydrateCleanup(...), $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function cleanupItem(string $id, \DateTimeImmutable $now): ?PreviewCleanupItem
    {
        self::assertId($id);
        $statement = $this->pdo->prepare(self::cleanupQuery() . ' WHERE p.id = :id');
        $statement->execute(['id' => $id, 'now' => self::databaseTime($now)]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? self::hydrateCleanup($row) : null;
    }

    private static function cleanupQuery(): string
    {
        // Browsing and review need metadata only. Artifact/status detail still uses find().
        return <<<'SQL'
SELECT p.id, p.status, p.job_id, p.error_code, NULL AS manifest_json, p.created_at, p.updated_at, p.include_drafts, p.include_preparation, p.build_kind, NULL AS progress_json, j.status AS job_status,
EXISTS(SELECT 1 FROM cms_releases r WHERE r.preview_id = p.id) AS release_linked,
(SELECT COUNT(*) FROM cms_preview_shares s WHERE s.preview_id = p.id AND s.revoked_at IS NULL AND s.expires_at > :now) AS active_shares
FROM cms_previews p JOIN cms_jobs j ON j.id = p.job_id
SQL;
    }

    /** @param array<string, mixed> $row */
    private static function hydrateCleanup(array $row): PreviewCleanupItem
    {
        return new PreviewCleanupItem(self::hydrateStatus($row), (bool) $row['release_linked'], (int) $row['active_shares'], in_array($row['job_status'], ['completed', 'needs_attention'], true));
    }

    public function claim(
        string $workerId,
        string $leaseToken,
        \DateTimeImmutable $now,
        \DateTimeImmutable $leaseExpiresAt,
    ): ?PreviewJob {
        if (
            preg_match('/\A[a-zA-Z0-9._:-]{1,100}\z/', $workerId) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/', $leaseToken) !== 1
        ) {
            throw new \InvalidArgumentException('Preview worker lease identity is invalid');
        }
        $time = self::databaseTime($now);
        $this->pdo->beginTransaction();
        try {
            $this->expireExhaustedLeases($time);
            $claim = $this->pdo->prepare(<<<'SQL'
UPDATE cms_jobs
SET status = 'running', attempts = attempts + 1,
    lease_owner = :lease_owner, lease_token = :lease_token,
    lease_expires_at = :lease_expires_at,
    progress_json = :progress, error_code = NULL, updated_at = :updated_at
WHERE job_type = 'preview_build'
  AND attempts < max_attempts
  AND (
      (status IN ('queued', 'retry') AND available_at <= :available_at)
      OR (status = 'running' AND lease_expires_at IS NOT NULL AND lease_expires_at <= :expired_at)
  )
ORDER BY available_at ASC, created_at ASC, id ASC
LIMIT 1
SQL);
            $claim->execute([
                'lease_owner' => $workerId,
                'lease_token' => $leaseToken,
                'lease_expires_at' => self::databaseTime($leaseExpiresAt),
                'progress' => json_encode(['phase' => 'building'], JSON_THROW_ON_ERROR),
                'updated_at' => $time,
                'available_at' => $time,
                'expired_at' => $time,
            ]);
            if ($claim->rowCount() !== 1) {
                $this->pdo->commit();
                return null;
            }
            $select = $this->pdo->prepare('SELECT * FROM cms_jobs WHERE lease_token = :lease_token');
            $select->execute(['lease_token' => $leaseToken]);
            $row = $select->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                throw new \RuntimeException('Claimed preview job disappeared');
            }
            $payload = self::payload($row);
            $previewId = self::payloadPreviewId($payload);
            $preview = $this->pdo->prepare(<<<'SQL'
UPDATE cms_previews SET status = 'building', error_code = NULL, updated_at = :updated_at
WHERE id = :id AND job_id = :job_id AND status IN ('queued', 'building')
SQL);
            $preview->execute(['updated_at' => $time, 'id' => $previewId, 'job_id' => $row['id']]);
            if ($preview->rowCount() !== 1) {
                throw new \RuntimeException('Preview is not buildable');
            }
            $this->pdo->commit();
            return new PreviewJob(
                (string) $row['id'],
                $previewId,
                (int) $row['attempts'],
                (int) $row['max_attempts'],
                $leaseToken,
                $leaseExpiresAt,
            );
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function snapshot(PreviewJob $job): PreviewSnapshot
    {
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT p.snapshot_json
FROM cms_previews p
JOIN cms_jobs j ON j.id = p.job_id
WHERE p.id = :preview_id AND p.job_id = :job_id AND p.status = 'building'
  AND j.status = 'running' AND j.lease_token = :lease_token
SQL);
        $statement->execute([
            'preview_id' => $job->previewId,
            'job_id' => $job->id,
            'lease_token' => $job->leaseToken,
        ]);
        $encoded = $statement->fetchColumn();
        if (!is_string($encoded)) {
            throw new \RuntimeException('Preview snapshot is unavailable');
        }
        return self::decodeSnapshot($encoded);
    }

    public function complete(
        PreviewJob $job,
        PreviewBuild $build,
        string $auditId,
        \DateTimeImmutable $now,
    ): void {
        self::assertId($auditId);
        $time = self::databaseTime($now);
        $manifest = json_encode($build->manifest(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $this->pdo->beginTransaction();
        try {
            $row = $this->lockedLease($job, $now);
            $preview = $this->pdo->prepare(<<<'SQL'
UPDATE cms_previews
SET status = 'ready', manifest_json = :manifest, error_code = NULL, updated_at = :updated_at
WHERE id = :id AND job_id = :job_id AND status = 'building'
SQL);
            $preview->execute([
                'manifest' => $manifest,
                'updated_at' => $time,
                'id' => $job->previewId,
                'job_id' => $job->id,
            ]);
            if ($preview->rowCount() !== 1) {
                throw new \RuntimeException('Preview completion compare-and-set failed');
            }
            $update = $this->pdo->prepare(<<<'SQL'
UPDATE cms_jobs
SET status = 'completed', result_json = :result, progress_json = :progress,
    lease_owner = NULL, lease_token = NULL, lease_expires_at = NULL,
    error_code = NULL, updated_at = :updated_at
WHERE id = :id AND status = 'running' AND lease_token = :lease_token
SQL);
            $update->execute([
                'result' => json_encode([
                    'formatVersion' => 1,
                    'previewId' => $job->previewId,
                    'artifactCount' => count($build->artifacts),
                ], JSON_THROW_ON_ERROR),
                'progress' => json_encode(['phase' => 'completed'], JSON_THROW_ON_ERROR),
                'updated_at' => $time,
                'id' => $job->id,
                'lease_token' => $job->leaseToken,
            ]);
            if ($update->rowCount() !== 1) {
                throw new \RuntimeException('Preview job completion compare-and-set failed');
            }
            $this->audit($auditId, (string) $row['created_by'], 'preview_ready', [
                'previewId' => $job->previewId,
                'jobId' => $job->id,
                'artifactCount' => count($build->artifacts),
            ], $time);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function fail(
        PreviewJob $job,
        string $errorCode,
        bool $retryable,
        \DateTimeImmutable $retryAt,
        \DateTimeImmutable $now,
        ?PreviewDiagnostics $diagnostics = null,
    ): void {
        if (preg_match('/\A[a-z][a-z0-9_]{0,79}\z/', $errorCode) !== 1) {
            $errorCode = 'preview_build_failed';
        }
        $time = self::databaseTime($now);
        $this->pdo->beginTransaction();
        try {
            $row = $this->lockedLease($job, $now);
            $shouldRetry = $retryable && (int) $row['attempts'] < (int) $row['max_attempts'];
            $jobStatus = $shouldRetry ? 'retry' : 'needs_attention';
            $previewStatus = $shouldRetry ? 'queued' : 'needs_attention';
            $update = $this->pdo->prepare(<<<'SQL'
UPDATE cms_jobs
SET status = :status, available_at = :available_at,
    lease_owner = NULL, lease_token = NULL, lease_expires_at = NULL,
    progress_json = :progress, error_code = :error_code, updated_at = :updated_at
WHERE id = :id AND status = 'running' AND lease_token = :lease_token
SQL);
            $update->execute([
                'status' => $jobStatus,
                'available_at' => self::databaseTime($shouldRetry ? $retryAt : $now),
                'progress' => json_encode(['phase' => $shouldRetry ? 'retry_wait' : 'failed', 'diagnostics' => $diagnostics?->document()], JSON_THROW_ON_ERROR),
                'error_code' => $errorCode,
                'updated_at' => $time,
                'id' => $job->id,
                'lease_token' => $job->leaseToken,
            ]);
            if ($update->rowCount() !== 1) {
                throw new \RuntimeException('Preview job failure compare-and-set failed');
            }
            $preview = $this->pdo->prepare(<<<'SQL'
UPDATE cms_previews SET status = :status, error_code = :error_code, updated_at = :updated_at
WHERE id = :id AND job_id = :job_id AND status = 'building'
SQL);
            $preview->execute([
                'status' => $previewStatus,
                'error_code' => $errorCode,
                'updated_at' => $time,
                'id' => $job->previewId,
                'job_id' => $job->id,
            ]);
            if ($preview->rowCount() !== 1) {
                throw new \RuntimeException('Preview failure compare-and-set failed');
            }
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function delete(
        string $previewId,
        string $actorUserId,
        string $auditId,
        \DateTimeImmutable $now,
    ): void {
        $this->deleteGuarded($previewId, $actorUserId, $auditId, $now, static function (): void {});
    }

    public function deleteGuarded(string $previewId, string $actorUserId, string $auditId, \DateTimeImmutable $now, \Closure $quarantine): void
    {
        foreach ([$previewId, $actorUserId, $auditId] as $id) {
            self::assertId($id);
        }
        $time = self::databaseTime($now);
        $wait = (int) $this->pdo->query('SELECT @@SESSION.innodb_lock_wait_timeout')->fetchColumn();
        if ($wait < 0 || $wait > 1_073_741_824) {
            throw new \RuntimeException('Preview deletion lock timeout is invalid');
        }
        $this->pdo->exec('SET SESSION innodb_lock_wait_timeout = ' . min($wait, 1));
        try {
            $this->pdo->beginTransaction();
            $select = $this->pdo->prepare(<<<'SQL'
SELECT p.status, p.job_id, p.build_kind, j.status AS job_status FROM cms_previews p JOIN cms_jobs j ON j.id = p.job_id WHERE p.id = :id FOR UPDATE
SQL);
            $select->execute(['id' => $previewId]);
            $row = $select->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                throw new PreviewNotFound();
            }
            if (!in_array($row['status'], ['ready', 'needs_attention'], true)
                || !in_array($row['job_status'], ['completed', 'needs_attention'], true)
                || $row['build_kind'] !== ($this->holdingSource === null ? 'website' : 'holding')) {
                throw new PreviewNotDeletable();
            }
            $references = $this->pdo->prepare('SELECT id FROM cms_releases WHERE preview_id = :id LIMIT 1');
            $references->execute(['id' => $previewId]);
            if ($references->fetchColumn() !== false) {
                throw new PreviewNotDeletable();
            }
            $quarantine();
            $deletePreview = $this->pdo->prepare('DELETE FROM cms_previews WHERE id = :id');
            $deletePreview->execute(['id' => $previewId]);
            if ($deletePreview->rowCount() !== 1) {
                throw new \RuntimeException('Preview deletion compare-and-set failed');
            }
            $deleteJob = $this->pdo->prepare(<<<'SQL'
DELETE FROM cms_jobs
WHERE id = :id AND job_type = 'preview_build' AND status IN ('completed', 'needs_attention')
SQL);
            $deleteJob->execute(['id' => $row['job_id']]);
            if ($deleteJob->rowCount() !== 1) {
                throw new \RuntimeException('Preview job could not be deleted');
            }
            $this->audit($auditId, $actorUserId, 'preview_deleted', [
                'previewId' => $previewId,
                'jobId' => (string) $row['job_id'],
            ], $time);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        } finally {
            $this->pdo->exec('SET SESSION innodb_lock_wait_timeout = ' . $wait);
        }
    }

    /**
     * Read-only projection shared by preview creation and isolated upgrade compilation checks.
     * It draws the public languages the installation chose, and languages in preparation only for review.
     * @param list<string> $locales
     */
    public function freezeSnapshot(
        string $profileId,
        string $profileVersion,
        array $locales,
        \DateTimeImmutable $now,
        bool $includeDrafts,
        bool $includePreparation = false,
        bool $currentVersions = false,
    ): PreviewSnapshot {
        $selected = $includeDrafts || $currentVersions ? 'r.current_revision_id' : "CASE WHEN r.workflow_state = 'published' THEN r.published_revision_id ELSE r.current_revision_id END";
        $missing = $this->pdo->query('SELECT COUNT(*) FROM cms_records r LEFT JOIN cms_record_revisions v ON v.id = (' . $selected . ') AND v.record_id = r.id WHERE v.id IS NULL');
        if ((int) $missing->fetchColumn() !== 0) {
            throw new \RuntimeException('Selected record revision is unavailable');
        }
        $bytes = (int) $this->pdo->query('SELECT COALESCE(SUM(OCTET_LENGTH(v.body_json)), 0) FROM cms_records r JOIN cms_record_revisions v ON v.id = ' . $selected)->fetchColumn();
        $facts = $this->pdo->query(<<<SQL
SELECT (SELECT COALESCE(SUM(OCTET_LENGTH(text_json)), 0) FROM cms_media WHERE status = 'ready') AS text_bytes,
       (SELECT COUNT(*) FROM cms_media) AS media_count,
       (SELECT COUNT(*) FROM cms_media_derivatives) AS derivative_count,
       (SELECT COUNT(*) FROM cms_record_relationship_index i JOIN cms_records r ON r.id = i.record_id AND i.revision_id = ({$selected})) AS relationship_count
SQL)->fetch(PDO::FETCH_ASSOC);
        if ($bytes + (int) $facts['text_bytes'] > \Utsuri\PortableCms\Conventional\Export\ExportCapacity::SNAPSHOT_BYTES
            || (int) $facts['media_count'] > \Utsuri\PortableCms\Conventional\Export\ExportCapacity::LIMITS['media']
            || (int) $facts['relationship_count'] > \Utsuri\PortableCms\Conventional\Export\ExportCapacity::LIMITS['relationships']
            || (int) $facts['derivative_count'] > \Utsuri\PortableCms\Conventional\Export\EXPORT_MAX_DERIVATIVES) {
            throw new PreviewPreflightFailure([new \Utsuri\PortableCms\Conventional\I18n\Message('preflight.issue.capacity')]);
        }
        $rows = $this->pdo->query(<<<SQL
SELECT r.id, r.type_id, r.workflow_state, rr.id AS current_revision_id, rr.revision_number AS current_revision_number, rr.body_json
FROM cms_records r
JOIN cms_record_revisions rr ON rr.id = {$selected} AND rr.record_id = r.id
ORDER BY r.type_id ASC, r.id ASC
LIMIT 2001
SQL)->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) > \Utsuri\PortableCms\Conventional\Export\ExportCapacity::LIMITS['records']) {
            throw new PreviewPreflightFailure([new \Utsuri\PortableCms\Conventional\I18n\Message('preflight.issue.capacity')]);
        }
        $relationships = [];
        foreach ($this->pdo->query(<<<SQL
SELECT i.record_id, i.field_id, i.target_record_id
FROM cms_record_relationship_index i
JOIN cms_records r ON r.id = i.record_id AND ({$selected}) = i.revision_id
ORDER BY i.record_id ASC, i.field_id ASC, i.position ASC
SQL)->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $relationships[(string) $row['record_id']][(string) $row['field_id']][] = (string) $row['target_record_id'];
        }
        $widths = [];
        foreach ($this->pdo->query(<<<'SQL'
SELECT d.media_id, d.variant, d.width, d.height, d.byte_size, d.checksum
FROM cms_media_derivatives d JOIN cms_media m ON m.id = d.media_id
WHERE m.status = 'ready' AND d.variant IN ('480-webp', '1600-webp', '2400-webp')
ORDER BY d.media_id, d.width
SQL)->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $maximum = \Utsuri\PortableCms\Conventional\Media\RasterWidths::maximum((string) $row['variant']);
            if ($maximum === null) {
                throw new \RuntimeException('Stored preview width is unsupported');
            }
            $widths[(string) $row['media_id']][] = new \Utsuri\PortableCms\Conventional\Media\RasterImageWidth($maximum, (int) $row['width'], (int) $row['height'], (int) $row['byte_size'], (string) $row['checksum']);
        }
        $media = [];
        foreach ($this->pdo->query(<<<'SQL'
SELECT m.owner_record_id, m.id, d.object_key, d.filename, d.mime,
       d.width, d.height, d.byte_size, d.checksum, m.text_json, m.focal_x, m.focal_y
FROM cms_media m
JOIN cms_media_derivatives d ON d.media_id = m.id AND d.variant IN ('960-webp', 'prepared-video')
WHERE m.status = 'ready'
ORDER BY m.owner_record_id ASC, m.created_at ASC, m.id ASC
SQL)->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $media[(string) $row['owner_record_id']][] = new PreviewMedia(
                (string) $row['id'],
                (string) $row['object_key'],
                (string) $row['filename'],
                (string) $row['mime'],
                (int) $row['byte_size'],
                (string) $row['checksum'],
                (int) $row['width'],
                (int) $row['height'],
                \Utsuri\PortableCms\Conventional\Media\MediaText::from($row['text_json'] === null ? null : json_decode((string) $row['text_json'], true, 16, JSON_THROW_ON_ERROR)),
                new \Utsuri\PortableCms\Conventional\Media\FocalPoint((int) $row['focal_x'], (int) $row['focal_y']),
                $widths[(string) $row['id']] ?? [],
            );
        }
        $records = [];
        foreach ($rows as $row) {
            $body = json_decode((string) $row['body_json'], true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($body) || array_is_list($body)) {
                throw new \RuntimeException('Stored preview record body is invalid');
            }
            $id = (string) $row['id'];
            $records[] = new PreviewRecord(
                $id,
                (string) $row['type_id'],
                (string) $row['current_revision_id'],
                (int) $row['current_revision_number'],
                $body,
                $relationships[$id] ?? [],
                $media[$id] ?? [],
                (string) $row['workflow_state'],
            );
        }
        $query = $this->pdo->prepare('SELECT document_json FROM cms_design_registries WHERE profile_id = :profile');
        $query->execute(['profile' => $profileId]);
        $json = $query->fetchColumn();
        $design = is_string($json) ? \Utsuri\PortableCms\Conventional\Design\RegistryDocument::fromDocument(json_decode($json, true, 32, JSON_THROW_ON_ERROR)) : $this->designDefaults;
        $history = $this->publishedSlugHistory ?? \Utsuri\PortableCms\Conventional\Publication\MariaDbPublicationRepository::history($this->pdo);
        // A destination context change cannot adopt the former origin's redirect authority.
        if ($history !== null && $history->context->checksum() !== $this->publicUrlContext?->checksum()) {
            $history = null;
        }
        $languages = (new \Utsuri\PortableCms\Conventional\Content\MariaDbSiteLanguages($this->pdo))->scoped($profileId, $locales);
        $drawn = $languages === null ? $locales : ($includePreparation ? $languages->editing() : $languages->public());
        // The site wording is read in the same transaction, so a build draws one consistent state.
        $query = $this->pdo->prepare('SELECT document_json FROM cms_site_wording WHERE profile_id = :profile');
        $query->execute(['profile' => $profileId]);
        $json = $query->fetchColumn();
        $wording = is_string($json) ? json_decode($json, true, 8, JSON_THROW_ON_ERROR) : null;
        \Utsuri\PortableCms\Conventional\Content\SiteWording::assertShape($wording ?? ['formatVersion' => 1, 'profileId' => $profileId, 'revision' => 0, 'values' => []], $profileId);
        $wording = array_values(array_filter($wording['values'] ?? [], static fn(array $entry): bool => in_array($entry['locale'], $drawn, true)));
        $publication = (new \Utsuri\PortableCms\Conventional\Publication\MariaDbWebsitePublication($this->pdo))->load($profileId, $locales);
        $publication->assertScope($profileId, $locales);
        $identity = $publication->identity();
        return new PreviewSnapshot($profileId, $profileVersion, $drawn, $records, $now, $design, $this->profileChecksum, $this->publicUrlContext, $history, $includeDrafts, defaultLocale: $languages?->primary, preparationLocales: $includePreparation ? ($languages?->preparation() ?? []) : [], wording: $wording, websiteIdentity: $identity, websiteMedia: $media[''] ?? []);
    }

    private function expireExhaustedLeases(string $time): void
    {
        $query = $this->pdo->prepare(<<<'SQL'
SELECT j.id, p.id AS preview_id, p.created_by
FROM cms_jobs j JOIN cms_previews p ON p.job_id = j.id
WHERE j.job_type = 'preview_build' AND j.status = 'running'
  AND j.attempts >= j.max_attempts AND j.lease_expires_at <= :expired
ORDER BY j.id LIMIT 50 FOR UPDATE
SQL);
        $query->execute(['expired' => $time]);
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $job = $this->pdo->prepare("UPDATE cms_jobs SET status = 'needs_attention', error_code = 'preview_attempts_exhausted', lease_owner = NULL, lease_token = NULL, lease_expires_at = NULL, updated_at = :time WHERE id = :id AND status = 'running'");
            $job->execute(['time' => $time, 'id' => $row['id']]);
            $preview = $this->pdo->prepare("UPDATE cms_previews SET status = 'needs_attention', error_code = 'preview_attempts_exhausted', updated_at = :time WHERE id = :id AND status = 'building'");
            $preview->execute(['time' => $time, 'id' => $row['preview_id']]);
            if ($job->rowCount() !== 1 || $preview->rowCount() !== 1) {
                throw new \RuntimeException('Expired preview lease compare-and-set failed');
            }
            $this->audit(substr(hash('sha256', 'preview_attempts_exhausted:' . $row['id']), 0, 32), (string) $row['created_by'], 'preview_attempts_exhausted', ['previewId' => (string) $row['preview_id'], 'jobId' => (string) $row['id']], $time);
        }
    }

    /** @return array<string, mixed> */
    private function lockedLease(PreviewJob $job, \DateTimeImmutable $now): array
    {
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT j.*, p.created_by
FROM cms_jobs j JOIN cms_previews p ON p.job_id = j.id
WHERE j.id = :id AND p.id = :preview_id AND j.status = 'running'
  AND j.lease_token = :lease_token AND j.lease_expires_at > :now
FOR UPDATE
SQL);
        $statement->execute([
            'id' => $job->id,
            'preview_id' => $job->previewId,
            'lease_token' => $job->leaseToken,
            'now' => self::databaseTime($now),
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new \RuntimeException('Preview job lease is no longer authoritative');
        }
        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function payload(array $row): array
    {
        $payload = json_decode((string) $row['payload_json'], true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($payload) || array_is_list($payload) || ($payload['formatVersion'] ?? null) !== 1) {
            throw new \RuntimeException('Preview job payload is invalid');
        }
        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private static function payloadPreviewId(array $payload): string
    {
        $previewId = $payload['previewId'] ?? null;
        if (!is_string($previewId)) {
            throw new \RuntimeException('Preview job does not identify a preview');
        }
        self::assertId($previewId);
        return $previewId;
    }

    /** @param array<string, mixed> $row */
    private static function hydrateStatus(array $row): PreviewStatus
    {
        $progress = isset($row['progress_json']) ? json_decode((string) $row['progress_json'], true, 16, JSON_THROW_ON_ERROR) : null;
        $manifest = $row['manifest_json'] === null
            ? null
            : json_decode((string) $row['manifest_json'], true, 8, JSON_THROW_ON_ERROR);
        if ($manifest !== null && (!is_array($manifest) || array_is_list($manifest))) {
            throw new \RuntimeException('Stored preview manifest is invalid');
        }
        return new PreviewStatus(
            (string) $row['id'],
            (string) $row['status'],
            (string) $row['job_id'],
            is_string($row['error_code'] ?? null) ? $row['error_code'] : null,
            $manifest,
            self::databaseDate((string) $row['created_at']),
            self::databaseDate((string) $row['updated_at']),
            (int) ($row['include_drafts'] ?? 0) === 1,
            (string) ($row['build_kind'] ?? 'website'),
            PreviewDiagnostics::fromDocument(is_array($progress) ? ($progress['diagnostics'] ?? null) : null),
            (int) ($row['include_preparation'] ?? 0) === 1,
        );
    }

    private static function encodeSnapshot(PreviewSnapshot $snapshot): string
    {
        return json_encode([
            'formatVersion' => 1,
            'includeDrafts' => $snapshot->includeDrafts,
            'holdingPage' => $snapshot->holdingPage?->document(),
            'websiteIdentity' => $snapshot->websiteIdentity?->document(),
            'designRegistries' => $snapshot->designRegistries?->document(),
            'profileId' => $snapshot->profileId,
            'profileVersion' => $snapshot->profileVersion,
            'profileChecksum' => $snapshot->profileChecksum,
            'publicUrlContext' => $snapshot->publicUrlContext?->document(),
            'publishedSlugHistory' => $snapshot->publishedSlugHistory?->document(),
            'locales' => $snapshot->locales,
            'defaultLocale' => $snapshot->defaultLocale,
            'preparationLocales' => $snapshot->preparationLocales,
            'wording' => $snapshot->wording,
            ...($snapshot->websiteMedia === [] ? [] : ['websiteMedia' => array_map(static fn(PreviewMedia $media): array => $media->document(), $snapshot->websiteMedia)]),
            'createdAt' => $snapshot->createdAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),
            'records' => array_map(static fn(PreviewRecord $record): array => [
                'id' => $record->id,
                'typeId' => $record->typeId,
                'revisionId' => $record->revisionId,
                'revisionNumber' => $record->revisionNumber,
                'workflowState' => $record->workflowState,
                'body' => $record->body,
                'relationships' => $record->relationships,
                'media' => array_map(static fn(PreviewMedia $media): array => [
                    'id' => $media->id,
                    'objectKey' => $media->objectKey,
                    'fileName' => $media->fileName,
                    'mime' => $media->mime,
                    'byteSize' => $media->byteSize,
                    'checksum' => $media->checksum,
                    'width' => $media->width,
                    'height' => $media->height,
                    'text' => ($media->text ?? \Utsuri\PortableCms\Conventional\Media\MediaText::from())->document(),
                    'focalPoint' => $media->focalPoint->document(),
                    'widths' => array_map(static fn(\Utsuri\PortableCms\Conventional\Media\RasterImageWidth $width): array => $width->document(), $media->widths),
                ], $record->media),
            ], $snapshot->records),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private static function decodeSnapshot(string $encoded): PreviewSnapshot
    {
        $value = json_decode($encoded, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($value) || array_is_list($value) || ($value['formatVersion'] ?? null) !== 1 || (array_key_exists('includeDrafts', $value) && !is_bool($value['includeDrafts']))) {
            throw new \RuntimeException('Stored preview snapshot is invalid');
        }
        $records = [];
        foreach (is_array($value['records'] ?? null) ? $value['records'] : [] as $record) {
            if (!is_array($record) || !is_array($record['body'] ?? null) || !is_array($record['relationships'] ?? null)) {
                throw new \RuntimeException('Stored preview record is invalid');
            }
            $assets = [];
            foreach (is_array($record['media'] ?? null) ? $record['media'] : [] as $media) {
                if (!is_array($media)) {
                    throw new \RuntimeException('Stored preview media is invalid');
                }
                $assets[] = new PreviewMedia(
                    (string) ($media['id'] ?? ''),
                    (string) ($media['objectKey'] ?? ''),
                    (string) ($media['fileName'] ?? ''),
                    (string) ($media['mime'] ?? ''),
                    (int) ($media['byteSize'] ?? 0),
                    (string) ($media['checksum'] ?? ''),
                    (int) ($media['width'] ?? 0),
                    (int) ($media['height'] ?? 0),
                    \Utsuri\PortableCms\Conventional\Media\MediaText::from($media['text'] ?? null),
                    \Utsuri\PortableCms\Conventional\Media\FocalPoint::from($media['focalPoint'] ?? null),
                    \Utsuri\PortableCms\Conventional\Media\RasterImageWidth::fromDocument($media['widths'] ?? []),
                );
            }
            $records[] = new PreviewRecord(
                (string) ($record['id'] ?? ''),
                (string) ($record['typeId'] ?? ''),
                (string) ($record['revisionId'] ?? ''),
                (int) ($record['revisionNumber'] ?? 0),
                $record['body'],
                $record['relationships'],
                $assets,
                is_string($record['workflowState'] ?? null) ? $record['workflowState'] : 'draft',
            );
        }
        if (!is_array($value['locales'] ?? null)) {
            throw new \RuntimeException('Stored preview locales are invalid');
        }
        return new PreviewSnapshot(
            (string) ($value['profileId'] ?? ''),
            (string) ($value['profileVersion'] ?? ''),
            $value['locales'],
            $records,
            new \DateTimeImmutable((string) ($value['createdAt'] ?? '')),
            isset($value['designRegistries']) ? \Utsuri\PortableCms\Conventional\Design\RegistryDocument::fromDocument($value['designRegistries']) : null,
            is_string($value['profileChecksum'] ?? null) ? $value['profileChecksum'] : null,
            isset($value['publicUrlContext']) ? \Utsuri\PortableCms\Conventional\Compiler\PublicUrlContext::from($value['publicUrlContext']) : null,
            isset($value['publishedSlugHistory']) ? \Utsuri\PortableCms\Conventional\Compiler\PublishedSlugHistory::from($value['publishedSlugHistory']) : null,
            ($value['includeDrafts'] ?? false) === true,
            isset($value['holdingPage']) ? \Utsuri\PortableCms\Conventional\Publication\WebsitePublication::from($value['holdingPage']) : null,
            is_string($value['defaultLocale'] ?? null) ? $value['defaultLocale'] : null,
            is_array($value['preparationLocales'] ?? null) ? $value['preparationLocales'] : [],
            is_array($value['wording'] ?? null) ? $value['wording'] : [],
            isset($value['websiteIdentity']) ? \Utsuri\PortableCms\Conventional\Content\WebsiteIdentity::from($value['websiteIdentity']) : null,
            array_map(static fn(array $media): PreviewMedia => PreviewMedia::fromDocument($media), $value['websiteMedia'] ?? []),
        );
    }

    /** @param array<string, int|string> $metadata */
    private function audit(string $id, string $actor, string $event, array $metadata, string $time): void
    {
        $statement = $this->pdo->prepare(<<<'SQL'
INSERT INTO cms_audit_events (id, actor_user_id, event_type, metadata_json, occurred_at)
VALUES (:id, :actor, :event, :metadata, :occurred_at)
SQL);
        $statement->execute([
            'id' => $id,
            'actor' => $actor,
            'event' => $event,
            'metadata' => json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'occurred_at' => $time,
        ]);
    }

    private static function assertId(string $id): void
    {
        if (preg_match('/\A[a-f0-9]{32}\z/', $id) !== 1) {
            throw new \InvalidArgumentException('Preview identity is invalid');
        }
    }

    private static function databaseTime(\DateTimeImmutable $time): string
    {
        return $time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private static function databaseDate(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable($time, new \DateTimeZone('UTC'));
    }
}
