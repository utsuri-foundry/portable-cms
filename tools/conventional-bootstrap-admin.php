<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\HostIo;
use Utsuri\PortableCms\Conventional\Identity\AdministratorBootstrap;
use Utsuri\PortableCms\Conventional\Identity\SystemClock;
use Utsuri\PortableCms\Conventional\Identity\SystemRandomSource;
use Utsuri\PortableCms\Conventional\RuntimeConfiguration;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/engine/src/cms/conventional/bootstrap.php';

$options = getopt('', ['config:', 'login:', 'display-name:', 'password-file::', 'help']);
if (isset($options['help'])) {
    fwrite(STDOUT, <<<'HELP'
Usage:
  php tools/conventional-bootstrap-admin.php \
    --config=/absolute/private/cms.json \
    --login=admin@example.test \
    --display-name="Named Administrator" \
    [--password-file=/absolute/private/password]

Without --password-file, the password is read twice from the controlling
terminal without echo. A password file must be owner-only and beside the CMS
configuration. The command refuses to run after any CMS user exists.
HELP);
    fwrite(STDOUT, "\n");
    exit(0);
}
$configPath = is_string($options['config'] ?? null) ? $options['config'] : '';
$login = is_string($options['login'] ?? null) ? $options['login'] : '';
$displayName = is_string($options['display-name'] ?? null) ? $options['display-name'] : '';
if ($configPath === '' || $login === '' || $displayName === '') {
    fwrite(STDERR, "Configuration, login, and display name are required.\n");
    exit(64);
}
/** @return string */
function readHiddenPassword(string $prompt): string
{
    fwrite(STDERR, $prompt);
    shell_exec('stty -echo');
    try {
        $value = fgets(STDIN);
    } finally {
        shell_exec('stty echo');
        fwrite(STDERR, "\n");
    }
    return is_string($value) ? rtrim($value, "\r\n") : '';
}

$passwordFile = is_string($options['password-file'] ?? null) ? $options['password-file'] : '';
if ($passwordFile !== '') {
    $resolvedPassword = realpath($passwordFile);
    $resolvedConfig = realpath($configPath);
    $permissions = $resolvedPassword !== false ? HostIo::attempt(fn() => fileperms($resolvedPassword))->value : false;
    if (
        $resolvedPassword === false
        || $resolvedConfig === false
        || dirname($resolvedPassword) !== dirname($resolvedConfig)
        || !is_int($permissions)
        || ($permissions & 0o077) !== 0
    ) {
        fwrite(STDERR, "The password file must be owner-only and beside the private configuration.\n");
        exit(64);
    }
    $password = HostIo::attempt(fn() => file_get_contents($resolvedPassword, false, null, 0, 1_025))->value;
    if (!is_string($password) || strlen($password) > 1_024) {
        fwrite(STDERR, "The password file is invalid.\n");
        exit(64);
    }
    $password = rtrim($password, "\r\n");
    $confirmation = $password;
} else {
    $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
    if (!function_exists('stream_isatty') || !stream_isatty(STDIN) || in_array('shell_exec', $disabled, true)) {
        fwrite(STDERR, "Use an owner-only --password-file when hidden terminal input is unavailable.\n");
        exit(64);
    }
    $password = readHiddenPassword('Password: ');
    $confirmation = readHiddenPassword('Repeat password: ');
    if (!hash_equals($password, $confirmation)) {
        fwrite(STDERR, "Passwords do not match.\n");
        exit(65);
    }
}

try {
    $configuration = RuntimeConfiguration::load($configPath);
    $bootstrap = new AdministratorBootstrap(
        $configuration->connect(),
        new SystemClock(),
        new SystemRandomSource(),
    );
    $bootstrap->create($login, $displayName, $password);
    fwrite(STDOUT, "The first named administrator was created and bootstrap is now closed.\n");
} catch (Throwable $error) {
    fwrite(STDERR, 'Administrator bootstrap failed (' . $error::class . ").\n");
    exit(1);
} finally {
    $password = '';
    $confirmation = '';
}
