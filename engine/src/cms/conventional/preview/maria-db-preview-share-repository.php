<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Preview;

use PDO;
use Utsuri\PortableCms\Conventional\Identity\MariaDbIdentityRepository;

final class MariaDbPreviewShareRepository implements PreviewShareRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function create(PreviewShare $share, string $tokenHash, string $actor, string $auditId): void
    {
        $this->transaction(function () use ($share, $tokenHash, $actor, $auditId): void {
            $this->publisher($actor);
            $preview = $this->pdo->prepare('SELECT status FROM cms_previews WHERE id = :id FOR UPDATE');
            $preview->execute(['id' => $share->previewId]);
            if ($preview->fetchColumn() !== 'ready' || count($this->active($share->previewId, $share->createdAt)) >= 50) {
                throw new ShareUnavailable();
            }
            $insert = $this->pdo->prepare(<<<'SQL'
INSERT INTO cms_preview_shares (id, preview_id, token_hash, created_by, created_at, expires_at)
VALUES (:id, :preview, :hash, :actor, :created, :expires)
SQL);
            $insert->execute(['id' => $share->id, 'preview' => $share->previewId, 'hash' => $tokenHash, 'actor' => $actor,
                'created' => self::time($share->createdAt), 'expires' => self::time($share->expiresAt)]);
            $this->audit($auditId, $actor, 'preview.share.created', $share, $share->createdAt);
        });
    }

    public function active(string $previewId, \DateTimeImmutable $now): array
    {
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT id, preview_id, created_at, expires_at FROM cms_preview_shares
WHERE preview_id = :preview AND revoked_at IS NULL AND expires_at > :now
ORDER BY created_at DESC, id DESC LIMIT 50
SQL);
        $statement->execute(['preview' => $previewId, 'now' => self::time($now)]);
        return array_map(self::hydrate(...), $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function revoke(string $previewId, string $id, string $actor, string $auditId, \DateTimeImmutable $now): void
    {
        $this->transaction(function () use ($previewId, $id, $actor, $auditId, $now): void {
            $this->publisher($actor);
            $select = $this->pdo->prepare('SELECT id, preview_id, created_at, expires_at FROM cms_preview_shares WHERE id = :id AND preview_id = :preview AND revoked_at IS NULL FOR UPDATE');
            $select->execute(['id' => $id, 'preview' => $previewId]);
            $row = $select->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                throw new ShareUnavailable();
            }
            $update = $this->pdo->prepare('UPDATE cms_preview_shares SET revoked_at = :now WHERE id = :id');
            $update->execute(['id' => $id, 'now' => self::time($now)]);
            $this->audit($auditId, $actor, 'preview.share.revoked', self::hydrate($row), $now);
        });
    }

    public function use(string $id, string $tokenHash, string $auditId, \DateTimeImmutable $now): ?PreviewShare
    {
        return $this->transaction(function () use ($id, $tokenHash, $auditId, $now): ?PreviewShare {
            $select = $this->pdo->prepare(<<<'SQL'
SELECT s.id, s.preview_id, s.token_hash, s.created_at, s.expires_at, s.revoked_at, p.status
FROM cms_preview_shares s JOIN cms_previews p ON p.id = s.preview_id
WHERE s.id = :id FOR UPDATE
SQL);
            $select->execute(['id' => $id]);
            $row = $select->fetch(PDO::FETCH_ASSOC);
            $matches = hash_equals(is_array($row) ? (string) $row['token_hash'] : str_repeat('0', 64), $tokenHash);
            if (!$matches || !is_array($row) || $row['revoked_at'] !== null || $row['status'] !== 'ready') {
                return null;
            }
            $share = self::hydrate($row);
            if ($share->expiresAt <= $now) {
                return null;
            }
            $this->audit($auditId, null, 'preview.share.used', $share, $now);
            return $share;
        });
    }

    public function createFiles(PreviewShare $share, string $tokenHash, \DateTimeImmutable $expiresAt, \DateTimeImmutable $now): void
    {
        $this->transaction(function () use ($share, $tokenHash, $expiresAt, $now): void {
            $select = $this->pdo->prepare('SELECT s.id FROM cms_preview_shares s JOIN cms_previews p ON p.id = s.preview_id WHERE s.id = :id AND s.preview_id = :preview AND s.revoked_at IS NULL AND s.expires_at > :now AND p.status = \'ready\' FOR UPDATE');
            $select->execute(['id' => $share->id, 'preview' => $share->previewId, 'now' => self::time($now)]);
            if ($select->fetchColumn() === false) {
                throw new ShareUnavailable();
            }
            $delete = $this->pdo->prepare('DELETE FROM cms_preview_share_files WHERE share_id = :id AND expires_at <= :now');
            $delete->execute(['id' => $share->id, 'now' => self::time($now)]);
            $count = $this->pdo->prepare('SELECT COUNT(*) FROM cms_preview_share_files WHERE share_id = :id');
            $count->execute(['id' => $share->id]);
            if ((int) $count->fetchColumn() >= PreviewFilePolicy::MAX_VIEWERS) {
                throw new ShareRateLimited();
            }
            $insert = $this->pdo->prepare('INSERT INTO cms_preview_share_files (token_hash, share_id, expires_at) VALUES (:hash, :share, :expires)');
            $insert->execute(['hash' => $tokenHash, 'share' => $share->id, 'expires' => self::time(min($expiresAt, $share->expiresAt))]);
        });
    }

    public function renewFiles(string $shareId, string $tokenHash, \DateTimeImmutable $expiresAt, \DateTimeImmutable $now): bool
    {
        $update = $this->pdo->prepare(<<<'SQL'
UPDATE cms_preview_share_files f JOIN cms_preview_shares s ON s.id = f.share_id JOIN cms_previews p ON p.id = s.preview_id
SET f.expires_at = LEAST(:expires, s.expires_at)
WHERE f.share_id = :id AND f.token_hash = :hash AND f.expires_at > :now_file
AND s.revoked_at IS NULL AND s.expires_at > :now_share AND p.status = 'ready'
SQL);
        $update->execute(['id' => $shareId, 'hash' => $tokenHash, 'expires' => self::time($expiresAt), 'now_file' => self::time($now), 'now_share' => self::time($now)]);
        // A renewal in the same instant need not change the row.
        return $this->files($shareId, $tokenHash, $now) !== null;
    }

    public function files(string $shareId, string $tokenHash, \DateTimeImmutable $now): ?PreviewShare
    {
        $select = $this->pdo->prepare(<<<'SQL'
SELECT s.id, s.preview_id, s.created_at, s.expires_at, f.token_hash
FROM cms_preview_share_files f JOIN cms_preview_shares s ON s.id = f.share_id JOIN cms_previews p ON p.id = s.preview_id
WHERE f.share_id = :id AND f.token_hash = :hash AND f.expires_at > :now_file
AND s.revoked_at IS NULL AND s.expires_at > :now_share AND p.status = 'ready'
SQL);
        $select->execute(['id' => $shareId, 'hash' => $tokenHash, 'now_file' => self::time($now), 'now_share' => self::time($now)]);
        $row = $select->fetch(PDO::FETCH_ASSOC);
        return hash_equals(is_array($row) ? (string) $row['token_hash'] : str_repeat('0', 64), $tokenHash) && is_array($row) ? self::hydrate($row) : null;
    }

    private function publisher(string $actor): void
    {
        $select = $this->pdo->prepare("SELECT id FROM cms_users WHERE id = :id AND role IN ('owner', 'administrator') AND status = 'active' AND must_change_password = 0 FOR UPDATE");
        $select->execute(['id' => $actor]);
        if ($select->fetchColumn() === false) {
            throw new ShareUnavailable();
        }
    }

    private function audit(string $id, ?string $actor, string $event, PreviewShare $share, \DateTimeImmutable $now): void
    {
        (new MariaDbIdentityRepository($this->pdo))->appendAudit(
            $id,
            $actor,
            $event,
            ['shareId' => $share->id, 'previewId' => $share->previewId, 'expiresAt' => self::time($share->expiresAt)],
            $now,
        );
    }

    /** @param array<string, mixed> $row */
    private static function hydrate(array $row): PreviewShare
    {
        return new PreviewShare(
            (string) $row['id'],
            (string) $row['preview_id'],
            new \DateTimeImmutable((string) $row['created_at'], new \DateTimeZone('UTC')),
            new \DateTimeImmutable((string) $row['expires_at'], new \DateTimeZone('UTC')),
        );
    }

    /**
     * @template T
     * @param \Closure(): T $action
     * @return T
     */
    private function transaction(\Closure $action): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $action();
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    private static function time(\DateTimeImmutable $time): string
    {
        return $time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
