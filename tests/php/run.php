<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\DatabaseReport;
use Utsuri\PortableCms\Conventional\EnvironmentComparison;
use Utsuri\PortableCms\Conventional\EnvironmentReport;

$composerAutoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
if (!is_file($composerAutoload)) {
    fwrite(STDERR, "PHP dependencies are missing. Run composer install.\n");
    exit(1);
}
require_once $composerAutoload;
require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/bootstrap.php';

require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/database-report.php';
require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/environment-comparison.php';
require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/environment-report.php';
require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/http/request.php';
require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/http/uploaded-file.php';
require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/http/response.php';
require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/http/security.php';
require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/text-normalizer.php';
require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/schema-validator.php';
require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/identity/identity-repository.php';
require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/identity/identity-service.php';
require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/content/profile.php';
require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/content/profile-package.php';
require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/content/record-repository.php';
require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/content/record-browser.php';
require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/content/editorial-controls.php';
require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/content/editorial-record-http.php';
require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/content/content-service.php';
require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/content/editorial-form.php';
require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/content/composition.php';
require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/content/composition-form.php';
require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/content/composition-preview.php';
require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/content/rich-text.php';
require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/content/rich-text-form.php';
require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/content/editorial-terminology.php';
require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/content/terminology-http.php';
require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/compiler/site-contract.php';
require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/compiler/frozen-snapshot.php';
require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/compiler/filesystem-media-source.php';
require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/compiler/static-site-compiler.php';
require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/media/media-contract.php';
require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/media/media-processing-contract.php';
require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/media/media-library.php';
require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/media/media-library-http.php';
require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/media/native-upload-http.php';
require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/media/upload-repository.php';
require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/media/filesystem-upload-storage.php';
require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/media/media-upload-service.php';
require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/media/filesystem-media-store.php';
require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/media/imagick-raster-processor.php';
require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/media/maria-db-media-job-repository.php';
require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/media/media-job-runner.php';
require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/preview/preview-contract.php';
require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/preview/preview-repository.php';
require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/preview/preview-cleanup-contract.php';
require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/preview/preview-cleanup-files.php';
require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/preview/preview-deletion.php';
require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/preview/preview-cleanup-service.php';
require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/preview/preview-cleanup-http.php';
require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/view/preview-cleanup-page.php';
require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/preview/maria-db-preview-repository.php';
require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/preview/gate-zero-preview-compiler.php';
require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/preview/filesystem-preview-store.php';
require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/preview/preview-job-runner.php';
require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/export/normalized-export-contract.php';
require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/export/export-repository.php';
require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/export/maria-db-export-repository.php';
require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/view/admin-shell.php';
require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/view/editorial-record-layout.php';
require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/view/terminology-page.php';
require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/view/pages.php';
require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/view/reference-controls.php';
require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/view/group-controls.php';
require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/view/field-hint.php';
require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/view/group-rows.php';
require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/view/media-library-page.php';
require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/view/editorial-value-summary.php';
require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/view/rich-text-editor.php';
require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/view/composition-view.php';
require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/view/composition-assets.php';
require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/view/composition-editor.php';
require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/runtime-configuration.php';
require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/installation/installation-service.php';
require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/identity-application.php';
require_once __DIR__ . '/assertions.php';

/**
 * @param list<string> $headers
 * @return array{status: int, body: string}
 */
function httpRequest(string $url, string $method = 'GET', array $headers = [], int $timeoutSeconds = 2): array
{
    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'ignore_errors' => true,
            'timeout' => $timeoutSeconds,
        ],
    ]);
    $http_response_header = [];
    $body = @file_get_contents($url, false, $context);
    $responseHeaders = $http_response_header;
    $status = isset($responseHeaders[0]) && preg_match('/\s(\d{3})\s/', $responseHeaders[0], $matches)
        ? (int) $matches[1]
        : 0;
    return ['status' => $status, 'body' => is_string($body) ? $body : ''];
}

/** @return array<string, bool> */
function filesystemOperations(): array
{
    return [
        'attempted' => true,
        'fileWrite' => true,
        'fileRename' => true,
        'fileReplace' => true,
        'directoryRename' => true,
        'symlink' => true,
        'cleanupComplete' => true,
    ];
}

