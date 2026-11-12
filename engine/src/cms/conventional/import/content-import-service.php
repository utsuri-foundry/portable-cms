<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Import;

use Utsuri\PortableCms\Conventional\Content\ContentService;
use Utsuri\PortableCms\Conventional\Content\ProfilePackage;
use Utsuri\PortableCms\Conventional\Content\RecordConflict;
use Utsuri\PortableCms\Conventional\Identity\Clock;
use Utsuri\PortableCms\Conventional\Identity\RandomSource;
use Utsuri\PortableCms\Conventional\Restore\FilesystemRestoreStore;
use Utsuri\PortableCms\Conventional\Restore\RestoreInput;

final class ContentImportService
{
    private readonly ImportPlanner $planner;
    public function __construct(private readonly ImportRepository $repository, private readonly ProfilePackage $package, private readonly ImportMediaTransfer $transfer, private readonly Clock $clock, private readonly RandomSource $random, bool $mediaAvailable)
    {
        $this->planner = new ImportPlanner($repository, $package, $clock, $random, $mediaAvailable);
    }
    public function dryRun(RestoreInput $input, FilesystemRestoreStore $inventory, string $source, string $actorId): ImportPlan
    {
        return $this->planner->plan($input, $inventory, $source, $actorId);
    }
    /** A fresh plan digest confirms exactly the reviewed destination revisions. Re-plan after interruption.
     * @return array<string, mixed>
     */
    public function apply(RestoreInput $input, FilesystemRestoreStore $inventory, string $source, string $actorId, string $confirmation, ?string $conflictConfirmation = null, int $maximumMedia = 2): array
    {
        if ($maximumMedia < 1 || $maximumMedia > 8) {
            throw new ImportRejected('invalid_media_batch_limit');
        }
        $before = $this->dryRun($input, $inventory, $source, $actorId);
        if (!hash_equals($before->sha256, $confirmation) || ($conflictConfirmation !== null && !hash_equals($before->sha256, $conflictConfirmation))) {
            throw new ImportRejected('reviewed_plan_changed');
        }
        $allowConflicts = $conflictConfirmation !== null;
        $deadline = $this->clock->now()->modify('+180 seconds');
        return $this->transfer->exclusive(function () use ($before, $input, $inventory, $source, $actorId, $confirmation, $allowConflicts, $maximumMedia, $deadline): array {
            if (!hash_equals($input->sha256, $inventory->exportChecksum())) {
                throw new ImportRejected('export_changed');
            }
            $plan = $this->repository->transaction(function () use ($before, $input, $inventory, $source, $actorId, $confirmation, $allowConflicts, $deadline): ImportPlan {
                $plan = $this->planner->plan($input, $inventory, $source, $actorId, $before->inventory);
                if (!hash_equals($plan->sha256, $confirmation)) {
                    throw new ImportRejected('reviewed_plan_changed');
                }
                $selected = array_values(array_filter($plan->records, static fn(ImportRecord $record): bool => $record->action !== 'blocked' && ($record->action !== 'conflict' || $allowConflicts)));
                // The transaction makes complete cyclic graphs visible only after projection succeeds.
                $graphRecords = array_map(static fn(ImportRecord $record): ImportRecord => $record->action === 'conflict' ? new ImportRecord($record->sourceId, $record->id, $record->typeId, $record->body, $record->sourceDigest, $record->sourceRevision, $record->expectedRevision, 'update', $record->issues, $record->expectedEditSequence) : $record, $selected);
                $graph = new ImportGraphRepository($this->repository->records(), $graphRecords);
                $languages = $this->repository->languages($this->package->manifest);
                $content = new ContentService($graph, $languages->installed($this->package->manifest), $this->clock, $this->random, publicLocales: static fn(): array => $languages->public());
                $mappings = $this->repository->recordMappings($source, array_map(static fn(ImportRecord $record): string => $record->sourceId, $selected));
                $changed = false;
                foreach ($selected as $record) {
                    if ($this->clock->now() >= $deadline) {
                        throw new ImportRejected('content_commit_time_budget_exhausted');
                    }
                    if ($record->action === 'unchanged' && ($mappings[$record->sourceId]->sourceDigest ?? null) === $record->sourceDigest) {
                        continue;
                    }
                    if ($record->action === 'unchanged' && isset($mappings[$record->sourceId]) && $record->sourceRevision <= $mappings[$record->sourceId]->sourceRevision) {
                        continue;
                    }
                    $revision = $record->expectedRevision;
                    if ($record->action !== 'unchanged') {
                        $result = $content->saveCanonical($record->typeId, $record->id, $revision, $record->body, $actorId, $this->repository->design());
                        if ($result instanceof RecordConflict) {
                            throw new ImportRejected('destination_changed_during_import');
                        }
                        $revision = $result->revisionNumber;
                    }
                    if ($revision === null) {
                        throw new \LogicException('Imported record has no revision');
                    }
                    $this->repository->checkpointRecord($source, $record, $revision, $actorId, $this->clock->now());
                    $changed = true;
                }
                $graph->flush($this->repository);
                foreach ($plan->media as $media) {
                    if (in_array($media->action, ['create', 'resume'], true) && ($media->ownerId === null || $this->repository->identity($media->ownerId) !== null)) {
                        $this->repository->reserveMedia($source, $media, $actorId, $this->clock->now());
                        $changed = $changed || $media->action === 'create';
                    }
                }
                if ($changed) {
                    $this->repository->audit(bin2hex($this->random->bytes(16)), $actorId, $source, $confirmation, $allowConflicts, $this->clock->now());
                }
                if ($this->clock->now() >= $deadline) {
                    throw new ImportRejected('content_commit_time_budget_exhausted');
                }
                return $plan;
            });
            $transferred = $updated = 0;
            $pending = $skipped = 0;
            foreach ($plan->media as $media) {
                if ($media->action === 'blocked' || ($media->action === 'conflict' && !$allowConflicts)) {
                    $skipped++;
                    continue;
                }
                if ($media->action === 'unchanged') {
                    continue;
                }
                if ($media->ownerId !== null && $this->repository->identity($media->ownerId) === null) {
                    $skipped++;
                    continue;
                }
                if ($transferred + $updated >= $maximumMedia || $this->clock->now() >= $deadline) {
                    $pending++;
                    continue;
                }
                $this->repository->authorize($actorId);
                if (in_array($media->action, ['update', 'conflict'], true)) {
                    $this->transfer->updateMetadata($media, $actorId);
                    $updated++;
                } else {
                    $this->transfer->transfer($media, $inventory, $actorId);
                    $transferred++;
                }
                $this->repository->completeMedia($source, $media);
                if (in_array($media->action, ['update', 'conflict'], true)) {
                    $this->repository->audit(bin2hex($this->random->bytes(16)), $actorId, $source, $confirmation, $allowConflicts, $this->clock->now());
                }
            }
            $skipped += count(array_filter($plan->records, static fn(ImportRecord $record): bool => $record->action === 'blocked' || ($record->action === 'conflict' && !$allowConflicts)));
            return $plan->report() + ['planSha256' => $confirmation, 'status' => $pending > 0 ? 'preparing' : ($skipped > 0 ? 'applied_with_skips' : 'applied'), 'transferredMedia' => $transferred, 'updatedMedia' => $updated, 'remainingMedia' => $pending, 'skippedItems' => $skipped, 'conflictsConfirmed' => $allowConflicts];
        });
    }
}
