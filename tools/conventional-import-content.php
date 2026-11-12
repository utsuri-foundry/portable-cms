<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Content\ProfilePackage;
use Utsuri\PortableCms\Conventional\Identity\SystemClock;
use Utsuri\PortableCms\Conventional\Identity\SystemRandomSource;
use Utsuri\PortableCms\Conventional\Import\ContentImportService;
use Utsuri\PortableCms\Conventional\Import\ImportMediaTransfer;
use Utsuri\PortableCms\Conventional\Import\ImportRejected;
use Utsuri\PortableCms\Conventional\Import\MariaDbImportRepository;
use Utsuri\PortableCms\Conventional\Media\MariaDbMediaLibrary;
use Utsuri\PortableCms\Conventional\Media\MariaDbUploadRepository;
use Utsuri\PortableCms\Conventional\Restore\FilesystemRestoreStore;
use Utsuri\PortableCms\Conventional\Restore\RestoreRejected;
use Utsuri\PortableCms\Conventional\RuntimeConfiguration;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/engine/src/cms/conventional/bootstrap.php';
$options = getopt('', ['config:', 'public-root:', 'package:', 'source:', 'administrator:', 'apply', 'confirm:', 'confirm-conflicts:', 'maximum-media:', 'help']);
if (isset($options['help'])) {
    fwrite(STDOUT, "Usage: php tools/conventional-import-content.php --config=/private/cms.json --public-root=/site/public_html --package=/private/content-package --source=stable-source-name --administrator=admin@example.test [--apply --confirm=PLAN_SHA256 --confirm-conflicts=PLAN_SHA256 --maximum-media=2]\nDry run writes nothing. Apply imports current content into destination-attributed revisions and queues normal media processing. Conflicts are skipped unless separately confirmed. Re-run dry run after interruption and confirm the new plan. No publication occurs.\n");
    exit(0);
}
set_error_handler(static function (): never {
    throw new RuntimeException('Operator filesystem operation failed');
});
try {
    $publicRoot = is_string($options['public-root'] ?? null) ? $options['public-root'] : '';
    if ($publicRoot === '' || realpath($publicRoot) !== $publicRoot || !is_dir($publicRoot)) {
        throw new ImportRejected('explicit_document_root_required');
    }
    $config = RuntimeConfiguration::load(is_string($options['config'] ?? null) ? $options['config'] : '', $publicRoot);
    if ($config->profileRoot === null) {
        throw new ImportRejected('installed_profile_required');
    }
    $runtimeLease = \Utsuri\PortableCms\Conventional\Upgrade\RuntimeLease::guard(is_string($options['config'] ?? null) ? $options['config'] : '', dirname(__DIR__));
    $pdo = $config->connect();
    $lookup = $pdo->prepare('SELECT id FROM cms_users WHERE login_name = :login AND role IN (\'owner\', \'administrator\') AND status = \'active\'');
    $lookup->execute(['login' => is_string($options['administrator'] ?? null) ? $options['administrator'] : '']);
    $actorId = $lookup->fetchColumn();
    if (!is_string($actorId)) {
        throw new ImportRejected('active_destination_administrator_required');
    }
    $clock = new SystemClock();
    $random = new SystemRandomSource();
    $package = ProfilePackage::load($config->profileRoot);
    $inventory = new FilesystemRestoreStore(is_string($options['package'] ?? null) ? $options['package'] : '', $clock);
    $inventory->assertOutside($publicRoot);
    $destination = new FilesystemRestoreStore($config->mediaRoot, $clock);
    $destination->assertOutside($publicRoot);
    if ($destination->root === $inventory->root) {
        throw new ImportRejected('separate_private_package_required');
    }
    $input = $inventory->load(dirname(__DIR__) . '/schemas/portable-cms-export-v1.schema.json');
    $paths = glob(dirname(__DIR__) . '/engine/migrations/*.sql');
    if (!is_array($paths)) {
        throw new ImportRejected('migration_files_unavailable');
    }
    sort($paths, SORT_STRING);
    $repository = new MariaDbImportRepository($pdo, hash('sha256', $config->mediaRoot), $package->manifest->designRegistries, $paths);
    $mediaAvailable = extension_loaded('imagick') && class_exists(Imagick::class) && Imagick::queryFormats('WEBP') !== [];
    $service = new ContentImportService($repository, $package, new ImportMediaTransfer(new MariaDbUploadRepository($pdo), $config->mediaRoot, $clock, $random, new MariaDbMediaLibrary($pdo)), $clock, $random, $mediaAvailable);
    $source = is_string($options['source'] ?? null) ? $options['source'] : '';
    $maximumMedia = filter_var($options['maximum-media'] ?? '2', FILTER_VALIDATE_INT);
    if (!is_int($maximumMedia) || $maximumMedia < 1 || $maximumMedia > 8) {
        throw new ImportRejected('invalid_media_batch_limit');
    }
    if (isset($options['apply'])) {
        $report = $service->apply($input, $inventory, $source, $actorId, is_string($options['confirm'] ?? null) ? $options['confirm'] : '', is_string($options['confirm-conflicts'] ?? null) ? $options['confirm-conflicts'] : null, $maximumMedia);
    } else {
        $plan = $service->dryRun($input, $inventory, $source, $actorId);
        $report = $plan->report() + ['status' => 'planned', 'planSha256' => $plan->sha256];
    }
    fwrite(STDOUT, json_encode($report, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    exit(($report['status'] === 'preparing' || ($report['skippedItems'] ?? 0) > 0) ? 2 : 0);
} catch (Throwable $error) {
    fwrite(STDERR, json_encode(['status' => 'rejected', 'reason' => $error instanceof ImportRejected || $error instanceof RestoreRejected ? $error->reason : 'content_import_failed_safely'], JSON_THROW_ON_ERROR) . "\n");
    exit(1);
}
