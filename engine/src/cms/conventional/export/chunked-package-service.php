<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Export;

use Utsuri\PortableCms\Conventional\Content\ProfilePackage;
use Utsuri\PortableCms\Conventional\HostIo;
use Utsuri\PortableCms\Conventional\Identity\Clock;
use Utsuri\PortableCms\Conventional\Identity\RandomSource;
use Utsuri\PortableCms\Conventional\Restore\FilesystemBatchCursor;
use Utsuri\PortableCms\Conventional\Restore\FilesystemRestoreStore;
use Utsuri\PortableCms\Conventional\Restore\RestorePlan;
use Utsuri\PortableCms\Conventional\Restore\RestoreRejected;

/** Operator-only capture/resume. A complete manifest appears only after all referenced bytes verify. */
final readonly class ChunkedPackageService
{
    public function __construct(private \PDO $pdo, private ProfilePackage $profile, private FilesystemRestoreStore $source, private FilesystemRestoreStore $output, private Clock $clock, private RandomSource $random, private ?\Utsuri\PortableCms\Conventional\Compiler\PublicUrlContext $context = null) {}

    /** One validated plan per invocation, even when driving multiple bounded file batches.
     * @param null|\Closure(array<string, mixed>): void $progress
     * @param null|\Closure(RestorePlan): void $completed
     * @return array<string, mixed>
     */
    public function run(string $actorId, int $maximumFiles = 8, int $maximumBatches = 1, ?ExportBudget $budget = null, ?\Closure $progress = null, ?\Closure $completed = null): array
    {
        if ($maximumFiles < 1 || $maximumFiles > 64) {
            throw new RestoreRejected('invalid_stage_limit');
        }
        if ($maximumBatches < 1 || $maximumBatches > 25032) {
            throw new RestoreRejected('invalid_batch_limit');
        }
        $budget ??= new ExportBudget($this->clock);
        $budget->check();
        $actor = $this->pdo->prepare("SELECT id FROM cms_users WHERE id = :id AND role IN ('owner', 'administrator') AND status = 'active' AND must_change_password = 0");
        $actor->execute(['id' => $actorId]);
        if ($actor->fetchColumn() !== $actorId) {
            throw new RestoreRejected('administrator_required');
        }
        return $this->output->exclusive(function () use ($actorId, $maximumFiles, $maximumBatches, $budget, $progress, $completed): array {
            $capture = $this->output->path('capture.json');
            if (!is_file($capture)) {
                // The lock is owned here, not pre-existing source content.
                $manifest = $this->capture($actorId, $budget);
            } else {
                if (filesize($capture) > ExportCapacity::MANIFEST_BYTES) {
                    throw new RestoreRejected('export_manifest_capacity_exceeded');
                }
                $manifest = json_decode((string) file_get_contents($capture), true, 32, JSON_THROW_ON_ERROR);
                if (!is_array($manifest)) {
                    throw new RestoreRejected('invalid_chunked_export_manifest');
                }
            }
            $bytes = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
            $export = new ChunkedExport($this->output, $manifest, $budget);
            $plan = new RestorePlan($export, hash('sha256', $bytes), $this->profile, $this->clock, $this->random);
            $cursor = new FilesystemBatchCursor($this->output, 'packaging-progress.json', $plan->sha256, count($plan->files));
            $prepared = $cursor->read();
            $originals = $this->pdo->prepare('SELECT original_key FROM cms_media WHERE id = :id');
            for ($batch = 0; $batch < $maximumBatches && $prepared < count($plan->files); $batch++) {
                $budget->check();
                $end = min(count($plan->files), $prepared + $maximumFiles);
                while ($prepared < $end) {
                    $budget->check();
                    $file = $plan->files[$prepared];
                    $key = 'blobs/' . $file->sha256;
                    if (is_file($this->output->path($key))) {
                        $this->output->verify($key, $file);
                        $cursor->advance(++$prepared);
                        continue;
                    }
                    $sourceKey = $file->key;
                    if (str_starts_with($sourceKey, 'originals/')) {
                        $originals->execute(['id' => explode('/', $sourceKey)[1]]);
                        $sourceKey = $originals->fetchColumn();
                        if (!is_string($sourceKey) || preg_match('#\Aoriginals/[a-f0-9]{32}/source\z#', $sourceKey) !== 1) {
                            throw new RestoreRejected('source_original_mapping_unavailable');
                        }
                    }
                    $this->output->copyPackageBlob($this->source->verify($sourceKey, $file), $file);
                    $cursor->advance(++$prepared);
                }
                if ($progress !== null) {
                    $progress($plan->report() + ['status' => 'packaging', 'preparedFiles' => $prepared]);
                }
            }
            $complete = $prepared === count($plan->files);
            if ($complete) {
                foreach ($plan->files as $index => $file) {
                    $budget->check();
                    if (!is_file($this->output->path('blobs/' . $file->sha256))) {
                        $cursor->advance($index);
                    }
                    $this->output->verify('blobs/' . $file->sha256, $file);
                }
                $target = $this->output->path('export.json');
                if (!is_file($target)) {
                    $temporary = $this->output->path('complete.partial');
                    if (is_file($temporary)) {
                        HostIo::required(static fn(): bool => unlink($temporary));
                    }
                    HostIo::createPrivateFile($temporary, $bytes);
                    HostIo::required(static fn(): bool => link($temporary, $target));
                    HostIo::required(static fn(): bool => unlink($temporary));
                } elseif (!hash_equals(hash('sha256', $bytes), (string) hash_file('sha256', $target))) {
                    throw new RestoreRejected('export_changed');
                }
                if ($completed !== null) {
                    $completed($plan);
                }
            }
            return $plan->report() + ['status' => $complete ? 'packaged' : 'packaging', 'preparedFiles' => $prepared];
        });
    }

    /** @return array<string, mixed> */
    private function capture(string $actorId, ExportBudget $budget): array
    {
        $profile = (new \Utsuri\PortableCms\Conventional\Content\MariaDbSiteLanguages($this->pdo))->installed($this->profile->manifest);
        $repository = new MariaDbExportRepository($this->pdo, $profile->designRegistries, $this->context, wordingProfile: $profile);
        return $repository->capture(new ChunkedExportWriter($this->output, $budget), bin2hex($this->random->bytes(16)), bin2hex($this->random->bytes(16)), $actorId, $profile->id, $profile->version, $profile->locales, $this->clock->now(), $budget);
    }
}
