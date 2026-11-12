<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Import;

use Utsuri\PortableCms\Conventional\HostIo;
use Utsuri\PortableCms\Conventional\Identity\Clock;
use Utsuri\PortableCms\Conventional\Identity\RandomSource;
use Utsuri\PortableCms\Conventional\Media\FilesystemMediaStore;
use Utsuri\PortableCms\Conventional\Media\FilesystemUploadStorage;
use Utsuri\PortableCms\Conventional\Media\MediaContract;
use Utsuri\PortableCms\Conventional\Media\MediaLibrary;
use Utsuri\PortableCms\Conventional\Media\MediaLibraryRepository;
use Utsuri\PortableCms\Conventional\Media\MediaUploadService;
use Utsuri\PortableCms\Conventional\Media\UploadRepository;
use Utsuri\PortableCms\Conventional\Restore\FilesystemRestoreStore;
use Utsuri\PortableCms\Conventional\Restore\RestoreFile;

/** Explicit reserved opaque IDs, never authentication material. Session IDs remain random and expirable. */
final class ReservedImportIds implements RandomSource
{
    /** @param list<string> $ids */
    public function __construct(private array $ids) {}
    public function bytes(int $length): string
    {
        $id = array_shift($this->ids);
        if ($length !== 16 || !is_string($id) || preg_match('/\A[a-f0-9]{32}\z/', $id) !== 1) {
            throw new \LogicException('Unexpected import identity request');
        }
        return (string) hex2bin($id);
    }
}

final class ImportMediaTransfer
{
    public function __construct(private readonly UploadRepository $uploads, private readonly string $root, private readonly Clock $clock, private readonly RandomSource $random, private readonly MediaLibraryRepository $library) {}
    public function updateMetadata(ImportMedia $media, string $actorId): void
    {
        $current = $this->library->find($media->id);
        if ($current === null) {
            throw new ImportRejected('media_metadata_changed_during_import');
        }
        if (ImportMedia::metadataDigest(['originalName' => $current->name, 'text' => $current->text?->document(), 'focalPoint' => $current->focalPoint->document()]) === ImportMedia::metadataDigest($media->value)) {
            return;
        }
        if ($current->metadataRevision !== ($media->expectedMetadataRevision ?? 1)) {
            throw new ImportRejected('media_metadata_changed_during_import');
        }
        $library = new MediaLibrary($this->library, new FilesystemMediaStore($this->root), $this->clock, $this->random);
        if (!$library->rename($media->id, $current->metadataRevision, $media->value['originalName'], $actorId, \Utsuri\PortableCms\Conventional\Media\MediaText::from($media->value['text']), \Utsuri\PortableCms\Conventional\Media\FocalPoint::from($media->value['focalPoint']))) {
            throw new ImportRejected('media_metadata_changed_during_import');
        }
    }
    /** @template T
     * @param callable(): T $operation
     * @return T
     */
    public function exclusive(callable $operation): mixed
    {
        $path = (new FilesystemRestoreStore($this->root, $this->clock))->path('content-import.lock');
        $handle = HostIo::attempt(fn() => fopen($path, 'c+b'))->value;
        if (!is_resource($handle)) {
            throw new ImportRejected('private_import_lock_unavailable');
        }
        try {
            HostIo::required(fn() => chmod($path, 0600));
            if (!flock($handle, LOCK_EX | LOCK_NB)) {
                throw new ImportRejected('another_content_import_is_running');
            }
            return $operation();
        } finally {
            HostIo::cleanup(fn() => fclose($handle));
        }
    }
    public function transfer(ImportMedia $media, FilesystemRestoreStore $inventory, string $actorId): void
    {
        $original = $media->value['original'];
        $path = $inventory->verify('blobs/' . $original['sha256'], new RestoreFile('source', $original['sha256'], $original['byteSize'], $original['mime']));
        $owner = $media->ownerId === null ? null : $this->uploads->ownerRecord($media->ownerId);
        if ($media->ownerId !== null && $owner === null) {
            throw new ImportRejected('media_owner_disappeared');
        }
        $storage = new FilesystemUploadStorage($this->root);
        $service = new MediaUploadService($this->uploads, $storage, $this->clock, $this->random);
        // Source names are presentation data; the service bounds and sanitizes them.
        $session = $media->ownerId === null ? $service->createWebsite($media->value['originalName'], $original['mime'], $original['byteSize'], 'content-import:' . $media->clientUploadId, $actorId)
            : $service->create($owner['typeId'], $media->ownerId, $owner['revisionNumber'], $media->value['originalName'], $original['mime'], $original['byteSize'], 'content-import:' . $media->clientUploadId, $actorId);
        if ($session->status !== 'completed') {
            $input = HostIo::attempt(fn() => fopen($path, 'rb'))->value;
            if (!is_resource($input)) {
                throw new ImportRejected('original_unreadable');
            }
            try {
                $done = array_column($session->parts, null, 'partNumber');
                for ($number = 1; $number <= $session->partCount; $number++) {
                    $size = MediaContract::expectedPartSize($session, $number);
                    if (isset($done[$number])) {
                        HostIo::required(fn(): bool => fseek($input, $size, SEEK_CUR) === 0);
                        continue;
                    }
                    $part = HostIo::attempt(fn() => fopen('php://temp/maxmemory:1048576', 'w+b'))->value;
                    if (!is_resource($part)) {
                        throw new ImportRejected('bounded_part_storage_unavailable');
                    }
                    try {
                        $copied = HostIo::attempt(fn() => stream_copy_to_stream($input, $part, $size))->value;
                        if ($copied !== $size) {
                            throw new ImportRejected('original_changed_during_transfer');
                        }
                        HostIo::required(fn(): bool => rewind($part));
                        $service->uploadPart($session->id, $number, $size, $part, $actorId);
                    } finally {
                        HostIo::cleanup(fn() => fclose($part));
                    }
                }
            } finally {
                HostIo::cleanup(fn() => fclose($input));
            }
        }
        $completion = new MediaUploadService($this->uploads, $storage, $this->clock, new ReservedImportIds([$media->id, $media->jobId, $media->auditId]));
        $result = $completion->complete($session->id, $actorId, $original['sha256']);
        if ($result['media']['id'] !== $media->id || $result['media']['checksum'] !== $original['sha256']) {
            throw new ImportRejected('completed_upload_identity_mismatch');
        }
        $this->updateMetadata($media, $actorId);
    }
}