/** @return array<string, mixed> */
function compatibleDatabase(): array
{
    return [
        'configured' => true,
        'connected' => true,
        'errorCode' => null,
        'engine' => 'mariadb',
        'serverVersion' => '10.11.8-MariaDB',
        'versionComment' => 'MariaDB Server',
        'sqlModes' => ['ERROR_FOR_DIVISION_BY_ZERO', 'STRICT_TRANS_TABLES'],
        'serverCharacterSet' => 'utf8mb3',
        'serverCollation' => 'utf8mb3_general_ci',
        'databaseCharacterSet' => 'utf8mb4',
        'databaseCollation' => 'utf8mb4_unicode_ci',
        'connectionCharacterSet' => 'utf8mb4',
        'connectionCollation' => 'utf8mb4_unicode_ci',
        'transactionIsolation' => 'REPEATABLE-READ',
        'autocommit' => true,
        'transactionProbe' => [
            'temporaryTable' => true,
            'rollback' => true,
            'compareAndSet' => true,
            'cleanupComplete' => true,
        ],
    ];
}

/** @return array<string, mixed> */
function compatibleSnapshot(): array
{
    $extensions = [];
    foreach (['curl', 'fileinfo', 'hash', 'intl', 'json', 'mbstring', 'openssl', 'pdo', 'pdo_mysql'] as $name) {
        $extensions[$name] = ['loaded' => true, 'version' => 'test'];
    }
    $directory = [
        'configured' => true,
        'directory' => true,
        'writable' => true,
        'freeBytes' => 1_000_000,
        'operations' => filesystemOperations(),
    ];
    $privateDirectory = $directory;
    $privateDirectory['protection'] = [
        'documentRootKnown' => true,
        'outsideDocumentRoot' => true,
        'denyRuleVerified' => false,
    ];
    return [
        'generatedAt' => '2026-09-29T12:00:00Z',
        'runtime' => [
            'phpVersion' => '8.2.0',
            'sapi' => 'fpm-fcgi',
            'integerBytes' => 8,
            'iniFileLoaded' => true,
            'iniScanFileCount' => 1,
        ],
        'extensions' => $extensions,
        'limits' => ['fileUploads' => true, 'memoryLimitBytes' => 256 * 1024 * 1024],
        'configuration' => [
            'disabledFunctions' => [],
            'openBasedirConfigured' => false,
            'openBasedirEntryCount' => 0,
            'session' => ['saveHandler' => 'files'],
        ],
        'storage' => [
            'privateStorage' => $privateDirectory,
            'publication' => $directory,
            'scratch' => $directory,
        ],
        'scheduler' => [
            'heartbeat' => ['configured' => true, 'observed' => true, 'ageSeconds' => 60, 'fresh' => true],
            'postResponseFunction' => true,
        ],
        'media' => [
            'selectedEngine' => 'imagick',
            'fidelity' => 'full',
            'engines' => [
                'imagick' => [
                    'loaded' => true,
                    'version' => 'ImageMagick test',
                    'formats' => ['avif' => true, 'jpeg' => true, 'png' => true, 'webp' => true],
                    'operations' => [
                        'decodePng' => true,
                        'encodeAvif' => true,
                        'encodeJpeg' => true,
                        'encodePng' => true,
                        'encodeWebp' => true,
                    ],
                    'resourceLimits' => ['memory' => 268_435_456],
                ],
                'gd' => [
                    'loaded' => false,
                    'version' => null,
                    'adapterStatus' => 'not_implemented',
                    'reportedFormats' => ['avif' => false, 'jpeg' => false, 'png' => false, 'webp' => false],
                ],
            ],
        ],
        'transport' => [
            'https' => 'on',
            'curlCaBundleConfigured' => true,
            'outboundHttps' => ['configured' => true, 'verified' => true, 'statusCode' => 204, 'errorCode' => null],
        ],
        'database' => compatibleDatabase(),
    ];
}

$tests = [];

