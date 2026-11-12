<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Transfer;

use Utsuri\PortableCms\Conventional\Export\ChunkedPackageService;
use Utsuri\PortableCms\Conventional\Export\ExportBudget;
use Utsuri\PortableCms\Conventional\Identity\Clock;
use Utsuri\PortableCms\Conventional\Identity\RandomSource;
use Utsuri\PortableCms\Conventional\Restore\FilesystemRestoreStore;
use Utsuri\PortableCms\Conventional\Restore\RestorePlan;
use Utsuri\PortableCms\Conventional\Restore\RestoreRejected;

/** One source invocation drives finite batches; the outer manifest is its final completion fact. */
final readonly class PortableExport
{
    public function __construct(private Clock $clock, private RandomSource $random) {}

    /** @param null|\Closure(array<string, mixed>): void $progress
     * @return array<string, mixed>
     */
    public function run(OperatorContext $context, FilesystemRestoreStore $output, OperationLimits $limits, bool $sourcePaused, ?\Closure $progress = null): array
    {
        if (!$sourcePaused) {
            throw new RestoreRejected('explicit_source_mutation_pause_required');
        }
        $budget = new ExportBudget($this->clock, $limits->seconds);
        $source = new FilesystemRestoreStore($context->config->mediaRoot, $this->clock, $limits->seconds);
        $source->assertOutside($context->publicRoot);
        $output->assertOutside($context->publicRoot);
        OperatorContext::separate($source, $output);
        return $output->exclusive(function () use ($context, $output, $source, $limits, $budget, $progress): array {
            $content = (new ExportWorkspace($output))->prepare($context, $this->clock, $limits->seconds);
            $manifest = null;
            $report = (new ChunkedPackageService($context->pdo, $context->profile, $source, $content, $this->clock, $this->random, $context->config->publicUrlContext))->run(
                $context->actorId,
                $limits->files,
                $limits->batches,
                $budget,
                $progress,
                static function (RestorePlan $plan) use ($output, $content, $budget, &$manifest): void {
                    $budget->check();
                    $size = filesize($content->path('export.json'));
                    if (!is_int($size)) {
                        throw new RestoreRejected('export_unreadable');
                    }
                    $manifest = PackageManifest::create($plan, $size);
                    PackageFiles::commit($output, 'package.json', $manifest->json);
                },
            );
            return $report + ($manifest === null ? [] : ['packageSha256' => $manifest->checksum(), 'profilePackageSha256' => $context->profile->checksum]);
        });
    }
}
