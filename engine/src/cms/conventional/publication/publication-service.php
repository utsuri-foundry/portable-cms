<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Publication;

use Utsuri\PortableCms\Conventional\Compiler\PublicUrlContext;
use Utsuri\PortableCms\Conventional\Compiler\PublishedSlugHistory;
use Utsuri\PortableCms\Conventional\Identity\Clock;
use Utsuri\PortableCms\Conventional\Identity\RandomSource;
use Utsuri\PortableCms\Conventional\Identity\SessionContext;
use Utsuri\PortableCms\Conventional\Preview\PreviewBuild;
use Utsuri\PortableCms\Conventional\Preview\PreviewService;

/** Approval, bounded target work and authenticated CAS activation are separate phases. */
final class PublicationService
{
    private readonly FeedPublication $feed;

    public function __construct(
        private readonly PublicationRepository $repository,
        private readonly PreviewService $previews,
        private readonly PublicationTarget $target,
        private readonly PublicUrlContext $context,
        private readonly string $profileId,
        private readonly string $artifactSchema,
        private readonly Clock $clock,
        private readonly RandomSource $random,
        public readonly ?HoldingPublication $holding = null,
        public readonly \DateTimeZone $timeZone = new \DateTimeZone('UTC'),
    ) {
        $this->feed = new FeedPublication($repository->feeds(), $target);
    }

    public function head(): PublicationHead
    {
        return $this->repository->head();
    }

    /** @return list<Release> */
    public function list(): array
    {
        return $this->repository->list();
    }

    public function runnable(): bool
    {
        $id = $this->head()->pendingId;
        return $this->repository->feeds()->dirty() || ($id === null ? $this->repository->due($this->clock->now()) : $this->repository->find($id)?->status !== 'needs_attention');
    }

    public function approve(string $previewId, ?string $expectedPrevious, SessionContext $actor, ?\DateTimeImmutable $scheduledAt = null): Release
    {
        $this->authorize($actor);
        if ($scheduledAt !== null) {
            ReleaseTime::assertFuture($scheduledAt, $this->clock->now());
        }
        $head = $this->head();
        if ($head->pendingId !== null || $head->activeId !== $expectedPrevious) {
            throw new PublicationConflict();
        }
        $artifact = $this->artifact($previewId);
        $this->repository->feeds()->assertAllowed(FeedTransition::from($artifact));
        $this->holding?->assertApproval($artifact);
        // Old approvals are not repaired silently after a different release/context.
        $history = $head->history?->context->checksum() === $this->context->checksum() ? $head->history : null;
        foreach ($artifact->holding() ? [] : ($history?->redirectsFor($artifact->routes, $this->profileId) ?? []) as $required) {
            $matches = array_filter($artifact->redirects, static fn($redirect): bool => $redirect->from === $required->from && $redirect->to === $required->to);
            if (count($matches) !== 1) {
                throw new PublicationFailure('new_preview_required');
            }
        }
        $release = new Release($this->id(), $previewId, $expectedPrevious, $scheduledAt === null ? 'queued' : 'scheduled', $artifact->checksum, $actor->userId ?? throw new PublicationFailure('authorization_required'), null, $this->target->identity(), scheduledAt: $scheduledAt?->setTimezone(new \DateTimeZone('UTC')), scheduleRevision: $scheduledAt === null ? 0 : 1);
        $this->repository->enqueue($release, $this->id(), $this->clock->now());
        return $release;
    }

    public function scheduleTime(string $local): \DateTimeImmutable
    {
        return ReleaseTime::parse($local, $this->timeZone, $this->clock->now());
    }

    public function changeSchedule(string $id, int $expectedRevision, ?\DateTimeImmutable $at, SessionContext $actor): void
    {
        $this->authorize($actor);
        if ($at !== null) {
            ReleaseTime::assertFuture($at, $this->clock->now());
        }
        $this->repository->changeSchedule($id, $expectedRevision, $at, $actor->userId ?? throw new PublicationFailure('authorization_required'), $this->id(), $this->clock->now());
    }

    /** @return list<Release> */
    public function scheduledForRecord(string $typeId, string $recordId): array
    {
        return $this->repository->scheduledForRecord($typeId, $recordId);
    }

