<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Export;

use PDO;

/** Eight buffered source rows at a time, inside the caller's repeatable-read snapshot. */
final readonly class MariaDbExportRows
{
    public function __construct(private PDO $pdo, private ExportBudget $budget = new ExportBudget()) {}

    /** @return iterable<array<string, mixed>> */
    public function rows(string $kind, int $maximum): iterable
    {
        ExportCapacity::limit($kind);
        $count = 0;
        $cursor = [];
        for (;;) {
            $this->budget->check();
            $page = match ($kind) {
                'records' => $this->records($cursor),
                'revisions' => $this->revisions($cursor),
                'relationships' => $this->relationships($cursor),
                'media' => $this->media($cursor),
                default => throw new \InvalidArgumentException('Unknown export collection'),
            };
            $pageCount = 0;
            foreach ($page as $row) {
                $pageCount++;
                $cursor = match ($kind) {
                    'records' => ['type' => $row['typeId'], 'id' => $row['id']],
                    'revisions' => ['parent' => $row['recordId'], 'number' => $row['revisionNumber']],
                    'relationships' => ['parent' => $row['recordId'], 'revision' => $row['revisionId'], 'field' => $row['fieldId'], 'position' => $row['position']],
                    default => ['owner' => $row['ownerRecordId'], 'created' => (new \DateTimeImmutable($row['createdAt']))->format('Y-m-d H:i:s.u'), 'id' => $row['id']],
                };
                if (++$count > $maximum) {
                    throw new ExportCapacityFailure('export_collection_capacity_exceeded');
                }
                yield $row;
            }
            if ($pageCount < 8) {
                break;
            }
        }
    }

    /** @param array<string, mixed> $cursor
     * @return list<array<string, mixed>>
     */
    private function records(array $cursor): array
    {
        $query = $this->pdo->prepare(<<<'SQL'
SELECT r.id, r.type_id, r.workflow_state, r.current_revision_id, r.current_revision_number, r.published_revision_id,
       COALESCE(a.source_created_by, r.created_by) AS created_by, r.created_at,
       CASE WHEN a.restored_revision_id = r.current_revision_id THEN a.source_updated_by ELSE r.updated_by END AS updated_by, r.updated_at
FROM cms_records r
LEFT JOIN cms_restore_authorship a ON a.entity_kind = 'record' AND a.entity_id = r.id
WHERE (r.type_id, r.id) > (:type, :id)
ORDER BY r.type_id ASC, r.id ASC
LIMIT 8
SQL);
        $query->execute($cursor + ['type' => '', 'id' => '']);
        $rows = $query->fetchAll(PDO::FETCH_ASSOC);
        return array_map(static fn(array $row): array => [
            'id' => (string) $row['id'],
            'typeId' => (string) $row['type_id'],
            'workflowState' => (string) $row['workflow_state'],
            'currentRevisionId' => (string) $row['current_revision_id'],
            'publishedRevisionId' => $row['published_revision_id'],
            'currentRevisionNumber' => (int) $row['current_revision_number'],
            'createdBy' => (string) $row['created_by'],
            'createdAt' => self::databaseTimestamp((string) $row['created_at']),
            'updatedBy' => (string) $row['updated_by'],
            'updatedAt' => self::databaseTimestamp((string) $row['updated_at']),
        ], $rows);
    }

    /** @param array<string, mixed> $cursor
     * @return iterable<array<string, mixed>>
     */
    private function revisions(array $cursor): iterable
    {
        $query = $this->pdo->prepare(<<<'SQL'
SELECT r.id, r.record_id, r.revision_number, r.profile_id, r.profile_version, r.site_sequence,
       r.body_json, COALESCE(a.source_created_by, r.created_by) AS created_by, r.created_at
FROM cms_record_revisions r
LEFT JOIN cms_restore_authorship a ON a.entity_kind = 'revision' AND a.entity_id = r.id
WHERE r.record_id > :parent OR (r.record_id = :same_parent AND r.revision_number > :number)
ORDER BY r.record_id ASC, r.revision_number ASC, r.id ASC
LIMIT 8
SQL);
        $query->execute($cursor + ['parent' => '', 'number' => 0, 'same_parent' => $cursor['parent'] ?? '']);
        $rows = $query->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $body = json_decode((string) $row['body_json'], true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($body) || array_is_list($body)) {
                throw new \RuntimeException('Stored revision body is invalid');
            }
            yield [
                'id' => (string) $row['id'],
                'recordId' => (string) $row['record_id'],
                'revisionNumber' => (int) $row['revision_number'],
                ...($row['site_sequence'] === null ? [] : ['siteSequence' => (int) $row['site_sequence']]),
                'profileId' => (string) $row['profile_id'],
                'profileVersion' => (string) $row['profile_version'],
                'body' => $body,
                'createdBy' => (string) $row['created_by'],
                'createdAt' => self::databaseTimestamp((string) $row['created_at']),
            ];
        }
    }

    /** @param array<string, mixed> $cursor
     * @return list<array<string, mixed>>
     */
    private function relationships(array $cursor): array
    {
        $query = $this->pdo->prepare(<<<'SQL'
SELECT record_id, revision_id, field_id, relation_kind, target_record_id, position
FROM cms_record_relationship_index
WHERE (record_id, revision_id, field_id, position) > (:parent, :revision, :field, :position)
ORDER BY record_id ASC, revision_id ASC, field_id ASC, position ASC, target_record_id ASC
LIMIT 8
SQL);
        $query->execute($cursor + ['parent' => '', 'revision' => '', 'field' => '', 'position' => -1]);
        $rows = $query->fetchAll(PDO::FETCH_ASSOC);
        return array_map(static fn(array $row): array => [
            'recordId' => (string) $row['record_id'],
            'revisionId' => (string) $row['revision_id'],
            'fieldId' => (string) $row['field_id'],
            'kind' => (string) $row['relation_kind'],
            'targetRecordId' => (string) $row['target_record_id'],
            'position' => (int) $row['position'],
        ], $rows);
    }

    /** @param array<string, mixed> $cursor
     * @return list<array<string, mixed>>
     */
    private function media(array $cursor): array
    {
        // NULL sorts before record IDs; preserve that order without losing NULL rows
        // to SQL tuple comparison or changing the legacy record-owned ordering.
        $after = $cursor === [] ? '1 = 1' : (($cursor['owner'] ?? null) === null
            ? '(m.owner_record_id IS NULL AND (m.created_at, m.id) > (:created, :id)) OR m.owner_record_id IS NOT NULL'
            : '(m.owner_record_id, m.created_at, m.id) > (:owner, :created, :id)');
        $sql = <<<'SQL'
SELECT m.id, m.owner_record_id, m.status, m.original_name, m.declared_type, m.detected_type,
       m.width, m.height, m.byte_size, m.checksum, m.text_json, m.focal_x, m.focal_y, m.metadata_revision, COALESCE(a.source_created_by, m.created_by) AS created_by, m.created_at, m.updated_at
FROM cms_media m
LEFT JOIN cms_restore_authorship a ON a.entity_kind = 'media' AND a.entity_id = m.id
WHERE %s
ORDER BY m.owner_record_id ASC, m.created_at ASC, m.id ASC
LIMIT 8
SQL;
        $query = $this->pdo->prepare(str_replace('%s', $after, $sql));
        $parameters = $cursor;
        if (($parameters['owner'] ?? null) === null) {
            unset($parameters['owner']);
        }
        $query->execute($parameters);
        $rows = $query->fetchAll(PDO::FETCH_ASSOC);
        $derivatives = [];
        $derivativeRows = [];
        if ($rows !== []) {
            $parameters = [];
            foreach ($rows as $index => $row) {
                $parameters['id' . $index] = $row['id'];
            }
            $placeholders = implode(', ', array_map(static fn(string $key): string => ':' . $key, array_keys($parameters)));
            $query = $this->pdo->prepare('SELECT media_id, variant, filename, mime, width, height, byte_size, checksum, processor_id, processor_version, recipe_version, parameters_json, created_at FROM cms_media_derivatives WHERE media_id IN (' . $placeholders . ') ORDER BY media_id ASC, variant ASC LIMIT 20001');
            $query->execute($parameters);
            $derivativeRows = $query->fetchAll(PDO::FETCH_ASSOC);
        }
        if (count($derivativeRows) > EXPORT_MAX_DERIVATIVES) {
            throw new \RuntimeException('Normalized export contains too many media derivatives');
        }
        foreach ($derivativeRows as $row) {
            $parameters = json_decode((string) $row['parameters_json'], true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($parameters) || array_is_list($parameters)) {
                throw new \RuntimeException('Stored derivative parameters are invalid');
            }
            $derivatives[(string) $row['media_id']][] = [
                'variant' => (string) $row['variant'],
                'fileName' => (string) $row['filename'],
                'mime' => (string) $row['mime'],
                'width' => (int) $row['width'],
                'height' => (int) $row['height'],
                'byteSize' => (int) $row['byte_size'],
                'sha256' => (string) $row['checksum'],
                'processorId' => (string) $row['processor_id'],
                'processorVersion' => (string) $row['processor_version'],
                'recipeVersion' => (int) $row['recipe_version'],
                'parameters' => $parameters,
                'createdAt' => self::databaseTimestamp((string) $row['created_at']),
            ];
        }
        return array_map(static fn(array $row): array => [
            'id' => (string) $row['id'],
            'ownerRecordId' => $row['owner_record_id'] === null ? null : (string) $row['owner_record_id'],
            'status' => (string) $row['status'],
            'originalName' => (string) $row['original_name'],
            'text' => \Utsuri\PortableCms\Conventional\Media\MediaText::from($row['text_json'] === null ? null : json_decode((string) $row['text_json'], true, 16, JSON_THROW_ON_ERROR))->document(),
            'metadataRevision' => (int) $row['metadata_revision'],
            'focalPoint' => (new \Utsuri\PortableCms\Conventional\Media\FocalPoint((int) $row['focal_x'], (int) $row['focal_y']))->document(),
            'declaredType' => (string) $row['declared_type'],
            'detectedType' => is_string($row['detected_type']) ? $row['detected_type'] : null,
            'width' => $row['width'] === null ? null : (int) $row['width'],
            'height' => $row['height'] === null ? null : (int) $row['height'],
            'original' => [
                'mime' => is_string($row['detected_type']) ? $row['detected_type'] : (string) $row['declared_type'],
                'byteSize' => (int) $row['byte_size'],
                'sha256' => (string) $row['checksum'],
            ],
            'derivatives' => $derivatives[(string) $row['id']] ?? [],
            'createdBy' => (string) $row['created_by'],
            'createdAt' => self::databaseTimestamp((string) $row['created_at']),
            'updatedAt' => self::databaseTimestamp((string) $row['updated_at']),
        ], $rows);
    }


    private static function databaseTimestamp(string $time): string
    {
        return (new \DateTimeImmutable($time, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s.u\\Z');
    }
}