$tests['parses PHP byte-size settings without guessing invalid values'] = static function (): void {
    assertSameValue(2 * 1024 * 1024, EnvironmentReport::parseIniBytes('2M'), '2M should parse');
    assertSameValue(null, EnvironmentReport::parseIniBytes('1.5K'), 'PHP does not accept fractional shorthand');
    assertSameValue(null, EnvironmentReport::parseIniBytes('-1'), 'unlimited should stay explicit');
    assertSameValue(null, EnvironmentReport::parseIniBytes('surprise'), 'invalid values should not be guessed');
    assertSameValue(null, EnvironmentReport::parseIniBytes('999999999999999999999G'), 'overflow should be rejected');
};

$tests['derives a compatible status from the supported baseline'] = static function (): void {
    $report = EnvironmentReport::fromSnapshot(compatibleSnapshot());
    assertSameValue(3, $report['formatVersion'], 'report format should be versioned');
    assertSameValue('compatible', $report['status'], 'supported snapshot should be compatible');
    assertSameValue('standalone', $report['installationClass'], 'baseline should be standalone');
    assertSameValue([], $report['blockers'], 'supported snapshot should have no blockers');
    assertSameValue([], $report['warnings'], 'supported snapshot should have no warnings');
};

$tests['reports stable blocker and warning codes'] = static function (): void {
    $snapshot = compatibleSnapshot();
    $snapshot['runtime']['phpVersion'] = '8.1.29';
    $snapshot['extensions']['intl']['loaded'] = false;
    $snapshot['storage']['privateStorage']['writable'] = false;
    $snapshot['media']['selectedEngine'] = null;
    $snapshot['media']['fidelity'] = 'unavailable';
    $snapshot['media']['engines']['imagick']['loaded'] = false;
    $snapshot['scheduler']['heartbeat']['fresh'] = false;
    $snapshot['transport']['https'] = 'off';
    $report = EnvironmentReport::fromSnapshot($snapshot);

    assertSameValue('blocked', $report['status'], 'unsupported snapshot should be blocked');
    assertSameValue(
        ['php_version', 'extension_intl', 'privateStorage_not_writable', 'imagick_unavailable'],
        $report['blockers'],
        'blockers should be deterministic and safe',
    );
    assertSameValue(
        ['cron_heartbeat_stale', 'https_not_observed'],
        $report['warnings'],
        'warnings should be deterministic and safe',
    );
    assertSameValue('unsupported', $report['installationClass'], 'non-media blockers should be unsupported');
};

$tests['classifies missing full-fidelity media as a managed service requirement'] = static function (): void {
    $snapshot = compatibleSnapshot();
    $snapshot['media']['selectedEngine'] = null;
    $snapshot['media']['fidelity'] = 'unavailable';
    $snapshot['media']['engines']['imagick']['loaded'] = false;
    $snapshot['media']['engines']['gd']['loaded'] = true;
    $report = EnvironmentReport::fromSnapshot($snapshot);

    assertSameValue('blocked', $report['status'], 'an external media dependency is not standalone support');
    assertSameValue(
        'managed_service_required',
        $report['installationClass'],
        'an otherwise capable host should be classified as managed when raster processing is external',
    );
    assertSameValue(['imagick_unavailable'], $report['blockers'], 'the standalone blocker should remain explicit');
    assertSameValue(
        ['gd_reduced_fidelity_candidate'],
        $report['warnings'],
        'GD should be reported as an unimplemented degraded candidate only',
    );
};

$tests['requires protected private storage and verified outbound HTTPS'] = static function (): void {
    $snapshot = compatibleSnapshot();
    $snapshot['storage']['privateStorage']['protection']['outsideDocumentRoot'] = false;
    $snapshot['transport']['outboundHttps'] = [
        'configured' => true,
        'verified' => false,
        'statusCode' => null,
        'errorCode' => 'tls_verification_failed',
    ];
    $report = EnvironmentReport::fromSnapshot($snapshot);

    assertSameValue(
        ['private_storage_exposure_unverified', 'outbound_https_unverified'],
        $report['blockers'],
        'private storage and verified outbound TLS are baseline capabilities',
    );
    assertSameValue('unsupported', $report['installationClass'], 'missing security capabilities are unsupported');
};

