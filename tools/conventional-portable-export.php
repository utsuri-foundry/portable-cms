<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Export\ExportCapacityFailure;
use Utsuri\PortableCms\Conventional\Identity\SystemClock;
use Utsuri\PortableCms\Conventional\Identity\SystemRandomSource;
use Utsuri\PortableCms\Conventional\Restore\FilesystemRestoreStore;
use Utsuri\PortableCms\Conventional\Restore\RestoreRejected;
use Utsuri\PortableCms\Conventional\Transfer\OperationLimits;
use Utsuri\PortableCms\Conventional\Transfer\OperatorContext;
use Utsuri\PortableCms\Conventional\Transfer\PortableExport;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/engine/src/cms/conventional/bootstrap.php';
$options = getopt('', ['config:', 'public-root:', 'output:', 'administrator:', 'source-paused', 'maximum-seconds:', 'maximum-batches:', 'maximum-files:', 'help']);
if (isset($options['help'])) {
    fwrite(STDOUT, "Usage: php tools/conventional-portable-export.php --config=/private/cms.json --public-root=/site/public_html --output=/private/empty-package --administrator=LOGIN --source-paused [--maximum-seconds=120 --maximum-batches=25032 --maximum-files=64]\nPause editing, workers, cleanup and upgrades before capture and retain immutable media until packaged. --source-paused acknowledges this operator protocol; it does not stop services. Repeat the identical command after incomplete work. Output must be private and separate from media. package.json is the final completion manifest.\nExit 0: packaged. Exit 2: incomplete, resume. Exit 1: rejected, investigate. Final JSON is stdout; bounded progress is stderr.\n");
    exit(0);
}
set_error_handler(static function (): never {
    throw new RuntimeException('Portable export host operation failed');
});
$latest = [];
try {
    $limits = OperationLimits::fromOptions($options);
    $context = OperatorContext::load($options, dirname(__DIR__));
    $clock = new SystemClock();
    $output = new FilesystemRestoreStore(is_string($options['output'] ?? null) ? $options['output'] : '', $clock, $limits->seconds);
    $progress = static function (array $report) use (&$latest): void {
        $latest = $report;
        fwrite(STDERR, json_encode(['status' => $report['status'], 'preparedFiles' => $report['preparedFiles']], JSON_THROW_ON_ERROR) . "\n");
    };
    $report = (new PortableExport($clock, new SystemRandomSource()))->run($context, $output, $limits, isset($options['source-paused']), $progress);
    fwrite(STDOUT, json_encode($report, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    exit($report['status'] === 'packaged' ? 0 : 2);
} catch (Throwable $error) {
    if (OperationLimits::incomplete($error)) {
        fwrite(STDOUT, json_encode(['status' => 'packaging', 'reason' => $error instanceof RestoreRejected ? $error->reason : 'operation_incomplete'] + $latest, JSON_THROW_ON_ERROR) . "\n");
        exit(2);
    }
    fwrite(STDERR, json_encode(['status' => 'rejected', 'reason' => $error instanceof RestoreRejected || $error instanceof ExportCapacityFailure ? $error->reason : 'portable_export_failed_safely'], JSON_THROW_ON_ERROR) . "\n");
    exit(1);
}
