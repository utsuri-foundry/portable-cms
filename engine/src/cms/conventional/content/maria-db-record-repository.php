<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Content;

use PDO;
use Throwable;

final class MariaDbRecordRepository implements RecordRepository, RecordBrowser, RecordActivity, PublishedRecordRepository, RecordDeletionRepository, EditingRecordRepository
{
    public function __construct(private readonly PDO $pdo)
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }

    public function counts(): array
    {
        $counts = [];
        foreach ($this->pdo->query('SELECT type_id, COUNT(*) AS total FROM cms_records GROUP BY type_id')?->fetchAll(PDO::FETCH_ASSOC) ?? [] as $row) {
            $counts[(string) $row['type_id']] = (int) $row['total'];
        }
        return $counts;
    }

    public function deleteArchived(RecordSnapshot $record, string $actorId, string $auditId, \DateTimeImmutable $now): bool|RecordConflict|ValidationFailure
    {
        return (new MariaDbRecordDeletion($this->pdo))->delete($record, $actorId, $auditId, $now);
    }

    public function recent(int $limit): array
    {
        $limit = max(1, min(20, $limit));
        $statement = $this->pdo->query(<<<SQL
SELECT r.id, r.type_id, r.workflow_state, r.current_revision_id,
       r.current_revision_number, rr.body_json, rr.site_sequence, r.updated_at, r.published_revision_id,
       pv.revision_number AS published_revision_number, pv.created_at AS published_at
FROM cms_records r
JOIN cms_record_revisions rr ON rr.id = r.current_revision_id
LEFT JOIN cms_record_revisions pv ON pv.id = r.published_revision_id AND pv.record_id = r.id
ORDER BY r.updated_at DESC, r.id ASC
LIMIT {$limit}
SQL);
        return array_map($this->hydrate(...), $statement === false ? [] : $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function incomingReferences(array $recordIds): array
    {
        $recordIds = array_values(array_unique(array_filter($recordIds, static fn(string $id): bool => preg_match('/\A[a-f0-9]{32}\z/', $id) === 1)));
        if ($recordIds === [] || count($recordIds) > 100) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, count($recordIds), '?'));
        $statement = $this->pdo->prepare(
            'SELECT target_record_id, COUNT(DISTINCT record_id) AS total FROM cms_record_relationship_index'
            . ' WHERE target_record_id IN (' . $placeholders . ') GROUP BY target_record_id',
        );
        $statement->execute($recordIds);
        $counts = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $counts[(string) $row['target_record_id']] = (int) $row['total'];
        }
        return $counts;
    }

    public function list(string $typeId, int $limit): array
    {
        $limit = max(1, min(100, $limit));
        $statement = $this->pdo->prepare(<<<SQL
SELECT r.id, r.type_id, r.workflow_state, r.current_revision_id,
       r.current_revision_number, rr.body_json, rr.site_sequence, r.updated_at, r.published_revision_id,
       pv.revision_number AS published_revision_number, pv.created_at AS published_at
FROM cms_records r
JOIN cms_record_revisions rr ON rr.id = r.current_revision_id
LEFT JOIN cms_record_revisions pv ON pv.id = r.published_revision_id AND pv.record_id = r.id
WHERE r.type_id = :type_id
ORDER BY r.updated_at DESC, r.id ASC
LIMIT {$limit}
SQL);
        $statement->execute(['type_id' => $typeId]);
        return array_map($this->hydrate(...), $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function search(string|array $typeId, string $query, int $page, string $state = ''): RecordPage
    {
        $types = is_array($typeId) ? $typeId : [$typeId];
        if ($types === [] || count($types) > 8) {
            throw new \InvalidArgumentException('Record search types are invalid');
        }
        $parameters = [];
        foreach ($types as $index => $type) {
            if (!ProfileManifest::canonicalId($type)) {
                throw new \InvalidArgumentException('Record search type is invalid');
            }
            $parameters['type' . $index] = $type;
        }
        $placeholders = implode(', ', array_map(static fn(string $key): string => ':' . $key, array_keys($parameters)));
        $page = max(1, min(1000, $page));
        $query = mb_substr(trim($query), 0, 100);
        $offset = ($page - 1) * 20;
        // Search the bounded textual projection, including localized labels and aliases.
        // The pattern is a bound value; wildcard characters in an editor's query are literal.
        $pattern = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $query) . '%';
        if (!in_array($state, ['', 'draft', 'published', 'archived', 'published_changes'], true)) {
            throw new \InvalidArgumentException('Unknown record status');
        }
        $stateClause = $state === 'published_changes' ? " AND r.workflow_state = 'published' AND r.current_revision_id <> r.published_revision_id" : ($state === '' ? '' : ' AND r.workflow_state = :workflow_state');
        $statement = $this->pdo->prepare(<<<SQL
SELECT r.id, r.type_id, r.workflow_state, r.current_revision_id,
       r.current_revision_number, rr.body_json, rr.site_sequence, r.updated_at, r.published_revision_id,
       pv.revision_number AS published_revision_number, pv.created_at AS published_at
FROM cms_records r JOIN cms_record_revisions rr ON rr.id = r.current_revision_id
LEFT JOIN cms_record_revisions pv ON pv.id = r.published_revision_id AND pv.record_id = r.id
WHERE r.type_id IN ({$placeholders}) AND rr.body_json LIKE :query ESCAPE '!' {$stateClause}
ORDER BY r.updated_at DESC, r.id ASC LIMIT 21 OFFSET {$offset}
SQL);
        $statement->execute([...$parameters, 'query' => $pattern, ...(in_array($state, ['', 'published_changes'], true) ? [] : ['workflow_state' => $state])]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        return new RecordPage(array_map($this->hydrate(...), array_slice($rows, 0, 20)), count($rows) > 20, $page, $query);
    }

    public function stateCounts(string $typeId): array
    {
        $statement = $this->pdo->prepare('SELECT workflow_state, COUNT(*) AS total FROM cms_records WHERE type_id = :type GROUP BY workflow_state');
        $statement->execute(['type' => $typeId]);
        $states = array_fill_keys(['draft', 'published', 'archived'], 0);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $states[(string) $row['workflow_state']] = (int) $row['total'];
        }
        $pending = $this->pdo->prepare("SELECT COUNT(*) FROM cms_records WHERE type_id = :type AND workflow_state = 'published' AND current_revision_id <> published_revision_id");
        $pending->execute(['type' => $typeId]);
        $states['published_changes'] = (int) $pending->fetchColumn();
        return $states;
    }

    public function find(string $typeId, string $recordId): ?RecordSnapshot
    {
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT r.id, r.type_id, r.workflow_state, r.current_revision_id,
       r.current_revision_number, rr.body_json, rr.site_sequence, r.updated_at, r.published_revision_id,
       pv.revision_number AS published_revision_number, pv.created_at AS published_at
FROM cms_records r
JOIN cms_record_revisions rr ON rr.id = r.current_revision_id
LEFT JOIN cms_record_revisions pv ON pv.id = r.published_revision_id AND pv.record_id = r.id
WHERE r.type_id = :type_id AND r.id = :record_id
SQL);
        $statement->execute(['type_id' => $typeId, 'record_id' => $recordId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $this->hydrate($row) : null;
    }

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
        ?RecordEditIntent $editing = null,
    ): RecordSnapshot {
        $time = self::databaseTime($now);
        $encoded = self::encodeBody($body);
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $record = $this->pdo->prepare(<<<'SQL'
INSERT INTO cms_records (
    id, type_id, workflow_state, current_revision_id, current_revision_number,
    created_by, created_at, updated_by, updated_at
) VALUES (
    :id, :type_id, 'draft', :revision_id, 1,
    :created_by, :created_at, :updated_by, :updated_at
)
SQL);
            $record->execute([
                'id' => $recordId,
                'type_id' => $typeId,
                'revision_id' => $revisionId,
                'created_by' => $actorUserId,
                'created_at' => $time,
                'updated_by' => $actorUserId,
                'updated_at' => $time,
            ]);
            $this->insertRevision(
                $revisionId,
                $recordId,
                1,
                $profileId,
                $profileVersion,
                $encoded,
                $actorUserId,
                $time,
            );
            $this->projectRelationships($recordId, $revisionId, $relationships);
            (new \Utsuri\PortableCms\Conventional\Database\MariaDbSiteSequence($this->pdo))->revision($typeId, $recordId, $revisionId, 1, $now);
            $this->appendAudit($auditId, $actorUserId, 'record_created', [
                'recordId' => $recordId,
                'typeId' => $typeId,
                'revisionNumber' => 1,
            ], $time);
            $this->editing($recordId, $editing, $time);
            $result = $this->find($typeId, $recordId) ?? throw new RecordNotFound();
            if ($ownsTransaction) {
                $this->pdo->commit();
            }
        } catch (Throwable $error) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        return $result;
    }

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
        ?string $workflowState = null,
        ?string $operation = null,
        ?RecordEditIntent $editing = null,
    ): RecordSnapshot|RecordConflict {
        $time = self::databaseTime($now);
        $encoded = self::encodeBody($body);
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $history = new MariaDbRecordHistory($this->pdo);
            $historyPolicy = $history->lockPolicy();
            $lock = $this->pdo->prepare(<<<'SQL'
SELECT r.current_revision_number, r.workflow_state, r.published_revision_id,
       r.current_revision_id, r.updated_by, r.editing_session_hash, r.editing_saved_at,
       r.editing_request_id, v.site_sequence, v.body_json
FROM cms_records r JOIN cms_record_revisions v ON v.id = r.current_revision_id
WHERE r.id = :record_id AND r.type_id = :type_id
FOR UPDATE
SQL);
            $lock->execute(['record_id' => $recordId, 'type_id' => $typeId]);
            $currentRow = $lock->fetch(PDO::FETCH_ASSOC);
            if (!is_array($currentRow)) {
                throw new RecordNotFound();
            }
            if ($editing?->requestId !== null && !$editing->resume && $currentRow['editing_session_hash'] === $editing->sessionHash && $currentRow['editing_request_id'] === $editing->requestId && $currentRow['updated_by'] === $actorUserId) {
                $result = $this->find($typeId, $recordId) ?? throw new RecordNotFound();
                if ($ownsTransaction) {
                    $this->pdo->commit();
                }
                return $result;
            }
            $currentNumber = $currentRow['current_revision_number'];
            $previousState = (string) $currentRow['workflow_state'];
            $nextState = $workflowState ?? $previousState;
            if (!in_array($nextState, ['draft', 'published', 'archived'], true)) {
                throw new \InvalidArgumentException('Unknown record state');
            }
            if ((int) $currentNumber !== $expectedRevisionNumber || ($editing !== null && (int) $currentRow['site_sequence'] !== $editing->sequence)) {
                $current = $this->find($typeId, $recordId);
                if ($current === null) {
                    throw new RecordNotFound();
                }
                if ($ownsTransaction) {
                    $this->pdo->rollBack();
                }
                return new RecordConflict($current);
            }

            if ($editing?->sessionHash !== null && $encoded === $currentRow['body_json']) {
                $result = $this->find($typeId, $recordId) ?? throw new RecordNotFound();
                if ($ownsTransaction) {
                    $this->pdo->commit();
                }
                return $result;
            }
            $coalesce = $editing?->sessionHash !== null
                && $currentRow['editing_session_hash'] === $editing->sessionHash
                && $currentRow['updated_by'] === $actorUserId
                && $currentRow['current_revision_id'] !== $currentRow['published_revision_id']
                && is_string($currentRow['editing_saved_at'])
                && new \DateTimeImmutable($currentRow['editing_saved_at'], new \DateTimeZone('UTC')) > $now->modify('-10 minutes');
            $revisionId = $coalesce ? (string) $currentRow['current_revision_id'] : $revisionId;
            $publishedId = $operation === 'record_changes_discarded' ? $revisionId : ($workflowState === null ? $currentRow['published_revision_id'] : ($nextState === 'published' ? $revisionId : null));
            $nextNumber = $expectedRevisionNumber + ($coalesce ? 0 : 1);
            if ($coalesce) {
                $this->pdo->prepare('UPDATE cms_record_revisions SET body_json = :body WHERE id = :id AND record_id = :record')->execute(['body' => $encoded, 'id' => $revisionId, 'record' => $recordId]);
            } else {
                $this->insertRevision(
                    $revisionId,
                    $recordId,
                    $nextNumber,
                    $profileId,
                    $profileVersion,
                    $encoded,
                    $actorUserId,
                    $time,
                );
            }
            $this->projectRelationships($recordId, $revisionId, $relationships);
            $update = $this->pdo->prepare(<<<'SQL'
UPDATE cms_records
SET current_revision_id = :revision_id,
    workflow_state = :workflow_state,
    published_revision_id = :published_revision_id,
    current_revision_number = :next_revision,
    updated_by = :actor_id,
    updated_at = :updated_at
WHERE id = :record_id
  AND type_id = :type_id
  AND current_revision_number = :expected_revision
SQL);
            $update->execute([
                'workflow_state' => $nextState,
                'published_revision_id' => $publishedId,
                'revision_id' => $revisionId,
                'next_revision' => $nextNumber,
                'actor_id' => $actorUserId,
                'updated_at' => $time,
                'record_id' => $recordId,
                'type_id' => $typeId,
                'expected_revision' => $expectedRevisionNumber,
            ]);
            if ($update->rowCount() !== 1 && !$coalesce) {
                throw new \RuntimeException('Record compare-and-set failed');
            }
            $purge = $this->pdo->prepare('DELETE FROM cms_record_relationship_index WHERE record_id = :id AND revision_id <> :current AND (:published IS NULL OR revision_id <> :kept)');
            $purge->execute(['id' => $recordId, 'current' => $revisionId, 'published' => $publishedId, 'kept' => $publishedId]);
            $sequence = new \Utsuri\PortableCms\Conventional\Database\MariaDbSiteSequence($this->pdo);
            if ($coalesce) {
                $sequence->edit($typeId, $recordId, $revisionId, $nextNumber, $now);
            } else {
                $sequence->revision($typeId, $recordId, $revisionId, $nextNumber, $now);
            }
            $this->editing($recordId, $editing, $time);
            $this->appendAudit($auditId, $actorUserId, $operation ?? ($workflowState === null ? 'record_revised' : ($previousState === 'published' && $nextState === 'published' ? 'record_changes_published' : 'record_status_changed')), [
                'recordId' => $recordId,
                'typeId' => $typeId,
                'revisionNumber' => $nextNumber,
                'publishedRevisionId' => $publishedId,
                ...($workflowState === null ? [] : ['previousState' => $previousState, 'state' => $nextState]),
            ], $time);
            // Domain-separated from the initiating command's random audit ID, so rollback/retry is atomic.
            $history->pruneRecord($recordId, $actorUserId, substr(hash('sha256', 'revision-pruning:' . $auditId), 0, 32), $now, $historyPolicy);
            $result = $this->find($typeId, $recordId) ?? throw new RecordNotFound();
            if ($ownsTransaction) {
                $this->pdo->commit();
            }
        } catch (Throwable $error) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        return $result;
    }

    public function transition(RecordSnapshot $record, string $state, string $revisionId, string $profileId, string $profileVersion, string $actorUserId, string $auditId, \DateTimeImmutable $now): RecordSnapshot|RecordConflict
    {
        // The immutable current projection is copied, never replaced by submitted form data.
        $statement = $this->pdo->prepare('SELECT field_id, relation_kind, target_record_id, position FROM cms_record_relationship_index WHERE record_id = :id AND revision_id = :revision ORDER BY field_id, position');
        $statement->execute(['id' => $record->id, 'revision' => $record->revisionId]);
        $relationships = array_map(static fn(array $row): RelationshipProjection => new RelationshipProjection((string) $row['field_id'], (string) $row['relation_kind'], (string) $row['target_record_id'], (int) $row['position']), $statement->fetchAll(PDO::FETCH_ASSOC));
        return $this->update($record->typeId, $record->id, $record->revisionNumber, $revisionId, $profileId, $profileVersion, $record->body, $relationships, $actorUserId, $auditId, $now, $state, editing: new RecordEditIntent($record->editSequence));
    }

    public function pendingPublicationCount(): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM cms_records WHERE workflow_state = 'published' AND current_revision_id <> published_revision_id")->fetchColumn();
    }

    public function keep(string $typeId, string $recordId, ?RecordSnapshot $record, RecordEditIntent $intent, string $revisionId, string $profileId, string $profileVersion, array $body, array $relationships, string $actorUserId, string $auditId, \DateTimeImmutable $now): RecordSnapshot|RecordConflict
    {
        $record ??= $this->find($typeId, $recordId);
        return $record === null
            ? $this->create($recordId, $revisionId, $typeId, $profileId, $profileVersion, $body, $relationships, $actorUserId, $auditId, $now, $intent)
            : $this->update($typeId, $recordId, $record->revisionNumber, $revisionId, $profileId, $profileVersion, $body, $relationships, $actorUserId, $auditId, $now, editing: $intent);
    }

    private function editing(string $recordId, ?RecordEditIntent $intent, string $time): void
    {
        $this->pdo->prepare('UPDATE cms_records SET editing_session_hash = :session, editing_saved_at = :saved, editing_request_id = :request WHERE id = :id')->execute(['session' => $intent?->sessionHash, 'saved' => $intent?->sessionHash === null ? null : $time, 'request' => $intent?->requestId, 'id' => $recordId]);
    }

    public function published(RecordSnapshot $record): ?RecordSnapshot
    {
        if ($record->publishedRevisionId === null) {
            return null;
        }
        $statement = $this->pdo->prepare('SELECT v.id AS current_revision_id, v.record_id AS id, r.type_id, r.workflow_state, v.revision_number AS current_revision_number, v.body_json, v.created_at AS updated_at, v.site_sequence, r.published_revision_id, v.revision_number AS published_revision_number, v.created_at AS published_at FROM cms_record_revisions v JOIN cms_records r ON r.id = v.record_id WHERE r.id = :id AND v.id = :revision AND r.published_revision_id = v.id');
        $statement->execute(['id' => $record->id, 'revision' => $record->publishedRevisionId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function discard(RecordSnapshot $record, RecordSnapshot $published, array $relationships, string $revisionId, string $profileId, string $profileVersion, string $actorUserId, string $auditId, \DateTimeImmutable $now): RecordSnapshot|RecordConflict
    {
        return $this->update($record->typeId, $record->id, $record->revisionNumber, $revisionId, $profileId, $profileVersion, $published->body, $relationships, $actorUserId, $auditId, $now, operation: 'record_changes_discarded', editing: new RecordEditIntent($record->editSequence));
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): RecordSnapshot
    {
        $body = json_decode((string) $row['body_json'], true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($body) || array_is_list($body)) {
            throw new \RuntimeException('Stored record body is invalid');
        }
        foreach ($body as $key => $value) {
            if (!is_string($key) || !self::validStoredValue($value, 0)) {
                throw new \RuntimeException('Stored record body is outside the field contract');
            }
        }
        return new RecordSnapshot(
            (string) $row['id'],
            (string) $row['type_id'],
            (string) $row['workflow_state'],
            (string) $row['current_revision_id'],
            (int) $row['current_revision_number'],
            $body,
            new \DateTimeImmutable((string) $row['updated_at'], new \DateTimeZone('UTC')),
            isset($row['published_revision_id']) ? (string) $row['published_revision_id'] : null,
            isset($row['published_revision_number']) ? (int) $row['published_revision_number'] : null,
            isset($row['published_at']) ? new \DateTimeImmutable((string) $row['published_at'], new \DateTimeZone('UTC')) : null,
            (int) ($row['site_sequence'] ?? 0),
        );
    }

    private function insertRevision(
        string $revisionId,
        string $recordId,
        int $revisionNumber,
        string $profileId,
        string $profileVersion,
        string $bodyJson,
        string $actorUserId,
        string $createdAt,
    ): void {
        $statement = $this->pdo->prepare(<<<'SQL'
INSERT INTO cms_record_revisions (
    id, record_id, revision_number, profile_id, profile_version,
    body_json, created_by, created_at
) VALUES (
    :id, :record_id, :revision_number, :profile_id, :profile_version,
    :body_json, :created_by, :created_at
)
SQL);
        $statement->execute([
            'id' => $revisionId,
            'record_id' => $recordId,
            'revision_number' => $revisionNumber,
            'profile_id' => $profileId,
            'profile_version' => $profileVersion,
            'body_json' => $bodyJson,
            'created_by' => $actorUserId,
            'created_at' => $createdAt,
        ]);
    }

    /** @param list<RelationshipProjection> $relationships */
    public function projectRelationships(string $recordId, string $revisionId, array $relationships): void
    {
        if (!$this->pdo->inTransaction()) {
            throw new \LogicException('Relationship projection requires its record transaction');
        }
        $delete = $this->pdo->prepare('DELETE FROM cms_record_relationship_index WHERE record_id = :id AND revision_id <> COALESCE((SELECT published_revision_id FROM cms_records WHERE id = :record), \'\')');
        $delete->execute(['id' => $recordId, 'record' => $recordId]);
        if ($relationships === []) {
            return;
        }
        $insert = $this->pdo->prepare(<<<'SQL'
INSERT INTO cms_record_relationship_index (
    record_id, revision_id, field_id, relation_kind, target_record_id, position
) VALUES (
    :record_id, :revision_id, :field_id, :relation_kind, :target_record_id, :position
)
SQL);
        foreach ($relationships as $relationship) {
            if (!$relationship instanceof RelationshipProjection) {
                throw new \InvalidArgumentException('Relationship projection is invalid');
            }
            $insert->execute([
                'record_id' => $recordId,
                'revision_id' => $revisionId,
                'field_id' => $relationship->fieldId,
                'relation_kind' => $relationship->kind,
                'target_record_id' => $relationship->targetRecordId,
                'position' => $relationship->position,
            ]);
        }
    }

    /** @param array<string, scalar|null> $metadata */
    private function appendAudit(
        string $id,
        string $actorUserId,
        string $eventType,
        array $metadata,
        string $occurredAt,
    ): void {
        $statement = $this->pdo->prepare(<<<'SQL'
INSERT INTO cms_audit_events (id, actor_user_id, event_type, metadata_json, occurred_at)
VALUES (:id, :actor_user_id, :event_type, :metadata_json, :occurred_at)
SQL);
        $statement->execute([
            'id' => $id,
            'actor_user_id' => $actorUserId,
            'event_type' => $eventType,
            'metadata_json' => json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'occurred_at' => $occurredAt,
        ]);
    }

    /** @param array<string, mixed> $body */
    private static function encodeBody(array $body): string
    {
        ksort($body, SORT_STRING);
        return json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private static function databaseTime(\DateTimeImmutable $time): string
    {
        return $time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private static function validStoredValue(mixed $value, int $depth): bool
    {
        if ($depth > 16) {
            return false;
        }
        if (
            is_string($value)
            || is_int($value)
            || is_bool($value)
            || (is_float($value) && is_finite($value))
            || $value === null
        ) {
            return true;
        }
        if (!is_array($value) || count($value) > 64) {
            return false;
        }
        foreach ($value as $key => $item) {
            if (!self::validStoredValue($item, $depth + 1)) {
                return false;
            }
        }
        return true;
    }
}