    /** Reserve due intent before other worker jobs can consume the invocation's budget. */
    public function startScheduled(): bool
    {
        return $this->target->locked(function (): bool {
            $this->assertTargetBinding();
            $this->admitSchedule();
            return true;
        });
    }

    /** Rollback is a new attributed activation of retained exact bytes, never a DB-only pointer edit. */
    public function rollback(string $id, ?string $expectedPrevious, SessionContext $actor): Release
    {
        $this->authorize($actor);
        $release = $this->repository->find($id);
        if ($release === null || $release->status !== 'retained') {
            throw new PublicationConflict();
        }
        $head = $this->head();
        if ($head->activeId !== $expectedPrevious || $head->pendingId !== null) {
            throw new PublicationConflict();
        }
        $artifact = $this->artifact($release->previewId);
        $this->repository->feeds()->assertAllowed(FeedTransition::from($artifact));
        // Verify historical route collisions before any effect, even though rollback preserves old rules.
        if (!$artifact->holding()) {
            $head->history?->redirectsFor($artifact->routes, $this->profileId);
        }
        $next = new Release($this->id(), $release->previewId, $expectedPrevious, 'queued', $artifact->checksum, $actor->userId ?? throw new PublicationFailure('authorization_required'), null, $this->target->identity(), true);
        $this->repository->enqueue($next, $this->id(), $this->clock->now());
        return $next;
    }

