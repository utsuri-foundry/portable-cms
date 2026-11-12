<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\HostIo;

require_once dirname(__DIR__) . '/engine/src/cms/conventional/host-io.php';

use Utsuri\PortableCms\Conventional\DatabaseReport;
use Utsuri\PortableCms\Conventional\EnvironmentComparison;
use Utsuri\PortableCms\Conventional\EnvironmentReport;

require_once dirname(__DIR__) . '/engine/src/cms/conventional/database-report.php';
require_once dirname(__DIR__) . '/engine/src/cms/conventional/host-io.php';
require_once dirname(__DIR__) . '/engine/src/cms/conventional/environment-comparison.php';
require_once dirname(__DIR__) . '/engine/src/cms/conventional/environment-report.php';

$options = getopt('', [
    'private-storage:',
    'publication:',
    'scratch:',
    'document-root:',
    'cron-heartbeat::',
    'compare-web::',
    'video-qualification:',
    'help',
]);

if (isset($options['help'])) {
    fwrite(STDOUT, <<<'HELP'
Usage:
  php tools/conventional-environment-check.php \
    --private-storage=/absolute/private/storage \
    --publication=/absolute/publication/root \
    --scratch=/absolute/private/scratch \
    --document-root=/absolute/publication/root \
    [--cron-heartbeat=/absolute/private/cron-heartbeat] \
    [--compare-web=/path/to/downloaded-web-report.json] \
    [--video-qualification=/absolute/private/video-qualification.json]

Database credentials are read only from CMS_PROBE_DB_DSN,
CMS_PROBE_DB_USERNAME, and CMS_PROBE_DB_PASSWORD. The JSON report inspects
configured paths but never prints them. With --compare-web, output is a
versioned comparison containing both redacted reports. Set
CMS_PROBE_OUTBOUND_HTTPS_URL to an operator-controlled HTTPS health endpoint
that returns a 2xx or 3xx response without authentication.
HELP);
    fwrite(STDOUT, "\n");
    exit(0);
}

$videoQualification = null;
if (isset($options['video-qualification'])) {
    try {
        $path = $options['video-qualification'];
        $permissions = is_string($path) ? HostIo::attempt(fn() => fileperms($path))->value : false;
        if (!is_int($permissions) || ($permissions & 0o077) !== 0) {
            throw new RuntimeException('Unsafe qualification file');
        }
        $value = HostIo::attempt(fn() => file_get_contents($path))->value;
        $videoQualification = \Utsuri\PortableCms\Conventional\Media\VideoUploadQualification::from(json_decode((string) $value, true, flags: JSON_THROW_ON_ERROR));
    } catch (Throwable) {
        fwrite(STDERR, "Could not read a valid private video qualification.\n");
        exit(64);
    }
}

$database = DatabaseReport::collect([
    'dsn' => getenv('CMS_PROBE_DB_DSN') ?: null,
    'username' => getenv('CMS_PROBE_DB_USERNAME') ?: null,
    'password' => getenv('CMS_PROBE_DB_PASSWORD') ?: null,
]);
$report = EnvironmentReport::collect([
    'privateStorage' => is_string($options['private-storage'] ?? null) ? $options['private-storage'] : null,
    'publication' => is_string($options['publication'] ?? null) ? $options['publication'] : null,
    'scratch' => is_string($options['scratch'] ?? null) ? $options['scratch'] : null,
    'documentRoot' => is_string($options['document-root'] ?? null) ? $options['document-root'] : null,
    'cronHeartbeat' => is_string($options['cron-heartbeat'] ?? null) ? $options['cron-heartbeat'] : null,
], database: $database, outboundHttpsUrl: getenv('CMS_PROBE_OUTBOUND_HTTPS_URL') ?: null, videoQualification: $videoQualification);

$comparePath = $options['compare-web'] ?? null;
if (is_string($comparePath) && $comparePath !== '') {
    $webJson = HostIo::attempt(fn() => file_get_contents($comparePath))->value;
    if (!is_string($webJson)) {
        fwrite(STDERR, "Could not read the web report.\n");
        exit(64);
    }
    try {
        $webReport = json_decode($webJson, true, flags: JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        fwrite(STDERR, "The web report is not valid JSON.\n");
        exit(65);
    }
    if (!is_array($webReport)) {
        fwrite(STDERR, "The web report does not contain an object.\n");
        exit(65);
    }
    $report = EnvironmentComparison::compare($webReport, $report);
}

$encoded = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
fwrite(STDOUT, $encoded . "\n");
exit($report['status'] === 'compatible' ? 0 : 2);
