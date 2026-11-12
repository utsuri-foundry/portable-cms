<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Ai;

use PDO;
use Utsuri\PortableCms\Conventional\Content\ProfileManifest;

final class MariaDbAssistanceRepository implements AssistanceRepository
{
    public function __construct(private readonly PDO $pdo, private readonly ProfileManifest $profile) {}

    public function source(string $typeId, string $recordId, string $mediaId): array
    {
        $query = $this->pdo->prepare(<<<'SQL'
SELECT r.current_revision_number, rr.body_json, m.checksum, d.checksum AS derivative_checksum
FROM cms_records r JOIN cms_record_revisions rr ON rr.id = r.current_revision_id
JOIN cms_media m ON m.id = :media AND m.status = 'ready'
JOIN cms_media_derivatives d ON d.media_id = m.id AND d.variant = '960-webp' AND d.byte_size <= 4194304
WHERE r.id = :record AND r.type_id = :type
SQL);
        $query->execute(['media' => $mediaId, 'record' => $recordId, 'type' => $typeId]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new AssistanceFailure('source_unavailable');
        }
        $body = json_decode((string) $row['body_json'], true, 32, JSON_THROW_ON_ERROR);
        $selected = false;
        foreach ($this->profile->type($typeId)->fields as $field) {
            if ($field->type === 'media_references' && is_array($body[$field->id] ?? null)
                && in_array($mediaId, $body[$field->id], true)) {
                $selected = true;
            }
        }
        if (!$selected) {
            throw new AssistanceFailure('source_unavailable');
        }
        // Changes in any installed taxonomy namespace invalidate drafts, including new and deprecated terms.
        $taxonomy = [];
        foreach ($this->profile->types as $type) {
            if ($type->kind !== 'taxonomy') {
                continue;
            }
            $terms = $this->pdo->prepare('SELECT id, current_revision_number FROM cms_records WHERE type_id = :type ORDER BY id LIMIT 1001');
            $terms->execute(['type' => $type->id]);
            foreach ($terms->fetchAll(PDO::FETCH_ASSOC) as $term) {
                $taxonomy[] = [(string) $term['id'], (int) $term['current_revision_number']];
                if (count($taxonomy) > 1000) {
                    throw new AssistanceFailure('taxonomy_limit');
                }
            }
        }
        return ['typeId' => $typeId, 'recordId' => $recordId, 'mediaId' => $mediaId,
            'sourceRevision' => (int) $row['current_revision_number'], 'mediaChecksum' => (string) $row['checksum'],
            'derivativeChecksum' => (string) $row['derivative_checksum'], 'taxonomyRevision' => hash('sha256', json_encode($taxonomy, JSON_THROW_ON_ERROR))];
    }

