<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Transfer;

use Utsuri\PortableCms\Conventional\Export\ExportBudget;
use Utsuri\PortableCms\Conventional\Export\NormalizedExport;
use Utsuri\PortableCms\Conventional\Identity\Clock;
use Utsuri\PortableCms\Conventional\Identity\RandomSource;
use Utsuri\PortableCms\Conventional\Restore\FilesystemRestoreStore;
use Utsuri\PortableCms\Conventional\Restore\MariaDbRestoreRepository;
use Utsuri\PortableCms\Conventional\Restore\RestorePlan;
use Utsuri\PortableCms\Conventional\Restore\RestoreRejected;
use Utsuri\PortableCms\Conventional\Restore\RestoreService;

final readonly class PortableRestore
{
    public function __construct(private Clock $clock, private RandomSource $random, private string $runtimeRoot) {}

    /** @param null|\Closure(array<string, mixed>): void $progress
     * @return array<string, mixed>
     */
    public function run(OperatorContext $context, FilesystemRestoreStore $package, OperationLimits $limits, bool $apply = false, string $confirmation = '', ?string $legacyProfileSha256 = null, ?\Closure $progress = null): array
    {
        $budget = new ExportBudget($this->clock, $limits->seconds);
        $destination = new FilesystemRestoreStore($context->config->mediaRoot, $this->clock, $limits->seconds);
        $package->assertOutside($context->publicRoot);
        $destination->assertOutside($context->publicRoot);
        OperatorContext::separate($package, $destination);
        if (is_file($package->path('package.json'))) {
            $manifest = PackageManifest::read(PackageFiles::read($package, 'package.json', PackageManifest::MAX_BYTES), (string) file_get_contents($this->runtimeRoot . '/schemas/portable-cms-content-package-v1.schema.json'));
            if (($manifest->document['profile']['packageSha256'] ?? null) !== $context->profile->checksum) {
                throw new RestoreRejected('exact_profile_package_mismatch');
            }
            $inventory = new FilesystemRestoreStore($package->path('content'), $this->clock, $limits->seconds);
        } else {
            // Legacy v1 never recorded a source package digest. Require independent operator evidence.
            if ($legacyProfileSha256 === null || !hash_equals($context->profile->checksum, $legacyProfileSha256)) {
                throw new RestoreRejected('legacy_exact_profile_checksum_required');
            }
            $inventory = $package;
            $manifest = null;
        }
        $input = $inventory->load($this->runtimeRoot . '/schemas/portable-cms-export-v1.schema.json');
        $budget->check();
        if ($manifest === null && !$input->export instanceof NormalizedExport) {
            throw new RestoreRejected('completed_package_manifest_required');
        }
        $plan = new RestorePlan($input->export, $input->sha256, $context->profile, $this->clock, $this->random);
        $size = filesize($inventory->path('export.json'));
        if (!is_int($size)) {
            throw new RestoreRejected('export_unreadable');
        }
        $legacy = $manifest === null;
        $manifest ??= PackageManifest::create($plan, $size, null);
        $manifest->assertPlan($plan, $size);
        $facts = ['packageSha256' => $manifest->checksum(), 'profilePackageSha256' => $context->profile->checksum, 'legacyProfileChecksumSupplied' => $legacy];
        $verify = static function () use ($package, $manifest, $legacy): void {
            if (!$legacy && !hash_equals($manifest->checksum(), hash('sha256', PackageFiles::read($package, 'package.json', PackageManifest::MAX_BYTES)))) {
                throw new RestoreRejected('package_metadata_changed');
            }
        };
        $paths = glob($this->runtimeRoot . '/engine/migrations/*.sql');
        if ($paths === false) {
            throw new RestoreRejected('migration_files_unavailable');
        }
        sort($paths, SORT_STRING);
        $service = new RestoreService(new MariaDbRestoreRepository($context->pdo, $paths), $destination, $this->clock);
        $budget->check();
        if (!$apply) {
            $report = $service->dryRun($plan, $inventory, $context->actorId);
            if (($report['issues'] ?? []) !== []) {
                // A read-only handover plan attests the source inventory even after destination completion.
                $report['status'] = 'blocked';
            }
            $verify();
        } else {
            if (!hash_equals($manifest->checksum(), $confirmation)) {
                throw new RestoreRejected('package_confirmation_mismatch');
            }
            $report = $service->complete($plan, $inventory, $context->actorId, $plan->sha256, $limits->files, $limits->batches, $budget, $progress, $verify);
        }
        return $report + $facts;
    }
}
