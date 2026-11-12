<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Restore;

use Utsuri\PortableCms\Conventional\Content\RecordConflict;
use Utsuri\PortableCms\Conventional\Content\RecordRepository;
use Utsuri\PortableCms\Conventional\Content\RecordSnapshot;

/** Read-only graph used by the ordinary authoritative content validator. */
final class ExportRecordLookup implements RecordRepository
{
    /** @var array<string, array<string, mixed>> */
    private array $records = [];

    private readonly \Utsuri\PortableCms\Conventional\Export\ExportDataset $export;

    /** @param array<string, mixed>|\Utsuri\PortableCms\Conventional\Export\ExportDataset $document */
    public function __construct(array|\Utsuri\PortableCms\Conventional\Export\ExportDataset $document)
    {
        $this->export = is_array($document) ? new \Utsuri\PortableCms\Conventional\Export\NormalizedExport($document) : $document;
        foreach ($this->export->rows('records') as $record) {
            $this->records[$record['id']] = $record;
        }
    }

    public function record(string $id): RecordSnapshot
    {
        $record = $this->metadata($id);
        $revision = $this->export->revision($record['currentRevisionId']);
        return new RecordSnapshot($record['id'], $record['typeId'], $record['workflowState'], $revision['id'], $revision['revisionNumber'], $revision['body'], new \DateTimeImmutable($record['updatedAt']));
    }

    /** @return array<string, mixed> */
    public function metadata(string $id): array
    {
        return $this->records[$id] ?? throw new RestoreRejected('missing_record');
    }

    public function find(string $typeId, string $recordId): ?RecordSnapshot
    {
        $record = $this->records[$recordId] ?? null;
        return ($record['typeId'] ?? null) === $typeId ? $this->record($recordId) : null;
    }

    public function list(string $typeId, int $limit): array
    {
        $records = array_slice(array_values(array_filter($this->records, static fn(array $record): bool => $record['typeId'] === $typeId)), 0, $limit);
        return array_map(fn(array $record): RecordSnapshot => $this->record($record['id']), $records);
    }

    public function create(string $recordId, string $revisionId, string $typeId, string $profileId, string $profileVersion, array $body, array $relationships, string $actorUserId, string $auditId, \DateTimeImmutable $now): RecordSnapshot
    {
        throw new \LogicException('Export lookup is read-only');
    }

    public function update(string $typeId, string $recordId, int $expectedRevisionNumber, string $revisionId, string $profileId, string $profileVersion, array $body, array $relationships, string $actorUserId, string $auditId, \DateTimeImmutable $now): RecordSnapshot|RecordConflict
    {
        throw new \LogicException('Export lookup is read-only');
    }
}
