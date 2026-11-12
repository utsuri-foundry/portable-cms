<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Content\ProfilePackage;
use Utsuri\PortableCms\Conventional\Identity\SystemClock;
use Utsuri\PortableCms\Conventional\Identity\SystemRandomSource;
use Utsuri\PortableCms\Conventional\Restore\FilesystemRestoreStore;
use Utsuri\PortableCms\Conventional\Restore\MariaDbRestoreRepository;
use Utsuri\PortableCms\Conventional\Restore\RestorePlan;
use Utsuri\PortableCms\Conventional\Restore\RestoreRejected;
use Utsuri\PortableCms\Conventional\Restore\RestoreService;
use Utsuri\PortableCms\Conventional\RuntimeConfiguration;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/engine/src/cms/conventional/bootstrap.php';
$options = getopt('', ['config:', 'public-root:', 'package:', 'administrator:', 'apply', 'confirm:', 'maximum-files:', 'help']);
if (isset($options['help'])) {
    fwrite(STDOUT, "Usage: php tools/conventional-restore.php --config=/private/cms.json --public-root=/site/public_html --package=/private/export-package --administrator=admin@example.test [--apply --confirm=EXPORT_SHA256 --maximum-files=8]\nDry run is the default. Apply targets an empty installation only; repeat the identical command to resume. No publication occurs.\nExit statuses:\n  0: compatible dry run, restored or already_restored.\n  2: preparing; incomplete, repeat the identical intent to resume.\n  1: blocked or rejected; investigate before retrying.\n");
    exit(0);
}
set_error_handler(static function (): never {
    throw new RuntimeException('Operator filesystem operation failed');
});
try {
    $publicRoot = is_string($options['public-root'] ?? null) ? $options['public-root'] : '';
    if ($publicRoot === '' || realpath($publicRoot) !== $publicRoot || !is_dir($publicRoot)) {
        throw new RestoreRejected('explicit_document_root_required');
    }
    $config = RuntimeConfiguration::load(is_string($options['config'] ?? null) ? $options['config'] : '', $publicRoot);
    if ($config->profileRoot === null) {
        throw new RestoreRejected('installed_profile_required');
    }
    $pdo = $config->connect();
    $lookup = $pdo->prepare("SELECT id FROM cms_users WHERE login_name = :login AND role IN ('owner', 'administrator') AND status = 'active' AND must_change_password = 0");
    $lookup->execute(['login' => is_string($options['administrator'] ?? null) ? $options['administrator'] : '']);
    $actorId = $lookup->fetchColumn();
    if (!is_string($actorId)) {
        throw new RestoreRejected('administrator_required');
    }
    $clock = new SystemClock();
    $inventory = new FilesystemRestoreStore(is_string($options['package'] ?? null) ? $options['package'] : '');
    $inventory->assertOutside($publicRoot);
    $input = $inventory->load(dirname(__DIR__) . '/schemas/portable-cms-export-v1.schema.json');
    $plan = new RestorePlan($input->export, $input->sha256, ProfilePackage::load($config->profileRoot), $clock, new SystemRandomSource());
    $paths = glob(dirname(__DIR__) . '/engine/migrations/*.sql');
    if ($paths === false) {
        throw new RestoreRejected('migration_files_unavailable');
    }
    sort($paths, SORT_STRING);
    $destination = new FilesystemRestoreStore($config->mediaRoot);
    $destination->assertOutside($publicRoot);
    $service = new RestoreService(new MariaDbRestoreRepository($pdo, $paths), $destination, $clock);
    $maximumFiles = filter_var($options['maximum-files'] ?? '8', FILTER_VALIDATE_INT);
    if (!is_int($maximumFiles)) {
        throw new RestoreRejected('invalid_stage_limit');
    }
    $report = isset($options['apply'])
        ? $service->apply($plan, $inventory, $actorId, is_string($options['confirm'] ?? null) ? $options['confirm'] : '', $maximumFiles)
        : $service->dryRun($plan, $inventory, $actorId);
    fwrite(STDOUT, json_encode($report, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    exit(match ($report['status']) {
        'compatible', 'restored', 'already_restored' => 0,
        'preparing' => 2,
        default => 1,
    });
} catch (Throwable $error) {
    fwrite(STDERR, json_encode(['status' => 'rejected', 'reason' => $error instanceof RestoreRejected || $error instanceof \Utsuri\PortableCms\Conventional\Export\ExportCapacityFailure ? $error->reason : 'restore_failed_safely'], JSON_THROW_ON_ERROR) . "\n");
    exit(1);
}
