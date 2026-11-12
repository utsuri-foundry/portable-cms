<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Restore;

use Utsuri\PortableCms\Conventional\Identity\Clock;

interface RestoreRepository
{
    public function inspect(RestorePlan $plan, string $destinationIdentity, string $actorId): string;
    public function reserve(RestorePlan $plan, string $destinationIdentity, string $actorId, \DateTimeImmutable $now): string;
    public function commit(RestorePlan $plan, string $destinationIdentity, string $actorId, \DateTimeImmutable $now): void;
}

final class RestoreService
{
    public function __construct(private readonly RestoreRepository $repository, private readonly FilesystemRestoreStore $destination, private readonly Clock $clock) {}

    /** @return array<string, mixed> */
    public function dryRun(RestorePlan $plan, FilesystemRestoreStore $inventory, string $actorId): array
    {
        $state = $this->repository->inspect($plan, $this->identity(), $actorId);
        $inventory->assertExport($plan);
        if ($state === 'completed') {
            $this->verifyCompletedFiles($plan);
        }
        if ($state === 'empty') {
            $this->destination->assertUnoccupied($plan);
        }
        $issues = $inventory->inventory($plan);
        return $plan->report() + ['status' => $state === 'completed' ? 'already_restored' : ($issues === [] ? 'compatible' : 'blocked'), 'issues' => $issues];
    }

    /** Exactly the same intent resumes; no replacement, merge, activation or identity import.
     * @return array<string, mixed>
     */
    public function apply(RestorePlan $plan, FilesystemRestoreStore $inventory, string $actorId, string $confirmation, int $maximumFiles = 8): array
    {
        return $this->drive($plan, $inventory, $actorId, $confirmation, $maximumFiles);
    }

    /** @param null|\Closure(array<string, mixed>): void $progress
     * @param null|\Closure(): void $verifyIntent
     * @return array<string, mixed>
     */
    public function complete(RestorePlan $plan, FilesystemRestoreStore $inventory, string $actorId, string $confirmation, int $maximumFiles = 64, int $maximumBatches = 25032, ?\Utsuri\PortableCms\Conventional\Export\ExportBudget $budget = null, ?\Closure $progress = null, ?\Closure $verifyIntent = null): array
    {
        return $this->drive($plan, $inventory, $actorId, $confirmation, $maximumFiles, $maximumBatches, $budget ?? new \Utsuri\PortableCms\Conventional\Export\ExportBudget($this->clock), $progress, true, $verifyIntent);
    }

    /** @param null|\Closure(array<string, mixed>): void $progress
     * @param null|\Closure(): void $verifyIntent
     * @return array<string, mixed>
     */
    private function drive(RestorePlan $plan, FilesystemRestoreStore $inventory, string $actorId, string $confirmation, int $maximumFiles, int $maximumBatches = 1, ?\Utsuri\PortableCms\Conventional\Export\ExportBudget $budget = null, ?\Closure $progress = null, bool $checkpointed = false, ?\Closure $verifyIntent = null): array
    {
        if ($maximumFiles < 1 || $maximumFiles > 64) {
            throw new RestoreRejected('invalid_stage_limit');
        }
        if (!hash_equals($plan->sha256, $confirmation)) {
            throw new RestoreRejected('confirmation_mismatch');
        }
        if ($maximumBatches < 1 || $maximumBatches > 25032) {
            throw new RestoreRejected('invalid_batch_limit');
        }
        // Authorization precedes even lock-file creation; recheck after acquiring it.
        $this->repository->inspect($plan, $this->identity(), $actorId);
        return $this->destination->exclusive(function () use ($plan, $inventory, $actorId, $maximumFiles, $maximumBatches, $budget, $progress, $checkpointed, $verifyIntent): array {
            $verifyIntent?->__invoke();
            $state = $this->repository->inspect($plan, $this->identity(), $actorId);
            if ($state === 'completed') {
                $this->verifyCompletedFiles($plan);
                return $plan->report() + ['status' => 'already_restored'];
            }
            if ($state === 'empty') {
                $this->destination->assertUnoccupied($plan);
            }
            try {
                $inventory->assertVideoFields($plan->export, $plan->package->manifest);
            } catch (\Utsuri\PortableCms\Conventional\I18n\InvalidInput $error) {
                throw new RestoreRejected($error->getMessage());
            }
            if (!$plan->export instanceof \Utsuri\PortableCms\Conventional\Export\ChunkedExport && $inventory->inventory($plan) !== []) {
                throw new RestoreRejected('inventory_not_verified');
            }
            // Export bytes must still be the exact operator-confirmed envelope.
            $inventory->assertExport($plan);
            $this->repository->reserve($plan, $this->identity(), $actorId, $this->clock->now());
            $prepared = ['prepared' => 0, 'complete' => false];
            for ($batch = 0; $batch < $maximumBatches; $batch++) {
                $budget?->check();
                $prepared = $this->destination->stage($plan, $inventory, $maximumFiles, $checkpointed);
                if ($progress !== null) {
                    $progress($plan->report() + ['status' => 'preparing', 'preparedFiles' => $prepared['prepared']]);
                }
                if ($prepared['complete']) {
                    $budget?->check();
                    $inventory->assertExport($plan);
                    $verifyIntent?->__invoke();
                    $this->repository->commit($plan, $this->identity(), $actorId, $this->clock->now());
                    return $plan->report() + ['status' => 'restored', 'preparedFiles' => $prepared['prepared']];
                }
            }
            return $plan->report() + ['status' => 'preparing', 'preparedFiles' => $prepared['prepared']];
        });
    }

    private function identity(): string
    {
        return hash('sha256', $this->destination->root);
    }

    private function verifyCompletedFiles(RestorePlan $plan): void
    {
        foreach ($plan->files as $file) {
            $this->destination->verify($file->key, $file);
        }
    }
}