$tests['reports missing AVIF as an optional capability'] = static function (): void {
    $snapshot = compatibleSnapshot();
    $snapshot['media']['engines']['imagick']['formats']['avif'] = false;
    $snapshot['media']['engines']['imagick']['operations']['encodeAvif'] = false;
    $report = EnvironmentReport::fromSnapshot($snapshot);

    assertSameValue('compatible', $report['status'], 'AVIF should not block the shared-host baseline');
    assertSameValue([], $report['blockers'], 'AVIF should not be a blocker');
    assertSameValue(
        ['imagick_format_avif_unavailable'],
        $report['warnings'],
        'AVIF capability should remain visible',
    );
};

$tests['requires the database baseline and transactional behavior'] = static function (): void {
    $snapshot = compatibleSnapshot();
    $snapshot['database']['serverVersion'] = '10.5.25-MariaDB';
    $snapshot['database']['transactionProbe']['compareAndSet'] = false;
    $report = EnvironmentReport::fromSnapshot($snapshot);

    assertSameValue('blocked', $report['status'], 'an insufficient database should block qualification');
    assertSameValue(
        ['database_version', 'database_compare_and_set_unavailable'],
        $report['blockers'],
        'database failures should use stable safe codes',
    );
};

$tests['allows a legacy server default when the database and connection use utf8mb4'] = static function (): void {
    $snapshot = compatibleSnapshot();
    $snapshot['database']['serverCharacterSet'] = 'utf8mb3';
    $snapshot['database']['serverCollation'] = 'utf8mb3_general_ci';
    $report = EnvironmentReport::fromSnapshot($snapshot);

    assertSameValue('compatible', $report['status'], 'shared-host server defaults are informational');
    assertSameValue([], $report['blockers'], 'server-wide settings outside tenant control must not block');
    assertSameValue([], $report['warnings'], 'a compatible database and connection need no warning');
};

$tests['requires utf8mb4 for both the selected database and active connection'] = static function (): void {
    $snapshot = compatibleSnapshot();
    $snapshot['database']['databaseCharacterSet'] = 'utf8mb3';
    $snapshot['database']['databaseCollation'] = 'utf8mb3_general_ci';
    $snapshot['database']['connectionCharacterSet'] = 'utf8mb3';
    $snapshot['database']['connectionCollation'] = 'utf8mb3_general_ci';
    $report = EnvironmentReport::fromSnapshot($snapshot);

    assertSameValue(
        ['database_character_set', 'database_connection_character_set'],
        $report['blockers'],
        'database and connection encoding failures should remain distinct',
    );
    assertSameValue(
        ['database_collation_not_utf8mb4', 'database_connection_collation_not_utf8mb4'],
        $report['warnings'],
        'database and connection collations should remain visible',
    );
};

$tests['database connection failures expose only stable diagnostics'] = static function (): void {
    $report = DatabaseReport::collect([
        'dsn' => 'pgsql:host=secret.internal;dbname=private',
        'username' => 'private-user',
        'password' => 'private-password',
    ]);
    $json = json_encode($report, JSON_THROW_ON_ERROR);

    assertSameValue('unsupported_driver', $report['errorCode'], 'unsupported drivers should be explicit');
    assertTrueValue(!str_contains($json, 'secret.internal'), 'database host must not appear in diagnostics');
    assertTrueValue(!str_contains($json, 'private-password'), 'database password must not appear in diagnostics');
};

