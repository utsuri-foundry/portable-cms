<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\HostIo;

require_once dirname(__DIR__) . '/engine/src/cms/conventional/host-io.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$options = getopt('', [
    'gate-output:',
    'token-output:',
    'runtime-config-path:',
    'help',
]);
if (isset($options['help'])) {
    fwrite(STDOUT, <<<'HELP'
Usage:
  php tools/conventional-create-migration-gate.php \
    --gate-output=/new/private/migrate.json \
    --token-output=/new/private/migration-token \
    --runtime-config-path=/absolute/remote/private/cms.json

Both outputs are created owner-only. The gate expires after one hour and
stores only the SHA-256 digest of the random operator token.
HELP);
    fwrite(STDOUT, "\n");
    exit(0);
}

/**
 * @param array<string, mixed> $options
 * @return string
 */
function migrationGateOption(array $options, string $name): string
{
    $value = $options[$name] ?? null;
    if (!is_string($value) || $value === '') {
        throw new RuntimeException('A required option is missing');
    }
    return $value;
}

$created = [];
try {
    $gateOutput = migrationGateOption($options, 'gate-output');
    $tokenOutput = migrationGateOption($options, 'token-output');
    $runtimeConfigPath = migrationGateOption($options, 'runtime-config-path');
    if (!str_starts_with($runtimeConfigPath, DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('Remote runtime configuration path must be absolute');
    }
    foreach ([$gateOutput, $tokenOutput] as $path) {
        if (file_exists($path) || !is_dir(dirname($path))) {
            throw new RuntimeException('Every output must be new inside an existing directory');
        }
    }
    $token = bin2hex(random_bytes(32));
    $gate = [
        'status' => 'open',
        'expiresAt' => gmdate('Y-m-d\TH:i:s\Z', time() + 3_600),
        'tokenHash' => hash('sha256', $token),
        'runtimeConfig' => $runtimeConfigPath,
    ];
    foreach ([
        $gateOutput => json_encode($gate, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
        $tokenOutput => $token . "\n",
    ] as $path => $contents) {
        HostIo::createPrivateFile($path, $contents);
        $created[] = $path;
    }
    fwrite(STDOUT, "Created an owner-only one-hour migration gate.\n");
} catch (Throwable $error) {
    foreach ($created as $path) {
        HostIo::cleanup(fn() => unlink($path));
    }
    fwrite(STDERR, 'Migration gate creation failed: ' . $error->getMessage() . "\n");
    exit(1);
} finally {
    $token = '';
}
