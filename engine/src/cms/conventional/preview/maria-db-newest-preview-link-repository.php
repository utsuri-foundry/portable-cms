<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Preview;

use PDO;
use Utsuri\PortableCms\Conventional\Identity\MariaDbIdentityRepository;

final class MariaDbNewestPreviewLinkRepository implements NewestPreviewLinkRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function create(NewestPreviewLink $link, string $tokenHash, string $actor, string $auditId): void
    {
        $this->transaction(function () use ($link, $tokenHash, $actor, $auditId): void {
            // The installation owner serializes the global active-link bound across publishers.
            $this->pdo->query("SELECT id FROM cms_users WHERE role = 'owner' FOR UPDATE")->fetchColumn();
            $this->publisher($actor);
            if (count($this->active($link->createdAt)) >= 50) {
                throw new ShareUnavailable();
            }
            $statement = $this->pdo->prepare('INSERT INTO cms_preview_follow_links (id, token_hash, created_by, created_at, expires_at) VALUES (:id, :hash, :actor, :created, :expires)');
            $statement->execute(['id' => $link->id, 'hash' => $tokenHash, 'actor' => $actor, 'created' => self::time($link->createdAt), 'expires' => self::time($link->expiresAt)]);
            $this->audit($auditId, $actor, 'preview.follow.created', $link, null, $link->createdAt);
        });
    }

    public function active(\DateTimeImmutable $now): array
    {
        $statement = $this->pdo->prepare('SELECT id, created_at, expires_at FROM cms_preview_follow_links WHERE revoked_at IS NULL AND expires_at > :now ORDER BY created_at DESC, id DESC LIMIT 50');
        $statement->execute(['now' => self::time($now)]);
        return array_map(self::hydrate(...), $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function revoke(string $id, string $actor, string $auditId, \DateTimeImmutable $now): void
    {
        $this->transaction(function () use ($id, $actor, $auditId, $now): void {
            $this->publisher($actor);
            $statement = $this->pdo->prepare('SELECT id, created_at, expires_at FROM cms_preview_follow_links WHERE id = :id AND revoked_at IS NULL FOR UPDATE');
            $statement->execute(['id' => $id]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                throw new ShareUnavailable();
            }
            $update = $this->pdo->prepare('UPDATE cms_preview_follow_links SET revoked_at = :now WHERE id = :id');
            $update->execute(['now' => self::time($now), 'id' => $id]);
            $this->audit($auditId, $actor, 'preview.follow.revoked', self::hydrate($row), null, $now);
        });
    }

    public function resolve(string $id, string $tokenHash, ?string $pinnedPreview, string $auditId, \DateTimeImmutable $now): ?NewestPreviewResolution
    {
        return $this->transaction(function () use ($id, $tokenHash, $pinnedPreview, $auditId, $now): ?NewestPreviewResolution {
            // This lock orders resolution/use with revocation and deduplicates the use audit.
            $statement = $this->pdo->prepare('SELECT id, token_hash, created_at, expires_at, revoked_at FROM cms_preview_follow_links WHERE id = :id FOR UPDATE');
            $statement->execute(['id' => $id]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            $matches = hash_equals(is_array($row) ? (string) $row['token_hash'] : str_repeat('0', 64), $tokenHash);
            if (!$matches || !is_array($row) || $row['revoked_at'] !== null) {
                return null;
            }
            $link = self::hydrate($row);
            if ($link->expiresAt <= $now) {
                return null;
            }
            if ($pinnedPreview === null) {
                $preview = (new MariaDbNewestReadyPreview($this->pdo))->id(lock: true);
            } else {
                $select = $this->pdo->prepare("SELECT p.id FROM cms_preview_follow_resolutions r JOIN cms_previews p ON p.id = r.preview_id WHERE r.link_id = :link AND p.id = :preview AND p.build_kind = 'website' AND p.status = 'ready' LOCK IN SHARE MODE");
                $select->execute(['link' => $id, 'preview' => $pinnedPreview]);
                $preview = $select->fetchColumn();
            }
            if (!is_string($preview)) {
                return null;
            }
            $known = $this->pdo->prepare('SELECT preview_id FROM cms_preview_follow_resolutions WHERE link_id = :link AND preview_id = :preview');
            $known->execute(['link' => $id, 'preview' => $preview]);
            if ($known->fetchColumn() === false) {
                $insert = $this->pdo->prepare('INSERT INTO cms_preview_follow_resolutions (link_id, preview_id, resolved_at) VALUES (:link, :preview, :now)');
                $insert->execute(['link' => $id, 'preview' => $preview, 'now' => self::time($now)]);
                $this->audit($auditId, null, 'preview.follow.used', $link, $preview, $now);
            }
            return new NewestPreviewResolution($link, $preview);
        });
    }

    public function createFiles(NewestPreviewResolution $resolution, string $tokenHash, \DateTimeImmutable $expiresAt, \DateTimeImmutable $now): void
    {
        $this->transaction(function () use ($resolution, $tokenHash, $expiresAt, $now): void {
            $select = $this->pdo->prepare(<<<'SQL'
SELECT l.id, l.created_at, l.expires_at
FROM cms_preview_follow_links l JOIN cms_preview_follow_resolutions r ON r.link_id = l.id JOIN cms_previews p ON p.id = r.preview_id
WHERE l.id = :id AND r.preview_id = :preview AND l.revoked_at IS NULL AND l.expires_at > :now AND p.status = 'ready' AND p.build_kind = 'website'
FOR UPDATE
SQL);
            $select->execute(['id' => $resolution->link->id, 'preview' => $resolution->previewId, 'now' => self::time($now)]);
            $row = $select->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                throw new ShareUnavailable();
            }
            $link = self::hydrate($row);
            $delete = $this->pdo->prepare('DELETE FROM cms_preview_follow_files WHERE link_id = :id AND expires_at <= :now');
            $delete->execute(['id' => $link->id, 'now' => self::time($now)]);
            $count = $this->pdo->prepare('SELECT COUNT(*) FROM cms_preview_follow_files WHERE link_id = :id');
            $count->execute(['id' => $link->id]);
            if ((int) $count->fetchColumn() >= PreviewFilePolicy::MAX_VIEWERS) {
                throw new ShareRateLimited();
            }
            $insert = $this->pdo->prepare('INSERT INTO cms_preview_follow_files (token_hash, link_id, preview_id, expires_at) VALUES (:hash, :link, :preview, :expires)');
            $insert->execute(['hash' => $tokenHash, 'link' => $link->id, 'preview' => $resolution->previewId, 'expires' => self::time(min($expiresAt, $link->expiresAt))]);
        });
    }

    public function files(string $id, string $tokenHash, \DateTimeImmutable $now): ?NewestPreviewResolution
    {
        $select = $this->pdo->prepare(<<<'SQL'
SELECT l.id, l.created_at, l.expires_at, f.preview_id, f.token_hash
FROM cms_preview_follow_files f JOIN cms_preview_follow_links l ON l.id = f.link_id
JOIN cms_preview_follow_resolutions r ON r.link_id = f.link_id AND r.preview_id = f.preview_id JOIN cms_previews p ON p.id = r.preview_id
WHERE f.link_id = :id AND f.token_hash = :hash AND f.expires_at > :now_file
AND l.revoked_at IS NULL AND l.expires_at > :now_link AND p.status = 'ready' AND p.build_kind = 'website'
SQL);
        $select->execute(['id' => $id, 'hash' => $tokenHash, 'now_file' => self::time($now), 'now_link' => self::time($now)]);
        $row = $select->fetch(PDO::FETCH_ASSOC);
        return hash_equals(is_array($row) ? (string) $row['token_hash'] : str_repeat('0', 64), $tokenHash) && is_array($row)
            ? new NewestPreviewResolution(self::hydrate($row), (string) $row['preview_id']) : null;
    }

    public function renewFiles(string $id, string $parentTokenHash, string $fileTokenHash, \DateTimeImmutable $expiresAt, \DateTimeImmutable $now): ?NewestPreviewResolution
    {
        return $this->transaction(function () use ($id, $parentTokenHash, $fileTokenHash, $expiresAt, $now): ?NewestPreviewResolution {
            // Keep parent-before-file lock ordering with minting and revocation.
            $select = $this->pdo->prepare('SELECT id, token_hash, created_at, expires_at, revoked_at FROM cms_preview_follow_links WHERE id = :id FOR UPDATE');
            $select->execute(['id' => $id]);
            $row = $select->fetch(PDO::FETCH_ASSOC);
            $matches = hash_equals(is_array($row) ? (string) $row['token_hash'] : str_repeat('0', 64), $parentTokenHash);
            if (!$matches || !is_array($row) || $row['revoked_at'] !== null || self::hydrate($row)->expiresAt <= $now) {
                return null;
            }
            $resolution = $this->files($id, $fileTokenHash, $now);
            if ($resolution === null) {
                return null;
            }
            $update = $this->pdo->prepare('UPDATE cms_preview_follow_files SET expires_at = :expires WHERE link_id = :id AND token_hash = :hash AND expires_at > :now');
            $update->execute(['expires' => self::time(min($expiresAt, $resolution->link->expiresAt)), 'id' => $id, 'hash' => $fileTokenHash, 'now' => self::time($now)]);
            return $this->files($id, $fileTokenHash, $now);
        });
    }

    private function publisher(string $actor): void
    {
        $statement = $this->pdo->prepare("SELECT id FROM cms_users WHERE id = :id AND role IN ('owner', 'administrator') AND status = 'active' AND must_change_password = 0 FOR UPDATE");
        $statement->execute(['id' => $actor]);
        if ($statement->fetchColumn() === false) {
            throw new ShareUnavailable();
        }
    }

    private function audit(string $id, ?string $actor, string $event, NewestPreviewLink $link, ?string $preview, \DateTimeImmutable $now): void
    {
        (new MariaDbIdentityRepository($this->pdo))->appendAudit($id, $actor, $event, ['linkId' => $link->id, 'previewId' => $preview, 'expiresAt' => self::time($link->expiresAt)], $now);
    }

    /** @param array<string, mixed> $row */
    private static function hydrate(array $row): NewestPreviewLink
    {
        return new NewestPreviewLink((string) $row['id'], new \DateTimeImmutable((string) $row['created_at'], new \DateTimeZone('UTC')), new \DateTimeImmutable((string) $row['expires_at'], new \DateTimeZone('UTC')));
    }

    /** @template T
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
