<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Preview;

/** Projects existing check/queue audit facts; failed checks never create a job or snapshot. */
final readonly class MariaDbPreviewCheckHistory implements PreviewCheckHistory
{
    public function __construct(private \PDO $pdo) {}

    public function failed(string $profileId, string $actorUserId, string $auditId, PreviewCheck $check): void
    {
        if ($this->pdo->inTransaction() || $check->issues === []) {
            throw new \LogicException('A failed preview check must be recorded after enqueue rollback');
        }
        $document = json_encode(['profileId' => $profileId, ...$check->document()], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($document) > 262144) {
            throw new \RuntimeException('Preview check report exceeds its byte bound');
        }
        $statement = $this->pdo->prepare(<<<'SQL'
INSERT INTO cms_audit_events (id, actor_user_id, event_type, metadata_json, occurred_at)
VALUES (:id, :actor, 'preview_check_failed', :metadata, :occurred_at)
SQL);
        $statement->execute(['id' => $auditId, 'actor' => $actorUserId, 'metadata' => $document, 'occurred_at' => $check->checkedAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u')]);
    }

    public function latest(string $profileId): ?PreviewCheck
    {
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT event_type, metadata_json, occurred_at FROM cms_audit_events
WHERE event_type IN ('preview_check_failed', 'preview_queued')
  AND JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.profileId')) = :profile
  AND (event_type = 'preview_check_failed' OR JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.buildKind')) = 'website')
ORDER BY occurred_at DESC, id DESC LIMIT 1
SQL);
        $statement->execute(['profile' => $profileId]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        if (strlen((string) $row['metadata_json']) > 262144) {
            throw new \RuntimeException('Stored preview check exceeds its byte bound');
        }
        $document = json_decode((string) $row['metadata_json'], true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($document)) {
            throw new \RuntimeException('Stored preview check is invalid');
        }
        $at = new \DateTimeImmutable((string) $row['occurred_at'], new \DateTimeZone('UTC'));
        if ($row['event_type'] === 'preview_queued') {
            return new PreviewCheck($at, ($document['includeDrafts'] ?? 0) === 1, []);
        }
        return PreviewCheck::fromDocument($at, $document);
    }
}
