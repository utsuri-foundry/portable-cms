<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Http\Request;
use Utsuri\PortableCms\Conventional\Identity\Account;
use Utsuri\PortableCms\Conventional\Identity\AccountFailure;
use Utsuri\PortableCms\Conventional\Identity\AccountPolicy;
use Utsuri\PortableCms\Conventional\Identity\AccountRepository;
use Utsuri\PortableCms\Conventional\Identity\AccountService;
use Utsuri\PortableCms\Conventional\Identity\AdministrationPermissions;
use Utsuri\PortableCms\Conventional\Identity\IdentityService;
use Utsuri\PortableCms\Conventional\Identity\SessionContext;

final class MemoryAccountRepository implements AccountRepository
{
    /** @var array<string, bool> */
    private array $recoveries = [];
    public function __construct(public readonly MemoryIdentityRepository $identity) {}

    public function transaction(Closure $operation): mixed
    {
        $before = [$this->identity->users, $this->identity->sessions, $this->identity->audit, $this->recoveries];
        try {
            $result = $operation();
            assertSameValue(1, count(array_filter($this->identity->users, static fn(array $user): bool => $user['role'] === 'owner' && $user['status'] === 'active')), 'one active owner at every commit');
            return $result;
        } catch (Throwable $error) {
            [$this->identity->users, $this->identity->sessions, $this->identity->audit, $this->recoveries] = $before;
            throw $error;
        }
    }

    public function find(string $id): ?Account
    {
        $u = $this->identity->users[$id] ?? null;
        return $u === null ? null : new Account($u['id'], $u['loginName'], $u['displayName'], $u['role'], $u['status'], $u['passwordHash'], $u['mustChangePassword'] ?? false, $u['securityVersion'] ?? 0, $u['revision'] ?? 1);
    }

    public function page(int $offset): array
    {
        return array_map(fn(string $id): Account => $this->find($id), array_slice(array_keys($this->identity->users), $offset, 50));
    }

    public function loginExists(string $login, ?string $except = null): bool
    {
        foreach ($this->identity->users as $user) {
            if ($user['loginName'] === $login && $user['id'] !== $except) {
                return true;
            }
        }
        return false;
    }

    public function insert(Account $account, DateTimeImmutable $at): void
    {
        $this->save($account, $at);
    }

    public function save(Account $account, DateTimeImmutable $at): void
    {
        $this->identity->users[$account->id] = ['id' => $account->id, 'loginName' => $account->login, 'displayName' => $account->name, 'role' => $account->role, 'status' => $account->status, 'passwordHash' => $account->passwordHash, 'mustChangePassword' => $account->mustChangePassword, 'securityVersion' => $account->securityVersion, 'revision' => $account->revision];
    }

    public function sessionCurrent(SessionContext $session, DateTimeImmutable $at): bool
    {
        $stored = $this->identity->sessions[$session->id] ?? null;
        return $stored !== null && $stored['userId'] === $session->userId && $stored['revokedAt'] === null && $stored['expiresAt'] > $at && $stored['absoluteExpiresAt'] > $at && $stored['securityVersion'] === $this->find((string) $session->userId)?->securityVersion;
    }

    public function revokeSessions(string $userId, ?string $keepSessionId, int $version, DateTimeImmutable $at): void
    {
        foreach ($this->identity->sessions as $id => $session) {
            if ($session['userId'] !== $userId) {
                continue;
            }
            if ($id === $keepSessionId) {
                $this->identity->sessions[$id]['securityVersion'] = $version;
            } else {
                $this->identity->revokeSession($id, $at);
            }
        }
    }

    public function audit(string $id, ?string $actor, string $event, string $target, DateTimeImmutable $at): void
    {
        $this->identity->appendAudit($id, $actor, $event, ['accountId' => $target], $at);
    }

    public function owner(): Account
    {
        foreach ($this->identity->users as $id => $user) {
            if ($user['role'] === 'owner') {
                return $this->find($id);
            }
        }
        throw new AccountFailure('owner_required');
    }

    public function consumeRecovery(string $gateHash, DateTimeImmutable $at): bool
    {
        if (isset($this->recoveries[$gateHash])) {
            return false;
        }
        $this->recoveries[$gateHash] = true;
        return true;
    }
}

