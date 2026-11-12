<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\HostIo;

require_once dirname(__DIR__) . '/engine/src/cms/conventional/host-io.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$options = getopt('', [
    'endpoint:',
    'token-file:',
    'password-file:',
    'login:',
    'display-name:',
    'help',
]);
if (isset($options['help'])) {
    fwrite(STDOUT, <<<'HELP'
Usage:
  php tools/conventional-install-client.php \
    --endpoint=https://cms.example.test/install.php \
    --token-file=/private/local/install-token \
    --password-file=/private/local/admin-password \
    --login=admin@example.test \
    --display-name='Named Administrator'
HELP);
    fwrite(STDOUT, "\n");
    exit(0);
}

/** @return string */
function privateFile(string $path, int $maximumBytes): string
{
    $resolved = realpath($path);
    $permissions = $resolved !== false ? HostIo::attempt(fn() => fileperms($resolved))->value : false;
    if ($resolved === false || !is_int($permissions) || ($permissions & 0o077) !== 0) {
        throw new RuntimeException('Credential file must exist with owner-only permissions');
    }
    $value = HostIo::attempt(fn() => file_get_contents($resolved, false, null, 0, $maximumBytes + 3))->value;
    if (!is_string($value)) {
        throw new RuntimeException('Credential file is invalid');
    }
    $value = rtrim($value, "\r\n");
    if ($value === '' || strlen($value) > $maximumBytes || str_contains($value, "\n") || str_contains($value, "\r")) {
        throw new RuntimeException('Credential file is invalid');
    }
    return $value;
}

try {
    $endpoint = is_string($options['endpoint'] ?? null) ? $options['endpoint'] : '';
    $parts = parse_url($endpoint);
    if (!is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || !isset($parts['host'])) {
        throw new RuntimeException('Installer endpoint must use HTTPS');
    }
    $token = privateFile((string) ($options['token-file'] ?? ''), 64);
    $password = privateFile((string) ($options['password-file'] ?? ''), 1_024);
    $login = is_string($options['login'] ?? null) ? $options['login'] : '';
    $displayName = is_string($options['display-name'] ?? null) ? $options['display-name'] : '';
    if (filter_var($login, FILTER_VALIDATE_EMAIL) === false) {
        throw new RuntimeException('Administrator login must be a valid email address');
    }
    if ($displayName === '' || mb_strlen($displayName, 'UTF-8') > 160) {
        throw new RuntimeException('Administrator display name must contain 1 to 160 characters');
    }
    if (strlen($password) < 14) {
        throw new RuntimeException('Administrator password must contain at least 14 bytes');
    }
    $payload = http_build_query([
        'login' => $login,
        'display_name' => $displayName,
        'password' => $password,
    ], '', '&', PHP_QUERY_RFC3986);
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", [
                'Content-Type: application/x-www-form-urlencoded',
                'Content-Length: ' . strlen($payload),
                'X-CMS-Install-Token: ' . $token,
            ]),
            'content' => $payload,
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
    if ($status === 422) {
        throw new RuntimeException('Server rejected the administrator email, name, or password');
    }
    if ($status !== 200 || !is_array($decoded) || ($decoded['status'] ?? null) !== 'installed') {
        throw new RuntimeException('Remote installer returned status ' . $status);
    }
    fwrite(STDOUT, "The remote CMS identity database and first administrator were installed.\n");
} catch (Throwable $error) {
    fwrite(STDERR, 'Installation client failed: ' . $error->getMessage() . "\n");
    exit(1);
} finally {
    $token = '';
    $password = '';
    $payload = '';
}
