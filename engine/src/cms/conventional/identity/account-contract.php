<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Identity;

final class AccountFailure extends \RuntimeException
{
    public function __construct(public readonly string $reason, public readonly int $status = 422)
    {
        parent::__construct($reason);
    }
}

/** Private repository value. Password hashes never enter a view or export. */
final readonly class Account
{
    public function __construct(
        public string $id,
        public string $login,
        public string $name,
        public string $role,
        public string $status,
        public string $passwordHash,
        public bool $mustChangePassword,
        public int $securityVersion,
        public int $revision,
    ) {}
}

interface AccountRepository
{
    /** Serializes account mutations under the installation singleton lock; commits audit with the change. */
    public function transaction(\Closure $operation): mixed;
    public function find(string $id): ?Account;
    /** @return list<Account> Bounded page, not an unbounded account inventory. */
    public function page(int $offset): array;
    public function loginExists(string $login, ?string $except = null): bool;
    public function insert(Account $account, \DateTimeImmutable $at): void;
    public function save(Account $account, \DateTimeImmutable $at): void;
    public function sessionCurrent(SessionContext $session, \DateTimeImmutable $at): bool;
    public function revokeSessions(string $userId, ?string $keepSessionId, int $version, \DateTimeImmutable $at): void;
    public function audit(string $id, ?string $actor, string $event, string $target, \DateTimeImmutable $at): void;
    public function owner(): Account;
    public function consumeRecovery(string $gateHash, \DateTimeImmutable $at): bool;
}

/** One policy for hierarchy, forms and authoritative account commands. */
final class AccountPolicy
{
    public static function below(Account $actor, Account $target): bool
    {
        return $actor->id !== $target->id && $target->role !== 'owner'
            && ($actor->role === 'owner' || ($actor->role === 'administrator' && $target->role === 'editor'));
    }

    public static function login(string $value): string
    {
        $login = IdentityService::normalizeLogin($value);
        if ($login === '' || strlen($login) > 254 || filter_var($login, FILTER_VALIDATE_EMAIL) === false) {
            throw new AccountFailure('invalid_login');
        }
        return $login;
    }

    public static function name(string $value): string
    {
        $name = trim($value);
        if ($name === '' || !mb_check_encoding($name, 'UTF-8') || mb_strlen($name, 'UTF-8') > 160 || preg_match('/[\x00-\x1f\x7f]/u', $name)) {
            throw new AccountFailure('invalid_name');
        }
        return $name;
    }

    public static function password(string $value): string
    {
        // PASSWORD_DEFAULT currently uses bcrypt: reject truncation and embedded NUL.
        if (strlen($value) < 14 || strlen($value) > 72 || str_contains($value, "\0")) {
            throw new AccountFailure('invalid_password');
        }
        return password_hash($value, PASSWORD_DEFAULT);
    }
}