/** @return array{accounts: AccountService, repository: MemoryAccountRepository, identity: IdentityService, clock: IdentityTestClock, random: IdentityTestRandom, owner: SessionContext} */
function accountFixture(): array
{
    $clock = new IdentityTestClock(new DateTimeImmutable('2026-10-11T12:00:00Z'));
    $random = new IdentityTestRandom();
    $memory = new MemoryIdentityRepository();
    $memory->addUser(str_repeat('1', 32), 'owner@example.test', 'Owner', password_hash('owner secret password', PASSWORD_DEFAULT), 'owner');
    $identity = new IdentityService($memory, $clock, $random, str_repeat('x', 32));
    $repository = new MemoryAccountRepository($memory);
    $accounts = new AccountService($repository, $clock, $random);
    $owner = $identity->login($identity->openSession(null, null), 'owner@example.test', 'owner secret password', '192.0.2.1')->session;
    return compact('accounts', 'repository', 'identity', 'clock', 'random', 'owner');
}

function accountReject(Closure $operation, string $reason): void
{
    try {
        $operation();
        throw new RuntimeException('Account operation unexpectedly succeeded');
    } catch (AccountFailure $error) {
        assertSameValue($reason, $error->reason, 'specific safe rejection');
    }
}

$tests['account hierarchy enforces owner administrator editor boundaries and self protection'] = static function (): void {
    extract(accountFixture());
    $adminId = $accounts->create($owner, 'ADMIN@EXAMPLE.TEST', '<Admin>', 'administrator', 'temporary password one');
    $admin = $identity->login($identity->openSession(null, null), 'admin@example.test', 'temporary password one', '192.0.2.2')->session;
    assertTrueValue($admin->mustChangePassword && !$admin->administrator(), 'temporary account has no administration authority');
    accountReject(fn() => $accounts->create($admin, 'bad@example.test', 'Bad', 'editor', 'temporary password two'), 'not_permitted');
    accountReject(fn() => $accounts->updateSelf($admin, 1, 'admin@example.test', '<Admin>', 'temporary password one', 'temporary password one'), 'replace_temporary');
    $accounts->updateSelf($admin, 1, 'admin@example.test', '<Admin>', 'temporary password one', 'permanent password two');
    $admin = $identity->openSession($admin->sessionToken, $admin->csrfToken);
    $editorId = $accounts->create($admin, 'editor@example.test', 'Editor', 'editor', 'temporary password three');
    accountReject(fn() => $accounts->create($admin, 'other@example.test', 'Other', 'administrator', 'temporary password four'), 'not_permitted');
    accountReject(fn() => $accounts->create($owner, 'other@example.test', 'Other', 'owner', 'temporary password four'), 'not_permitted');
    accountReject(fn() => $accounts->manage($admin, $owner->userId, 1, 'disable'), 'not_permitted');
    accountReject(fn() => $accounts->manage($owner, $owner->userId, 1, 'disable'), 'not_permitted');
    accountReject(fn() => $accounts->manage($admin, $adminId, 2, 'disable'), 'not_permitted');
    accountReject(fn() => $accounts->manage($admin, $editorId, 1, 'promote'), 'not_permitted');
    accountReject(fn() => $accounts->create($owner, 'ADMIN@example.test', 'Duplicate', 'editor', 'temporary password four'), 'login_used');
    $accounts->manage($owner, $adminId, 2, 'transfer');
    assertSameValue('owner', $repository->find($adminId)->role, 'ownership moved');
    assertSameValue('administrator', $repository->find($owner->userId)->role, 'previous owner demoted');
    assertTrueValue(!$identity->openSession($admin->sessionToken, $admin->csrfToken)->authenticated(), 'recipient session revoked');
    assertTrueValue(!$identity->openSession($owner->sessionToken, $owner->csrfToken)->authenticated(), 'previous owner session revoked');
};

