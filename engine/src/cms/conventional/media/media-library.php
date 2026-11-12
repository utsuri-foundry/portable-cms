<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Media;

use Utsuri\PortableCms\Conventional\Identity\Clock;
use Utsuri\PortableCms\Conventional\Identity\RandomSource;

final readonly class LibraryMedia
{
    public function __construct(
        public string $id,
        public ?string $ownerRecordId,
        public string $name,
        public string $status,
        public string $mime,
        public int $byteSize,
        public ?int $width,
        public ?int $height,
        public int $metadataRevision,
        public ?string $errorCode,
        public ?MediaDerivative $thumbnail,
        public ?MediaText $text = null,
        public FocalPoint $focalPoint = new FocalPoint(),
        public ?VideoFrame $videoFrame = null,
    ) {}
}

final readonly class MediaPage
{
    /** @param list<LibraryMedia> $items */
    public function __construct(public array $items, public bool $hasNext, public int $page, public string $query) {}
}

interface MediaLibraryRepository
{
    public function search(string $query, int $page): MediaPage;

    public function find(string $id): ?LibraryMedia;

    /** @return list<array{recordId: string, typeId: string, fieldId: string}> */
    public function usage(string $id): array;

    public function rename(string $id, int $expectedRevision, string $name, string $actorId, string $auditId, \DateTimeImmutable $now, ?MediaText $text = null, ?FocalPoint $focalPoint = null, bool $keepForWebsite = false): bool;
}

final class MediaLibrary
{
    public function __construct(
        private readonly MediaLibraryRepository $repository,
        private readonly FilesystemMediaStore $storage,
        private readonly Clock $clock,
        private readonly RandomSource $random,
        public readonly VideoUploadPolicy $videoUploads = new VideoUploadPolicy(),
    ) {}

    /** @var array<string, array<string, mixed>> */
    private array $videoFacts = [];

    /** @return array<string, mixed> */
    public function videoFacts(LibraryMedia $media): array
    {
        $derivative = $media->thumbnail;
        if ($media->status !== 'ready' || $derivative?->variant !== 'prepared-video') {
            throw new \Utsuri\PortableCms\Conventional\I18n\InvalidInput('video.facts_unavailable', ['field' => $media->name]);
        }
        $key = $media->id . $derivative->checksum;
        try {
            return $this->videoFacts[$key] ??= is_bool($derivative->parameters['hasAudio'] ?? null) && is_int($derivative->parameters['durationMilliseconds'] ?? null) && is_string($derivative->parameters['codec'] ?? null)
                ? [...$derivative->parameters, 'width' => $derivative->width, 'height' => $derivative->height]
                : $this->storage->videoFacts($derivative);
        } catch (MediaProcessingFailure $error) {
            throw new \Utsuri\PortableCms\Conventional\I18n\InvalidInput('video.facts_unavailable', ['field' => $media->name], $error);
        }
    }

    public function search(string $query = '', int $page = 1): MediaPage
    {
        $result = $this->repository->search(mb_substr(trim($query), 0, 100), max(1, min(1000, $page)));
        return new MediaPage(array_map($this->withFrame(...), $result->items), $result->hasNext, $result->page, $result->query);
    }

    public function find(string $id): ?LibraryMedia
    {
        $media = preg_match('/\A[a-f0-9]{32}\z/', $id) === 1 ? $this->repository->find($id) : null;
        return $media === null ? null : $this->withFrame($media);
    }

    /** @return list<array{recordId: string, typeId: string, fieldId: string}> */
    public function usage(string $id): array
    {
        return $this->find($id) === null ? [] : $this->repository->usage($id);
    }

    public function rename(string $id, int $revision, string $name, string $actorId, ?MediaText $text = null, ?FocalPoint $focalPoint = null): bool
    {
        $media = $this->find($id);
        if ($focalPoint !== null && $media !== null && !str_starts_with($media->mime, 'image/')) {
            throw new \Utsuri\PortableCms\Conventional\I18n\InvalidInput('media.focal.invalid');
        }
        if ($media === null || $revision < 1 || trim($name) === '' || mb_strlen($name) > 180
            || preg_match('/[\x00-\x1f\x7f]/u', $name) === 1) {
            throw new \InvalidArgumentException('Enter a name of at most 180 characters.');
        }
        return $this->repository->rename($id, $revision, trim($name), $actorId, bin2hex($this->random->bytes(16)), $this->clock->now(), $text, $focalPoint);
    }

    public function video(string $id): ?MediaDelivery
    {
        $media = $this->find($id);
        return $media === null || $media->status !== 'ready' || $media->thumbnail?->variant !== 'prepared-video'
            ? null : $this->storage->readVideo($media->thumbnail);
    }

    public function keepForWebsite(string $id, int $revision, string $actorId): bool
    {
        $media = $this->find($id);
        if ($media === null || $media->status !== 'ready' || !in_array($media->mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new \Utsuri\PortableCms\Conventional\I18n\InvalidInput('website_media.image_required');
        }
        if ($media->ownerRecordId === null) {
            return $media->metadataRevision === $revision;
        }
        return $this->repository->rename($id, $revision, $media->name, $actorId, bin2hex($this->random->bytes(16)), $this->clock->now(), keepForWebsite: true);
    }

    public function cacheVideoFrame(string $id, string $sourceChecksum, string $bytes): bool
    {
        $video = $this->find($id);
        if ($video?->status !== 'ready' || $video->thumbnail?->variant !== 'prepared-video'
            || !hash_equals($video->thumbnail->checksum, $sourceChecksum)) {
            return false;
        }
        return $this->storage->cacheVideoFrame($video->thumbnail, $bytes);
    }

    private function withFrame(LibraryMedia $media): LibraryMedia
    {
        if ($media->status !== 'ready' || $media->thumbnail?->variant !== 'prepared-video') {
            return $media;
        }
        $thumbnail = $media->thumbnail;
        if (!is_int($thumbnail->parameters['durationMilliseconds'] ?? null) || !is_string($thumbnail->parameters['codec'] ?? null)) {
            try {
                $facts = $this->videoFacts($media);
                $thumbnail = new MediaDerivative($thumbnail->mediaId, $thumbnail->variant, $thumbnail->objectKey, $thumbnail->filename, $thumbnail->mime, $thumbnail->width, $thumbnail->height, $thumbnail->byteSize, $thumbnail->checksum, $thumbnail->processorId, $thumbnail->processorVersion, $thumbnail->recipeVersion, [...$thumbnail->parameters, ...$facts]);
            } catch (\InvalidArgumentException) {
                // Unavailable legacy bytes retain a visible video fallback, never a preparing state.
            }
        }
        try {
            $frame = $this->storage->videoFrame($media->thumbnail);
        } catch (MediaProcessingFailure) {
            $frame = null;
        }
        return new LibraryMedia(
            $media->id,
            $media->ownerRecordId,
            $media->name,
            $media->status,
            $media->mime,
            $media->byteSize,
            $media->width,
            $media->height,
            $media->metadataRevision,
            $media->errorCode,
            $thumbnail,
            $media->text,
            $media->focalPoint,
            $frame,
        );
    }

    public function thumbnail(string $id): ?string
    {
        $media = $this->find($id);
        if ($media?->status === 'ready' && $media->thumbnail?->variant === 'prepared-video') {
            return $this->storage->readVideoFrame($media->thumbnail);
        }
        if ($media === null || $media->status !== 'ready' || $media->thumbnail === null || $media->thumbnail->mime !== 'image/webp') {
            return null;
        }
        return $this->storage->readThumbnail($media->thumbnail);
    }
}
