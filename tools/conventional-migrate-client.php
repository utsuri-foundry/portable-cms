<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\HostIo;

require_once dirname(__DIR__) . '/engine/src/cms/conventional/host-io.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$options = getopt('', ['endpoint:', 'token-file:', 'help']);
if (isset($options['help'])) {
    fwrite(STDOUT, <<<'HELP'
Usage:
  php tools/conventional-migrate-client.php \
    --endpoint=https://cms.example.test/migrate.php \
    --token-file=/private/local/migration-token
HELP);
    fwrite(STDOUT, "\n");
    exit(0);
}

try {
    $endpoint = is_string($options['endpoint'] ?? null) ? $options['endpoint'] : '';
    $parts = parse_url($endpoint);
    if (!is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || !isset($parts['host'])) {
        throw new RuntimeException('Migration endpoint must use HTTPS');
    }
    $tokenPath = realpath((string) ($options['token-file'] ?? ''));
    $permissions = $tokenPath !== false ? HostIo::attempt(fn() => fileperms($tokenPath))->value : false;
    if ($tokenPath === false || !is_int($permissions) || ($permissions & 0o077) !== 0) {
        throw new RuntimeException('Token file must exist with owner-only permissions');
    }
    $token = rtrim((string) HostIo::attempt(fn() => file_get_contents($tokenPath, false, null, 0, 67))->value, "\r\n");
    if (preg_match('/\A[a-f0-9]{64}\z/', $token) !== 1) {
        throw new RuntimeException('Token file is invalid');
    }
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => 'X-CMS-Migration-Token: ' . $token,
            'content' => '',
            'ignore_errors' => true,
            'follow_location' => 0,
            'timeout' => 30,
        ],
    ]);
    $headers = [];
    $body = HostIo::attempt(static function () use ($endpoint, $context, &$headers) {
        $http_response_header = [];
        $body = file_get_contents($endpoint, false, $context, 0, 4_097);
        $headers = $http_response_header;
        return $body;
    })->value;
    $status = isset($headers[0]) && preg_match('/\s(\d{3})\s/', $headers[0], $matches) === 1
        ? (int) $matches[1]
        : 0;
    $decoded = is_string($body) && strlen($body) <= 4_096 ? json_decode($body, true) : null;
    if ($status !== 200 || !is_array($decoded) || ($decoded['status'] ?? null) !== 'migrated') {
        throw new RuntimeException('Remote migration endpoint returned status ' . $status);
    }
    $applied = filter_var($decoded['migrationsApplied'] ?? null, FILTER_VALIDATE_INT);
    $skipped = filter_var($decoded['migrationsSkipped'] ?? null, FILTER_VALIDATE_INT);
    if (!is_int($applied) || !is_int($skipped) || $applied < 0 || $skipped < 0) {
        throw new RuntimeException('Remote migration response is invalid');
    }
    fwrite(STDOUT, sprintf(
        "Remote migrations complete: %d applied; %d already complete.\n",
        $applied,
        $skipped,
    ));
} catch (Throwable $error) {
    fwrite(STDERR, 'Migration client failed: ' . $error->getMessage() . "\n");
    exit(1);
} finally {
    $token = '';
}