    /** Returns true only when a pending operation settles, false when idle/busy/in progress. */
    public function runBatch(int $maximumFiles = 8): bool
    {
        return $this->target->locked(function () use ($maximumFiles): bool {
            $this->assertTargetBinding();
            if (!$this->feed->synchronize($this->clock->now(), $maximumFiles)) {
                return false;
            }
            $this->target->retainFeed($this->repository->feeds()->retention());
            $this->admitSchedule();
            $head = $this->head();
            if ($head->pendingId === null) {
                return false;
            }
            $release = $this->repository->find($head->pendingId) ?? throw new PublicationFailure('pending_release_missing');
            if ($release->status === 'needs_attention') {
                return false;
            }
            if ($head->activeId !== $release->previousId) {
                throw new PublicationConflict();
            }
            if ($release->targetChecksum !== $this->target->identity()) {
                throw new PublicationFailure('publication_target_changed');
            }
            try {
                $artifact = $this->artifact($release->previewId);
                if ($artifact->checksum !== $release->artifactChecksum) {
                    throw new PublicationFailure('approved_artifact_changed');
                }
                $previousRelease = $release->previousId === null ? null : $this->repository->find($release->previousId);
                $previous = $previousRelease === null ? null : $this->historicalArtifact($previousRelease);
                $recovering = $release->status === 'recovering';
                $transition = FeedTransition::from($artifact);
                if (!$recovering) {
                    $this->repository->feeds()->assertAllowed($transition);
                }
                $sourcePreview = $recovering ? $previousRelease?->previewId : $release->previewId;
                $read = fn(string $path): string|\Utsuri\PortableCms\Conventional\Media\MediaDelivery => $sourcePreview === null ? throw new PublicationFailure('recovery_source_missing') : $this->delivery($sourcePreview, $path);
                $this->repository->progress($release, $recovering, null, $this->clock->now());
                if (!$this->target->advance($release, $artifact, $previous, $read, $maximumFiles, $recovering)) {
                    return false;
                }
                if ($recovering) {
                    $this->repository->recovered($release, $this->id(), $this->clock->now());
                    return true;
                }
                $verificationId = $this->id();
                $receipt = $this->target->receipt($release, $artifact, $verificationId);
                $this->target->authenticate($receipt, $release, $artifact);
                $now = $this->clock->now();
                $previousHistory = $head->history?->context->checksum() === $this->context->checksum() ? $head->history : null;
                // Holding is not slug authority: preserve the last live history for relaunch.
                $history = $artifact->holding() ? $previousHistory : PublishedSlugHistory::afterVerifiedActivation($artifact->routes, $this->profileId, ['releaseId' => $release->id, 'artifactSha256' => $artifact->checksum,
                    'activatedAt' => $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'), 'verificationId' => $verificationId], $previousHistory);
                $this->repository->activate($release, $history, $receipt, $this->id(), $now, $transition);
            } catch (\Throwable $error) {
                $this->repository->progress($release, true, $error instanceof PublicationFailure ? $error->codeName : 'publication_operation_failed', $this->clock->now());
                throw $error;
            }
            // A committed activation must never be turned into recovery by a later outbox failure.
            return $this->feed->synchronize($this->clock->now(), $maximumFiles);
        });
    }

    public function purgeFeed(string $snapshotId, SessionContext $actor, int $maximumFiles = 8): bool
    {
        $this->authorize($actor);
        return $this->target->locked(function () use ($snapshotId, $actor, $maximumFiles): bool {
            $this->assertTargetBinding();
            $this->repository->feeds()->purge($snapshotId, $actor->userId ?? throw new PublicationFailure('authorization_required'), $this->id(), $this->clock->now());
            return $this->feed->synchronize($this->clock->now(), $maximumFiles);
        });
    }

    public function forget(string $id, SessionContext $actor): void
    {
        $this->authorize($actor);
        if (!$this->target->locked(function () use ($id, $actor): bool {
            $this->repository->forget($id, $actor->userId ?? throw new PublicationFailure('authorization_required'), $this->id(), $this->clock->now());
            $this->target->cleanupJournal($id);
            return true;
        })) {
            throw new PublicationConflict();
        }
    }

    public function retry(string $pendingId, ?string $expectedPrevious, SessionContext $actor): void
    {
        $this->authorize($actor);
        $this->repository->retry($pendingId, $expectedPrevious, $actor->userId ?? throw new PublicationFailure('authorization_required'), $this->id(), $this->clock->now());
    }

    public function artifact(string $previewId): ReleaseArtifact
    {
        $preview = $this->source($previewId)->releaseCandidate($previewId);
        $inventory = PreviewBuild::fromManifest($preview->manifest ?? throw new PublicationFailure('preview_inventory_missing'));
        return new ReleaseArtifact($this->bytes($previewId, 'artifact.json'), $inventory, $this->context, $this->profileId, $this->artifactSchema);
    }

    /** Recovery may restore the preceding verified origin, never an imported source. */
    private function historicalArtifact(Release $release): ReleaseArtifact
    {
        $json = $this->bytes($release->previewId, 'artifact.json');
        if (!hash_equals($release->artifactChecksum, hash('sha256', $json))) {
            throw new PublicationFailure('approved_artifact_changed');
        }
        $document = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($document) || !is_array($document['publicUrlContext'] ?? null)) {
            throw new PublicationFailure('public_context_missing');
        }
        $context = PublicUrlContext::from(array_intersect_key($document['publicUrlContext'], array_flip(['formatVersion', 'baseUrl'])));
        $preview = $this->source($release->previewId)->releaseCandidate($release->previewId);
        return new ReleaseArtifact($json, PreviewBuild::fromManifest($preview->manifest ?? throw new PublicationFailure('preview_inventory_missing')), $context, $this->profileId, $this->artifactSchema);
    }

    private function bytes(string $previewId, string $path): string
    {
        $body = $this->delivery($previewId, $path);
        return is_string($body) ? $body : throw new PublicationFailure('expected_text_artifact');
    }

    private function delivery(string $previewId, string $path): string|\Utsuri\PortableCms\Conventional\Media\MediaDelivery
    {
        $body = $this->source($previewId)->artifact($previewId, $path) ?? throw new PublicationFailure('approved_file_missing');
        return $body->file ?? $body->contents;
    }

    private function source(string $id): PreviewService
    {
        return $this->holding?->builds->find($id) !== null ? $this->holding->builds : $this->previews;
    }

    private function authorize(SessionContext $actor): void
    {
        if (!$actor->administrator() || $actor->userId === null) {
            throw new PublicationFailure('authorization_required');
        }
    }

    private function admitSchedule(): void
    {
        if ($this->head()->pendingId === null && $this->repository->due($this->clock->now())) {
            $this->repository->startDue($this->id(), $this->clock->now());
        }
    }

    private function assertTargetBinding(): void
    {
        $head = $this->head();
        foreach ([$head->activeId, $head->pendingId] as $id) {
            if ($id !== null && $this->repository->find($id)?->targetChecksum !== $this->target->identity()) {
                throw new PublicationFailure('publication_target_changed');
            }
        }
    }

    private function id(): string
    {
        return bin2hex($this->random->bytes(16));
    }
}