$tests['account credentials preserve only the changing session and deactivation revokes immediately'] = static function (): void {
    extract(accountFixture());
    $other = $identity->login($identity->openSession(null, null), 'owner@example.test', 'owner secret password', '192.0.2.3')->session;
    accountReject(fn() => $accounts->updateSelf($owner, 1, 'new@example.test', 'New', 'wrong password', ''), 'current_password');
    $accounts->updateSelf($owner, 1, 'new@example.test', 'New <name>', 'owner secret password', 'new owner password');
    assertTrueValue($identity->openSession($owner->sessionToken, $owner->csrfToken)->administrator(), 'changing session remains usable');
    assertTrueValue(!$identity->openSession($other->sessionToken, $other->csrfToken)->authenticated(), 'other session revoked');
    accountReject(fn() => $accounts->updateSelf($owner, 1, 'new@example.test', 'Old', '', ''), 'changed');
    $owner = $identity->openSession($owner->sessionToken, $owner->csrfToken);
    $id = $accounts->create($owner, 'editor@example.test', 'Editor', 'editor', 'temporary editor secret');
    $editor = $identity->login($identity->openSession(null, null), 'editor@example.test', 'temporary editor secret', '192.0.2.4')->session;
    $accounts->updateSelf($editor, 1, 'editor@example.test', 'Editor', 'temporary editor secret', 'permanent editor secret');
    $editor = $identity->openSession($editor->sessionToken, $editor->csrfToken);
    $accounts->manage($owner, $id, 2, 'disable');
    assertTrueValue(!$identity->openSession($editor->sessionToken, $editor->csrfToken)->authenticated(), 'disabled account loses session');
    accountReject(fn() => $accounts->create($editor, 'bad@example.test', 'Bad', 'editor', 'temporary secret'), 'not_permitted');
    $accounts->manage($owner, $id, 3, 'enable');
    $accounts->manage($owner, $id, 4, 'reset', 'reset editor password');
    $login = $identity->login($identity->openSession(null, null), 'editor@example.test', 'reset editor password', '192.0.2.4');
    assertTrueValue($login->authenticated && $login->session->mustChangePassword, 'reset re-enters mandatory replacement');
    $audit = json_encode($repository->identity->audit, JSON_THROW_ON_ERROR);
    assertTrueValue(!str_contains($audit, 'reset editor password') && !str_contains($audit, 'editor@example.test'), 'audit stores IDs not credentials or login');
};

$tests['editor route policy refuses every privileged surface and temporary sessions cannot bypass replacement'] = static function (): void {
    $session = new SessionContext('s', 'authenticated', str_repeat('1', 32), 'a@example.test', 'A', 'editor', 'token', 'csrf', new DateTimeImmutable('+1 day'), false);
    $profile = \Utsuri\PortableCms\Conventional\Content\ProfilePackage::load(dirname(__DIR__, 2) . '/profiles/reference-studio')->manifest;
    foreach (['/accounts', '/exports', '/releases', '/releases/holding/' . str_repeat('a', 32) . '/artifact/', '/settings/design', '/settings/terminology', '/settings/publication', '/records/project/' . str_repeat('a', 32) . '/status', '/records/site-settings', '/previews/' . str_repeat('a', 32) . '/shares'] as $path) {
        assertTrueValue(!AdministrationPermissions::allows($session, new Request('POST', $path), $profile), 'editor denied ' . $path);
    }
    foreach (['/', '/records/project', '/records/article/new', '/previews', '/releases', '/media', '/account', '/settings/theme', '/settings/language'] as $path) {
        assertTrueValue(AdministrationPermissions::allows($session, new Request('GET', $path), $profile), 'editor allowed ' . $path);
    }
    foreach (['owner', 'administrator', 'editor'] as $role) {
        $temporary = new SessionContext('s', 'authenticated', str_repeat('1', 32), 'a@example.test', 'A', $role, 'token', 'csrf', new DateTimeImmutable('+1 day'), false, mustChangePassword: true);
        assertTrueValue(!AdministrationPermissions::allows($temporary, new Request('POST', '/previews'), $profile), 'replacement cannot be bypassed');
    }
};

