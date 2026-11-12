<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Compiler\FrozenSnapshot;
use Utsuri\PortableCms\Conventional\Content\ProfilePackage;

final readonly class ReferenceStudioDevelopmentSeeder
{
    public function __construct(
        private ProfilePackage $package,
        private FrozenSnapshot $snapshot,
    ) {
        if ($this->package->manifest->id !== 'reference-studio') {
            throw new InvalidArgumentException('Development seed requires the Reference Studio profile');
        }
    }

    public static function load(string $profileRoot, string $snapshotSchema): self
    {
        $package = ProfilePackage::load($profileRoot);
        return new self(
            $package,
            FrozenSnapshot::load(
                $profileRoot . '/fixtures/snapshot.json',
                $snapshotSchema,
                $package,
            ),
        );
    }

    public function seed(PDO $pdo, string $actorUserId): bool
    {
        if (preg_match('/\A[a-f0-9]{32}\z/', $actorUserId) !== 1) {
            throw new InvalidArgumentException('Development seed actor is invalid');
        }
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        $existing = (int) $pdo->query('SELECT COUNT(*) FROM cms_records')->fetchColumn();
        if ($existing > 0) {
            $profiles = $pdo->query(<<<'SQL'
SELECT DISTINCT rr.profile_id
FROM cms_records r
JOIN cms_record_revisions rr ON rr.id = r.current_revision_id
SQL)->fetchAll(PDO::FETCH_COLUMN);
            if ($profiles !== [$this->package->manifest->id]) {
                throw new RuntimeException('Local sandbox contains records from another profile');
            }
            return false;
        }

        $recordStatement = $pdo->prepare(<<<'SQL'
INSERT INTO cms_records (
    id, type_id, workflow_state, current_revision_id, current_revision_number, published_revision_id,
    created_by, created_at, updated_by, updated_at
) VALUES (
    :id, :type_id, :workflow_state, :revision_id, 1, :published_revision_id,
    :created_by, :created_at, :updated_by, :updated_at
)
SQL);
        $revisionStatement = $pdo->prepare(<<<'SQL'
INSERT INTO cms_record_revisions (
    id, record_id, revision_number, profile_id, profile_version,
    body_json, created_by, created_at
) VALUES (
    :id, :record_id, 1, :profile_id, :profile_version,
    :body_json, :actor, :created_at
)
SQL);
        $relationshipStatement = $pdo->prepare(<<<'SQL'
INSERT INTO cms_record_relationship_index (
    record_id, revision_id, field_id, relation_kind, target_record_id, position
) VALUES (
    :record_id, :revision_id, :field_id, :relation_kind, :target_record_id, :position
)
SQL);
        $createdAt = $this->snapshot->createdAt
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s.u');

        $pdo->beginTransaction();
        try {
            foreach ($this->snapshot->records as $record) {
                $recordStatement->execute([
                    'id' => $record->id,
                    'type_id' => $record->typeId,
                    'workflow_state' => $record->workflowState,
                    'published_revision_id' => $record->workflowState === 'published' ? $record->revisionId : null,
                    'revision_id' => $record->revisionId,
                    'created_by' => $actorUserId,
                    'created_at' => $createdAt,
                    'updated_by' => $actorUserId,
                    'updated_at' => $createdAt,
                ]);
            }
            foreach ($this->snapshot->records as $record) {
                $revisionStatement->execute([
                    'id' => $record->revisionId,
                    'record_id' => $record->id,
                    'profile_id' => $this->package->manifest->id,
                    'profile_version' => $this->package->manifest->version,
                    'body_json' => json_encode(
                        $record->body,
                        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
                    ),
                    'actor' => $actorUserId,
                    'created_at' => $createdAt,
                ]);
                (new \Utsuri\PortableCms\Conventional\Database\MariaDbSiteSequence($pdo))->revision($record->typeId, $record->id, $record->revisionId, 1, $this->snapshot->createdAt);
                foreach ($record->relationships as $relationship) {
                    $relationshipStatement->execute([
                        'record_id' => $record->id,
                        'revision_id' => $record->revisionId,
                        'field_id' => $relationship->fieldId,
                        'relation_kind' => $relationship->kind,
                        'target_record_id' => $relationship->targetRecordId,
                        'position' => $relationship->position,
                    ]);
                }
            }
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
        return true;
    }
}
