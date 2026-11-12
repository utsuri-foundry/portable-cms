<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Content\ProfilePackage;
use Utsuri\PortableCms\Conventional\Identity\SystemClock;
use Utsuri\PortableCms\Conventional\Identity\SystemRandomSource;
use Utsuri\PortableCms\Conventional\Restore\FilesystemRestoreStore;
use Utsuri\PortableCms\Conventional\Restore\RestoreFile;
use Utsuri\PortableCms\Conventional\Restore\RestorePlan;
use Utsuri\PortableCms\Conventional\Restore\RestoreRejected;
use Utsuri\PortableCms\Conventional\RuntimeConfiguration;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/engine/src/cms/conventional/bootstrap.php';
$options = getopt('', ['config:', 'public-root:', 'export-json:', 'output:', 'administrator:', 'maximum-files:', 'help']);
if (isset($options['help'])) {
    fwrite(STDOUT, "Usage: php tools/conventional-export-package.php --config=/private/cms.json --public-root=/site/public_html --output=/private/package [--administrator=LOGIN --maximum-files=8 | --export-json=/private/legacy.json]\nWithout --export-json, capture a complete chunked export and resume the same package until status is packaged. Packaging is incomplete. Never put the package in the document root.\n");
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
    if (!isset($options['export-json'])) {
        $pdo = $config->connect();
        $query = $pdo->prepare("SELECT id FROM cms_users WHERE login_name = :login AND role IN ('owner', 'administrator') AND status = 'active' AND must_change_password = 0");
        $query->execute(['login' => is_string($options['administrator'] ?? null) ? $options['administrator'] : '']);
        $actorId = $query->fetchColumn();
        if (!is_string($actorId)) {
            throw new RestoreRejected('administrator_required');
        }
        $source = new FilesystemRestoreStore($config->mediaRoot);
        $package = new FilesystemRestoreStore(is_string($options['output'] ?? null) ? $options['output'] : '');
        $source->assertOutside($publicRoot);
        $package->assertOutside($publicRoot);
        $maximumFiles = filter_var($options['maximum-files'] ?? '8', FILTER_VALIDATE_INT);
        if (!is_int($maximumFiles)) {
            throw new RestoreRejected('invalid_stage_limit');
        }
        $report = (new \Utsuri\PortableCms\Conventional\Export\ChunkedPackageService($pdo, ProfilePackage::load($config->profileRoot), $source, $package, new SystemClock(), new SystemRandomSource(), $config->publicUrlContext))->run($actorId, $maximumFiles);
        fwrite(STDOUT, json_encode($report, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
        exit($report['status'] === 'packaged' ? 0 : 2);
    }
    $jsonPath = is_string($options['export-json']) ? $options['export-json'] : '';
    $input = new FilesystemRestoreStore(dirname($jsonPath));
    $input->assertOutside($publicRoot);
    $loaded = $input->load(dirname(__DIR__) . '/schemas/portable-cms-export-v1.schema.json', basename($jsonPath));
    if (!$loaded->export instanceof \Utsuri\PortableCms\Conventional\Export\NormalizedExport) {
        throw new RestoreRejected('use_chunked_capture_without_export_json');
    }
    $plan = new RestorePlan($loaded->export, $loaded->sha256, ProfilePackage::load($config->profileRoot), new SystemClock(), new SystemRandomSource());
    $package = new FilesystemRestoreStore(is_string($options['output'] ?? null) ? $options['output'] : '');
    $package->assertOutside($publicRoot);
    $source = new FilesystemRestoreStore($config->mediaRoot);
    $source->assertOutside($publicRoot);
    $pdo = $config->connect();
    $originalKeys = $pdo->prepare('SELECT original_key FROM cms_media WHERE id = :id');
    $package->package($plan, $source, static function (RestoreFile $file) use ($originalKeys): string {
        if (!str_starts_with($file->key, 'originals/')) {
            return $file->key;
        }
        $originalKeys->execute(['id' => explode('/', $file->key)[1]]);
        $key = $originalKeys->fetchColumn();
        if (!is_string($key) || preg_match('#\Aoriginals/[a-f0-9]{32}/source\z#', $key) !== 1) {
            throw new RestoreRejected('source_original_mapping_unavailable');
        }
        return $key;
    });
    fwrite(STDOUT, json_encode(['status' => 'packaged', 'exportSha256' => $package->exportChecksum(), 'files' => count($plan->files)], JSON_THROW_ON_ERROR) . "\n");
} catch (Throwable $error) {
    fwrite(STDERR, json_encode(['status' => 'rejected', 'reason' => $error instanceof RestoreRejected || $error instanceof \Utsuri\PortableCms\Conventional\Export\ExportCapacityFailure ? $error->reason : 'package_failed_safely'], JSON_THROW_ON_ERROR) . "\n");
    exit(1);
}
