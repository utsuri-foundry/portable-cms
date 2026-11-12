<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Import;

use Utsuri\PortableCms\Conventional\Export\ExportCapacity;
use Utsuri\PortableCms\Conventional\Export\ExportDataset;
use Utsuri\PortableCms\Conventional\Export\ExportMemory;

/** A bounded current-content projection; historical bodies never enter the import plan. */
final readonly class ImportSource implements ExportDataset
{
    public const MAXIMUM_BYTES = 8 * 1024 * 1024;

    /** @param array<string, mixed> $document */
    private function __construct(public array $document, public int $historicalRevisions, public int $derivatives) {}

    public static function from(ExportDataset $source): self
    {
        foreach (['records', 'relationships', 'media'] as $kind) {
            if ($source->count($kind) > ExportCapacity::limit($kind)) {
                throw new ImportRejected('current_content_count_limit');
            }
        }
        // Measure one row at a time before retaining the decoded projection and plan together.
        $bytes = 0;
        self::admit($bytes, $source->metadata());
        $derivatives = 0;
        foreach ($source->rows('records') as $record) {
            self::admit($bytes, $record);
            self::admit($bytes, $source->revision($record['currentRevisionId']));
        }
        foreach ($source->rows('relationships') as $row) {
            self::admit($bytes, $row);
        }
        foreach ($source->rows('media') as $row) {
            $derivatives += count($row['derivatives']);
            $row['derivatives'] = [];
            self::admit($bytes, $row);
        }
        $document = $source->metadata() + ['records' => [], 'revisions' => [], 'relationships' => [], 'media' => []];
        foreach ($source->rows('records') as $record) {
            $document['records'][] = $record;
            $revision = $source->revision($record['currentRevisionId']);
            $document['revisions'][$revision['id']] = $revision;
        }
        foreach (['relationships', 'media'] as $kind) {
            foreach ($source->rows($kind) as $row) {
                if ($kind === 'media') {
                    $row['derivatives'] = [];
                }
                $document[$kind][] = $row;
            }
        }
        return new self($document, $source->count('revisions') - $source->count('records'), $derivatives);
    }

    /** @param array<string, mixed> $row */
    private static function admit(int &$bytes, array $row): void
    {
        $bytes += strlen(json_encode($row, JSON_THROW_ON_ERROR));
        if ($bytes > self::MAXIMUM_BYTES) {
            throw new ImportRejected('current_content_byte_limit');
        }
        if (ExportMemory::available() < 33_554_432 + $bytes * 24) {
            throw new ImportRejected('insufficient_current_content_memory');
        }
    }

    public function metadata(): array
    {
        return array_diff_key($this->document, array_flip(ExportCapacity::COLLECTIONS));
    }

    public function rows(string $collection): iterable
    {
        yield from $this->document[$collection];
    }

    public function count(string $collection): int
    {
        return count($this->document[$collection]);
    }

    public function revision(string $id): array
    {
        return $this->document['revisions'][$id] ?? throw new ImportRejected('current_revision_missing');
    }
}