$tests['native account forms enforce CSRF preserve non-secret conflict input and never render passwords'] = static function (): void {
    extract(accountFixture());
    $app = new \Utsuri\PortableCms\Conventional\IdentityApplication($identity, new \Utsuri\PortableCms\Conventional\Http\OriginPolicy('https://cms.example.test'), $clock, $random, accounts: $accounts);
    $cookies = [IdentityService::SESSION_COOKIE => $owner->sessionToken, IdentityService::CSRF_COOKIE => $owner->csrfToken];
    $headers = ['origin' => 'https://cms.example.test'];
    $form = ['_csrf' => $owner->csrfToken, '_revision' => '1', 'login' => 'owner@example.test', 'name' => '<Saved>', 'current_password' => 'owner secret password', 'new_password' => 'new owner password'];
    assertSameValue(403, $app->handle(new Request('POST', '/account', $headers, [...$form, '_csrf' => 'bad'], $cookies))->status, 'CSRF applies');
    assertSameValue(403, $app->handle(new Request('POST', '/account', ['origin' => 'https://evil.example'], $form, $cookies))->status, 'origin applies');
    assertSameValue(303, $app->handle(new Request('POST', '/account', $headers, $form, $cookies))->status, 'native PRG save');
    $conflict = $app->handle(new Request('POST', '/account', $headers, [...$form, 'name' => '<Pending>'], $cookies));
    assertSameValue(409, $conflict->status, 'account CAS conflict');
    assertTrueValue(str_contains($conflict->body, '&lt;Pending&gt;') && !str_contains($conflict->body, 'new owner password'), 'only escaped non-secret input retained');
    $add = ['_csrf' => $owner->csrfToken, 'action' => 'create', 'login' => 'editor@example.test', 'name' => 'Editor', 'role' => 'editor', 'temporary_password' => 'temporary editor password'];
    assertSameValue(303, $app->handle(new Request('POST', '/accounts', $headers, $add, $cookies))->status, 'owner adds through native form');
    $login = $identity->login($identity->openSession(null, null), 'editor@example.test', 'temporary editor password', '192.0.2.5')->session;
    $editorCookies = [IdentityService::SESSION_COOKIE => $login->sessionToken, IdentityService::CSRF_COOKIE => $login->csrfToken];
    assertSameValue('/account', $app->handle(new Request('GET', '/', cookies: $editorCookies))->headers()['location'][0], 'first sign-in gates all navigation');
    assertSameValue(200, $app->handle(new Request('GET', '/account', cookies: $editorCookies))->status, 'replacement form works');
    $forged = [...$form, '_csrf' => $login->csrfToken, 'login' => 'owner@example.test', 'current_password' => 'temporary editor password', 'id' => $owner->userId, 'role' => 'owner'];
    assertSameValue(422, $app->handle(new Request('POST', '/account', $headers, $forged, $editorCookies))->status, 'forged login cannot take another account email');
    assertSameValue('editor', $accounts->self($login)->role, 'self form never accepts a forged role or target');
    $ignoredTarget = [...$forged, 'login' => 'editor@example.test', 'name' => 'My own name'];
    assertSameValue(303, $app->handle(new Request('POST', '/account', $headers, $ignoredTarget, $editorCookies))->status, 'valid self change ignores forged target and role fields');
    $editor = $identity->openSession($login->sessionToken, $login->csrfToken);
    assertSameValue('editor', $accounts->self($editor)->role, 'successful self save cannot escalate its role');
    assertSameValue('My own name', $accounts->self($editor)->name, 'only the acting account is changed');
    assertSameValue('<Saved>', $accounts->self($identity->openSession($owner->sessionToken, $owner->csrfToken))->name, 'forged target cannot alter the owner');
};

$tests['owner recovery consumes a one-use receipt and bounded passwords cannot truncate'] = static function (): void {
    extract(accountFixture());
    $gateHash = str_repeat('a', 64);
    $accounts->recoverOwner('recovered owner password', $gateHash);
    accountReject(fn() => $accounts->recoverOwner('another owner password', $gateHash), 'not_permitted');
    assertTrueValue(!$identity->openSession($owner->sessionToken, $owner->csrfToken)->authenticated(), 'operator recovery revokes all sessions');
    $login = $identity->login($identity->openSession(null, null), 'owner@example.test', 'recovered owner password', '192.0.2.1');
    assertTrueValue($login->authenticated && $login->session->mustChangePassword, 'recovery password is temporary');
    foreach (['short', str_repeat('x', 73), "long password\0suffix"] as $bad) {
        accountReject(fn() => AccountPolicy::password($bad), 'invalid_password');
    }
};
