<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Import;

use Utsuri\PortableCms\Conventional\Content\RecordConflict;
use Utsuri\PortableCms\Conventional\Content\RecordRepository;
use Utsuri\PortableCms\Conventional\Content\RecordSnapshot;
use Utsuri\PortableCms\Conventional\Content\RelationshipProjection;

/** Planned targets permit cyclic graphs. Projection is deferred only inside the import transaction. */
final class ImportGraphRepository implements RecordRepository
{
    /** @var array<string, RecordSnapshot> */
    private array $planned = [];
    /** @var list<array{id: string, revisionId: string, relationships: list<RelationshipProjection>}> */
    private array $projections = [];
    /** @param list<ImportRecord> $records */
    public function __construct(private readonly RecordRepository $destination, array $records)
    {
        foreach ($records as $record) {
            if (!in_array($record->action, ['blocked', 'conflict'], true)) {
                $this->planned[$record->id] = new RecordSnapshot($record->id, $record->typeId, 'draft', $record->id, $record->expectedRevision ?? 1, $record->body, new \DateTimeImmutable('2000-01-01T00:00:00Z'));
            }
        }
    }
    public function find(string $typeId, string $recordId): ?RecordSnapshot
    {
        $record = $this->destination->find($typeId, $recordId) ?? $this->planned[$recordId] ?? null;
        return $record?->typeId === $typeId ? $record : null;
    }
    public function list(string $typeId, int $limit): array
    {
        return $this->destination->list($typeId, $limit);
    }
    public function create(string $recordId, string $revisionId, string $typeId, string $profileId, string $profileVersion, array $body, array $relationships, string $actorUserId, string $auditId, \DateTimeImmutable $now): RecordSnapshot
    {
        $result = $this->destination->create($recordId, $revisionId, $typeId, $profileId, $profileVersion, $body, [], $actorUserId, $auditId, $now);
        $this->projections[] = ['id' => $recordId, 'revisionId' => $revisionId, 'relationships' => $relationships];
        return $result;
    }
    public function update(string $typeId, string $recordId, int $expectedRevisionNumber, string $revisionId, string $profileId, string $profileVersion, array $body, array $relationships, string $actorUserId, string $auditId, \DateTimeImmutable $now): RecordSnapshot|RecordConflict
    {
        $result = $this->destination->update($typeId, $recordId, $expectedRevisionNumber, $revisionId, $profileId, $profileVersion, $body, [], $actorUserId, $auditId, $now);
        if ($result instanceof RecordSnapshot) {
            $this->projections[] = ['id' => $recordId, 'revisionId' => $revisionId, 'relationships' => $relationships];
        }
        return $result;
    }
    public function flush(ImportRepository $repository): void
    {
        foreach ($this->projections as $projection) {
            $repository->project($projection['id'], $projection['revisionId'], $projection['relationships']);
        }
    }
}
