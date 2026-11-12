<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Media;

use PDO;
use Throwable;

final class MariaDbUploadRepository implements UploadRepository
{
    public function __construct(private readonly PDO $pdo)
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }

    public function ownerRecord(string $recordId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT type_id, current_revision_number FROM cms_records WHERE id = :id',
        );
        $statement->execute(['id' => $recordId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? [
            'typeId' => (string) $row['type_id'],
            'revisionNumber' => (int) $row['current_revision_number'],
        ] : null;
    }

    public function findByClientId(?string $recordId, string $actorUserId, string $clientUploadId): ?UploadSession
    {
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT id FROM cms_media_upload_sessions
WHERE owner_record_id <=> :record_id
  AND actor_user_id = :actor_id
  AND client_upload_id = :client_upload_id
  AND active_client_key IS NOT NULL
  AND status IN ('uploading', 'completing', 'completed')
ORDER BY created_at DESC, id DESC
LIMIT 1
SQL);
        $statement->execute([
            'record_id' => $recordId,
            'actor_id' => $actorUserId,
            'client_upload_id' => $clientUploadId,
        ]);
        $id = $statement->fetchColumn();
        return is_string($id) ? $this->find($id) : null;
    }

    public function create(
        UploadSession $session,
        string $activeClientKey,
        \DateTimeImmutable $now,
    ): UploadSession {
        $time = self::databaseTime($now);
        $statement = $this->pdo->prepare(<<<'SQL'
INSERT INTO cms_media_upload_sessions (
    id, client_upload_id, active_client_key, owner_record_id, actor_user_id,
    status, object_key, original_name, declared_type, byte_size, part_size,
    part_count, initial_record_revision, expires_at, created_at, updated_at
) VALUES (
    :id, :client_upload_id, :active_client_key, :owner_record_id, :actor_user_id,
    'uploading', :object_key, :original_name, :declared_type, :byte_size, :part_size,
    :part_count, :initial_record_revision, :expires_at, :created_at, :updated_at
)
SQL);
        $statement->bindValue(':active_client_key', $activeClientKey, PDO::PARAM_LOB);
        foreach ([
            'id' => $session->id,
            'client_upload_id' => $session->clientUploadId,
            'owner_record_id' => $session->ownerRecordId,
            'actor_user_id' => $session->actorUserId,
            'object_key' => $session->objectKey,
            'original_name' => $session->originalName,
            'declared_type' => $session->declaredType,
            'byte_size' => $session->byteSize,
            'part_size' => $session->partSize,
            'part_count' => $session->partCount,
            'initial_record_revision' => $session->initialRecordRevision,
            'expires_at' => self::databaseTime($session->expiresAt),
            'created_at' => $time,
            'updated_at' => $time,
        ] as $key => $value) {
            $statement->bindValue(':' . $key, $value, $value === null ? PDO::PARAM_NULL : (is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR));
        }
        $statement->execute();
        return $this->find($session->id) ?? throw new \RuntimeException('Upload session was not stored');
    }

    public function find(string $sessionId): ?UploadSession
    {
        $statement = $this->pdo->prepare('SELECT * FROM cms_media_upload_sessions WHERE id = :id');
        $statement->execute(['id' => $sessionId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $parts = $this->pdo->prepare(<<<'SQL'
SELECT part_number, byte_size, checksum
FROM cms_media_upload_parts
WHERE session_id = :session_id
ORDER BY part_number ASC
SQL);
        $parts->execute(['session_id' => $sessionId]);
        return new UploadSession(
            (string) $row['id'],
            (string) $row['client_upload_id'],
            $row['owner_record_id'] === null ? null : (string) $row['owner_record_id'],
            (string) $row['actor_user_id'],
            (string) $row['status'],
            (string) $row['object_key'],
            (string) $row['original_name'],
            (string) $row['declared_type'],
            (int) $row['byte_size'],
            (int) $row['part_size'],
            (int) $row['part_count'],
            $row['initial_record_revision'] === null ? null : (int) $row['initial_record_revision'],
            is_string($row['media_id'] ?? null) ? $row['media_id'] : null,
            is_string($row['job_id'] ?? null) ? $row['job_id'] : null,
            self::hydrateTime((string) $row['expires_at']),
            array_map(
                static fn(array $part): UploadPart => new UploadPart(
                    (int) $part['part_number'],
                    (int) $part['byte_size'],
                    (string) $part['checksum'],
                ),
                $parts->fetchAll(PDO::FETCH_ASSOC),
            ),
        );
    }

    public function recordPart(string $sessionId, UploadPart $part, \DateTimeImmutable $now): UploadSession
    {
        $time = self::databaseTime($now);
        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->prepare(
                'SELECT status FROM cms_media_upload_sessions WHERE id = :id FOR UPDATE',
            );
            $lock->execute(['id' => $sessionId]);
            if ($lock->fetchColumn() !== 'uploading') {
                throw new UploadFailure('upload_not_writable', 409, 'upload.error.the_upload_no_longer_accepts_parts');
            }
            $statement = $this->pdo->prepare(<<<'SQL'
INSERT INTO cms_media_upload_parts (session_id, part_number, byte_size, checksum, updated_at)
VALUES (:session_id, :part_number, :byte_size, :checksum, :updated_at)
ON DUPLICATE KEY UPDATE
    byte_size = VALUES(byte_size), checksum = VALUES(checksum), updated_at = VALUES(updated_at)
SQL);
            $statement->execute([
                'session_id' => $sessionId,
                'part_number' => $part->partNumber,
                'byte_size' => $part->byteSize,
                'checksum' => $part->checksum,
                'updated_at' => $time,
            ]);
            $touch = $this->pdo->prepare(
                'UPDATE cms_media_upload_sessions SET updated_at = :time WHERE id = :id',
            );
            $touch->execute(['time' => $time, 'id' => $sessionId]);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        return $this->find($sessionId) ?? throw new \RuntimeException('Upload session disappeared');
    }

    public function transition(
        string $sessionId,
        array $fromStatuses,
        string $status,
        \DateTimeImmutable $now,
        bool $releaseClientKey = false,
    ): UploadSession {
        if ($fromStatuses === []) {
            throw new \InvalidArgumentException('A source upload state is required');
        }
        $placeholders = implode(', ', array_fill(0, count($fromStatuses), '?'));
        $statement = $this->pdo->prepare(
            'UPDATE cms_media_upload_sessions SET status = ?, updated_at = ?, active_client_key = '
            . ($releaseClientKey ? 'NULL' : 'active_client_key')
            . " WHERE id = ? AND status IN ({$placeholders})",
        );
        $statement->execute([
            $status,
            self::databaseTime($now),
            $sessionId,
            ...$fromStatuses,
        ]);
        $current = $this->find($sessionId);
        if ($current === null) {
            throw new UploadFailure('upload_not_found', 404, 'upload.error.the_upload_session_was_not_found');
        }
        if ($statement->rowCount() !== 1 && $current->status !== $status) {
            throw new UploadFailure('upload_state_conflict', 409, 'upload.error.the_upload_changed_before_this_operation');
        }
        return $current;
    }

    public function complete(
        string $sessionId,
        string $mediaId,
        string $jobId,
        StoredOriginal $original,
        string $auditId,
        \DateTimeImmutable $now,
    ): array {
        $time = self::databaseTime($now);
        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->prepare('SELECT * FROM cms_media_upload_sessions WHERE id = :id FOR UPDATE');
            $lock->execute(['id' => $sessionId]);
            $row = $lock->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                throw new UploadFailure('upload_not_found', 404, 'upload.error.the_upload_session_was_not_found');
            }
            if ($row['status'] === 'completed') {
                $this->pdo->commit();
                return $this->completedValue($sessionId, (string) $row['media_id'], (string) $row['job_id']);
            }
            if ($row['status'] !== 'completing') {
                throw new UploadFailure('upload_state_conflict', 409, 'upload.error.the_upload_is_not_ready_to_complete');
            }
            $media = $this->pdo->prepare(<<<'SQL'
INSERT INTO cms_media (
    id, owner_record_id, status, original_name, declared_type, byte_size,
    checksum, original_key, created_by, created_at, updated_at
) VALUES (
    :id, :owner_record_id, 'uploaded', :original_name, :declared_type, :byte_size,
    :checksum, :original_key, :created_by, :created_at, :updated_at
)
SQL);
            $media->execute([
                'id' => $mediaId,
                'owner_record_id' => $row['owner_record_id'],
                'original_name' => $row['original_name'],
                'declared_type' => $row['declared_type'],
                'byte_size' => $original->byteSize,
                'checksum' => $original->checksum,
                'original_key' => $original->key,
                'created_by' => $row['actor_user_id'],
                'created_at' => $time,
                'updated_at' => $time,
            ]);
            $payload = json_encode([
                'formatVersion' => 1,
                'ownerRecordId' => $row['owner_record_id'],
                'mediaId' => $mediaId,
                'expectedByteSize' => $original->byteSize,
                'declaredType' => $row['declared_type'],
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $job = $this->pdo->prepare(<<<'SQL'
INSERT INTO cms_jobs (
    id, job_type, status, payload_json, available_at, created_at, updated_at
) VALUES (
    :id, 'media_derivatives', 'queued', :payload, :available_at, :created_at, :updated_at
)
SQL);
            $job->execute([
                'id' => $jobId,
                'payload' => $payload,
                'available_at' => $time,
                'created_at' => $time,
                'updated_at' => $time,
            ]);
            $update = $this->pdo->prepare(<<<'SQL'
UPDATE cms_media_upload_sessions
SET status = 'completed', media_id = :media_id, job_id = :job_id, updated_at = :updated_at
WHERE id = :id AND status = 'completing' AND media_id IS NULL AND job_id IS NULL
SQL);
            $update->execute([
                'media_id' => $mediaId,
                'job_id' => $jobId,
                'updated_at' => $time,
                'id' => $sessionId,
            ]);
            if ($update->rowCount() !== 1) {
                throw new \RuntimeException('Upload completion compare-and-set failed');
            }
            $audit = $this->pdo->prepare(<<<'SQL'
INSERT INTO cms_audit_events (id, actor_user_id, event_type, metadata_json, occurred_at)
VALUES (:id, :actor_id, 'media_upload_completed', :metadata, :occurred_at)
SQL);
            $audit->execute([
                'id' => $auditId,
                'actor_id' => $row['actor_user_id'],
                'metadata' => json_encode([
                    'recordId' => $row['owner_record_id'],
                    'mediaId' => $mediaId,
                    'jobId' => $jobId,
                    'byteSize' => $original->byteSize,
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
        return $this->completedValue($sessionId, $mediaId, $jobId);
    }

    public function completed(string $sessionId): array
    {
        $session = $this->find($sessionId);
        if ($session === null) {
            throw new UploadFailure('upload_not_found', 404, 'upload.error.the_upload_session_was_not_found');
        }
        if ($session->status !== 'completed' || $session->mediaId === null || $session->jobId === null) {
            throw new UploadFailure('upload_state_conflict', 409, 'upload.error.the_upload_is_not_complete');
        }
        return $this->completedValue($sessionId, $session->mediaId, $session->jobId);
    }

    /** @return array{session: UploadSession, media: array<string, mixed>, job: array<string, mixed>} */
    private function completedValue(string $sessionId, string $mediaId, string $jobId): array
    {
        $session = $this->find($sessionId) ?? throw new \RuntimeException('Completed upload disappeared');
        $media = $this->pdo->prepare('SELECT * FROM cms_media WHERE id = :id');
        $media->execute(['id' => $mediaId]);
        $mediaRow = $media->fetch(PDO::FETCH_ASSOC);
        $job = $this->pdo->prepare('SELECT id, job_type, status, attempts, max_attempts, available_at FROM cms_jobs WHERE id = :id');
        $job->execute(['id' => $jobId]);
        $jobRow = $job->fetch(PDO::FETCH_ASSOC);
        if (!is_array($mediaRow) || !is_array($jobRow)) {
            throw new \RuntimeException('Completed upload records are incomplete');
        }
        return ['session' => $session, 'media' => $mediaRow, 'job' => $jobRow];
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
