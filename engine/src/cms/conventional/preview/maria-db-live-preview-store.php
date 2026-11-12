<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Preview;

use PDO;

/** Token hashes only; a render dies with its expiry, its session's sign-out or its session's expiry. */
final class MariaDbLivePreviewStore implements LivePreviewStore
{
    /** Renders kept per session and record, so the visible page's late requests still resolve. */
    private const KEEP = 3;

    public function __construct(private readonly PDO $pdo)
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }

    public function owns(string $tokenHash, string $sessionId, string $recordKey, \DateTimeImmutable $now): bool
    {
        $time = self::databaseTime($now);
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT 1 FROM cms_live_previews WHERE token_hash = :token_hash AND session_id = :session_id AND record_key = :record_key AND expires_at > :now LIMIT 1
SQL);
        $statement->execute(['token_hash' => $tokenHash, 'session_id' => $sessionId, 'record_key' => $recordKey, 'now' => $time]);
        return $statement->fetchColumn() !== false;
    }

    public function save(string $tokenHash, string $renderId, string $sessionId, string $recordKey, LivePreviewDocument $document, \DateTimeImmutable $now, \DateTimeImmutable $expiresAt): void
    {
        if (preg_match('/\A[a-f0-9]{64}\z/', $tokenHash) !== 1 || preg_match('/\A[a-f0-9]{32}\z/', $renderId) !== 1 || preg_match('/\A[a-f0-9]{32}\z/', $sessionId) !== 1
            || preg_match('/\A[a-z][a-z0-9_-]{0,63}:(?:[a-f0-9]{32}|new)\z/', $recordKey) !== 1 || $expiresAt <= $now) {
            throw new \InvalidArgumentException('Live preview identity is invalid');
        }
        $time = self::databaseTime($now);
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(<<<'SQL'
DELETE l FROM cms_live_previews l LEFT JOIN cms_sessions s ON s.id = l.session_id
WHERE l.expires_at <= :now OR s.id IS NULL OR s.revoked_at IS NOT NULL OR s.expires_at <= :session_now
SQL)->execute(['now' => $time, 'session_now' => $time]);
            $this->pdo->prepare(<<<'SQL'
INSERT INTO cms_live_previews (token_hash, render_id, session_id, record_key, document_json, created_at, expires_at)
VALUES (:token_hash, :render_id, :session_id, :record_key, :document_json, :created_at, :expires_at)
SQL)->execute([
                'token_hash' => $tokenHash,
                'render_id' => $renderId,
                'session_id' => $sessionId,
                'record_key' => $recordKey,
                'document_json' => json_encode($document->document(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'created_at' => $time,
                'expires_at' => self::databaseTime($expiresAt),
            ]);
            $older = $this->pdo->prepare(<<<'SQL'
SELECT token_hash, render_id FROM cms_live_previews WHERE session_id = :session_id AND record_key = :record_key
ORDER BY created_at DESC, render_id ASC
SQL);
            $older->execute(['session_id' => $sessionId, 'record_key' => $recordKey]);
            $delete = $this->pdo->prepare('DELETE FROM cms_live_previews WHERE token_hash = :token_hash AND render_id = :render_id');
            foreach (array_slice($older->fetchAll(PDO::FETCH_ASSOC), self::KEEP) as $stale) {
                $delete->execute(['token_hash' => $stale['token_hash'], 'render_id' => $stale['render_id']]);
            }
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function find(string $tokenHash, ?string $renderId, \DateTimeImmutable $now): ?LivePreviewDocument
    {
        if (preg_match('/\A[a-f0-9]{64}\z/', $tokenHash) !== 1 || ($renderId !== null && preg_match('/\A[a-f0-9]{32}\z/', $renderId) !== 1)) {
            return null;
        }
        $time = self::databaseTime($now);
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT l.document_json FROM cms_live_previews l
JOIN cms_sessions s ON s.id = l.session_id AND s.kind = 'authenticated' AND s.revoked_at IS NULL
    AND s.expires_at > :session_now AND s.absolute_expires_at > :absolute_now
WHERE l.token_hash = :token_hash AND l.expires_at > :now AND (:any_render = 1 OR l.render_id = :render_id)
ORDER BY l.created_at DESC, l.render_id ASC LIMIT 1
SQL);
        $statement->execute(['token_hash' => $tokenHash, 'now' => $time, 'session_now' => $time, 'absolute_now' => $time, 'any_render' => $renderId === null ? 1 : 0, 'render_id' => $renderId ?? '']);
        $json = $statement->fetchColumn();
        if (!is_string($json)) {
            return null;
        }
        $document = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        return is_array($document) ? LivePreviewDocument::fromDocument($document) : null;
    }

    private static function databaseTime(\DateTimeImmutable $time): string
    {
        return $time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
