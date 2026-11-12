<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Preview;

/** Hash-only file leases: no browser cookie, no guest share, and no lifetime beyond the creating session. */
final readonly class MariaDbEditorPreviewFiles implements EditorPreviewFilesRepository
{
    private const SESSION_AUTHORITY = "s.kind = 'authenticated' AND s.revoked_at IS NULL AND s.expires_at > :session_now AND s.absolute_expires_at > :absolute_now AND u.status = 'active' AND u.must_change_password = 0 AND s.security_version = u.security_version AND u.role IN ('owner', 'administrator', 'editor')";

    public function __construct(private \PDO $pdo) {}

    public function create(string $previewId, string $sessionId, string $tokenHash, \DateTimeImmutable $now, \DateTimeImmutable $expiresAt): void
    {
        if (!PreviewFilePolicy::validToken($tokenHash) || preg_match('/\A[a-f0-9]{32}\z/', $sessionId) !== 1 || $expiresAt <= $now || $expiresAt > PreviewFilePolicy::expiresAt($now)) {
            throw new \InvalidArgumentException('Editor preview file lease is invalid');
        }
        $time = self::time($now);
        $this->pdo->beginTransaction();
        try {
            $session = $this->pdo->prepare('SELECT s.id FROM cms_sessions s JOIN cms_users u ON u.id = s.user_id WHERE s.id = :session AND ' . self::SESSION_AUTHORITY . ' FOR UPDATE');
            $session->execute(['session' => $sessionId, 'session_now' => $time, 'absolute_now' => $time]);
            if ($session->fetchColumn() === false) {
                throw new PreviewNotFound();
            }
            $this->pdo->prepare('DELETE FROM cms_editor_preview_files WHERE expires_at <= :now LIMIT 200')->execute(['now' => $time]);
            $insert = $this->pdo->prepare("INSERT INTO cms_editor_preview_files (token_hash, preview_id, session_id, created_at, expires_at) SELECT :hash, p.id, :session, :now, :expires FROM cms_previews p WHERE p.id = :preview AND p.status = 'ready' AND p.build_kind = 'website' AND p.include_drafts = 1");
            $insert->execute(['hash' => $tokenHash, 'session' => $sessionId, 'now' => $time, 'expires' => self::time($expiresAt), 'preview' => $previewId]);
            if ($insert->rowCount() !== 1) {
                throw new PreviewNotFound();
            }
            $old = $this->pdo->prepare('SELECT token_hash FROM cms_editor_preview_files WHERE session_id = :session AND token_hash <> :new ORDER BY created_at DESC, token_hash DESC LIMIT 20 OFFSET 19');
            $old->execute(['session' => $sessionId, 'new' => $tokenHash]);
            $delete = $this->pdo->prepare('DELETE FROM cms_editor_preview_files WHERE token_hash = :hash');
            foreach ($old->fetchAll(\PDO::FETCH_COLUMN) as $hash) {
                $delete->execute(['hash' => $hash]);
            }
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function authorize(string $previewId, string $tokenHash, \DateTimeImmutable $now): ?string
    {
        $time = self::time($now);
        $query = $this->pdo->prepare("SELECT p.id FROM cms_editor_preview_files f JOIN cms_sessions s ON s.id = f.session_id JOIN cms_users u ON u.id = s.user_id JOIN cms_previews p ON p.id = f.preview_id WHERE f.token_hash = :hash AND f.preview_id = :preview AND f.expires_at > :now AND p.status = 'ready' AND p.build_kind = 'website' AND p.include_drafts = 1 AND " . self::SESSION_AUTHORITY);
        $query->execute(['hash' => $tokenHash, 'preview' => $previewId, 'now' => $time, 'session_now' => $time, 'absolute_now' => $time]);
        $id = $query->fetchColumn();
        return is_string($id) ? $id : null;
    }

    private static function time(\DateTimeImmutable $time): string
    {
        return $time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
