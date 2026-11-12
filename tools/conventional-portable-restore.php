<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Export\ExportCapacityFailure;
use Utsuri\PortableCms\Conventional\Identity\SystemClock;
use Utsuri\PortableCms\Conventional\Identity\SystemRandomSource;
use Utsuri\PortableCms\Conventional\Restore\FilesystemRestoreStore;
use Utsuri\PortableCms\Conventional\Restore\RestoreRejected;
use Utsuri\PortableCms\Conventional\Transfer\OperationLimits;
use Utsuri\PortableCms\Conventional\Transfer\OperatorContext;
use Utsuri\PortableCms\Conventional\Transfer\PortableRestore;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/engine/src/cms/conventional/bootstrap.php';
$options = getopt('', ['config:', 'public-root:', 'package:', 'administrator:', 'apply', 'confirm:', 'legacy-profile-sha256:', 'maximum-seconds:', 'maximum-batches:', 'maximum-files:', 'help']);
if (isset($options['help'])) {
    fwrite(STDOUT, "Usage: php tools/conventional-portable-restore.php --config=/private/cms.json --public-root=/site/public_html --package=/private/package --administrator=LOGIN [--apply --confirm=PACKAGE_SHA256] [--maximum-seconds=120 --maximum-batches=25032 --maximum-files=64]\nDefault: read-only compatibility/inventory plan. Apply confirms packageSha256, not exportSha256, and drives bounded batches on an independently prepared empty installation. Repeat the identical intent after interruption. Legacy flat v1 packages require --legacy-profile-sha256=INDEPENDENTLY_VERIFIED_SOURCE_PROFILE_HASH. No accounts, configuration, profile code or public activation are imported.\nExit 0: compatible, restored or already_restored. Exit 2: incomplete, resume. Exit 1: blocked or rejected, investigate. Final JSON is stdout; progress is stderr.\n");
    exit(0);
}
set_error_handler(static function (): never {
    throw new RuntimeException('Portable restore host operation failed');
});
$latest = [];
try {
    $limits = OperationLimits::fromOptions($options);
    $context = OperatorContext::load($options, dirname(__DIR__));
    $clock = new SystemClock();
    $package = new FilesystemRestoreStore(is_string($options['package'] ?? null) ? $options['package'] : '', $clock, $limits->seconds);
    $progress = static function (array $report) use (&$latest): void {
        $latest = $report;
        fwrite(STDERR, json_encode(['status' => $report['status'], 'preparedFiles' => $report['preparedFiles']], JSON_THROW_ON_ERROR) . "\n");
    };
    $report = (new PortableRestore($clock, new SystemRandomSource(), dirname(__DIR__)))->run($context, $package, $limits, isset($options['apply']), is_string($options['confirm'] ?? null) ? $options['confirm'] : '', is_string($options['legacy-profile-sha256'] ?? null) ? $options['legacy-profile-sha256'] : null, $progress);
    fwrite(STDOUT, json_encode($report, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    exit(in_array($report['status'], ['compatible', 'restored', 'already_restored'], true) ? 0 : ($report['status'] === 'preparing' ? 2 : 1));
} catch (Throwable $error) {
    if (OperationLimits::incomplete($error)) {
        fwrite(STDOUT, json_encode(['status' => 'preparing', 'reason' => $error instanceof RestoreRejected ? $error->reason : 'operation_incomplete'] + $latest, JSON_THROW_ON_ERROR) . "\n");
        exit(2);
    }
    fwrite(STDERR, json_encode(['status' => 'rejected', 'reason' => $error instanceof RestoreRejected || $error instanceof ExportCapacityFailure ? $error->reason : 'portable_restore_failed_safely'], JSON_THROW_ON_ERROR) . "\n");
    exit(1);
}
