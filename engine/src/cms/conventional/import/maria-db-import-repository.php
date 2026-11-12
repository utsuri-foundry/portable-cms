<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Import;

use PDO;
use Utsuri\PortableCms\Conventional\Content\MariaDbRecordRepository;
use Utsuri\PortableCms\Conventional\Content\RecordRepository;
use Utsuri\PortableCms\Conventional\Design\RegistryDocument;

/** Import checkpoints commit with ordinary content revisions. No source SQL is accepted. */
final class MariaDbImportRepository implements ImportRepository
{
    private readonly MariaDbRecordRepository $records;
    /** @param list<string> $migrationPaths */
    public function __construct(private readonly PDO $pdo, private readonly string $destination, private readonly ?RegistryDocument $defaults, private readonly array $migrationPaths)
    {
        $this->records = new MariaDbRecordRepository($pdo);
        $pdo->exec("SET time_zone = '+00:00'");
    }
    public function authorize(string $actorId): void
    {
        $statement = $this->pdo->prepare("SELECT id FROM cms_users WHERE id = :id AND role IN ('owner', 'administrator') AND status = 'active'");
        $statement->execute(['id' => $actorId]);
        if ($statement->fetchColumn() !== $actorId) {
            throw new ImportRejected('active_destination_administrator_required');
        }
        $applied = array_column($this->pdo->query('SELECT id, checksum, status FROM cms_migrations')->fetchAll(PDO::FETCH_ASSOC), null, 'id');
        if (count($applied) !== count($this->migrationPaths)) {
            throw new ImportRejected('destination_migrations_required');
        }
        foreach ($this->migrationPaths as $path) {
            $id = basename($path, '.sql');
            if (($applied[$id]['status'] ?? null) !== 'complete' || hash_file('sha256', $path) !== ($applied[$id]['checksum'] ?? null)) {
                throw new ImportRejected('destination_migration_checksum_mismatch');
            }
        }
        $state = $this->pdo->query('SELECT status FROM cms_restore_state WHERE singleton_id = 1')->fetchColumn();
        if ($state !== false && $state !== 'completed') {
            throw new ImportRejected('isolated_restore_in_progress');
        }
        if ($this->pdo->query("SELECT 1 FROM cms_language_text_deletions WHERE state = 'running' LIMIT 1")->fetchColumn() !== false) {
            throw new ImportRejected('language_text_deletion_in_progress');
        }
    }
    public function records(): RecordRepository
    {
        return $this->records;
    }
    public function languages(\Utsuri\PortableCms\Conventional\Content\ProfileManifest $profile): \Utsuri\PortableCms\Conventional\Content\SiteLanguages
    {
        return (new \Utsuri\PortableCms\Conventional\Content\MariaDbSiteLanguages($this->pdo))->load($profile);
    }
    public function destinationIdentity(): string
    {
        return $this->destination;
    }
    public function design(): ?RegistryDocument
    {
        if ($this->defaults === null) {
            return null;
        }
        $statement = $this->pdo->prepare('SELECT document_json FROM cms_design_registries WHERE profile_id = :profile');
        $statement->execute(['profile' => $this->defaults->profileId()]);
        $json = $statement->fetchColumn();
        return is_string($json) ? RegistryDocument::fromDocument(json_decode($json, true, 64, JSON_THROW_ON_ERROR)) : $this->defaults;
    }
    public function recordMappings(string $source, array $ids): array
    {
        if (count($ids) > \Utsuri\PortableCms\Conventional\Export\ExportCapacity::limit('records')) {
            throw new ImportRejected('record_lookup_limit');
        }
        $statement = $this->pdo->prepare('SELECT * FROM cms_content_import_records WHERE source_namespace = :source AND source_id = :id');
        $result = [];
        foreach ($ids as $id) {
            $statement->execute(['source' => $source, 'id' => $id]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                $result[$id] = new ImportRecordCheckpoint($row['source_id'], $row['destination_id'], $row['source_digest'], (int) $row['source_revision_number'], (int) $row['destination_revision_number']);
            }
        }
        return $result;
    }
    public function mediaMappings(string $source, array $identities): array
    {
        if (count($identities) > 5000) {
            throw new ImportRejected('media_lookup_limit');
        }
        $statement = $this->pdo->prepare('SELECT * FROM cms_content_import_media WHERE source_namespace = :source AND source_id = :id AND source_sha256 = :sha256');
        $result = [];
        foreach ($identities as $identity) {
            $statement->execute(['source' => $source, 'id' => $identity['id'], 'sha256' => $identity['sha256']]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                $result[$identity['id'] . ':' . $identity['sha256']] = new ImportMediaCheckpoint($row['destination_id'], $row['owner_record_id'], $row['actor_user_id'], $row['status'], $row['source_metadata_sha256'], $row['destination_metadata_revision'] === null ? null : (int) $row['destination_metadata_revision'], $row['source_metadata_revision'] === null ? null : (int) $row['source_metadata_revision']);
            }
        }
        return $result;
    }
    public function recordSource(string $source, string $destinationId): ?string
    {
        $statement = $this->pdo->prepare('SELECT source_id FROM cms_content_import_records WHERE source_namespace = :source AND destination_id = :destination');
        $statement->execute(['source' => $source, 'destination' => $destinationId]);
        $id = $statement->fetchColumn();
        return is_string($id) ? $id : null;
    }
    public function mediaIdentity(string $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT owner_record_id, checksum, status, original_name, metadata_revision, text_json, focal_x, focal_y FROM cms_media WHERE id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? ['ownerRecordId' => $row['owner_record_id'], 'sha256' => $row['checksum'], 'status' => $row['status'], 'name' => $row['original_name'], 'metadataRevision' => (int) $row['metadata_revision'], 'metadataSha256' => ImportMedia::metadataDigest(['originalName' => $row['original_name'], 'text' => $row['text_json'] === null ? null : json_decode($row['text_json'], true, 16, JSON_THROW_ON_ERROR), 'focalPoint' => ['x' => (int) $row['focal_x'], 'y' => (int) $row['focal_y']]])] : null;
    }
    public function identity(string $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT type_id, current_revision_number FROM cms_records WHERE id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? ['typeId' => $row['type_id'], 'revisionNumber' => (int) $row['current_revision_number']] : null;
    }
    public function transaction(callable $action): mixed
    {
        if ($this->pdo->inTransaction()) {
            throw new \LogicException('Content import owns its transaction');
        }
        $this->pdo->exec('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');
        $this->pdo->beginTransaction();
        try {
            $result = $action();
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $error) {
            $this->rollback();
            throw $error;
        }
    }
    public function checkpointRecord(string $source, ImportRecord $record, int $revision, string $actorId, \DateTimeImmutable $now): void
    {
        $exists = isset($this->recordMappings($source, [$record->sourceId])[$record->sourceId]);
        $statement = $this->pdo->prepare($exists
            ? 'UPDATE cms_content_import_records SET source_digest = :digest, source_revision_number = :source_revision, destination_revision_number = :revision, actor_user_id = :actor, imported_at = :time WHERE source_namespace = :source AND source_id = :id AND destination_id = :destination'
            : 'INSERT INTO cms_content_import_records (source_namespace, source_id, destination_id, source_digest, source_revision_number, destination_revision_number, actor_user_id, imported_at) VALUES (:source, :id, :destination, :digest, :source_revision, :revision, :actor, :time)');
        $statement->execute(['source' => $source, 'id' => $record->sourceId, 'destination' => $record->id, 'digest' => $record->sourceDigest, 'source_revision' => $record->sourceRevision, 'revision' => $revision, 'actor' => $actorId, 'time' => self::time($now)]);
        if ($statement->rowCount() !== 1) {
            throw new ImportRejected('record_checkpoint_changed');
        }
    }
    public function reserveMedia(string $source, ImportMedia $media, string $actorId, \DateTimeImmutable $now): void
    {
        $existing = $this->mediaMappings($source, [['id' => $media->value['id'], 'sha256' => $media->value['original']['sha256']]])[$media->value['id'] . ':' . $media->value['original']['sha256']] ?? null;
        if ($existing !== null) {
            return;
        }
        $collision = $this->pdo->prepare('SELECT 1 FROM cms_media WHERE id = :id');
        $collision->execute(['id' => $media->id]);
        if ($collision->fetchColumn() !== false) {
            throw new ImportRejected('unowned_destination_media_identity');
        }
        $statement = $this->pdo->prepare('INSERT INTO cms_content_import_media (source_namespace, source_id, source_sha256, source_metadata_sha256, source_metadata_revision, destination_id, owner_record_id, client_upload_id, job_id, audit_id, status, actor_user_id, imported_at) VALUES (:source, :id, :sha256, :metadata_digest, :source_revision, :destination, :owner, :upload, :job, :audit, \'pending\', :actor, :time)');
        $statement->execute(['source' => $source, 'id' => $media->value['id'], 'sha256' => $media->value['original']['sha256'], 'metadata_digest' => ImportMedia::metadataDigest($media->value), 'source_revision' => $media->value['metadataRevision'], 'destination' => $media->id, 'owner' => $media->ownerId, 'upload' => $media->clientUploadId, 'job' => $media->jobId, 'audit' => $media->auditId, 'actor' => $actorId, 'time' => self::time($now)]);
    }
    public function completeMedia(string $source, ImportMedia $media): void
    {
        $identity = $this->mediaIdentity($media->id);
        if ($identity === null || $identity['metadataSha256'] !== ImportMedia::metadataDigest($media->value)) {
            throw new ImportRejected('media_metadata_changed_during_import');
        }
        $metadataDigest = ImportMedia::metadataDigest($media->value);
        $previous = $this->mediaMappings($source, [['id' => $media->value['id'], 'sha256' => $media->value['original']['sha256']]])[$media->value['id'] . ':' . $media->value['original']['sha256']] ?? null;
        $sourceRevision = $media->value['metadataRevision'];
        if ($previous !== null && $previous->sourceMetadataDigest === $metadataDigest && $previous->sourceMetadataRevision !== null && ($sourceRevision === null || $sourceRevision < $previous->sourceMetadataRevision)) {
            $sourceRevision = $previous->sourceMetadataRevision;
        }
        $statement = $this->pdo->prepare('UPDATE cms_content_import_media checkpoint JOIN cms_media media ON media.id = checkpoint.destination_id SET checkpoint.status = \'completed\', checkpoint.source_metadata_sha256 = :metadata_digest, checkpoint.source_metadata_revision = :source_revision, checkpoint.destination_metadata_revision = :revision WHERE checkpoint.source_namespace = :source AND checkpoint.source_id = :id AND checkpoint.source_sha256 = :sha256 AND checkpoint.destination_id = :destination AND media.metadata_revision = :expected_revision AND media.original_name = :name');
        $statement->execute(['source' => $source, 'id' => $media->value['id'], 'sha256' => $media->value['original']['sha256'], 'destination' => $media->id, 'metadata_digest' => $metadataDigest, 'source_revision' => $sourceRevision, 'revision' => $identity['metadataRevision'], 'expected_revision' => $identity['metadataRevision'], 'name' => $media->value['originalName']]);
        if ($statement->rowCount() !== 1) {
            $current = $this->mediaMappings($source, [['id' => $media->value['id'], 'sha256' => $media->value['original']['sha256']]])[$media->value['id'] . ':' . $media->value['original']['sha256']] ?? null;
            if ($current === null || $current->status !== 'completed' || $current->destinationId !== $media->id || $current->ownerId !== $media->ownerId || $current->sourceMetadataDigest !== $metadataDigest || $current->destinationMetadataRevision !== $identity['metadataRevision'] || $current->sourceMetadataRevision !== $sourceRevision) {
                throw new ImportRejected('media_checkpoint_changed');
            }
        }
    }
    public function project(string $id, string $revisionId, array $relationships): void
    {
        $this->records->projectRelationships($id, $revisionId, $relationships);
    }
    public function audit(string $id, string $actorId, string $source, string $digest, bool $confirmedConflicts, \DateTimeImmutable $now): void
    {
        $statement = $this->pdo->prepare('INSERT INTO cms_audit_events (id, actor_user_id, event_type, metadata_json, occurred_at) VALUES (:id, :actor, \'content_import_applied\', :metadata, :time)');
        $statement->execute(['id' => $id, 'actor' => $actorId, 'metadata' => json_encode(['source' => $source, 'planSha256' => $digest, 'conflictsConfirmed' => $confirmedConflicts], JSON_THROW_ON_ERROR), 'time' => self::time($now)]);
    }
    private static function time(\DateTimeImmutable $time): string
    {
        return $time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
    private function rollback(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }
}
