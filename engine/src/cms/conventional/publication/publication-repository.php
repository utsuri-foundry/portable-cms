<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Publication;

use Utsuri\PortableCms\Conventional\Compiler\PublishedSlugHistory;

final readonly class Release
{
    public function __construct(
        public string $id,
        public string $previewId,
        public ?string $previousId,
        public string $status,
        public string $artifactChecksum,
        public string $actorId,
        public ?string $errorCode,
        public string $targetChecksum,
        public bool $rollback = false,
        public ?\DateTimeImmutable $scheduledAt = null,
        public int $scheduleRevision = 0,
    ) {
        foreach ([$id, $previewId, $actorId, ...($previousId === null ? [] : [$previousId])] as $identity) {
            if (preg_match('/\A[a-f0-9]{32}\z/', $identity) !== 1) {
                throw new \InvalidArgumentException('Release identity is invalid');
            }
        }
        if ($scheduleRevision < 0 || ($status === 'scheduled' && ($scheduledAt === null || $scheduleRevision < 1)) || preg_match('/\A[a-f0-9]{64}\z/', $artifactChecksum) !== 1 || preg_match('/\A[a-f0-9]{64}\z/', $targetChecksum) !== 1 || !in_array($status, ['scheduled', 'needs_approval', 'cancelled', 'queued', 'applying', 'recovering', 'needs_attention', 'active', 'retained', 'failed'], true)) {
            throw new \InvalidArgumentException('Release facts are invalid');
        }
    }
}

final readonly class PublicationHead
{
    public function __construct(public ?string $activeId, public ?string $pendingId, public ?PublishedSlugHistory $history) {}
}

interface PublicationRepository
{
    public function feeds(): FeedRepository;

    public function head(): PublicationHead;

    public function find(string $id): ?Release;

    /** @return list<Release> */
    public function list(): array;

    public function enqueue(Release $release, string $auditId, \DateTimeImmutable $now): void;

    public function due(\DateTimeImmutable $now): bool;

    /** Claims at most one due approval, or marks it stale, under the publication-head lock. */
    public function startDue(string $auditId, \DateTimeImmutable $now): void;

    public function changeSchedule(string $id, int $expectedRevision, ?\DateTimeImmutable $at, string $actorId, string $auditId, \DateTimeImmutable $now): void;

    /** @return list<Release> */
    public function scheduledForRecord(string $typeId, string $recordId): array;

    public function progress(Release $release, bool $recovering, ?string $errorCode, \DateTimeImmutable $now): void;

    public function activate(Release $release, ?PublishedSlugHistory $history, string $receipt, string $auditId, \DateTimeImmutable $now, ?FeedTransition $feed = null): void;

    public function recovered(Release $release, string $auditId, \DateTimeImmutable $now): void;

    public function retry(string $pendingId, ?string $expectedPrevious, string $actorId, string $auditId, \DateTimeImmutable $now): void;

    public function forget(string $id, string $actorId, string $auditId, \DateTimeImmutable $now): void;
}
