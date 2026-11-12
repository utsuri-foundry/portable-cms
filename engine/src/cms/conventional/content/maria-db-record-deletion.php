<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Content;

use PDO;
use Utsuri\PortableCms\Conventional\I18n\Message;

interface RecordDeletionRepository
{
    public function deleteArchived(RecordSnapshot $record, string $actorId, string $auditId, \DateTimeImmutable $now): bool|RecordConflict|ValidationFailure;
}

/** Hard deletion is limited to unreferenced archived collections with no private media/jobs. */
final class MariaDbRecordDeletion
{
    public function __construct(private readonly PDO $pdo) {}

    public function delete(RecordSnapshot $record, string $actorId, string $auditId, \DateTimeImmutable $now): bool|RecordConflict|ValidationFailure
    {
        $this->pdo->beginTransaction();
        try {
            $query = $this->pdo->prepare('SELECT r.current_revision_number, r.workflow_state, v.site_sequence FROM cms_records r JOIN cms_record_revisions v ON v.id = r.current_revision_id WHERE r.id = :id FOR UPDATE');
            $query->execute(['id' => $record->id]);
            $current = $query->fetch(PDO::FETCH_ASSOC);
            if (!is_array($current)) {
                throw new RecordNotFound();
            }
            if ((int) $current['current_revision_number'] !== $record->revisionNumber || (int) $current['site_sequence'] !== $record->editSequence) {
                $fresh = (new MariaDbRecordRepository($this->pdo))->find($record->typeId, $record->id) ?? throw new RecordNotFound();
                $this->pdo->rollBack();
                return new RecordConflict($fresh);
            }
            $blocked = $current['workflow_state'] !== 'archived';
            foreach (['SELECT record_id FROM cms_record_relationship_index WHERE target_record_id = :id AND record_id <> :self LIMIT 1',
                'SELECT id FROM cms_media WHERE owner_record_id = :id LIMIT 1', 'SELECT id FROM cms_media_upload_sessions WHERE owner_record_id = :id LIMIT 1',
                'SELECT id FROM cms_ai_jobs WHERE record_id = :id LIMIT 1'] as $sql) {
                $check = $this->pdo->prepare($sql);
                $check->execute(str_contains($sql, ':self') ? ['id' => $record->id, 'self' => $record->id] : ['id' => $record->id]);
                $blocked = $blocked || $check->fetchColumn() !== false;
            }
            if ($blocked) {
                $this->pdo->rollBack();
                return new ValidationFailure($record->body, ['_workflow' => new Message('workflow.delete_blocked')]);
            }
            (new \Utsuri\PortableCms\Conventional\Database\MariaDbSiteSequence($this->pdo))->removal($record->typeId, $record->id, $record->revisionId, $record->revisionNumber, $now);
            foreach (['DELETE FROM cms_record_relationship_index WHERE record_id = :id', 'DELETE FROM cms_record_revisions WHERE record_id = :id', 'DELETE FROM cms_records WHERE id = :id'] as $sql) {
                $this->pdo->prepare($sql)->execute(['id' => $record->id]);
            }
            $this->pdo->prepare('INSERT INTO cms_audit_events (id, actor_user_id, event_type, metadata_json, occurred_at) VALUES (:id, :actor, :event, :metadata, :at)')->execute(['id' => $auditId, 'actor' => $actorId, 'event' => 'archived_record_deleted', 'metadata' => json_encode(['recordId' => $record->id, 'typeId' => $record->typeId, 'revisionId' => $record->revisionId, 'revisionNumber' => $record->revisionNumber], JSON_THROW_ON_ERROR), 'at' => $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u')]);
            $this->pdo->commit();
            return true;
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }
}
