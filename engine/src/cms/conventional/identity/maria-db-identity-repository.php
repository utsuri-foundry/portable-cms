<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Identity;

use PDO;
use Throwable;

final class MariaDbIdentityRepository implements IdentityRepository
{
    private readonly \DateTimeZone $utc;

    public function __construct(private readonly PDO $pdo)
    {
        $this->utc = new \DateTimeZone('UTC');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        $this->pdo->exec("SET time_zone = '+00:00'");
        $this->pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');
    }

    public function findSessionByTokenHash(string $tokenHash): ?array
    {
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT
    s.id,
    s.kind,
    s.user_id,
    s.csrf_hash,
    s.expires_at,
    s.absolute_expires_at,
    u.id AS active_user_id,
    u.login_name,
    u.display_name,
    u.role,
    u.interface_language,
    u.must_change_password,
    u.security_version
FROM cms_sessions s
LEFT JOIN cms_users u ON u.id = s.user_id AND u.status = 'active'
WHERE s.token_hash = :token_hash AND s.revoked_at IS NULL
    AND (s.kind = 'anonymous' OR s.security_version = u.security_version)
LIMIT 1
SQL);
        $statement->bindValue(':token_hash', $tokenHash, PDO::PARAM_LOB);
        $statement->execute();
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        if ($row['kind'] === 'authenticated' && $row['active_user_id'] === null) {
            return null;
        }
        return [
            'id' => (string) $row['id'],
            'kind' => (string) $row['kind'],
            'userId' => $row['user_id'] !== null ? (string) $row['user_id'] : null,
            'loginName' => $row['login_name'] !== null ? (string) $row['login_name'] : null,
            'displayName' => $row['display_name'] !== null ? (string) $row['display_name'] : null,
            'role' => $row['role'] !== null ? (string) $row['role'] : null,
            'interfaceLanguage' => $row['interface_language'] !== null ? (string) $row['interface_language'] : null,
            'mustChangePassword' => (bool) $row['must_change_password'],
            'securityVersion' => (int) $row['security_version'],
            'csrfHash' => (string) $row['csrf_hash'],
            'expiresAt' => $this->time((string) $row['expires_at']),
            'absoluteExpiresAt' => $this->time((string) $row['absolute_expires_at']),
        ];
    }

    public function createSession(
        string $id,
        string $kind,
        ?string $userId,
        string $tokenHash,
        string $csrfHash,
        \DateTimeImmutable $createdAt,
        \DateTimeImmutable $expiresAt,
        \DateTimeImmutable $absoluteExpiresAt,
        int $securityVersion = 0,
    ): void {
        if ($kind === 'authenticated') {
            $user = $this->pdo->prepare("SELECT id FROM cms_users WHERE id = :id AND status = 'active' AND security_version = :version");
            $user->execute(['id' => $userId, 'version' => $securityVersion]);
            if ($user->fetchColumn() === false) {
                throw new AccountFailure('not_permitted', 403);
            }
        }
        $statement = $this->pdo->prepare(<<<'SQL'
INSERT INTO cms_sessions (
    id, kind, user_id, token_hash, csrf_hash, created_at, last_seen_at,
    expires_at, absolute_expires_at, revoked_at, security_version
) VALUES (
    :id, :kind, :user_id, :token_hash, :csrf_hash, :created_at, :last_seen_at,
    :expires_at, :absolute_expires_at, NULL, :security_version
)
SQL);
        $statement->bindValue(':id', $id);
        $statement->bindValue(':kind', $kind);
        $statement->bindValue(':user_id', $userId);
        $statement->bindValue(':token_hash', $tokenHash, PDO::PARAM_LOB);
        $statement->bindValue(':csrf_hash', $csrfHash, PDO::PARAM_LOB);
        $statement->bindValue(':created_at', self::databaseTime($createdAt));
        $statement->bindValue(':last_seen_at', self::databaseTime($createdAt));
        $statement->bindValue(':expires_at', self::databaseTime($expiresAt));
        $statement->bindValue(':absolute_expires_at', self::databaseTime($absoluteExpiresAt));
        $statement->bindValue(':security_version', $securityVersion, PDO::PARAM_INT);
        $statement->execute();
    }

    public function touchSession(string $id, \DateTimeImmutable $lastSeenAt, \DateTimeImmutable $expiresAt): void
    {
        $statement = $this->pdo->prepare(<<<'SQL'
UPDATE cms_sessions
SET last_seen_at = :last_seen_at, expires_at = :expires_at
WHERE id = :id AND revoked_at IS NULL
SQL);
        $statement->execute([
            'last_seen_at' => self::databaseTime($lastSeenAt),
            'expires_at' => self::databaseTime($expiresAt),
            'id' => $id,
        ]);
    }

    public function revokeSession(string $id, \DateTimeImmutable $revokedAt): void
    {
        $statement = $this->pdo->prepare(<<<'SQL'
UPDATE cms_sessions
SET revoked_at = COALESCE(revoked_at, :revoked_at)
WHERE id = :id
SQL);
        $statement->execute(['revoked_at' => self::databaseTime($revokedAt), 'id' => $id]);
    }

    public function findActiveUserByLogin(string $normalizedLogin): ?array
    {
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT id, login_name, display_name, role, interface_language, password_hash, must_change_password, security_version
FROM cms_users
WHERE login_name = :login_name AND status = 'active'
LIMIT 1
SQL);
        $statement->execute(['login_name' => $normalizedLogin]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        return [
            'id' => (string) $row['id'],
            'loginName' => (string) $row['login_name'],
            'displayName' => (string) $row['display_name'],
            'role' => (string) $row['role'],
            'interfaceLanguage' => $row['interface_language'] !== null ? (string) $row['interface_language'] : null,
            'passwordHash' => (string) $row['password_hash'],
            'mustChangePassword' => (bool) $row['must_change_password'],
            'securityVersion' => (int) $row['security_version'],
        ];
    }

    public function replacePasswordHash(string $userId, string $passwordHash, \DateTimeImmutable $changedAt, ?string $expectedHash = null): void
    {
        $statement = $this->pdo->prepare(<<<'SQL'
UPDATE cms_users
SET password_hash = :password_hash, password_changed_at = :password_changed_at
WHERE id = :id AND status = 'active' AND (:unguarded = 1 OR password_hash = :expected_hash)
SQL);
        $statement->execute([
            'password_hash' => $passwordHash,
            'password_changed_at' => self::databaseTime($changedAt),
            'unguarded' => $expectedHash === null ? 1 : 0,
            'expected_hash' => $expectedHash ?? '',
            'id' => $userId,
        ]);
    }

    public function setInterfaceLanguage(string $userId, ?string $language): void
    {
        $statement = $this->pdo->prepare("UPDATE cms_users SET interface_language = :language WHERE id = :id AND status = 'active'");
        $statement->execute(['language' => $language, 'id' => $userId]);
    }

    public function loginAllowed(array $keyHashes, \DateTimeImmutable $now): bool
    {
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT blocked_until
FROM cms_login_throttles
WHERE key_hash = :key_hash
SQL);
        foreach ($keyHashes as $keyHash) {
            $statement->bindValue(':key_hash', $keyHash, PDO::PARAM_LOB);
            $statement->execute();
            $blockedUntil = $statement->fetchColumn();
            $statement->closeCursor();
            if (is_string($blockedUntil) && $this->time($blockedUntil) > $now) {
                return false;
            }
        }
        return true;
    }

    public function recordLoginFailure(
        array $keyHashes,
        \DateTimeImmutable $now,
        int $windowSeconds,
        int $maximumFailures,
        int $blockSeconds,
    ): void {
        $this->pdo->beginTransaction();
        try {
            foreach ($keyHashes as $keyHash) {
                $seed = $this->pdo->prepare(<<<'SQL'
INSERT IGNORE INTO cms_login_throttles (key_hash, failures, window_started_at, blocked_until)
VALUES (:key_hash, 0, :window_started_at, NULL)
SQL);
                $seed->bindValue(':key_hash', $keyHash, PDO::PARAM_LOB);
                $seed->bindValue(':window_started_at', self::databaseTime($now));
                $seed->execute();
                $select = $this->pdo->prepare(<<<'SQL'
SELECT failures, window_started_at
FROM cms_login_throttles
WHERE key_hash = :key_hash
FOR UPDATE
SQL);
                $select->bindValue(':key_hash', $keyHash, PDO::PARAM_LOB);
                $select->execute();
                $row = $select->fetch(PDO::FETCH_ASSOC);
                if (!is_array($row)) {
                    throw new \RuntimeException('Login throttle row disappeared during update');
                }
                $windowExpired = $this->time((string) $row['window_started_at'])
                    ->modify('+' . $windowSeconds . ' seconds') <= $now;
                $failures = $windowExpired ? 1 : ((int) $row['failures'] + 1);
                $windowStartedAt = $windowExpired
                    ? $now
                    : $this->time((string) $row['window_started_at']);
                $blockedUntil = $failures >= $maximumFailures
                    ? $now->modify('+' . $blockSeconds . ' seconds')
                    : null;

                $update = $this->pdo->prepare(<<<'SQL'
UPDATE cms_login_throttles
SET failures = :failures,
    window_started_at = :window_started_at,
    blocked_until = :blocked_until
WHERE key_hash = :key_hash
SQL);
                $update->bindValue(':key_hash', $keyHash, PDO::PARAM_LOB);
                $update->bindValue(':failures', $failures, PDO::PARAM_INT);
                $update->bindValue(':window_started_at', self::databaseTime($windowStartedAt));
                $update->bindValue(
                    ':blocked_until',
                    $blockedUntil !== null ? self::databaseTime($blockedUntil) : null,
                );
                $update->execute();
            }
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function clearLoginFailures(array $keyHashes): void
    {
        $statement = $this->pdo->prepare('DELETE FROM cms_login_throttles WHERE key_hash = :key_hash');
        foreach ($keyHashes as $keyHash) {
            $statement->bindValue(':key_hash', $keyHash, PDO::PARAM_LOB);
            $statement->execute();
        }
    }

    public function appendAudit(
        string $id,
        ?string $actorUserId,
        string $eventType,
        array $metadata,
        \DateTimeImmutable $occurredAt,
    ): void {
        $encoded = json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($encoded) > 2_048) {
            throw new \InvalidArgumentException('Audit metadata exceeds its bound');
        }
        $statement = $this->pdo->prepare(<<<'SQL'
INSERT INTO cms_audit_events (id, actor_user_id, event_type, metadata_json, occurred_at)
VALUES (:id, :actor_user_id, :event_type, :metadata_json, :occurred_at)
SQL);
        $statement->execute([
            'id' => $id,
            'actor_user_id' => $actorUserId,
            'event_type' => $eventType,
            'metadata_json' => $encoded,
            'occurred_at' => self::databaseTime($occurredAt),
        ]);
    }

    private function time(string $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable($value, $this->utc);
    }

    private static function databaseTime(\DateTimeImmutable $time): string
    {
        return $time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
