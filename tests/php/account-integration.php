<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\ApplicationFactory;
use Utsuri\PortableCms\Conventional\Database\MigrationRunner;
use Utsuri\PortableCms\Conventional\Http\Request;
use Utsuri\PortableCms\Conventional\Identity\AccountService;
use Utsuri\PortableCms\Conventional\Identity\IdentityService;
use Utsuri\PortableCms\Conventional\Identity\MariaDbAccountRepository;
use Utsuri\PortableCms\Conventional\Identity\MariaDbIdentityRepository;
use Utsuri\PortableCms\Conventional\Identity\OwnerRecovery;
use Utsuri\PortableCms\Conventional\Identity\SessionContext;
use Utsuri\PortableCms\Conventional\Identity\SystemClock;
use Utsuri\PortableCms\Conventional\Identity\SystemRandomSource;
use Utsuri\PortableCms\Conventional\RuntimeConfiguration;
use Utsuri\PortableCms\Conventional\Upgrade\DatabaseBackup;

require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/bootstrap.php';
require_once __DIR__ . '/assertions.php';
require_once __DIR__ . '/identity-test.php';
require_once __DIR__ . '/account-test.php';

$dsn = getenv('CMS_TEST_ACCOUNT_DSN');
if (!is_string($dsn) || $dsn === '') {
    throw new RuntimeException('Isolated account database required');
}
$pdo = new PDO($dsn, 'portable', 'integration-secret', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
$clock = new SystemClock();
$random = new SystemRandomSource();
$migrations = glob(dirname(__DIR__, 2) . '/engine/migrations/*.sql') ?: [];
sort($migrations, SORT_STRING);
$legacy = array_filter($migrations, static fn(string $path): bool => basename($path) !== '0015-account-roles.sql');
$runner = new MigrationRunner($pdo);
$runner->apply(array_values($legacy));
// Actual old contract, not the new bootstrap acting as a legacy fixture.
$oldId = str_repeat('1', 32);
$insert = $pdo->prepare("INSERT INTO cms_users (id, login_name, display_name, password_hash, role, status, created_at, password_changed_at) VALUES (:id, 'owner@example.test', 'Legacy administrator', :hash, 'administrator', 'active', NOW(6), NOW(6))");
$insert->execute(['id' => $oldId, 'hash' => password_hash('legacy owner password', PASSWORD_DEFAULT)]);
$pdo->exec("UPDATE cms_bootstrap_state SET status = 'closed', closed_at = NOW(6) WHERE singleton_id = 1");
$legacyIdentity = new MariaDbIdentityRepository($pdo);
// Pre-migration sessions are upgraded in place as version zero.
$pdo->prepare("INSERT INTO cms_sessions (id, kind, user_id, token_hash, csrf_hash, created_at, last_seen_at, expires_at, absolute_expires_at) VALUES (:id, 'authenticated', :user, :token, :csrf, NOW(6), NOW(6), DATE_ADD(NOW(6), INTERVAL 1 HOUR), DATE_ADD(NOW(6), INTERVAL 1 DAY))")->execute(['id' => str_repeat('a', 32), 'user' => $oldId, 'token' => hash('sha256', str_repeat('t', 43), true), 'csrf' => hash('sha256', str_repeat('c', 43), true)]);
$runner->apply($migrations);
assertSameValue('owner', $pdo->query('SELECT role FROM cms_users')->fetchColumn(), 'existing single administrator becomes owner');
$identity = new IdentityService($legacyIdentity, $clock, $random, str_repeat('x', 32));
assertTrueValue($identity->openSession(str_repeat('t', 43), str_repeat('c', 43))->administrator(), 'existing session retains proper owner authority');
$repository = new MariaDbAccountRepository($pdo);
$accounts = new AccountService($repository, $clock, $random);
$login = static fn(string $email, string $password): SessionContext => $identity->login($identity->openSession(null, null), $email, $password, '192.0.2.1')->session;
$owner = $login('owner@example.test', 'legacy owner password');
$second = $login('owner@example.test', 'legacy owner password');
$adminId = $accounts->create($owner, 'admin@example.test', 'Agency administrator', 'administrator', 'temporary admin password');
$admin = $login('admin@example.test', 'temporary admin password');
accountReject(fn() => $accounts->create($admin, 'bad@example.test', 'Bad', 'editor', 'temporary account password'), 'not_permitted');
$accounts->updateSelf($admin, 1, 'admin@example.test', 'Agency administrator', 'temporary admin password', 'permanent admin password');
$admin = $identity->openSession($admin->sessionToken, $admin->csrfToken);
$editorId = $accounts->create($admin, 'editor@example.test', 'Client editor', 'editor', 'temporary editor password');
$editor = $login('editor@example.test', 'temporary editor password');
$accounts->updateSelf($editor, 1, 'editor@example.test', 'Client editor', 'temporary editor password', 'permanent editor password');
$editor = $identity->openSession($editor->sessionToken, $editor->csrfToken);
accountReject(fn() => $accounts->manage($admin, $adminId, 2, 'disable'), 'not_permitted');
accountReject(fn() => $accounts->manage($admin, $oldId, 1, 'reset', 'forged reset password'), 'not_permitted');
$accounts->updateSelf($owner, 1, 'owner-new@example.test', 'Owner', 'legacy owner password', 'new owner password');
assertTrueValue(!$identity->openSession($second->sessionToken, $second->csrfToken)->authenticated(), 'email/password change revokes other MariaDB sessions');
assertTrueValue($identity->openSession($owner->sessionToken, $owner->csrfToken)->administrator(), 'current session retains authority');

$state = sys_get_temp_dir() . '/portable-cms-accounts-' . bin2hex(random_bytes(6));
mkdir($state, 0700);
mkdir($state . '/public', 0700);
mkdir($state . '/media', 0700);
$configPath = $state . '/cms.json';
$config = ['origin' => 'https://cms.example.test', 'database' => ['dsn' => $dsn, 'username' => 'portable', 'password' => 'integration-secret'], 'throttleSecretBase64' => base64_encode(str_repeat('x', 32)), 'errorLog' => $state . '/error.log', 'mediaRoot' => $state . '/media'];
file_put_contents($configPath, json_encode($config, JSON_THROW_ON_ERROR));
chmod($configPath, 0600);
try {
    $application = ApplicationFactory::identity(RuntimeConfiguration::load($configPath), \Utsuri\PortableCms\Conventional\Content\ProfileManifest::gateZero());
    $cookies = [IdentityService::SESSION_COOKIE => $editor->sessionToken, IdentityService::CSRF_COOKIE => $editor->csrfToken];
    foreach (['/accounts', '/exports', '/settings/terminology', '/settings/publication'] as $path) {
        assertSameValue(403, $application->handle(new Request('GET', $path, cookies: $cookies))->status, 'editor HTTP server denies ' . $path);
    }
    assertSameValue(200, $application->handle(new Request('GET', '/records/portfolio-entry', cookies: $cookies))->status, 'editor can author records');
    $body = $application->handle(new Request('GET', '/', cookies: $cookies))->body;
    assertTrueValue(!str_contains($body, 'href="/exports"') && str_contains($body, 'href="/releases"') && !str_contains($body, 'href="/accounts"'), 'editor chrome advertises read-only releases but no forbidden actions');
    assertSameValue(200, $application->handle(new Request('GET', '/releases', cookies: $cookies))->status, 'editors see release schedules');
    foreach (['approve', 'move_schedule', 'cancel_schedule'] as $action) {
        assertSameValue(403, $application->handle(new Request('POST', '/releases', ['origin' => 'https://cms.example.test'], ['_csrf' => $editor->csrfToken, 'action' => $action], $cookies))->status, 'editor cannot mutate a schedule');
    }
    $forged = ['_csrf' => $editor->csrfToken, 'action' => 'create', 'role' => 'owner', 'login' => 'forged@example.test', 'name' => 'Forged', 'temporary_password' => 'forged owner password'];
    assertSameValue(403, $application->handle(new Request('POST', '/accounts', ['origin' => 'https://cms.example.test'], $forged, $cookies))->status, 'forged create blocked before write');
    $passwordTime = $pdo->prepare('SELECT password_changed_at FROM cms_users WHERE id = :id');
    $passwordTime->execute(['id' => $editorId]);
    $unchangedPasswordTime = $passwordTime->fetchColumn();
    $accounts->manage($admin, $editorId, 2, 'disable');
    $passwordTime->execute(['id' => $editorId]);
    assertSameValue($unchangedPasswordTime, $passwordTime->fetchColumn(), 'access changes do not pretend the password changed');
    assertTrueValue(!$identity->openSession($editor->sessionToken, $editor->csrfToken)->authenticated(), 'deactivation revokes durable sessions');
    accountReject(fn() => $accounts->manage($admin, $editorId, 2, 'enable'), 'changed');
    $accounts->manage($admin, $editorId, 3, 'enable');
    $accounts->manage($admin, $editorId, 4, 'reset', 'reset editor password');
    // Old login snapshot cannot mint an authenticated session after a reset.
    accountReject(fn() => $legacyIdentity->createSession(str_repeat('e', 32), 'authenticated', $editorId, random_bytes(32), random_bytes(32), $clock->now(), $clock->now()->modify('+1 hour'), $clock->now()->modify('+1 day'), $editor->securityVersion), 'not_permitted');
    $owner = $identity->openSession($owner->sessionToken, $owner->csrfToken);
    $accounts->manage($owner, $adminId, 2, 'transfer');
    assertSameValue(1, (int) $pdo->query("SELECT COUNT(*) FROM cms_users WHERE role = 'owner'")->fetchColumn(), 'exactly one owner after transfer');
    assertTrueValue(!$identity->openSession($owner->sessionToken, $owner->csrfToken)->authenticated(), 'old owner logged out');
    $newOwner = $login('admin@example.test', 'permanent admin password');
    accountReject(fn() => $accounts->manage($newOwner, $adminId, 3, 'disable'), 'not_permitted');
    $token = bin2hex(random_bytes(32));
    $gatePath = $state . '/recover-owner.json';
    file_put_contents($gatePath, json_encode(['status' => 'open', 'expiresAt' => $clock->now()->modify('+1 hour')->format(DATE_ATOM), 'tokenHash' => hash('sha256', $token), 'runtimeConfig' => $configPath], JSON_THROW_ON_ERROR));
    chmod($gatePath, 0600);
    accountReject(fn() => OwnerRecovery::execute($gatePath, str_repeat('0', 64), 'recovered owner password', $state . '/public', $clock, $random), 'not_permitted');
    $tokenPath = $state . '/recovery-token';
    $passwordPath = $state . '/recovery-password';
    file_put_contents($tokenPath, $token . "\n");
    file_put_contents($passwordPath, "recovered owner password\n");
    chmod($tokenPath, 0600);
    chmod($passwordPath, 0600);
    $command = [PHP_BINARY, dirname(__DIR__, 2) . '/tools/conventional-recover-owner.php', '--gate=' . $gatePath, '--token-file=' . $tokenPath, '--password-file=' . $passwordPath, '--document-root=' . $state . '/public'];
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    assertTrueValue(is_resource($process), 'actual recovery command started');
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    assertSameValue(0, proc_close($process), 'actual CLI recovery succeeds');
    assertTrueValue(str_contains($output, 'Owner recovery completed') && !str_contains($output, $token) && !str_contains($output, 'recovered owner password'), 'operator output never exposes credentials');
    assertTrueValue(!$identity->openSession($newOwner->sessionToken, $newOwner->csrfToken)->authenticated(), 'operator recovery revoked owner session');
    assertTrueValue($login('admin@example.test', 'recovered owner password')->mustChangePassword, 'operator recovery requires replacement');
    accountReject(fn() => $accounts->recoverOwner('another owner password', hash('sha256', $token)), 'not_permitted');
    $events = $pdo->query("SELECT actor_user_id, event_type, metadata_json FROM cms_audit_events WHERE event_type LIKE 'account_%' OR event_type = 'owner_operator_recovery'")->fetchAll(PDO::FETCH_ASSOC);
    assertTrueValue(count($events) > 10, 'all successful account changes audited');
    assertTrueValue(!str_contains(json_encode($events, JSON_THROW_ON_ERROR), 'password"'), 'audit contains no submitted credential');
    // Full installation backup, unlike normalized content export, must retain credentials.
    $backup = new DatabaseBackup($pdo);
    $before = $backup->fingerprint();
    $backup->capture($state . '/backup');
    $backup->restore($state . '/backup', replace: true);
    assertSameValue($before, $backup->fingerprint(), 'generated owner slot survives exact backup round trip');
    assertSameValue(1, (int) $pdo->query("SELECT COUNT(*) FROM cms_users WHERE role = 'owner'")->fetchColumn(), 'backup retains ownership');
    fwrite(STDOUT, "PASS MariaDB account migration, hierarchy, credential changes, temporary passwords, session revocation, editor HTTP denial, ownership transfer, gated recovery and exact account backup.\n");
} finally {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($state, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($state);
}
