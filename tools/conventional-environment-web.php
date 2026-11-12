<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\HostIo;

require_once dirname(__DIR__) . '/engine/src/cms/conventional/host-io.php';

use Utsuri\PortableCms\Conventional\DatabaseReport;
use Utsuri\PortableCms\Conventional\EnvironmentReport;

require_once dirname(__DIR__) . '/engine/src/cms/conventional/database-report.php';
require_once dirname(__DIR__) . '/engine/src/cms/conventional/environment-report.php';

/** @param array<string, mixed> $body */
function respond(int $status, array $body): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    echo json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    respond(405, ['error' => 'method_not_allowed']);
}

$https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
$loopback = in_array(($_SERVER['REMOTE_ADDR'] ?? ''), ['127.0.0.1', '::1'], true)
    && getenv('CMS_PROBE_ALLOW_INSECURE_LOOPBACK') === 'true';
if (!$loopback && ($https === '' || $https === 'off' || $https === '0') && ($_SERVER['SERVER_PORT'] ?? '') !== '443') {
    respond(404, ['error' => 'not_found']);
}

$configPath = getenv('CMS_PROBE_CONFIG');
$resolvedConfig = is_string($configPath) ? realpath($configPath) : false;
$documentRoot = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
if ($resolvedConfig === false || !is_file($resolvedConfig)) {
    respond(503, ['error' => 'probe_unavailable']);
}
if ($documentRoot !== false && str_starts_with($resolvedConfig, rtrim($documentRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
    respond(503, ['error' => 'unsafe_probe_configuration']);
}
$permissions = HostIo::attempt(fn() => fileperms($resolvedConfig))->value;
if (!is_int($permissions) || ($permissions & 0o077) !== 0) {
    respond(503, ['error' => 'unsafe_probe_configuration']);
}

try {
    $config = json_decode((string) HostIo::attempt(fn() => file_get_contents($resolvedConfig))->value, true, flags: JSON_THROW_ON_ERROR);
} catch (Throwable) {
    respond(503, ['error' => 'probe_unavailable']);
}
if (!is_array($config)) {
    respond(503, ['error' => 'probe_unavailable']);
}

$expiresAt = isset($config['expiresAt']) ? strtotime((string) $config['expiresAt']) : false;
$now = time();
if (!is_int($expiresAt) || $expiresAt < $now || $expiresAt > $now + 86400) {
    respond(404, ['error' => 'not_found']);
}
$token = (string) ($_SERVER['HTTP_X_PROBE_TOKEN'] ?? '');
$tokenHash = (string) ($config['tokenHash'] ?? '');
if ($token === '' || $tokenHash === '' || !password_verify($token, $tokenHash)) {
    respond(404, ['error' => 'not_found']);
}

$paths = is_array($config['paths'] ?? null) ? $config['paths'] : [];
$databaseConfig = is_array($config['database'] ?? null) ? $config['database'] : null;
$database = DatabaseReport::collect($databaseConfig);
$outboundHttpsUrl = is_string($config['outboundHttpsUrl'] ?? null) ? $config['outboundHttpsUrl'] : null;
try {
    $videoQualification = \Utsuri\PortableCms\Conventional\Media\VideoUploadQualification::from($config['videoUploads'] ?? null);
} catch (InvalidArgumentException) {
    respond(503, ['error' => 'invalid_video_qualification']);
}
$report = EnvironmentReport::collect($paths, $now, $_SERVER, $database, $outboundHttpsUrl, $videoQualification);
respond($report['status'] === 'compatible' ? 200 : 422, $report);
