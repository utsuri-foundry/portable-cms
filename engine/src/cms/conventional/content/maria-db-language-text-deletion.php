<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Content;

final readonly class MariaDbLanguageTextDeletion implements LanguageTextDeletionRepository
{
    private MariaDbRecordRepository $records;

    public function __construct(private \PDO $pdo)
    {
        $this->records = new MariaDbRecordRepository($pdo);
    }

    public function latest(string $profileId): ?LanguageTextDeletion
    {
        $query = $this->pdo->prepare("SELECT id, profile_id, locale, actor_user_id, state, records_total, records_done, media_done, started_at FROM cms_language_text_deletions WHERE profile_id = :profile ORDER BY state = 'running' DESC, started_at DESC, id DESC LIMIT 1");
        $query->execute(['profile' => $profileId]);
        $row = $query->fetch(\PDO::FETCH_ASSOC);
        return is_array($row) ? new LanguageTextDeletion(
            (string) $row['id'],
            (string) $row['profile_id'],
            (string) $row['locale'],
            (string) $row['actor_user_id'],
            (string) $row['state'],
            (int) $row['records_total'],
            (int) $row['records_done'],
            (int) $row['media_done'],
            new \DateTimeImmutable((string) $row['started_at'], new \DateTimeZone('UTC')),
        ) : null;
    }

    public function start(LanguageTextDeletion $deletion, string $auditId, \DateTimeImmutable $now): void
    {
        $this->pdo->beginTransaction();
        try {
            // Serialized through the profile's language choice, so two owners cannot start two deletions.
            $this->pdo->prepare('SELECT revision FROM cms_site_languages WHERE profile_id = :profile FOR UPDATE')->execute(['profile' => $deletion->profileId]);
            $running = $this->pdo->prepare("SELECT COUNT(*) FROM cms_language_text_deletions WHERE profile_id = :profile AND state = 'running' FOR UPDATE");
            $running->execute(['profile' => $deletion->profileId]);
            if ((int) $running->fetchColumn() > 0) {
                throw new LanguageTextDeletionConflict();
            }
            $time = self::time($now);
            $this->pdo->prepare('INSERT INTO cms_language_text_deletions (id, profile_id, locale, actor_user_id, state, records_total, records_done, media_done, started_at, updated_at, completed_at) VALUES (:id, :profile, :locale, :actor, :state, :records, 0, 0, :started, :updated, NULL)')
                ->execute(['id' => $deletion->id, 'profile' => $deletion->profileId, 'locale' => $deletion->locale, 'actor' => $deletion->actorId, 'state' => $deletion->state, 'records' => $deletion->records, 'started' => self::time($deletion->startedAt), 'updated' => $time]);
            $this->audit($auditId, $deletion->actorId, 'language_text_deletion_started', ['deletionId' => $deletion->id, 'locale' => $deletion->locale, 'records' => $deletion->records], $time);
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function advance(LanguageTextDeletion $deletion, \DateTimeImmutable $now, ?string $auditId = null): void
    {
        $this->pdo->beginTransaction();
        try {
            $time = self::time($now);
            $this->pdo->prepare('UPDATE cms_language_text_deletions SET state = :state, records_done = :records, media_done = :media, updated_at = :updated, completed_at = :completed WHERE id = :id')
                ->execute(['state' => $deletion->state, 'records' => $deletion->recordsDone, 'media' => $deletion->mediaDone, 'updated' => $time, 'completed' => $deletion->state === 'completed' ? $time : null, 'id' => $deletion->id]);
            if ($auditId !== null) {
                $this->audit($auditId, $deletion->actorId, 'language_text_deleted', ['deletionId' => $deletion->id, 'locale' => $deletion->locale, 'records' => $deletion->recordsDone, 'media' => $deletion->mediaDone], $time);
            }
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function records(ProfileManifest $profile, string $locale, int $limit): array
    {
        $records = [];
        foreach ($this->candidates($profile, $locale) as [$typeId, $id]) {
            $record = $this->records->find($typeId, $id);
            if ($record !== null) {
                $records[] = $record;
                if (count($records) >= $limit) {
                    break;
                }
            }
        }
        return $records;
    }

    public function countRecords(ProfileManifest $profile, string $locale): int
    {
        return count($this->candidates($profile, $locale));
    }

    public function media(string $locale, int $limit): array
    {
        return array_slice($this->mediaWithText($locale), 0, $limit);
    }

    public function countMedia(string $locale): int
    {
        return count($this->mediaWithText($locale));
    }

    /**
     * Current revisions naming the language, read by their structure; the text filter only narrows the scan.
     * @return list<array{string, string}>
     */
    private function candidates(ProfileManifest $profile, string $locale): array
    {
        $query = $this->pdo->prepare('SELECT r.type_id, r.id, v.body_json FROM cms_records r JOIN cms_record_revisions v ON v.id = r.current_revision_id WHERE v.body_json LIKE :named ORDER BY r.id');
        $query->execute(['named' => '%"' . $locale . '"%']);
        $found = [];
        while (($row = $query->fetch(\PDO::FETCH_ASSOC)) !== false) {
            $type = $profile->type((string) $row['type_id']);
            $body = json_decode((string) $row['body_json'], true, 64, JSON_THROW_ON_ERROR);
            if ($type !== null && is_array($body) && LanguageText::record($type->fields, $body, $locale)) {
                $found[] = [(string) $row['type_id'], (string) $row['id']];
            }
        }
        return $found;
    }

    /** @return list<string> */
    private function mediaWithText(string $locale): array
    {
        $query = $this->pdo->prepare('SELECT id, text_json FROM cms_media WHERE text_json LIKE :named ORDER BY id');
        $query->execute(['named' => '%"' . $locale . '"%']);
        $found = [];
        foreach ($query->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if (LanguageText::media(json_decode((string) $row['text_json'], true, 16, JSON_THROW_ON_ERROR), $locale)) {
                $found[] = (string) $row['id'];
            }
        }
        return $found;
    }

    /** @param array<string, mixed> $metadata */
    private function audit(string $id, string $actor, string $event, array $metadata, string $time): void
    {
        $this->pdo->prepare('INSERT INTO cms_audit_events (id, actor_user_id, event_type, metadata_json, occurred_at) VALUES (:id, :actor, :event, :metadata, :time)')
            ->execute(['id' => $id, 'actor' => $actor, 'event' => $event, 'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR), 'time' => $time]);
    }

    private static function time(\DateTimeImmutable $time): string
    {
        return $time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
