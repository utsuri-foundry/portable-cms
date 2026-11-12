<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Export;

/** Compact identity proofs only: no historical revision bodies are retained. */
final class ExportGraph
{
    /** @param array<string, mixed> $metadata
     * @param \Closure(string): iterable<array<string, mixed>> $rows
     * @param array<string, int> $limits
     */
    public static function validate(array $metadata, \Closure $rows, array $limits, bool $streamed = false): void
    {
        $records = [];
        foreach (self::bounded('records', $rows, $limits) as $record) {
            if (isset($records[$record['id']])) {
                throw new \InvalidArgumentException('Normalized export contains duplicate records');
            }
            $records[$record['id']] = [$record['currentRevisionId'], $record['currentRevisionNumber'], NormalizedExport::publishedRevisionId($record)];
        }
        $revisions = $numbers = $sequences = [];
        foreach (self::bounded('revisions', $rows, $limits, $streamed) as $revision) {
            $key = $revision['recordId'] . ':' . $revision['revisionNumber'];
            if (isset($revisions[$revision['id']]) || isset($numbers[$key]) || !isset($records[$revision['recordId']])) {
                throw new \InvalidArgumentException('Normalized export revision graph is invalid');
            }
            if (isset($revision['siteSequence'])) {
                $sequence = $revision['siteSequence'];
                if ($sequence > ($metadata['siteSequence'] ?? 0) || isset($sequences[$sequence])) {
                    throw new \InvalidArgumentException('Normalized export site sequence graph is invalid');
                }
                $sequences[$sequence] = true;
            }
            $revisions[$revision['id']] = $revision['recordId'] . ':' . $revision['revisionNumber'];
            $numbers[$key] = true;
        }
        foreach ($records as $id => [$revision, $number, $published]) {
            if (($revisions[$revision] ?? null) !== $id . ':' . $number) {
                throw new \InvalidArgumentException('Normalized export current revision is invalid');
            }
            if ($published !== null && (!isset($revisions[$published]) || !str_starts_with($revisions[$published], $id . ':') || (int) substr($revisions[$published], 33) > $number)) {
                throw new \InvalidArgumentException('Normalized export published revision graph is invalid');
            }
        }
        unset($numbers, $sequences);
        $relations = [];
        foreach (self::bounded('relationships', $rows, $limits) as $relation) {
            $key = $relation['recordId'] . ':' . $relation['revisionId'] . ':' . $relation['fieldId'] . ':' . $relation['position'];
            if (isset($relations[$key]) || !isset($records[$relation['targetRecordId']])
                || !in_array($relation['revisionId'], [$records[$relation['recordId']][0] ?? null, $records[$relation['recordId']][2] ?? null], true)
                || !isset($revisions[$relation['revisionId']])) {
                throw new \InvalidArgumentException('Normalized export relationship graph is invalid');
            }
            $relations[$key] = true;
        }
        unset($relations, $revisions);
        $mediaIds = [];
        $derivatives = 0;
        foreach (self::bounded('media', $rows, $limits) as $media) {
            \Utsuri\PortableCms\Conventional\Media\MediaText::from($media['text'] ?? null, $metadata['profile']['locales']);
            if (isset($mediaIds[$media['id']]) || ($media['ownerRecordId'] !== null && !isset($records[$media['ownerRecordId']]))) {
                throw new \InvalidArgumentException('Normalized export media graph is invalid');
            }
            $mediaIds[$media['id']] = true;
            $derivatives += count($media['derivatives']);
            if ($derivatives > EXPORT_MAX_DERIVATIVES) {
                throw new \InvalidArgumentException('Normalized export contains too many derivatives');
            }
        }
    }

    /** @param \Closure(string): iterable<array<string, mixed>> $rows
     * @param array<string, int> $limits
     * @return iterable<array<string, mixed>>
     */
    private static function bounded(string $kind, \Closure $rows, array $limits, bool $streamed = false): iterable
    {
        $count = 0;
        foreach ($rows($kind) as $row) {
            if (++$count > $limits[$kind]) {
                throw new \InvalidArgumentException('Normalized export collection is invalid');
            }
            NormalizedExport::entry($kind, $row, $streamed);
            yield $row;
        }
    }
}
