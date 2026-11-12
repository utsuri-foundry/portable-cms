<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Identity;

final readonly class AccountService
{
    public function __construct(private AccountRepository $repository, private Clock $clock, private RandomSource $random) {}

    public function self(SessionContext $actor): Account
    {
        return $this->repository->transaction(fn(): Account => $this->actor($actor, allowTemporary: true));
    }

    /** @return list<Account> */
    public function page(SessionContext $actor, int $offset = 0): array
    {
        return $this->repository->transaction(function () use ($actor, $offset): array {
            $this->manager($actor);
            if ($offset < 0 || $offset > 1_000_000) {
                throw new AccountFailure('invalid_input');
            }
            return $this->repository->page($offset);
        });
    }

    public function create(SessionContext $actor, string $login, string $name, string $role, string $temporaryPassword): string
    {
        $login = AccountPolicy::login($login);
        $name = AccountPolicy::name($name);
        $hash = AccountPolicy::password($temporaryPassword);
        return $this->repository->transaction(function () use ($actor, $login, $name, $role, $hash): string {
            $manager = $this->manager($actor);
            if (!in_array($role, ['administrator', 'editor'], true) || ($role === 'administrator' && $manager->role !== 'owner')) {
                throw new AccountFailure('not_permitted', 403);
            }
            $this->uniqueLogin($login);
            $id = $this->id();
            $this->repository->insert(new Account($id, $login, $name, $role, 'active', $hash, true, 0, 1), $this->clock->now());
            $this->audit($manager->id, 'account_created', $id);
            return $id;
        });
    }

    public function updateSelf(SessionContext $actor, int $revision, string $login, string $name, string $currentPassword, string $newPassword): void
    {
        $login = AccountPolicy::login($login);
        $name = AccountPolicy::name($name);
        $hash = $newPassword === '' ? null : AccountPolicy::password($newPassword);
        $this->repository->transaction(function () use ($actor, $revision, $login, $name, $currentPassword, $newPassword, $hash): void {
            $user = $this->actor($actor, allowTemporary: true);
            $this->revision($user, $revision);
            $credentialChange = $login !== $user->login || $hash !== null;
            if (($credentialChange || $user->mustChangePassword) && (strlen($currentPassword) > 72 || !password_verify($currentPassword, $user->passwordHash))) {
                throw new AccountFailure('current_password');
            }
            if ($user->mustChangePassword && ($hash === null || password_verify($newPassword, $user->passwordHash))) {
                throw new AccountFailure('replace_temporary');
            }
            $this->uniqueLogin($login, $user->id);
            $version = $user->securityVersion + ($credentialChange ? 1 : 0);
            $this->repository->save(new Account($user->id, $login, $name, $user->role, $user->status, $hash ?? $user->passwordHash, $hash === null ? $user->mustChangePassword : false, $version, $user->revision + 1), $this->clock->now());
            if ($credentialChange) {
                $this->repository->revokeSessions($user->id, $actor->id, $version, $this->clock->now());
            }
            $this->audit($user->id, 'account_profile_changed', $user->id);
            if ($hash !== null) {
                $this->audit($user->id, 'account_password_changed', $user->id);
            }
        });
    }

    public function manage(SessionContext $actor, string $targetId, int $revision, string $action, string $password = ''): void
    {
        $hash = $action === 'reset' ? AccountPolicy::password($password) : null;
        $this->repository->transaction(function () use ($actor, $targetId, $revision, $action, $hash): void {
            $manager = $this->manager($actor);
            $target = $this->repository->find($targetId) ?? throw new AccountFailure('not_found', 404);
            if (!AccountPolicy::below($manager, $target)) {
                throw new AccountFailure('not_permitted', 403);
            }
            $this->revision($target, $revision);
            if (!in_array($action, ['reset', 'disable', 'enable', 'remove', 'promote', 'demote', 'transfer'], true)) {
                throw new AccountFailure('invalid_input');
            }
            if (in_array($action, ['promote', 'demote', 'transfer'], true) && $manager->role !== 'owner') {
                throw new AccountFailure('not_permitted', 403);
            }
            if ($action === 'transfer') {
                if ($target->role !== 'administrator' || $target->status !== 'active' || $target->mustChangePassword) {
                    throw new AccountFailure('transfer_target');
                }
                // Free the unique owner slot first; both updates commit or neither does.
                $this->replace($manager, role: 'administrator');
                $this->replace($target, role: 'owner');
            } else {
                if ($action === 'promote' && ($target->role !== 'editor' || $target->status !== 'active')) {
                    throw new AccountFailure('invalid_input');
                }
                if ($action === 'demote' && $target->role !== 'administrator') {
                    throw new AccountFailure('invalid_input');
                }
                $role = match ($action) {
                    'promote' => 'administrator', 'demote' => 'editor', default => $target->role
                };
                // Removal is a tombstone: retain authorship and audit foreign keys.
                $status = match ($action) {
                    'enable' => 'active', 'disable', 'remove' => 'disabled', default => $target->status
                };
                $this->replace($target, role: $role, status: $status, hash: $hash);
            }
            $this->audit($manager->id, 'account_' . $action, $target->id);
        });
    }

    /** Operator-only boundary, never reachable through administration account forms. */
    public function recoverOwner(string $temporaryPassword, string $gateHash): void
    {
        if (preg_match('/\A[a-f0-9]{64}\z/', $gateHash) !== 1) {
            throw new AccountFailure('not_permitted', 403);
        }
        $hash = AccountPolicy::password($temporaryPassword);
        $this->repository->transaction(function () use ($hash, $gateHash): void {
            if (!$this->repository->consumeRecovery($gateHash, $this->clock->now())) {
                throw new AccountFailure('not_permitted', 403);
            }
            $owner = $this->repository->owner();
            $this->replace($owner, hash: $hash);
            $this->audit(null, 'owner_operator_recovery', $owner->id);
        });
    }

    private function actor(SessionContext $session, bool $allowTemporary = false): Account
    {
        if (!$session->authenticated() || $session->userId === null || !$this->repository->sessionCurrent($session, $this->clock->now())) {
            throw new AccountFailure('not_permitted', 403);
        }
        $user = $this->repository->find($session->userId) ?? throw new AccountFailure('not_permitted', 403);
        if ($user->status !== 'active' || (!$allowTemporary && $user->mustChangePassword)) {
            throw new AccountFailure('not_permitted', 403);
        }
        return $user;
    }

    private function manager(SessionContext $actor): Account
    {
        $user = $this->actor($actor);
        if (!in_array($user->role, ['owner', 'administrator'], true)) {
            throw new AccountFailure('not_permitted', 403);
        }
        return $user;
    }

    private function revision(Account $account, int $revision): void
    {
        if ($account->revision !== $revision) {
            throw new AccountFailure('changed', 409);
        }
    }

    private function uniqueLogin(string $login, ?string $except = null): void
    {
        if ($this->repository->loginExists($login, $except)) {
            throw new AccountFailure('login_used');
        }
    }

    private function replace(Account $account, ?string $role = null, ?string $status = null, ?string $hash = null): void
    {
        $version = $account->securityVersion + 1;
        $this->repository->save(new Account($account->id, $account->login, $account->name, $role ?? $account->role, $status ?? $account->status, $hash ?? $account->passwordHash, $hash !== null || $account->mustChangePassword, $version, $account->revision + 1), $this->clock->now());
        $this->repository->revokeSessions($account->id, null, $version, $this->clock->now());
    }

    private function audit(?string $actor, string $event, string $target): void
    {
        $this->repository->audit($this->id(), $actor, $event, $target, $this->clock->now());
    }

    private function id(): string
    {
        return bin2hex($this->random->bytes(16));
    }
}
