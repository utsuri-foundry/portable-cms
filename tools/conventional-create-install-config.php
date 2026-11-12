<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Database\TablePrefix;
use Utsuri\PortableCms\Conventional\HostIo;
use Utsuri\PortableCms\Conventional\RuntimeConfiguration;

require_once dirname(__DIR__) . '/engine/src/cms/conventional/host-io.php';
require_once dirname(__DIR__) . '/engine/src/cms/conventional/database/table-prefix.php';
require_once dirname(__DIR__) . '/engine/src/cms/conventional/runtime-configuration.php';
require_once dirname(__DIR__) . '/engine/src/cms/conventional/publication/release-time.php';
require_once dirname(__DIR__) . '/engine/src/cms/conventional/compiler/site-contract.php';
require_once dirname(__DIR__) . '/engine/src/cms/conventional/compiler/public-url-context.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$options = getopt('', [
    'runtime-output:',
    'runtime-config-path:',
    'gate-output:',
    'token-output:',
    'origin:',
    'dsn:',
    'username:',
    'database-password-file:',
    'table-prefix:',
    'upgrade-mode:',
    'database-maximum-bytes:',
    'profile-root:',
    'public-base-url:',
    'publication-root:',
    'time-zone:',
    'error-log:',
    'help',
]);
if (isset($options['help'])) {
    fwrite(STDOUT, <<<'HELP'
Usage:
  php tools/conventional-create-install-config.php \
    --runtime-output=/new/private/cms.json \
    --runtime-config-path=/absolute/remote/private/cms.json \
    --gate-output=/new/private/install.json \
    --token-output=/new/private/install-token \
    --origin=https://cms.example.test \
    --dsn='mysql:host=db.example;dbname=cms;charset=utf8mb4' \
    --username=cms \
    --database-password-file=/private/local/database-password \
    --error-log=/absolute/remote/private/cms-errors.log \
    [--table-prefix=site_] \
    [--upgrade-mode=table_set|in_place|separate_database] \
    [--database-maximum-bytes=N] \
    [--profile-root=/absolute/remote/private/profile] \
    [--public-base-url=https://www.example.test] \
    [--publication-root=/absolute/remote/public-website] \
    [--time-zone=Europe/Andorra]

The optional table prefix (default cms_) lets this installation share a
database with other table sets. The upgrade mode defaults to table_set; choose
in_place for maintenance with backup restore, or separate_database for an empty
standby database. The optional quota is the host's positive whole-byte limit.
Omitting both settings preserves the runtime defaults without writing them.
The profile and publication paths name absolute remote locations; they are not
resolved locally. The public HTTPS address must have a different origin from
the CMS and no credentials, query or fragment. Supply all three settings for a
packaged profile that publishes a website. Omitting them keeps the installation
without a packaged profile or publication.
Every output is created owner-only. The database password file must already be
owner-only. No secret is accepted as a command argument or printed.
HELP);
    fwrite(STDOUT, "\n");
    exit(0);
}

/**
 * @param array<string, mixed> $options
 * @return string
 */
function requiredOption(array $options, string $name): string
{
    $value = $options[$name] ?? null;
    if (!is_string($value) || $value === '') {
        throw new RuntimeException('A required option is missing');
    }
    return $value;
}

$created = [];
try {
    // getopt omits empty or missing required values; they must not become defaults.
    foreach (['upgrade-mode', 'database-maximum-bytes', 'profile-root', 'public-base-url', 'publication-root', 'time-zone'] as $name) {
        if (in_array('--' . $name . '=', $argv, true)
            || (in_array('--' . $name, $argv, true) && !array_key_exists($name, $options))) {
            throw new RuntimeException('Installation option requires a value');
        }
    }
    $upgradeMode = RuntimeConfiguration::upgradeMode($options['upgrade-mode'] ?? RuntimeConfiguration::UPGRADE_TABLE_SET);
    $maximumBytes = array_key_exists('database-maximum-bytes', $options)
        ? RuntimeConfiguration::maximumBytes(filter_var(requiredOption($options, 'database-maximum-bytes'), FILTER_VALIDATE_INT))
        : null;
    $origin = requiredOption($options, 'origin');
    $timeZone = RuntimeConfiguration::timeZone($options['time-zone'] ?? 'UTC');
    $profileRoot = RuntimeConfiguration::profileRoot(array_key_exists('profile-root', $options) ? requiredOption($options, 'profile-root') : null);
    $publicationRoot = RuntimeConfiguration::publicationRoot(array_key_exists('publication-root', $options) ? requiredOption($options, 'publication-root') : null);
    $publicUrlContext = RuntimeConfiguration::publicUrlContext(array_key_exists('public-base-url', $options)
        ? ['formatVersion' => 1, 'baseUrl' => requiredOption($options, 'public-base-url')] : null, $origin);
    $runtimeOutput = requiredOption($options, 'runtime-output');
    $runtimeConfigPath = requiredOption($options, 'runtime-config-path');
    $gateOutput = requiredOption($options, 'gate-output');
    $tokenOutput = requiredOption($options, 'token-output');
    foreach ([$runtimeOutput, $gateOutput, $tokenOutput] as $path) {
        if (file_exists($path) || !is_dir(dirname($path))) {
            throw new RuntimeException('Every output must be new inside an existing directory');
        }
    }
    $passwordPath = realpath(requiredOption($options, 'database-password-file'));
    $permissions = $passwordPath !== false ? HostIo::attempt(fn() => fileperms($passwordPath))->value : false;
    if ($passwordPath === false || !is_int($permissions) || ($permissions & 0o077) !== 0) {
        throw new RuntimeException('Database password file must be owner-only');
    }
    $databasePassword = HostIo::attempt(fn() => file_get_contents($passwordPath, false, null, 0, 1_025))->value;
    if (!is_string($databasePassword) || strlen($databasePassword) > 1_024) {
        throw new RuntimeException('Database password file is invalid');
    }
    $databasePassword = rtrim($databasePassword, "\r\n");
    $token = bin2hex(random_bytes(32));
    $originParts = parse_url($origin);
    $dsn = requiredOption($options, 'dsn');
    $errorLog = requiredOption($options, 'error-log');
    if (!is_array($originParts) || ($originParts['scheme'] ?? null) !== 'https' || !isset($originParts['host'])) {
        throw new RuntimeException('CMS origin must use HTTPS');
    }
    if (!str_starts_with($dsn, 'mysql:') || !str_contains(strtolower($dsn), 'charset=utf8mb4')) {
        throw new RuntimeException('Database DSN must select MariaDB/MySQL with utf8mb4');
    }
    if (!str_starts_with($errorLog, DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('Remote error log path must be absolute');
    }
    $runtime = [
        'origin' => $origin,
        'database' => [
            'dsn' => $dsn,
            'username' => requiredOption($options, 'username'),
            'password' => $databasePassword,
            ...(isset($options['table-prefix']) ? ['tablePrefix' => (new TablePrefix(requiredOption($options, 'table-prefix')))->value] : []),
            ...(array_key_exists('upgrade-mode', $options) ? ['upgradeMode' => $upgradeMode] : []),
            ...($maximumBytes !== null ? ['maximumBytes' => $maximumBytes] : []),
        ],
        'storage' => [
            'mediaRoot' => dirname($runtimeConfigPath) . '/media',
        ],
        'throttleSecretBase64' => base64_encode(random_bytes(32)),
        'mediaWakeStrategy' => 'post_response',
        ...(array_key_exists('time-zone', $options) ? ['timeZone' => $timeZone] : []),
        'errorLog' => $errorLog,
        ...($profileRoot !== null ? ['profileRoot' => $profileRoot] : []),
        ...($publicUrlContext !== null ? ['publicUrlContext' => $publicUrlContext->document()] : []),
        ...($publicationRoot !== null ? ['publicationRoot' => $publicationRoot] : []),
    ];
    $gate = [
        'status' => 'open',
        'expiresAt' => gmdate('Y-m-d\TH:i:s\Z', time() + 3_600),
        'tokenHash' => hash('sha256', $token),
        'runtimeConfig' => $runtimeConfigPath,
    ];
    foreach ([
        $runtimeOutput => json_encode($runtime, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
        $gateOutput => json_encode($gate, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
        $tokenOutput => $token . "\n",
    ] as $path => $contents) {
        HostIo::createPrivateFile($path, $contents);
        $created[] = $path;
    }
    fwrite(STDOUT, "Created owner-only runtime configuration and one-hour installation gate.\n");
    fwrite(STDOUT, 'Upgrade mode: ' . $upgradeMode . (array_key_exists('upgrade-mode', $options) ? '.' : ' (runtime default).') . "\n");
    fwrite(STDOUT, $profileRoot !== null ? "Packaged profile configured.\n" : "Installation will not use a packaged profile.\n");
    fwrite(STDOUT, $publicUrlContext !== null && $publicationRoot !== null ? "Publication configured; host layout must be qualified.\n" : "Installation will not publish; public base URL and publication root are both required.\n");
} catch (Throwable $error) {
    foreach ($created as $path) {
        HostIo::cleanup(fn() => unlink($path));
    }
    fwrite(STDERR, 'Installation configuration failed: ' . $error->getMessage() . "\n");
    exit(1);
} finally {
    $databasePassword = '';
    $token = '';
}
