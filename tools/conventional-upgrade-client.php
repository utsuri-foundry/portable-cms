<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\HostIo;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/engine/src/cms/conventional/host-io.php';
$options = getopt('', ['endpoint:', 'token-file:', 'help']);
if (isset($options['help'])) {
    fwrite(STDOUT, "Usage: php tools/conventional-upgrade-client.php --endpoint=https://cms.example.test/upgrade.php --token-file=/private/upgrade-token\n");
    exit(0);
}
try {
    $endpoint = is_string($options['endpoint'] ?? null) ? $options['endpoint'] : '';
    $parts = parse_url($endpoint);
    if (!is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
        throw new RuntimeException('Upgrade endpoint must use HTTPS without credentials');
    }
    $path = is_string($options['token-file'] ?? null) ? realpath($options['token-file']) : false;
    $permissions = $path !== false ? HostIo::attempt(fn() => fileperms($path))->value : false;
    if ($path === false || !is_file($path) || !is_int($permissions) || ($permissions & 0o077) !== 0) {
        throw new RuntimeException('An owner-only token file is required');
    }
    $token = trim((string) HostIo::attempt(fn() => file_get_contents($path, false, null, 0, 67))->value);
    if (preg_match('/\A[a-f0-9]{64}\z/', $token) !== 1) {
        throw new RuntimeException('Upgrade token is invalid');
    }
    $context = stream_context_create(['http' => ['method' => 'POST', 'header' => 'X-CMS-Upgrade-Token: ' . $token, 'content' => '', 'follow_location' => 0, 'ignore_errors' => true, 'timeout' => 300], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
    $headers = [];
    $body = HostIo::attempt(static function () use ($endpoint, $context, &$headers) {
        $http_response_header = [];
        $body = file_get_contents($endpoint, false, $context, 0, 131_073);
        $headers = $http_response_header;
        return $body;
    })->value;
    $status = isset($headers[0]) && preg_match('/\s(\d{3})\s/', $headers[0], $matches) === 1 ? (int) $matches[1] : 0;
    if (!is_string($body) || strlen($body) > 131_072 || !in_array($status, [200, 422], true)) {
        throw new RuntimeException('Upgrade endpoint failed; inspect the private journal and recover before retrying');
    }
    $decoded = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($decoded) || isset($decoded['error'])) {
        throw new RuntimeException('Upgrade response is invalid');
    }
    fwrite(STDOUT, json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    exit($status === 422 ? 2 : 0);
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
} finally {
    $token = '';
}
