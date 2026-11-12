<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Media;

use PDO;
use Throwable;

final class MariaDbMediaJobRepository implements MediaJobRepository
{
    public function __construct(private readonly PDO $pdo, private readonly bool $prepareWidths = false)
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }

    public function claim(
        string $workerId,
        string $leaseToken,
        \DateTimeImmutable $now,
        \DateTimeImmutable $leaseExpiresAt,
    ): ?MediaJob {
        if (
            preg_match('/\A[a-zA-Z0-9._:-]{1,100}\z/', $workerId) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/', $leaseToken) !== 1
        ) {
            throw new \InvalidArgumentException('Worker lease identity is invalid');
        }
        $time = self::databaseTime($now);
        if ($this->prepareWidths) {
            (new MariaDbRasterWidthQueue($this->pdo))->enqueue($now);
        }
        $this->pdo->beginTransaction();
        try {
            $claim = $this->pdo->prepare(<<<'SQL'
UPDATE cms_jobs
SET status = 'running', attempts = attempts + 1,
    lease_owner = :lease_owner, lease_token = :lease_token,
    lease_expires_at = :lease_expires_at,
    progress_json = :progress, error_code = NULL, updated_at = :updated_at
WHERE job_type IN ('media_derivatives', 'media_image_width')
  AND attempts < max_attempts
  AND (
      (status IN ('queued', 'retry') AND available_at <= :available_at)
      OR (status = 'running' AND lease_expires_at IS NOT NULL AND lease_expires_at <= :expired_at)
  )
ORDER BY (job_type = 'media_image_width') ASC,
         available_at ASC, created_at ASC, id ASC
LIMIT 1
SQL);
            $claim->execute([
                'lease_owner' => $workerId,
                'lease_token' => $leaseToken,
                'lease_expires_at' => self::databaseTime($leaseExpiresAt),
                'progress' => json_encode(['phase' => 'processing'], JSON_THROW_ON_ERROR),
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
                throw new \RuntimeException('Claimed media job disappeared');
            }
            $job = self::hydrateJob($row);
            $mediaId = self::payloadMediaId($job->payload);
            if (isset($job->payload['maximumWidth'])) {
                $this->pdo->commit();
                return $job;
            }
            $media = $this->pdo->prepare(<<<'SQL'
UPDATE cms_media
SET status = 'processing', error_code = NULL, updated_at = :updated_at
WHERE id = :id AND status IN ('uploaded', 'processing')
SQL);
            $media->execute(['updated_at' => $time, 'id' => $mediaId]);
            if ($media->rowCount() !== 1) {
                throw new MediaProcessingFailure('media_not_processable', false, 'The queued media item is unavailable.');
            }
            $this->pdo->commit();
            return $job;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function source(MediaJob $job): MediaSource
    {
        $mediaId = self::payloadMediaId($job->payload);
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT id, owner_record_id, created_by, declared_type, byte_size, checksum, original_key, status
FROM cms_media
WHERE id = :id
SQL);
        $statement->execute(['id' => $mediaId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new MediaProcessingFailure('source_missing', false, 'The queued media source is unavailable.');
        }
        if (isset($job->payload['maximumWidth']) && $row['status'] !== 'ready') {
            throw new MediaProcessingFailure('media_not_processable', false, 'The queued media item is unavailable.');
        }
        if (
            (int) ($job->payload['expectedByteSize'] ?? 0) !== (int) $row['byte_size']
            || ($job->payload['declaredType'] ?? null) !== $row['declared_type']
        ) {
            throw new MediaProcessingFailure('job_source_mismatch', false, 'The job no longer matches its media source.');
        }
        return new MediaSource(
            (string) $row['id'],
            $row['owner_record_id'] === null ? null : (string) $row['owner_record_id'],
            (string) $row['created_by'],
            (string) $row['declared_type'],
            (int) $row['byte_size'],
            (string) $row['checksum'],
            (string) $row['original_key'],
            self::maximumWidth($job),
        );
    }

    public function complete(
        MediaJob $job,
        ProcessedMedia $processed,
        string $auditId,
        \DateTimeImmutable $now,
    ): void {
        $time = self::databaseTime($now);
        $this->pdo->beginTransaction();
        try {
            $row = $this->lockedLease($job, $now);
            $mediaId = self::payloadMediaId(self::payload($row));
            if (isset($job->payload['maximumWidth'])) {
                $ready = $this->pdo->prepare("SELECT id FROM cms_media WHERE id = :id AND status = 'ready' FOR UPDATE");
                $ready->execute(['id' => $mediaId]);
                if ($ready->fetchColumn() === false) {
                    throw new MediaProcessingFailure('media_not_processable', false, 'The queued media item is unavailable.');
                }
            }
            if ($processed->derivative->mediaId !== $mediaId || (isset($job->payload['maximumWidth']) && $processed->derivative->variant !== RasterWidths::variant(self::maximumWidth($job)))) {
                throw new MediaProcessingFailure('derivative_media_mismatch', false, 'The derivative belongs to different media.');
            }
            $existing = $this->pdo->prepare(<<<'SQL'
SELECT object_key, byte_size, checksum FROM cms_media_derivatives
WHERE media_id = :media_id AND variant = :variant
FOR UPDATE
SQL);
            $existing->execute(['media_id' => $mediaId, 'variant' => $processed->derivative->variant]);
            $existingRow = $existing->fetch(PDO::FETCH_ASSOC);
            if (is_array($existingRow)) {
                if (
                    $existingRow['object_key'] !== $processed->derivative->objectKey
                    || (int) $existingRow['byte_size'] !== $processed->derivative->byteSize
                    || !hash_equals((string) $existingRow['checksum'], $processed->derivative->checksum)
                ) {
                    throw new MediaProcessingFailure(
                        'immutable_derivative_conflict',
                        false,
                        'Stored derivative metadata conflicts with immutable bytes.',
                    );
                }
            } else {
                $insert = $this->pdo->prepare(<<<'SQL'
INSERT INTO cms_media_derivatives (
    media_id, variant, object_key, filename, mime, width, height, byte_size,
    checksum, processor_id, processor_version, recipe_version, parameters_json, created_at
) VALUES (
    :media_id, :variant, :object_key, :filename, :mime, :width, :height, :byte_size,
    :checksum, :processor_id, :processor_version, :recipe_version, :parameters_json, :created_at
)
SQL);
                $insert->execute([
                    'media_id' => $mediaId,
                    'variant' => $processed->derivative->variant,
                    'object_key' => $processed->derivative->objectKey,
                    'filename' => $processed->derivative->filename,
                    'mime' => $processed->derivative->mime,
                    'width' => $processed->derivative->width,
                    'height' => $processed->derivative->height,
                    'byte_size' => $processed->derivative->byteSize,
                    'checksum' => $processed->derivative->checksum,
                    'processor_id' => $processed->derivative->processorId,
                    'processor_version' => $processed->derivative->processorVersion,
                    'recipe_version' => $processed->derivative->recipeVersion,
                    'parameters_json' => json_encode(
                        $processed->derivative->parameters,
                        JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                    ),
                    'created_at' => $time,
                ]);
            }
            if (!isset($job->payload['maximumWidth'])) {
                $media = $this->pdo->prepare(<<<'SQL'
UPDATE cms_media
SET status = 'ready', detected_type = :detected_type, width = :width, height = :height,
    error_code = NULL, updated_at = :updated_at
WHERE id = :id AND status = 'processing'
SQL);
                $media->execute([
                    'detected_type' => $processed->detectedType,
                    'width' => $processed->width,
                    'height' => $processed->height,
                    'updated_at' => $time,
                    'id' => $mediaId,
                ]);
                if ($media->rowCount() !== 1) {
                    throw new \RuntimeException('Media completion compare-and-set failed');
                }
            }
            $jobUpdate = $this->pdo->prepare(<<<'SQL'
UPDATE cms_jobs
SET status = 'completed', result_json = :result, progress_json = :progress,
    lease_owner = NULL, lease_token = NULL, lease_expires_at = NULL,
    error_code = NULL, updated_at = :updated_at
WHERE id = :id AND status = 'running' AND lease_token = :lease_token
SQL);
            $jobUpdate->execute([
                'result' => json_encode([
                    'formatVersion' => 1,
                    'mediaId' => $mediaId,
                    'derivativeCount' => 1,
                    'recipeVersion' => $processed->derivative->recipeVersion,
                ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'progress' => json_encode(['phase' => 'completed'], JSON_THROW_ON_ERROR),
                'updated_at' => $time,
                'id' => $job->id,
                'lease_token' => $job->leaseToken,
            ]);
            if ($jobUpdate->rowCount() !== 1) {
                throw new \RuntimeException('Media job completion compare-and-set failed');
            }
            $actor = $this->pdo->prepare('SELECT created_by FROM cms_media WHERE id = :id');
            $actor->execute(['id' => $mediaId]);
            $actorId = $actor->fetchColumn();
            if (!is_string($actorId)) {
                throw new \RuntimeException('Media actor is unavailable');
            }
            $audit = $this->pdo->prepare(<<<'SQL'
INSERT INTO cms_audit_events (id, actor_user_id, event_type, metadata_json, occurred_at)
VALUES (:id, :actor_id, 'media_derivatives_completed', :metadata, :occurred_at)
SQL);
            $audit->execute([
                'id' => $auditId,
                'actor_id' => $actorId,
                'metadata' => json_encode([
                    'mediaId' => $mediaId,
                    'jobId' => $job->id,
                    'variant' => $processed->derivative->variant,
                    'recipeVersion' => $processed->derivative->recipeVersion,
                ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'occurred_at' => $time,
            ]);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function fail(
        MediaJob $job,
        string $errorCode,
        bool $retryable,
        \DateTimeImmutable $retryAt,
        \DateTimeImmutable $now,
    ): void {
        if (preg_match('/\A[a-z][a-z0-9_]{0,79}\z/', $errorCode) !== 1) {
            $errorCode = 'processing_failed';
        }
        $time = self::databaseTime($now);
        $this->pdo->beginTransaction();
        try {
            $row = $this->lockedLease($job, $now);
            $mediaId = self::payloadMediaId(self::payload($row));
            $shouldRetry = $retryable && (int) $row['attempts'] < (int) $row['max_attempts'];
            $status = $shouldRetry ? 'retry' : 'needs_attention';
            $update = $this->pdo->prepare(<<<'SQL'
UPDATE cms_jobs
SET status = :status, available_at = :available_at,
    lease_owner = NULL, lease_token = NULL, lease_expires_at = NULL,
    progress_json = :progress, error_code = :error_code, updated_at = :updated_at
WHERE id = :id AND status = 'running' AND lease_token = :lease_token
SQL);
            $update->execute([
                'status' => $status,
                'available_at' => self::databaseTime($shouldRetry ? $retryAt : $now),
                'progress' => json_encode([
                    'phase' => $shouldRetry ? 'retry_wait' : 'failed',
                    'errorCode' => $errorCode,
                ], JSON_THROW_ON_ERROR),
                'error_code' => $errorCode,
                'updated_at' => $time,
                'id' => $job->id,
                'lease_token' => $job->leaseToken,
            ]);
            if ($update->rowCount() !== 1) {
                throw new \RuntimeException('Media job failure compare-and-set failed');
            }
            if (!isset($job->payload['maximumWidth'])) {
                $media = $this->pdo->prepare(<<<'SQL'
UPDATE cms_media SET status = :status, error_code = :error_code, updated_at = :updated_at
WHERE id = :id AND status = 'processing'
SQL);
                $media->execute([
                    'status' => $shouldRetry ? 'uploaded' : 'needs_attention',
                    'error_code' => $errorCode,
                    'updated_at' => $time,
                    'id' => $mediaId,
                ]);
            }
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function publicStatus(string $jobId): ?array
    {
        if (preg_match('/\A[a-f0-9]{32}\z/', $jobId) !== 1) {
            return null;
        }
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT id, job_type, status, payload_json, attempts, max_attempts, error_code, updated_at
FROM cms_jobs WHERE id = :id AND job_type IN ('media_derivatives', 'media_image_width')
SQL);
        $statement->execute(['id' => $jobId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        return $this->publicStatusFromRow($row);
    }

    public function latestPublicStatus(): ?array
    {
        $statement = $this->pdo->query(<<<'SQL'
SELECT id, job_type, status, payload_json, attempts, max_attempts, error_code, updated_at
FROM cms_jobs
WHERE job_type IN ('media_derivatives', 'media_image_width')
ORDER BY created_at DESC, id DESC
LIMIT 1
SQL);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $this->publicStatusFromRow($row) : null;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, int|string|null>
     */
    private function publicStatusFromRow(array $row): array
    {
        $mediaId = self::payloadMediaId(self::payload($row));
        $media = $this->pdo->prepare('SELECT status FROM cms_media WHERE id = :id');
        $media->execute(['id' => $mediaId]);
        $mediaStatus = $media->fetchColumn();
        return [
            'formatVersion' => 1,
            'id' => (string) $row['id'],
            'type' => (string) $row['job_type'],
            'status' => (string) $row['status'],
            'attempts' => (int) $row['attempts'],
            'maxAttempts' => (int) $row['max_attempts'],
            'errorCode' => is_string($row['error_code'] ?? null) ? $row['error_code'] : null,
            'mediaId' => $mediaId,
            'mediaStatus' => is_string($mediaStatus) ? $mediaStatus : null,
            'updatedAt' => self::hydrateTime((string) $row['updated_at'])->format('Y-m-d\TH:i:s.u\Z'),
        ];
    }

    /** @return array<string, mixed> */
    private function lockedLease(MediaJob $job, \DateTimeImmutable $now): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM cms_jobs WHERE id = :id FOR UPDATE');
        $statement->execute(['id' => $job->id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (
            !is_array($row)
            || !in_array($row['job_type'], ['media_derivatives', 'media_image_width'], true)
            || $row['status'] !== 'running'
            || !is_string($row['lease_token'] ?? null)
            || !hash_equals($row['lease_token'], $job->leaseToken)
            || !is_string($row['lease_expires_at'] ?? null)
            || self::hydrateTime($row['lease_expires_at']) <= $now
        ) {
            throw new MediaProcessingFailure('job_lease_lost', true, 'The media job lease is no longer authoritative.');
        }
        return $row;
    }

    /** @param array<string, mixed> $row */
    private static function hydrateJob(array $row): MediaJob
    {
        $leaseToken = $row['lease_token'] ?? null;
        $leaseExpiresAt = $row['lease_expires_at'] ?? null;
        if (!is_string($leaseToken) || !is_string($leaseExpiresAt)) {
            throw new \RuntimeException('Claimed media job has no lease');
        }
        return new MediaJob(
            (string) $row['id'],
            (string) $row['status'],
            self::payload($row),
            (int) $row['attempts'],
            (int) $row['max_attempts'],
            $leaseToken,
            self::hydrateTime($leaseExpiresAt),
        );
    }

    private static function maximumWidth(MediaJob $job): int
    {
        $width = $job->payload['maximumWidth'] ?? 960;
        if (!is_int($width) || !in_array($width, RasterWidths::WIDTHS, true)) {
            throw new MediaProcessingFailure('invalid_derivative_recipe', false, 'The derivative recipe is unavailable.');
        }
        return $width;
    }

    /** @param array<string, mixed> $row
     *  @return array<string, mixed>
     */
    private static function payload(array $row): array
    {
        try {
            $payload = json_decode((string) ($row['payload_json'] ?? ''), true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable $error) {
            throw new MediaProcessingFailure('invalid_job_payload', false, 'The media job payload is invalid.', $error);
        }
        if (!is_array($payload) || ($payload['formatVersion'] ?? null) !== 1) {
            throw new MediaProcessingFailure('invalid_job_payload', false, 'The media job payload is invalid.');
        }
        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private static function payloadMediaId(array $payload): string
    {
        $mediaId = $payload['mediaId'] ?? null;
        if (!is_string($mediaId) || preg_match('/\A[a-f0-9]{32}\z/', $mediaId) !== 1) {
            throw new MediaProcessingFailure('invalid_job_payload', false, 'The media job does not identify valid media.');
        }
        return $mediaId;
    }

    private static function databaseTime(\DateTimeImmutable $time): string
    {
        return $time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private static function hydrateTime(string $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
    }
}
