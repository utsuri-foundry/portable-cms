<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Identity;

use PDO;
use Throwable;

final readonly class AdministratorBootstrap
{
    public function __construct(
        private PDO $pdo,
        private Clock $clock,
        private RandomSource $random,
    ) {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        $this->pdo->exec("SET time_zone = '+00:00'");
        $this->pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');
    }

    public function create(string $login, string $displayName, string $password): string
    {
        $normalizedLogin = IdentityService::normalizeLogin($login);
        $displayName = trim($displayName);
        if ($normalizedLogin === '' || filter_var($normalizedLogin, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('Administrator login must be a valid email address');
        }
        if ($displayName === '' || mb_strlen($displayName, 'UTF-8') > 160) {
            throw new \InvalidArgumentException('Administrator display name must contain 1 to 160 characters');
        }
        if (strlen($password) < 14 || strlen($password) > 72 || str_contains($password, "\0")) {
            throw new \InvalidArgumentException('Owner password must contain 14 to 72 bytes');
        }
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        $this->pdo->beginTransaction();
        try {
            $state = $this->pdo->query(
                'SELECT status FROM cms_bootstrap_state WHERE singleton_id = 1 FOR UPDATE',
            )->fetchColumn();
            if ($state !== 'open') {
                throw new \RuntimeException('Administrator bootstrap is already closed');
            }
            $count = (int) $this->pdo->query('SELECT COUNT(*) FROM cms_users')->fetchColumn();
            if ($count !== 0) {
                throw new \RuntimeException('Administrator bootstrap is already closed');
            }
            $id = bin2hex($this->random->bytes(16));
            $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
            $insert = $this->pdo->prepare(<<<'SQL'
INSERT INTO cms_users (
    id, login_name, display_name, password_hash, role, status, created_at, password_changed_at
) VALUES (
    :id, :login_name, :display_name, :password_hash, 'owner', 'active', :created_at, :password_changed_at
)
SQL);
            $insert->execute([
                'id' => $id,
                'login_name' => $normalizedLogin,
                'display_name' => $displayName,
                'password_hash' => $passwordHash,
                'created_at' => $now,
                'password_changed_at' => $now,
            ]);
            $audit = $this->pdo->prepare(<<<'SQL'
INSERT INTO cms_audit_events (id, actor_user_id, event_type, metadata_json, occurred_at)
VALUES (:id, :actor_user_id, 'administrator_bootstrapped', :metadata_json, :occurred_at)
SQL);
            $audit->execute([
                'id' => bin2hex($this->random->bytes(16)),
                'actor_user_id' => $id,
                'metadata_json' => '{"method":"operator_cli"}',
                'occurred_at' => $now,
            ]);
            $close = $this->pdo->prepare(<<<'SQL'
UPDATE cms_bootstrap_state
SET status = 'closed', closed_at = :closed_at
WHERE singleton_id = 1 AND status = 'open'
SQL);
            $close->execute(['closed_at' => $now]);
            if ($close->rowCount() !== 1) {
                throw new \RuntimeException('Administrator bootstrap could not close safely');
            }
            $this->pdo->commit();
            return $id;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }
}
