<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Content;

final readonly class RelationshipProjection
{
    public function __construct(
        public string $fieldId,
        public string $kind,
        public string $targetRecordId,
        public int $position,
    ) {}
}

final readonly class RecordSnapshot
{
    public ?string $publishedRevisionId;
    public ?int $publishedRevisionNumber;
    public ?\DateTimeImmutable $publishedAt;

    /** @param array<string, mixed> $body */
    public function __construct(
        public string $id,
        public string $typeId,
        public string $workflowState,
        public string $revisionId,
        public int $revisionNumber,
        public array $body,
        public \DateTimeImmutable $updatedAt,
        ?string $publishedRevisionId = null,
        ?int $publishedRevisionNumber = null,
        ?\DateTimeImmutable $publishedAt = null,
        public int $editSequence = 0,
    ) {
        $this->publishedRevisionId = $workflowState === 'published' ? ($publishedRevisionId ?? $revisionId) : null;
        $this->publishedRevisionNumber = $workflowState === 'published' ? ($publishedRevisionNumber ?? $revisionNumber) : null;
        $this->publishedAt = $workflowState === 'published' ? ($publishedAt ?? $updatedAt) : null;
    }

    public function hasUnpublishedChanges(): bool
    {
        return $this->workflowState === 'published' && $this->publishedRevisionId !== $this->revisionId;
    }
}

interface RecordRepository
{
    /** @return list<RecordSnapshot> */
    public function list(string $typeId, int $limit): array;

    public function find(string $typeId, string $recordId): ?RecordSnapshot;

    /**
     * @param array<string, mixed> $body
     * @param list<RelationshipProjection> $relationships
     */
    public function create(
        string $recordId,
        string $revisionId,
        string $typeId,
        string $profileId,
        string $profileVersion,
        array $body,
        array $relationships,
        string $actorUserId,
        string $auditId,
        \DateTimeImmutable $now,
    ): RecordSnapshot;

    /**
     * @param array<string, mixed> $body
     * @param list<RelationshipProjection> $relationships
     */
    public function update(
        string $typeId,
        string $recordId,
        int $expectedRevisionNumber,
        string $revisionId,
        string $profileId,
        string $profileVersion,
        array $body,
        array $relationships,
        string $actorUserId,
        string $auditId,
        \DateTimeImmutable $now,
    ): RecordSnapshot|RecordConflict;
}

final readonly class RecordConflict
{
    public function __construct(public RecordSnapshot $current) {}
}

final class RecordNotFound extends \RuntimeException {}

/** A status transition copies the immutable body into a new concurrency revision. */
interface RecordWorkflowRepository
{
    /** @return array<string, int> */
    public function stateCounts(string $typeId): array;

    public function transition(RecordSnapshot $record, string $state, string $revisionId, string $profileId, string $profileVersion, string $actorUserId, string $auditId, \DateTimeImmutable $now): RecordSnapshot|RecordConflict;
}

interface PublishedRecordRepository extends RecordWorkflowRepository
{
    public function pendingPublicationCount(): int;

    public function published(RecordSnapshot $record): ?RecordSnapshot;

    /** @param list<RelationshipProjection> $relationships */
    public function discard(RecordSnapshot $record, RecordSnapshot $published, array $relationships, string $revisionId, string $profileId, string $profileVersion, string $actorUserId, string $auditId, \DateTimeImmutable $now): RecordSnapshot|RecordConflict;
}

final class RecordPermissionDenied extends \RuntimeException {}
