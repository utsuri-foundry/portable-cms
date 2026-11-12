<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Media;

use PDO;

final class MariaDbMediaLibrary implements MediaLibraryRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function search(string $query, int $page): MediaPage
    {
        $page = max(1, min(1000, $page));
        $offset = ($page - 1) * 20;
        $query = mb_substr(trim($query), 0, 100);
        $statement = $this->pdo->prepare("SELECT id FROM cms_media WHERE original_name LIKE :query ESCAPE '!' ORDER BY created_at DESC, id ASC LIMIT 21 OFFSET {$offset}");
        $statement->execute(['query' => '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $query) . '%']);
        $ids = $statement->fetchAll(PDO::FETCH_COLUMN);
        $items = [];
        foreach (array_slice($ids, 0, 20) as $id) {
            $media = $this->find((string) $id);
            if ($media !== null) {
                $items[] = $media;
            }
        }
        return new MediaPage($items, count($ids) > 20, $page, $query);
    }

    public function find(string $id): ?LibraryMedia
    {
        $statement = $this->pdo->prepare('SELECT * FROM cms_media WHERE id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $derivative = $this->pdo->prepare("SELECT * FROM cms_media_derivatives WHERE media_id = :id AND variant IN ('960-webp', 'prepared-video')");
        $derivative->execute(['id' => $id]);
        $value = $derivative->fetch(PDO::FETCH_ASSOC);
        $thumbnail = is_array($value) ? new MediaDerivative(
            $id,
            (string) $value['variant'],
            (string) $value['object_key'],
            (string) $value['filename'],
            (string) $value['mime'],
            (int) $value['width'],
            (int) $value['height'],
            (int) $value['byte_size'],
            (string) $value['checksum'],
            (string) $value['processor_id'],
            (string) $value['processor_version'],
            (int) $value['recipe_version'],
            self::videoParameters((string) $value['parameters_json']),
        ) : null;
        return new LibraryMedia(
            $id,
            $row['owner_record_id'] === null ? null : (string) $row['owner_record_id'],
            (string) $row['original_name'],
            (string) $row['status'],
            (string) ($row['detected_type'] ?? $row['declared_type']),
            (int) $row['byte_size'],
            $row['width'] === null ? null : (int) $row['width'],
            $row['height'] === null ? null : (int) $row['height'],
            (int) $row['metadata_revision'],
            $row['error_code'] === null ? null : (string) $row['error_code'],
            $thumbnail,
            MediaText::from($row['text_json'] === null ? null : json_decode((string) $row['text_json'], true, 16, JSON_THROW_ON_ERROR)),
            new FocalPoint((int) $row['focal_x'], (int) $row['focal_y']),
        );
    }

    /** @return array<string, int|string|bool> */
    private static function videoParameters(string $json): array
    {
        $value = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        $parameters = [];
        if (is_array($value)) {
            if (is_int($value['durationMilliseconds'] ?? null) && $value['durationMilliseconds'] > 0 && $value['durationMilliseconds'] <= 7200000) {
                $parameters['durationMilliseconds'] = $value['durationMilliseconds'];
            }
            if (is_string($value['codec'] ?? null) && in_array($value['codec'], ['h264', 'vp8', 'vp9'], true)) {
                $parameters['codec'] = $value['codec'];
            }
            if (is_bool($value['hasAudio'] ?? null)) {
                $parameters['hasAudio'] = $value['hasAudio'];
            }
        }
        return $parameters;
    }

    public function usage(string $id): array
    {
        // The current body is authoritative. Historical revisions and snapshots must not be rewritten on replacement.
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT r.id, r.type_id, rr.body_json FROM cms_records r
JOIN cms_record_revisions rr ON rr.id = r.current_revision_id
WHERE rr.body_json LIKE :id LIMIT 101
SQL);
        $statement->execute(['id' => '%' . $id . '%']);
        $usage = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $body = json_decode((string) $row['body_json'], true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($body)) {
                continue;
            }
            foreach ($body as $field => $value) {
                if (self::contains($value, $id)) {
                    $usage[] = ['recordId' => (string) $row['id'], 'typeId' => (string) $row['type_id'], 'fieldId' => (string) $field];
                }
                if (count($usage) >= 100) {
                    return $usage;
                }
            }
        }
        return $usage;
    }

    public function rename(string $id, int $expectedRevision, string $name, string $actorId, string $auditId, \DateTimeImmutable $now, ?MediaText $text = null, ?FocalPoint $focalPoint = null, bool $keepForWebsite = false): bool
    {
        $this->pdo->beginTransaction();
        try {
            $update = $this->pdo->prepare('UPDATE cms_media SET original_name = :name, text_json = COALESCE(:text, text_json), focal_x = COALESCE(:focal_x, focal_x), focal_y = COALESCE(:focal_y, focal_y), metadata_revision = metadata_revision + 1, updated_at = :time'
                . ($keepForWebsite ? ', owner_record_id = NULL' : '') . ' WHERE id = :id AND metadata_revision = :revision'
                . ($keepForWebsite ? " AND owner_record_id IS NOT NULL AND status = 'ready' AND declared_type IN ('image/jpeg', 'image/png', 'image/webp')" : ''));
            $update->execute(['name' => $name, 'focal_x' => $focalPoint?->x, 'focal_y' => $focalPoint?->y, 'text' => $text === null ? null : json_encode($text->document(), JSON_THROW_ON_ERROR), 'time' => $now->format('Y-m-d H:i:s.u'), 'id' => $id, 'revision' => $expectedRevision]);
            if ($update->rowCount() !== 1) {
                $this->pdo->rollBack();
                return false;
            }
            if ($keepForWebsite) {
                // Completed receipts must no longer retain the former record or its retry scope.
                $this->pdo->prepare("UPDATE cms_media_upload_sessions SET owner_record_id = NULL, initial_record_revision = NULL, active_client_key = NULL WHERE media_id = :id AND status = 'completed'")->execute(['id' => $id]);
            }
            $audit = $this->pdo->prepare('INSERT INTO cms_audit_events (id, actor_user_id, event_type, metadata_json, occurred_at) VALUES (:id, :actor, :event, :metadata, :time)');
            $audit->execute(['id' => $auditId, 'actor' => $actorId, 'event' => $keepForWebsite ? 'media_kept_for_website' : ($text === null && $focalPoint === null ? 'media_renamed' : 'media_metadata_updated'), 'metadata' => json_encode(['mediaId' => $id, 'metadataRevision' => $expectedRevision + 1], JSON_THROW_ON_ERROR), 'time' => $now->format('Y-m-d H:i:s.u')]);
            $this->pdo->commit();
            return true;
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    private static function contains(mixed $value, string $id): bool
    {
        if ($value === $id) {
            return true;
        }
        if (is_array($value)) {
            foreach ($value as $child) {
                if (self::contains($child, $id)) {
                    return true;
                }
            }
        }
        return false;
    }
}