$tests['web probe requires a short-lived token and keeps its configuration private'] = static function (): void {
    $repository = dirname(__DIR__, 2);
    $root = sys_get_temp_dir() . '/portable-cms-web-probe-' . bin2hex(random_bytes(8));
    $private = $root . '/private';
    $publication = $root . '/publication';
    $scratch = $root . '/scratch';
    foreach ([$root, $private, $publication, $scratch] as $directory) {
        if (!mkdir($directory, 0700) && !is_dir($directory)) {
            throw new RuntimeException('Could not create web-probe test directory');
        }
    }
    $token = bin2hex(random_bytes(32));
    $outboundProbeUrl = 'https://127.0.0.1:1/private-probe-name';
    $configPath = $root . '/probe.json';
    file_put_contents($configPath, json_encode([
        'expiresAt' => gmdate('Y-m-d\TH:i:s\Z', time() + 3600),
        'tokenHash' => password_hash($token, PASSWORD_DEFAULT),
        'outboundHttpsUrl' => $outboundProbeUrl,
        'paths' => [
            'privateStorage' => $private,
            'publication' => $publication,
            'scratch' => $scratch,
        ],
        'database' => [],
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    chmod($configPath, 0600);

    $socket = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);
    if ($socket === false) {
        throw new RuntimeException("Could not allocate test port: {$errorNumber}");
    }
    $address = (string) stream_socket_get_name($socket, false);
    fclose($socket);
    $port = (int) substr($address, strrpos($address, ':') + 1);
    $process = proc_open(
        [PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', $repository],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']],
        $pipes,
        $repository,
        array_merge($_ENV, [
            'CMS_PROBE_CONFIG' => $configPath,
            'CMS_PROBE_ALLOW_INSECURE_LOOPBACK' => 'true',
        ]),
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start PHP web-probe test server');
    }

    $url = "http://127.0.0.1:{$port}/tools/conventional-environment-web.php";
    try {
        $ready = false;
        for ($attempt = 0; $attempt < 40; $attempt++) {
            $response = httpRequest($url);
            if ($response['status'] !== 0) {
                $ready = true;
                break;
            }
            usleep(25_000);
        }
        assertTrueValue($ready, 'PHP test server should become ready');

        $unauthorized = httpRequest($url, 'POST', ['X-Probe-Token: incorrect']);
        assertSameValue(404, $unauthorized['status'], 'an invalid probe token should reveal nothing');

        // Native media probes and the bounded outbound check (up to ten seconds) exceed an ordinary form request's budget.
        $authorized = httpRequest($url, 'POST', ["X-Probe-Token: {$token}"], 12);
        assertSameValue(422, $authorized['status'], 'an authenticated incomplete host should return its report');
        $report = json_decode($authorized['body'], true, flags: JSON_THROW_ON_ERROR);
        assertSameValue('cli-server', $report['runtime']['sapi'], 'the web SAPI should be recorded');
        assertTrueValue(!str_contains($authorized['body'], $root), 'the web report must not disclose private paths');
        assertTrueValue(!str_contains($authorized['body'], $token), 'the web report must not disclose its token');
        assertTrueValue(
            !str_contains($authorized['body'], $outboundProbeUrl),
            'the web report must not disclose its outbound probe URL',
        );
    } finally {
        proc_terminate($process);
        proc_close($process);
        @unlink($configPath);
        foreach ([$scratch, $publication, $private, $root] as $directory) {
            @rmdir($directory);
        }
    }
};

$tests['compares web and CLI reports without requiring exact patch versions'] = static function (): void {
    $web = EnvironmentReport::fromSnapshot(compatibleSnapshot());
    $cliSnapshot = compatibleSnapshot();
    $cliSnapshot['runtime']['sapi'] = 'cli';
    $cliSnapshot['runtime']['phpVersion'] = '8.2.4';
    $cli = EnvironmentReport::fromSnapshot($cliSnapshot);
    $comparison = EnvironmentComparison::compare($web, $cli, 1_799_712_000);

    assertSameValue('compatible', $comparison['status'], 'patch-level differences should remain compatible');
    assertSameValue(
        ['runtime-phpVersion-differs'],
        $comparison['warnings'],
        'patch differences should remain visible',
    );
    assertSameValue('2027-01-12T00:00:00Z', $comparison['generatedAt'], 'comparison time should be injected');
};

$tests['blocks required capabilities that differ between web and CLI'] = static function (): void {
    $web = EnvironmentReport::fromSnapshot(compatibleSnapshot());
    $cliSnapshot = compatibleSnapshot();
    $cliSnapshot['runtime']['sapi'] = 'cli';
    $cliSnapshot['runtime']['phpVersion'] = '8.3.0';
    $cliSnapshot['extensions']['intl']['loaded'] = false;
    $cli = EnvironmentReport::fromSnapshot($cliSnapshot);
    $comparison = EnvironmentComparison::compare($web, $cli);

    assertSameValue('blocked', $comparison['status'], 'a required SAPI mismatch should block qualification');
    assertTrueValue(
        in_array('extensions-intl-loaded-differs', $comparison['blockers'], true),
        'the exact extension mismatch should be reported',
    );
};

$tests['preserves managed media classification in the combined report'] = static function (): void {
    $webSnapshot = compatibleSnapshot();
    $webSnapshot['media']['selectedEngine'] = null;
    $webSnapshot['media']['fidelity'] = 'unavailable';
    $webSnapshot['media']['engines']['imagick']['loaded'] = false;
    $web = EnvironmentReport::fromSnapshot($webSnapshot);
    $cliSnapshot = $webSnapshot;
    $cliSnapshot['runtime']['sapi'] = 'cli';
    $cli = EnvironmentReport::fromSnapshot($cliSnapshot);
    $comparison = EnvironmentComparison::compare($web, $cli);

    assertSameValue('blocked', $comparison['status'], 'managed installations are blocked for standalone use');
    assertSameValue(
        'managed_service_required',
        $comparison['installationClass'],
        'the combined report should retain the declared external dependency class',
    );
};

$tests['collects filesystem evidence without exposing configured paths'] = static function (): void {
    $root = sys_get_temp_dir() . '/portable-cms-secret-' . bin2hex(random_bytes(8));
    if (!mkdir($root, 0700) && !is_dir($root)) {
        throw new RuntimeException('Could not create test directory');
    }
    $heartbeat = $root . '/cron-heartbeat';
    file_put_contents($heartbeat, "ok\n");
    try {
        $report = EnvironmentReport::collect(
            [
                'privateStorage' => $root,
                'publication' => $root,
                'scratch' => $root,
                'cronHeartbeat' => $heartbeat,
            ],
            time(),
            ['HTTPS' => 'on'],
            compatibleDatabase(),
        );
        $json = json_encode($report, JSON_THROW_ON_ERROR);
        assertTrueValue(!str_contains($json, $root), 'report must not disclose configured absolute paths');
        assertSameValue(true, $report['storage']['privateStorage']['writable'], 'writable storage should be observed');
        assertSameValue(true, $report['storage']['publication']['operations']['fileReplace'], 'file replacement should be exercised');
        assertSameValue(true, $report['storage']['scratch']['operations']['cleanupComplete'], 'probe files should be removed');
        assertSameValue(true, $report['scheduler']['heartbeat']['fresh'], 'new heartbeat should be fresh');
    } finally {
        @unlink($heartbeat);
        @rmdir($root);
    }
};

require dirname(__DIR__) . '/php/identity-test.php';
require dirname(__DIR__) . '/php/content-test.php';
require dirname(__DIR__) . '/php/media-upload-test.php';
require dirname(__DIR__) . '/php/media-processing-test.php';
require dirname(__DIR__) . '/php/preview-compiler-test.php';
require dirname(__DIR__) . '/php/preview-share-test.php';
require dirname(__DIR__) . '/php/newest-preview-link-test.php';
require dirname(__DIR__) . '/php/normalized-export-test.php';
require dirname(__DIR__) . '/php/site-sequence-test.php';
require dirname(__DIR__) . '/php/text-normalizer-test.php';
require dirname(__DIR__) . '/php/schema-validator-test.php';
require dirname(__DIR__) . '/php/product-contract-test.php';
require dirname(__DIR__) . '/php/reference-studio-test.php';
require dirname(__DIR__) . '/php/admin-experience-test.php';
require dirname(__DIR__) . '/php/interface-catalog-test.php';
require dirname(__DIR__) . '/php/interface-language-test.php';
require dirname(__DIR__) . '/php/administration-labels-test.php';
require dirname(__DIR__) . '/php/editorial-foundation-test.php';
require dirname(__DIR__) . '/php/editorial-controls-test.php';
require dirname(__DIR__) . '/php/edited-field-upload-test.php';
require dirname(__DIR__) . '/php/group-rows-test.php';
require dirname(__DIR__) . '/php/static-site-compiler-test.php';
require dirname(__DIR__) . '/php/site-builder-components-test.php';
require dirname(__DIR__) . '/php/profile-preview-test.php';
require dirname(__DIR__) . '/php/live-preview-test.php';
require dirname(__DIR__) . '/php/responsive-images-test.php';
require dirname(__DIR__) . '/php/preview-diagnostics-test.php';
require dirname(__DIR__) . '/php/search-sharing-test.php';
require dirname(__DIR__) . '/php/route-discovery-test.php';
require dirname(__DIR__) . '/php/structured-data-test.php';
require dirname(__DIR__) . '/php/sharing-image-test.php';
require dirname(__DIR__) . '/php/website-search-defaults-test.php';
require dirname(__DIR__) . '/php/mixed-record-references-test.php';
require dirname(__DIR__) . '/php/published-slug-history-test.php';
require dirname(__DIR__) . '/php/design-registry-test.php';
require dirname(__DIR__) . '/php/font-families-test.php';
require dirname(__DIR__) . '/php/rich-text-test.php';
require dirname(__DIR__) . '/php/composition-test.php';
require dirname(__DIR__) . '/php/linked-composition-test.php';
require dirname(__DIR__) . '/php/ai-assistance-test.php';
require dirname(__DIR__) . '/php/normalized-restore-test.php';
require dirname(__DIR__) . '/php/content-import-test.php';
require dirname(__DIR__) . '/php/chunked-export-test.php';
require dirname(__DIR__) . '/php/portable-transfer-test.php';
require dirname(__DIR__) . '/php/host-io-test.php';
require dirname(__DIR__) . '/php/install-config-test.php';
require dirname(__DIR__) . '/php/media-text-test.php';
require dirname(__DIR__) . '/php/media-descriptions-test.php';
require dirname(__DIR__) . '/php/focal-point-test.php';
require dirname(__DIR__) . '/php/speculation-rules-test.php';
require dirname(__DIR__) . '/php/record-workflow-test.php';
require dirname(__DIR__) . '/php/published-record-test.php';
require dirname(__DIR__) . '/php/preview-check-test.php';
require dirname(__DIR__) . '/php/publication-test.php';
require dirname(__DIR__) . '/php/release-schedule-test.php';
require dirname(__DIR__) . '/php/website-publication-test.php';
require dirname(__DIR__) . '/php/website-identity-test.php';
require dirname(__DIR__) . '/php/installation-brand-test.php';
require dirname(__DIR__) . '/php/administration-logo-test.php';
require dirname(__DIR__) . '/php/website-media-test.php';
require dirname(__DIR__) . '/php/holding-assets-test.php';

require dirname(__DIR__) . '/php/video-test.php';
require dirname(__DIR__) . '/php/vimeo-video-test.php';
require dirname(__DIR__) . '/php/video-previews-test.php';
require dirname(__DIR__) . '/php/video-limits-test.php';
require dirname(__DIR__) . '/php/web-address-test.php';
require dirname(__DIR__) . '/php/blocks-test.php';
require dirname(__DIR__) . '/php/blocks-editor-test.php';
require dirname(__DIR__) . '/php/content-feed-test.php';
require dirname(__DIR__) . '/php/trailer-upload-test.php';
require dirname(__DIR__) . '/php/upgrade-test.php';
require dirname(__DIR__) . '/php/engine-release-test.php';
require dirname(__DIR__) . '/php/profile-field-groups-test.php';
require dirname(__DIR__) . '/php/table-prefix-test.php';
require dirname(__DIR__) . '/php/account-test.php';
require dirname(__DIR__) . '/php/preview-cleanup-test.php';
require dirname(__DIR__) . '/php/site-languages-test.php';
require dirname(__DIR__) . '/php/site-wording-test.php';
require dirname(__DIR__) . '/php/site-wording-labels-test.php';
require dirname(__DIR__) . '/php/site-language-publication-test.php';
require dirname(__DIR__) . '/php/added-languages-test.php';
require dirname(__DIR__) . '/php/language-text-deletion-test.php';
require dirname(__DIR__) . '/php/quiet-record-editor-test.php';
require dirname(__DIR__) . '/php/editor-exact-preview-test.php';
require dirname(__DIR__) . '/php/record-history-test.php';
require dirname(__DIR__) . '/php/single-line-rich-text-test.php';

$failures = 0;
foreach ($tests as $name => $test) {
    try {
        $test();
        fwrite(STDOUT, "PASS {$name}\n");
    } catch (Throwable $error) {
        $failures++;
        fwrite(STDERR, "FAIL {$name}\n{$error->getMessage()}\n");
    }
}

fwrite(STDOUT, sprintf("%d PHP tests, %d failures\n", count($tests), $failures));
exit($failures === 0 ? 0 : 1);
