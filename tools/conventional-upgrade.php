<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Upgrade\UpgradeBundle;
use Utsuri\PortableCms\Conventional\Upgrade\UpgradeFailure;
use Utsuri\PortableCms\Conventional\Upgrade\UpgradeService;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/engine/src/cms/conventional/bootstrap.php';
$options = getopt('', ['action:', 'bundle:', 'config:', 'cms-root:', 'backup:', 'confirm-bundle:', 'standby-config:', 'table-prefix:', 'confirm-table-prefix:', 'help']);
if (isset($options['help'])) {
    fwrite(STDOUT, <<<'HELP'
Usage: php NEW-BUNDLE/private/runtime/tools/conventional-upgrade.php
  --config=/private/cms.json --cms-root=/cms-document-root
  --action=dry-run|backup|apply|recover|rollback|cleanup-table-set
  [--bundle=/private/uploaded-bundle] [--backup=/private/new-backup]
  [--confirm-bundle=MANIFEST-SHA256] [--standby-config=/private/standby.json]
  [--table-prefix=PREFIX --confirm-table-prefix=PREFIX]

Dry run is read-only and reports the installation's database upgrade mode.
Backup captures and verifies the installation's table set, private media and
current runtime/configuration. Apply requires that unchanged backup (at most
one hour old) and explicit bundle identity confirmation. Schema/profile changes
follow database.upgradeMode in the configuration: table_set (default) builds a
new table set in the same database, in_place pauses the CMS and changes the
serving tables with the backup as the way back, and separate_database needs an
empty database whose standby.json contributes only its database credentials.
Recover retains the previous release after incomplete preparation, restores
an interrupted in-place upgrade, or settles an already committed switch.
Rollback never silently discards later content. cleanup-table-set removes a
retired table set; the serving and rollback table sets are always kept.
Stop legacy cron invocations before the first backup; afterwards use the stable
private upgrade/worker.php --tool=media|ai dispatcher for cron.
HELP);
    fwrite(STDOUT, "\n");
    exit(0);
}
try {
    $config = $options['config'] ?? '';
    $cmsRoot = $options['cms-root'] ?? '';
    $action = $options['action'] ?? 'dry-run';
    if (!is_string($config) || !is_string($cmsRoot) || $config === '' || $cmsRoot === '') {
        throw new UpgradeFailure('private_configuration_and_cms_root_required');
    }
    $service = new UpgradeService($config, $cmsRoot, new DateTimeImmutable('now', new DateTimeZone('UTC')), launcherSource: (string) file_get_contents(__DIR__ . '/conventional-release-launcher.php'));
    $bundle = null;
    if (in_array($action, ['dry-run', 'apply'], true)) {
        if (!is_string($options['bundle'] ?? null)) {
            throw new UpgradeFailure('private_bundle_required');
        }
        $bundle = UpgradeBundle::load($options['bundle']);
        if (realpath(dirname(__DIR__)) !== realpath($bundle->root . '/private/runtime') || $bundle->engineVersion !== \Utsuri\PortableCms\Conventional\Content\ProfileManifest::ENGINE_VERSION) {
            throw new UpgradeFailure('run_the_new_bundles_upgrade_command');
        }
    }
    $backup = is_string($options['backup'] ?? null) ? $options['backup'] : '';
    $result = match ($action) {
        'dry-run' => $service->dryRun($bundle ?? throw new UpgradeFailure('private_bundle_required')),
        'backup' => $backup === '' ? throw new UpgradeFailure('new_private_backup_required') : $service->backup($backup),
        'apply' => $service->apply($bundle ?? throw new UpgradeFailure('private_bundle_required'), $backup, is_string($options['confirm-bundle'] ?? null) ? $options['confirm-bundle'] : '', is_string($options['standby-config'] ?? null) ? $options['standby-config'] : null),
        'recover' => $service->recover(),
        'rollback' => $service->rollback(),
        'cleanup-table-set' => $service->cleanupTableSet(is_string($options['table-prefix'] ?? null) ? $options['table-prefix'] : '', is_string($options['confirm-table-prefix'] ?? null) ? $options['confirm-table-prefix'] : ''),
        default => throw new UpgradeFailure('upgrade_action_invalid'),
    };
    fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    exit(($result['blocked'] ?? false) === true ? 2 : 0);
} catch (Throwable $error) {
    if ($error instanceof \Utsuri\PortableCms\Conventional\Content\ProfileEngineMismatch) {
        fwrite(STDERR, json_encode($error->document(), JSON_THROW_ON_ERROR) . "\n");
        exit(1);
    }
    $code = $error instanceof UpgradeFailure ? $error->codeName : 'upgrade_failed_previous_release_retained_or_recovery_required';
    fwrite(STDERR, json_encode(['error' => $code], JSON_THROW_ON_ERROR) . "\n");
    exit(1);
}
