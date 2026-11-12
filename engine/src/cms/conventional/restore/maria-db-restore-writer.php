<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Restore;

/** Prepared inserts only, inside the repository's fenced transaction. */
final readonly class MariaDbRestoreWriter
{
    public function __construct(private \PDO $pdo) {}

    public function write(RestorePlan $plan, string $actorId, \DateTimeImmutable $now): void
    {
        if (!$this->pdo->inTransaction()) {
            throw new \LogicException('Restore writer requires its owning transaction');
        }
        $document = $plan->export->metadata();
        foreach ($plan->export->rows('records') as $record) {
            $this->insert('INSERT INTO cms_records (id, type_id, workflow_state, published_revision_id, current_revision_id, current_revision_number, created_by, created_at, updated_by, updated_at) VALUES (:id, :type, :workflow, :published, :revision, :number, :createdBy, :createdAt, :updatedBy, :updatedAt)', [
                'id' => $record['id'], 'type' => $record['typeId'], 'workflow' => $record['workflowState'], 'published' => \Utsuri\PortableCms\Conventional\Export\NormalizedExport::publishedRevisionId($record), 'revision' => $record['currentRevisionId'], 'number' => $record['currentRevisionNumber'],
                'createdBy' => $actorId, 'createdAt' => self::time($record['createdAt']), 'updatedBy' => $actorId, 'updatedAt' => self::time($record['updatedAt']),
            ]);
            $this->authorship('record', $record['id'], $record['createdBy'], $record['updatedBy'], $record['currentRevisionId']);
        }
        foreach ($plan->export->rows('revisions') as $revision) {
            $this->insert('INSERT INTO cms_record_revisions (id, record_id, revision_number, profile_id, profile_version, body_json, created_by, created_at, site_sequence) VALUES (:id, :record, :number, :profile, :version, :body, :actor, :created, :sequence)', [
                'sequence' => $revision['siteSequence'] ?? null,
                'id' => $revision['id'], 'record' => $revision['recordId'], 'number' => $revision['revisionNumber'], 'profile' => $revision['profileId'], 'version' => $revision['profileVersion'],
                'body' => self::json($revision['body']), 'actor' => $actorId, 'created' => self::time($revision['createdAt']),
            ]);
            $this->authorship('revision', $revision['id'], $revision['createdBy']);
        }
        foreach ($plan->export->rows('relationships') as $relationship) {
            $this->insert('INSERT INTO cms_record_relationship_index (record_id, revision_id, field_id, relation_kind, target_record_id, position) VALUES (:record, :revision, :field, :kind, :target, :position)', [
                'record' => $relationship['recordId'], 'revision' => $relationship['revisionId'], 'field' => $relationship['fieldId'], 'kind' => $relationship['kind'], 'target' => $relationship['targetRecordId'], 'position' => $relationship['position'],
            ]);
        }
        foreach ($plan->export->rows('media') as $media) {
            $this->insert('INSERT INTO cms_media (id, owner_record_id, status, original_name, declared_type, detected_type, width, height, byte_size, checksum, original_key, created_by, created_at, updated_at, error_code, text_json, metadata_revision, focal_x, focal_y) VALUES (:id, :owner, :status, :name, :declared, :detected, :width, :height, :size, :sha256, :key, :actor, :created, :updated, :error, :text, :revision, :focal_x, :focal_y)', [
                'focal_x' => ($media['focalPoint']['x'] ?? 50), 'focal_y' => ($media['focalPoint']['y'] ?? 50),
                'text' => isset($media['text']) ? json_encode($media['text'], JSON_THROW_ON_ERROR) : null, 'revision' => $media['metadataRevision'] ?? 1,
                'id' => $media['id'], 'owner' => $media['ownerRecordId'], 'status' => $media['status'], 'name' => $media['originalName'], 'declared' => $media['declaredType'], 'detected' => $media['detectedType'], 'width' => $media['width'], 'height' => $media['height'],
                'size' => $media['original']['byteSize'], 'sha256' => $media['original']['sha256'], 'key' => 'originals/' . $media['id'] . '/source',
                'actor' => $actorId, 'created' => self::time($media['createdAt']), 'updated' => self::time($media['updatedAt']), 'error' => $media['status'] === 'needs_attention' ? 'restored_needs_attention' : null,
            ]);
            $this->authorship('media', $media['id'], $media['createdBy']);
            foreach ($media['derivatives'] as $derivative) {
                $this->insert('INSERT INTO cms_media_derivatives (media_id, variant, object_key, filename, mime, width, height, byte_size, checksum, processor_id, processor_version, recipe_version, parameters_json, created_at) VALUES (:media, :variant, :key, :name, :mime, :width, :height, :size, :sha256, :processor, :version, :recipe, :parameters, :created)', [
                    'media' => $media['id'], 'variant' => $derivative['variant'], 'key' => 'derivatives/' . $media['id'] . '/' . $derivative['fileName'], 'name' => $derivative['fileName'], 'mime' => $derivative['mime'], 'width' => $derivative['width'], 'height' => $derivative['height'], 'size' => $derivative['byteSize'], 'sha256' => $derivative['sha256'],
                    'processor' => $derivative['processorId'], 'version' => $derivative['processorVersion'], 'recipe' => $derivative['recipeVersion'], 'parameters' => self::json($derivative['parameters']), 'created' => self::time($derivative['createdAt']),
                ]);
            }
        }
        $this->registries($document, $actorId, $now);
        if (isset($document['siteLanguages'])) {
            $languages = $document['siteLanguages'];
            $this->insert('INSERT INTO cms_site_languages (profile_id, revision, document_json) VALUES (:profile, :revision, :document)', ['profile' => $languages['profileId'], 'revision' => $languages['revision'], 'document' => self::json($languages)]);
        }
        if (isset($document['siteWording'])) {
            $wording = $document['siteWording'];
            $values = ['profile' => $wording['profileId'], 'revision' => $wording['revision'], 'document' => self::json($wording)];
            $this->insert('INSERT INTO cms_site_wording (profile_id, revision, document_json) VALUES (:profile, :revision, :document)', $values);
            if ($wording['revision'] > 0) {
                $this->insert('INSERT INTO cms_site_wording_revisions (profile_id, revision, document_json, actor_user_id, created_at) VALUES (:profile, :revision, :document, :actor, :created)', $values + ['actor' => $actorId, 'created' => self::time($now)]);
            }
        }
        if (isset($document['websitePublication'])) {
            $intent = \Utsuri\PortableCms\Conventional\Publication\WebsitePublication::from($document['websitePublication']);
            $this->insert('INSERT INTO cms_website_publication (profile_id, revision, document_json) VALUES (:profile, :revision, :document)', ['profile' => $intent->document()['profileId'], 'revision' => $intent->revision(), 'document' => self::json($intent->document())]);
        }
    }

    /** @param array<string, mixed> $document */
    private function registries(array $document, string $actorId, \DateTimeImmutable $now): void
    {
        if (isset($document['designRegistries'])) {
            $registry = \Utsuri\PortableCms\Conventional\Design\RegistryDocument::fromDocument($document['designRegistries']);
            $values = ['profile' => $registry->profileId(), 'revision' => $registry->revision(), 'document' => self::json($registry->document())];
            $this->insert('INSERT INTO cms_design_registries (profile_id, revision, document_json) VALUES (:profile, :revision, :document)', $values);
            if ($registry->revision() > 0) {
                $this->insert('INSERT INTO cms_design_registry_revisions (profile_id, revision, document_json, actor_user_id, created_at) VALUES (:profile, :revision, :document, :actor, :created)', $values + ['actor' => $actorId, 'created' => self::time($now)]);
            }
        }
        if (isset($document['editorialTerminology'])) {
            $registry = $document['editorialTerminology'];
            $values = ['profile' => $registry['profileId'], 'revision' => $registry['revision'], 'labels' => self::json($registry['labels'])];
            $this->insert('INSERT INTO cms_editorial_terminology (profile_id, revision, labels_json) VALUES (:profile, :revision, :labels)', $values);
            if ($registry['revision'] > 0) {
                $this->insert('INSERT INTO cms_editorial_terminology_revisions (profile_id, revision, labels_json, actor_user_id, created_at) VALUES (:profile, :revision, :labels, :actor, :created)', $values + ['actor' => $actorId, 'created' => self::time($now)]);
            }
        }
    }

    private function authorship(string $kind, string $id, string $created, ?string $updated = null, ?string $revision = null): void
    {
        $this->insert('INSERT INTO cms_restore_authorship (entity_kind, entity_id, source_created_by, source_updated_by, restored_revision_id) VALUES (:kind, :id, :created, :updated, :revision)', ['kind' => $kind, 'id' => $id, 'created' => $created, 'updated' => $updated, 'revision' => $revision]);
    }

    /** @param array<string, mixed> $values */
    private function insert(string $sql, array $values): void
    {
        $this->pdo->prepare($sql)->execute($values);
    }

    private static function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private static function time(string|\DateTimeImmutable $value): string
    {
        $date = is_string($value) ? new \DateTimeImmutable($value) : $value;
        return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
