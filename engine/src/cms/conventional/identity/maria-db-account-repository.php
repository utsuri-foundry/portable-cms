<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Identity;

use PDO;
use Throwable;

final readonly class MariaDbAccountRepository implements AccountRepository
{
    public function __construct(private PDO $pdo) {}

    public function transaction(\Closure $operation): mixed
    {
        $this->pdo->beginTransaction();
        try {
            // One installation-wide ordering lock also used by bootstrap.
            $state = $this->pdo->query('SELECT status FROM cms_bootstrap_state WHERE singleton_id = 1 FOR UPDATE')->fetchColumn();
            if ($state !== 'closed') {
                throw new AccountFailure('not_permitted', 403);
            }
            $result = $operation();
            if ((int) $this->pdo->query("SELECT COUNT(*) FROM cms_users WHERE role = 'owner' AND status = 'active'")->fetchColumn() !== 1) {
                throw new AccountFailure('owner_required', 409);
            }
            $this->pdo->commit();
            return $result;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function find(string $id): ?Account
    {
        $statement = $this->pdo->prepare('SELECT * FROM cms_users WHERE id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? self::account($row) : null;
    }

    public function page(int $offset): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM cms_users ORDER BY created_at, id LIMIT 50 OFFSET :offset');
        $statement->bindValue('offset', $offset, PDO::PARAM_INT);
        $statement->execute();
        return array_map(self::account(...), $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function loginExists(string $login, ?string $except = null): bool
    {
        $statement = $this->pdo->prepare('SELECT id FROM cms_users WHERE login_name = :login');
        $statement->execute(['login' => $login]);
        $id = $statement->fetchColumn();
        return is_string($id) && $id !== $except;
    }

    public function insert(Account $account, \DateTimeImmutable $at): void
    {
        $statement = $this->pdo->prepare(<<<'SQL'
INSERT INTO cms_users (id, login_name, display_name, role, status, password_hash,
    must_change_password, security_version, revision, created_at, password_changed_at)
VALUES (:id, :login, :name, :role, :status, :hash, :temporary, :version, :revision, :created, :changed)
SQL);
        $statement->execute([...self::values($account), 'created' => self::time($at), 'changed' => self::time($at)]);
    }

    public function save(Account $account, \DateTimeImmutable $at): void
    {
        $statement = $this->pdo->prepare(<<<'SQL'
UPDATE cms_users SET password_changed_at = CASE WHEN password_hash <> :changed_hash THEN :changed ELSE password_changed_at END,
    login_name = :login, display_name = :name, role = :role,
    status = :status, password_hash = :hash, must_change_password = :temporary,
    security_version = :version, revision = :revision
WHERE id = :id AND revision = :expected
SQL);
        $statement->execute([...self::values($account), 'changed_hash' => $account->passwordHash, 'changed' => self::time($at), 'expected' => $account->revision - 1]);
        if ($statement->rowCount() !== 1) {
            throw new AccountFailure('changed', 409);
        }
    }

    public function sessionCurrent(SessionContext $session, \DateTimeImmutable $at): bool
    {
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT s.id FROM cms_sessions s JOIN cms_users u ON u.id = s.user_id
WHERE s.id = :id AND s.user_id = :user AND s.revoked_at IS NULL
    AND s.expires_at > :expires AND s.absolute_expires_at > :absolute
    AND s.security_version = u.security_version AND u.status = 'active'
SQL);
        $statement->execute(['id' => $session->id, 'user' => $session->userId, 'expires' => self::time($at), 'absolute' => self::time($at)]);
        return $statement->fetchColumn() !== false;
    }

    public function revokeSessions(string $userId, ?string $keepSessionId, int $version, \DateTimeImmutable $at): void
    {
        $statement = $this->pdo->prepare('UPDATE cms_sessions SET revoked_at = COALESCE(revoked_at, :at) WHERE user_id = :user AND id <> :keep');
        $statement->execute(['at' => self::time($at), 'user' => $userId, 'keep' => $keepSessionId ?? '']);
        if ($keepSessionId !== null) {
            $statement = $this->pdo->prepare('UPDATE cms_sessions SET security_version = :version WHERE id = :id AND user_id = :user AND revoked_at IS NULL');
            $statement->execute(['version' => $version, 'id' => $keepSessionId, 'user' => $userId]);
            if ($statement->rowCount() !== 1) {
                throw new AccountFailure('not_permitted', 403);
            }
        }
    }

    public function audit(string $id, ?string $actor, string $event, string $target, \DateTimeImmutable $at): void
    {
        (new MariaDbIdentityRepository($this->pdo))->appendAudit($id, $actor, $event, ['accountId' => $target], $at);
    }

    public function owner(): Account
    {
        $row = $this->pdo->query("SELECT * FROM cms_users WHERE role = 'owner' AND status = 'active'")->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? self::account($row) : throw new AccountFailure('owner_required', 409);
    }

    public function consumeRecovery(string $gateHash, \DateTimeImmutable $at): bool
    {
        $statement = $this->pdo->prepare('INSERT IGNORE INTO cms_owner_recoveries (gate_hash, completed_at) VALUES (:hash, :at)');
        $statement->execute(['hash' => $gateHash, 'at' => self::time($at)]);
        return $statement->rowCount() === 1;
    }

    /** @param array<string, mixed> $row */
    private static function account(array $row): Account
    {
        return new Account((string) $row['id'], (string) $row['login_name'], (string) $row['display_name'], (string) $row['role'], (string) $row['status'], (string) $row['password_hash'], (bool) $row['must_change_password'], (int) $row['security_version'], (int) $row['revision']);
    }

    /** @return array<string, int|string> */
    private static function values(Account $account): array
    {
        return ['id' => $account->id, 'login' => $account->login, 'name' => $account->name, 'role' => $account->role, 'status' => $account->status, 'hash' => $account->passwordHash, 'temporary' => (int) $account->mustChangePassword, 'version' => $account->securityVersion, 'revision' => $account->revision];
    }

    private static function time(\DateTimeImmutable $at): string
    {
        return $at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
