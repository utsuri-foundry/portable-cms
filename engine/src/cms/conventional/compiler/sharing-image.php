<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Compiler;

/** Verified derivative identity, independent of private storage and provider URLs. */
final readonly class SharingImage
{
    public string $path;

    public function __construct(
        public string $mediaId,
        public string $sourceSha256,
        public string $sha256,
        public int $byteSize,
        public string $processorVersion,
        public ?\Utsuri\PortableCms\Conventional\Media\MediaText $text = null,
        public string $defaultLocale = '',
    ) {
        if (preg_match('/\A[a-f0-9]{32}\z/', $mediaId) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/', $sourceSha256) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/', $sha256) !== 1
            || $byteSize < 1 || $byteSize > 10 * 1024 * 1024
            || $processorVersion === '' || strlen($processorVersion) > 180
            || preg_match('/[\x00-\x1f\x7f]/', $processorVersion) === 1) {
            throw new \InvalidArgumentException('Sharing image identity is invalid');
        }
        $this->path = 'sharing/' . $sha256 . '.jpg';
    }

    /** @return array{mediaId: string, sourceSha256: string, path: string, sha256: string, byteSize: int, width: int, height: int, recipe: array{id: string, version: int, processorVersion: string, quality: int, fit: string, background: string}} */
    public function document(): array
    {
        return ['mediaId' => $this->mediaId, 'sourceSha256' => $this->sourceSha256,
            'path' => $this->path, 'sha256' => $this->sha256, 'byteSize' => $this->byteSize,
            'width' => 1200, 'height' => 630,
            'recipe' => ['id' => 'imagick-sharing-jpeg', 'version' => 1,
                'processorVersion' => $this->processorVersion, 'quality' => 82,
                'fit' => 'contain-no-upscale', 'background' => 'white']];
    }
}

final readonly class SharingImageOutput
{
    public function __construct(public SharingImage $image, public string $bytes)
    {
        if (strlen($bytes) !== $image->byteSize || !hash_equals($image->sha256, hash('sha256', $bytes))
            || !str_starts_with($bytes, "\xff\xd8\xff")) {
            throw new \InvalidArgumentException('Sharing image bytes do not match their identity');
        }
    }
}

interface SharingImageProcessor
{
    public function process(FrozenMedia $source, string $verifiedPath): SharingImageOutput;
}

final readonly class SharingImages
{
    /** @var array<string, SharingImage> */
    private array $byMedia;

    /** @param list<SharingImage> $images */
    public function __construct(array $images = [])
    {
        if (count($images) > 1000 || !array_is_list($images)) {
            throw new \InvalidArgumentException('Sharing image collection is invalid');
        }
        $byMedia = [];
        foreach ($images as $image) {
            if (isset($byMedia[$image->mediaId])) {
                throw new \InvalidArgumentException('Sharing image selection is duplicated');
            }
            $byMedia[$image->mediaId] = $image;
        }
        $this->byMedia = $byMedia;
    }

    public function image(?string $mediaId): ?SharingImage
    {
        if ($mediaId === null) {
            return null;
        }
        return $this->byMedia[$mediaId] ?? throw new \InvalidArgumentException('Selected sharing image was not prepared');
    }
}

interface SharingSiteBuilder extends ProfileSiteBuilder
{
    /** @return list<string> */
    public function sharingMediaIds(FrozenSnapshot $snapshot): array;

    public function buildWithSharing(FrozenSnapshot $snapshot, SharingImages $images): ProfileSiteBuild;
}
