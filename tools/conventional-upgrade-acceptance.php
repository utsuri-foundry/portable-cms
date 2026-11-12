<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\HostIo;
use Utsuri\PortableCms\Conventional\RuntimeConfiguration;
use Utsuri\PortableCms\Conventional\Upgrade\UpgradeBundle;
use Utsuri\PortableCms\Conventional\Upgrade\UpgradeFailure;
use Utsuri\PortableCms\Conventional\Upgrade\UpgradeFiles;
use Utsuri\PortableCms\Conventional\Upgrade\UpgradeService;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/engine/src/cms/conventional/bootstrap.php';
$options = getopt('', ['bundle:', 'confirm-bundle:', 'config:', 'cms-root:', 'backup:', 'standby-config:', 'help']);
if (isset($options['help'])) {
    fwrite(STDOUT, <<<'HELP'
Usage: php NEW-BUNDLE/private/runtime/tools/conventional-upgrade-acceptance.php
  --bundle=/private/incoming --confirm-bundle=REVIEWED_MANIFEST_SHA256
  --config=/private/cms.json --cms-root=/cms-document-root
  --backup=/private/new-qualification-backup [--standby-config=/private/standby.json]

Explicitly applies AND rolls back the new release on an isolated fictional
qualified-host installation. Verifies real HTTPS CMS/assets and all active
public artifact files. It is an operator operation, not a read-only probe.
HELP);
    fwrite(STDOUT, "\n");
    exit(0);
}

/** Real origin verification keeps TLS enabled and never follows redirects. */
function upgradeAcceptanceFetch(string $url, int $maximum): string
{
    if ((parse_url($url, PHP_URL_SCHEME)) !== 'https') {
        throw new UpgradeFailure('qualification_requires_https');
    }
    $context = stream_context_create(['http' => ['timeout' => 30, 'ignore_errors' => true, 'follow_location' => 0], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
    $headers = [];
    $bytes = HostIo::attempt(static function () use ($url, $context, $maximum, &$headers) {
        $http_response_header = [];
        $bytes = file_get_contents($url, false, $context, 0, $maximum + 1);
        $headers = $http_response_header;
        return $bytes;
    })->value;
    if (!is_string($bytes) || strlen($bytes) > $maximum || !isset($headers[0]) || preg_match('/\AHTTP\/\S+ 200\b/', $headers[0]) !== 1) {
        throw new UpgradeFailure('qualified_origin_check_failed');
    }
    return $bytes;
}

function upgradeAcceptanceOrigins(RuntimeConfiguration $configuration, string $assets): void
{
    $login = upgradeAcceptanceFetch($configuration->origin . '/login', 4_194_304);
    if (!str_contains($login, '<form')) {
        throw new UpgradeFailure('qualified_cms_check_failed');
    }
    foreach (UpgradeFiles::inventory($assets) as $name => $facts) {
        if (hash('sha256', upgradeAcceptanceFetch($configuration->origin . '/assets/' . $name, $facts['bytes'])) !== $facts['sha256']) {
            throw new UpgradeFailure('qualified_cms_asset_changed');
        }
    }
    if ($configuration->publicationRoot === null || $configuration->publicUrlContext === null) {
        throw new UpgradeFailure('qualification_requires_active_public_release');
    }
    $artifact = UpgradeFiles::json($configuration->publicationRoot . '/artifact.json');
    foreach ($artifact['files'] as $file) {
        $path = str_ends_with($file['path'], '/index.html') ? substr($file['path'], 0, -strlen('index.html')) : $file['path'];
        if (hash('sha256', upgradeAcceptanceFetch($configuration->publicUrlContext->baseUrl . '/' . $path, $file['byteSize'])) !== $file['sha256']) {
            throw new UpgradeFailure('qualified_public_file_changed');
        }
    }
}

try {
    foreach (['config', 'cms-root', 'bundle', 'backup', 'confirm-bundle'] as $required) {
        if (!is_string($options[$required] ?? null) || $options[$required] === '') {
            throw new UpgradeFailure('qualification_options_required');
        }
    }
    $bundle = UpgradeBundle::load($options['bundle']);
    if (realpath(dirname(__DIR__)) !== realpath($bundle->root . '/private/runtime') || $bundle->engineVersion !== \Utsuri\PortableCms\Conventional\Content\ProfileManifest::ENGINE_VERSION || !hash_equals($bundle->checksum, $options['confirm-bundle'])) {
        throw new UpgradeFailure('run_and_confirm_reviewed_new_bundle');
    }
    $service = new UpgradeService($options['config'], $options['cms-root'], new DateTimeImmutable('now', new DateTimeZone('UTC')), launcherSource: (string) file_get_contents(__DIR__ . '/conventional-release-launcher.php'));
    $report = $service->dryRun($bundle);
    if ($report['blocked'] || $report['noOp']) {
        throw new UpgradeFailure('qualification_requires_unblocked_actual_upgrade');
    }
    $service->backup($options['backup']);
    $applied = $service->apply($bundle, $options['backup'], $options['confirm-bundle'], is_string($options['standby-config'] ?? null) ? $options['standby-config'] : null);
    try {
        $active = UpgradeFiles::json(dirname($options['config']) . '/upgrade/active.json');
        upgradeAcceptanceOrigins(RuntimeConfiguration::load($active['config']), $active['assets']);
    } finally {
        $rollback = $service->rollback();
    }
    $previous = UpgradeFiles::json(dirname($options['config']) . '/upgrade/active.json');
    upgradeAcceptanceOrigins(RuntimeConfiguration::load($previous['config']), $previous['assets']);
    fwrite(STDOUT, json_encode(['status' => 'qualified_upgrade_and_rollback_passed', 'report' => $report, 'upgrade' => $applied, 'rollback' => $rollback], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
} catch (Throwable $error) {
    if ($error instanceof \Utsuri\PortableCms\Conventional\Content\ProfileEngineMismatch) {
        fwrite(STDERR, json_encode($error->document(), JSON_THROW_ON_ERROR) . "\n");
        exit(1);
    }
    fwrite(STDERR, json_encode(['error' => $error instanceof UpgradeFailure ? $error->codeName : 'qualified_upgrade_failed_inspect_journal'], JSON_THROW_ON_ERROR) . "\n");
    exit(1);
}
