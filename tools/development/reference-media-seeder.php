<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Compiler\FilesystemMediaSource;
use Utsuri\PortableCms\Conventional\Compiler\FrozenSnapshot;
use Utsuri\PortableCms\Conventional\Content\ProfilePackage;

/** Development only: fictional originals enter the same durable derivative pipeline as an upload. */
final class ReferenceMediaSeeder
{
    public static function seed(PDO $pdo, ProfilePackage $package, FrozenSnapshot $snapshot, string $actorId, string $mediaRoot): void
    {
        $source = new FilesystemMediaSource($package->root . '/fixtures/media');
        foreach ($snapshot->media as $media) {
            if (!str_starts_with($media->mime, 'image/')) {
                continue;
            }
            $existing = $pdo->prepare('SELECT id FROM cms_media WHERE id = :id');
            $existing->execute(['id' => $media->id]);
            if ($existing->fetchColumn() !== false) {
                continue;
            }
            $path = $source->file($media);
            $key = 'originals/' . $media->id . '/source';
            $directory = $mediaRoot . '/originals/' . $media->id;
            if (!is_dir($directory) && !mkdir($directory, 0700, true)) {
                throw new RuntimeException('Fictional private media directory could not be created');
            }
            if (is_link($directory) || !copy($path, $mediaRoot . '/' . $key) || !chmod($mediaRoot . '/' . $key, 0600)) {
                throw new RuntimeException('Fictional private media could not be prepared');
            }
            $time = $snapshot->createdAt->format('Y-m-d H:i:s.u');
            $name = 'Reference image ' . substr($media->id, -1) . '.webp';
            $pdo->beginTransaction();
            try {
                $insert = $pdo->prepare("INSERT INTO cms_media (id, owner_record_id, status, original_name, declared_type, byte_size, checksum, original_key, created_by, created_at, updated_at, text_json, focal_x, focal_y) VALUES (:id, :owner, 'uploaded', :name, :mime, :size, :checksum, :key, :actor, :created, :updated, :text, :focal_x, :focal_y)");
                $insert->execute(['focal_x' => $media->focalPoint->x, 'focal_y' => $media->focalPoint->y, 'text' => json_encode(($media->text ?? \Utsuri\PortableCms\Conventional\Media\MediaText::from())->document(), JSON_THROW_ON_ERROR), 'id' => $media->id, 'owner' => $media->ownerRecordId, 'name' => $name, 'mime' => $media->mime, 'size' => $media->byteSize, 'checksum' => $media->checksum, 'key' => $key, 'actor' => $actorId, 'created' => $time, 'updated' => $time]);
                $job = $pdo->prepare("INSERT INTO cms_jobs (id, job_type, status, payload_json, available_at, created_at, updated_at) VALUES (:id, 'media_derivatives', 'queued', :payload, :available, :created, :updated)");
                $job->execute(['id' => substr(hash('sha256', 'reference-media-job:' . $media->id), 0, 32), 'payload' => json_encode(['formatVersion' => 1, 'mediaId' => $media->id, 'ownerRecordId' => $media->ownerRecordId, 'expectedByteSize' => $media->byteSize, 'declaredType' => $media->mime], JSON_THROW_ON_ERROR), 'available' => $time, 'created' => $time, 'updated' => $time]);
                $pdo->commit();
            } catch (Throwable $error) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $error;
            }
        }
    }
}