    public function enqueue(string $id, string $clientId, string $actorId, AssistanceRequest $request, AssistancePolicy $policy, \DateTimeImmutable $now): AssistanceJob
    {
        $key = hash('sha256', $actorId . ':' . $clientId);
        $time = self::time($now);
        $json = json_encode($request->frozen, JSON_THROW_ON_ERROR);
        $this->pdo->beginTransaction();
        try {
            $day = $now->format('Y-m-d');
            $this->pdo->prepare('INSERT IGNORE INTO cms_ai_quota (quota_day) VALUES (:day)')->execute(['day' => $day]);
            $quota = $this->pdo->prepare('SELECT requests, reserved_micros FROM cms_ai_quota WHERE quota_day = :day FOR UPDATE');
            $quota->execute(['day' => $day]);
            $usage = $quota->fetch(PDO::FETCH_ASSOC);
            $existing = $this->pdo->prepare('SELECT * FROM cms_ai_jobs WHERE client_key = :key FOR UPDATE');
            $existing->execute(['key' => $key]);
            $row = $existing->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                if ($row['request_json'] !== $json) {
                    throw new AssistanceFailure('request_conflict');
                }
                $this->pdo->commit();
                return self::hydrate($row);
            }
            $reservation = 2 * $policy->attemptCostMicros;
            if (!is_array($usage) || (int) $usage['requests'] >= $policy->dailyRequests
                || (int) $usage['reserved_micros'] + $reservation > $policy->dailyBudgetMicros) {
                throw new AssistanceFailure('quota_exceeded');
            }
            $this->pdo->prepare('UPDATE cms_ai_quota SET requests = requests + 1, reserved_micros = reserved_micros + :cost WHERE quota_day = :day')->execute(['day' => $day, 'cost' => $reservation]);
            $insert = $this->pdo->prepare(<<<'SQL'
INSERT INTO cms_ai_jobs (id, client_key, actor_id, record_id, status, request_json, available_at, expires_at, created_at)
VALUES (:id, :key, :actor, :record, 'queued', :request, :available, :expiry, :created)
SQL);
            $insert->execute(['id' => $id, 'key' => $key, 'actor' => $actorId, 'record' => $request->frozen['recordId'],
                'request' => $json, 'available' => $time, 'expiry' => self::time($now->modify('+' . $policy->retentionHours . ' hours')), 'created' => $time]);
            $this->pdo->commit();
            return new AssistanceJob($id, 'queued', $request, 0, '', null, null);
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function find(string $id, string $actorId, string $recordId, \DateTimeImmutable $now): ?AssistanceJob
    {
        $this->purge($now);
        $query = $this->pdo->prepare('SELECT * FROM cms_ai_jobs WHERE id = :id AND actor_id = :actor AND record_id = :record');
        $query->execute(['id' => $id, 'actor' => $actorId, 'record' => $recordId]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? self::hydrate($row) : null;
    }

    public function latest(string $actorId, string $recordId, \DateTimeImmutable $now): ?AssistanceJob
    {
        $this->purge($now);
        $query = $this->pdo->prepare('SELECT * FROM cms_ai_jobs WHERE actor_id = :actor AND record_id = :record ORDER BY created_at DESC, id DESC LIMIT 1');
        $query->execute(['actor' => $actorId, 'record' => $recordId]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? self::hydrate($row) : null;
    }

    public function cancel(string $id, string $actorId, string $recordId, \DateTimeImmutable $now): void
    {
        $query = $this->pdo->prepare("UPDATE cms_ai_jobs SET status = 'cancelled', lease_token = NULL, lease_expires_at = NULL, candidate_json = NULL WHERE id = :id AND actor_id = :actor AND record_id = :record AND status IN ('queued','retry','running','completed')");
        $query->execute(['id' => $id, 'actor' => $actorId, 'record' => $recordId]);
    }

    public function claim(string $lease, \DateTimeImmutable $now): ?AssistanceJob
    {
        $this->purge($now);
        $this->pdo->beginTransaction();
        try {
            $day = $now->format('Y-m-d');
            $this->pdo->prepare('INSERT IGNORE INTO cms_ai_quota (quota_day) VALUES (:day)')->execute(['day' => $day]);
            $lock = $this->pdo->prepare('SELECT requests FROM cms_ai_quota WHERE quota_day = :day FOR UPDATE');
            $lock->execute(['day' => $day]);
            $active = $this->pdo->prepare("SELECT id FROM cms_ai_jobs WHERE status = 'running' AND lease_expires_at > :time LIMIT 1");
            $active->execute(['time' => self::time($now)]);
            if ($active->fetchColumn() !== false) {
                $this->pdo->commit();
                return null;
            }
            $query = $this->pdo->prepare(<<<'SQL'
UPDATE cms_ai_jobs SET status = 'running', attempts = attempts + 1, lease_token = :lease, lease_expires_at = :expiry, error_code = NULL
WHERE attempts < 2 AND expires_at > :retained AND (
    (status IN ('queued','retry') AND available_at <= :available) OR (status = 'running' AND lease_expires_at <= :expired)
) ORDER BY created_at, id LIMIT 1
SQL);
            $query->execute(['lease' => $lease, 'expiry' => self::time($now->modify('+2 minutes')),
                'retained' => self::time($now), 'available' => self::time($now), 'expired' => self::time($now)]);
            if ($query->rowCount() !== 1) {
                $this->pdo->commit();
                return null;
            }
            $select = $this->pdo->prepare('SELECT * FROM cms_ai_jobs WHERE lease_token = :lease');
            $select->execute(['lease' => $lease]);
            $row = $select->fetch(PDO::FETCH_ASSOC);
            $this->pdo->commit();
            return is_array($row) ? self::hydrate($row) : null;
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function finish(AssistanceJob $job, ?AssistanceCandidate $candidate, ?string $errorCode, bool $retryable, \DateTimeImmutable $now): void
    {
        $query = $this->pdo->prepare(<<<'SQL'
UPDATE cms_ai_jobs SET status = :status, candidate_json = :candidate, error_code = :error,
    available_at = :available, lease_token = NULL, lease_expires_at = NULL
WHERE id = :id AND status = 'running' AND lease_token = :lease AND lease_expires_at > :time AND expires_at > :retained
SQL);
        $query->execute(['status' => $candidate !== null ? 'completed' : ($retryable && $job->attempts < 2 ? 'retry' : 'failed'),
            'candidate' => $candidate === null ? null : json_encode($candidate->document(), JSON_THROW_ON_ERROR),
            'error' => $errorCode, 'available' => self::time($now->modify('+5 minutes')), 'id' => $job->id,
            'lease' => $job->lease, 'time' => self::time($now), 'retained' => self::time($now)]);
    }

    public function purge(\DateTimeImmutable $now): void
    {
        $query = $this->pdo->prepare("UPDATE cms_ai_jobs SET status = 'expired', request_json = NULL, candidate_json = NULL, error_code = NULL, lease_token = NULL, lease_expires_at = NULL WHERE expires_at <= :time AND status <> 'expired'");
        $query->execute(['time' => self::time($now)]);
        $query = $this->pdo->prepare("UPDATE cms_ai_jobs SET status = 'failed', error_code = 'attempts_exhausted', lease_token = NULL, lease_expires_at = NULL WHERE status = 'running' AND attempts >= 2 AND lease_expires_at <= :time");
        $query->execute(['time' => self::time($now)]);
        $this->pdo->prepare('DELETE FROM cms_ai_jobs WHERE expires_at < :time')->execute(['time' => self::time($now->modify('-30 days'))]);
        $this->pdo->prepare('DELETE FROM cms_ai_quota WHERE quota_day < :day')->execute(['day' => $now->modify('-90 days')->format('Y-m-d')]);
    }

    /** @param array<string, mixed> $row */
    private static function hydrate(array $row): AssistanceJob
    {
        $request = is_string($row['request_json']) ? new AssistanceRequest(json_decode($row['request_json'], true, 16, JSON_THROW_ON_ERROR)) : null;
        $candidate = is_string($row['candidate_json']) && $request !== null ? AssistanceCandidate::from(json_decode($row['candidate_json'], true, 16, JSON_THROW_ON_ERROR), $request->locales()) : null;
        return new AssistanceJob((string) $row['id'], (string) $row['status'], $request, (int) $row['attempts'], (string) ($row['lease_token'] ?? ''), $candidate, $row['error_code'] === null ? null : (string) $row['error_code']);
    }

    public function disablePending(): void
    {
        $this->pdo->exec("UPDATE cms_ai_jobs SET status = 'failed', error_code = 'unavailable', lease_token = NULL, lease_expires_at = NULL WHERE status IN ('queued','retry','running')");
    }

    private static function time(\DateTimeImmutable $now): string
    {
        return $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
