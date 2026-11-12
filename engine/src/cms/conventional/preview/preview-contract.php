<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Preview;

const PREVIEW_ARTIFACT_MAX_BYTES = 40 * 1024 * 1024;
// The compiler schema allows 20,000 entries; the preview also retains artifact.json itself.
const PREVIEW_ARTIFACT_MAX_FILES = 20_001;

final readonly class PreviewMedia
{
    /** @return array<string, mixed> */
    public function document(): array
    {
        return ['id' => $this->id, 'objectKey' => $this->objectKey, 'fileName' => $this->fileName, 'mime' => $this->mime, 'byteSize' => $this->byteSize, 'checksum' => $this->checksum, 'width' => $this->width, 'height' => $this->height,
            'text' => ($this->text ?? \Utsuri\PortableCms\Conventional\Media\MediaText::from())->document(), 'focalPoint' => $this->focalPoint->document(), 'widths' => array_map(static fn(\Utsuri\PortableCms\Conventional\Media\RasterImageWidth $width): array => $width->document(), $this->widths)];
    }

    /** @param array<string, mixed> $value */
    public static function fromDocument(array $value): self
    {
        return new self(
            (string) ($value['id'] ?? ''),
            (string) ($value['objectKey'] ?? ''),
            (string) ($value['fileName'] ?? ''),
            (string) ($value['mime'] ?? ''),
            (int) ($value['byteSize'] ?? 0),
            (string) ($value['checksum'] ?? ''),
            (int) ($value['width'] ?? 0),
            (int) ($value['height'] ?? 0),
            \Utsuri\PortableCms\Conventional\Media\MediaText::from($value['text'] ?? null),
            \Utsuri\PortableCms\Conventional\Media\FocalPoint::from($value['focalPoint'] ?? null),
            \Utsuri\PortableCms\Conventional\Media\RasterImageWidth::fromDocument($value['widths'] ?? []),
        );
    }

    /** @param list<\Utsuri\PortableCms\Conventional\Media\RasterImageWidth> $widths */
    public function __construct(
        public string $id,
        public string $objectKey,
        public string $fileName,
        public string $mime,
        public int $byteSize,
        public string $checksum,
        public int $width,
        public int $height,
        public ?\Utsuri\PortableCms\Conventional\Media\MediaText $text = null,
        public \Utsuri\PortableCms\Conventional\Media\FocalPoint $focalPoint = new \Utsuri\PortableCms\Conventional\Media\FocalPoint(),
        public array $widths = [],
    ) {
        if (!array_is_list($widths) || count($widths) > 3 || ($widths !== [] && $mime !== 'image/webp')) {
            throw new \InvalidArgumentException('Preview image widths are invalid');
        }
        $seen = [];
        foreach ($widths as $item) {
            if (!$item instanceof \Utsuri\PortableCms\Conventional\Media\RasterImageWidth || $item->maximumWidth === 960 || isset($seen[$item->maximumWidth])) {
                throw new \InvalidArgumentException('Preview image widths are invalid');
            }
            $seen[$item->maximumWidth] = true;
        }
        if (preg_match('/\A[a-f0-9]{32}\z/', $id) !== 1) {
            throw new \InvalidArgumentException('Preview media ID is invalid');
        }
        if (
            $objectKey !== 'derivatives/' . $id . '/' . $fileName
            || $fileName !== match ($mime) {
                'image/webp' => $id . '-960-webp.webp',
                'video/mp4' => $id . '-prepared-video.mp4',
                'video/webm' => $id . '-prepared-video.webm',
                default => '',
            }
            || $byteSize < 1
            || $byteSize > (in_array($mime, ['video/mp4', 'video/webm'], true) ? \Utsuri\PortableCms\Conventional\Media\VideoSupport::MAX_BYTES : PREVIEW_ARTIFACT_MAX_BYTES)
            || preg_match('/\A[a-f0-9]{64}\z/', $checksum) !== 1
            || $width < 1
            || $height < 1
        ) {
            throw new \InvalidArgumentException('Preview media metadata is invalid');
        }
    }
}

final readonly class PreviewRecord
{
    /**
     * @param array<string, mixed> $body
     * @param array<string, list<string>> $relationships
     * @param list<PreviewMedia> $media
     */
    public function __construct(
        public string $id,
        public string $typeId,
        public string $revisionId,
        public int $revisionNumber,
        public array $body,
        public array $relationships,
        public array $media = [],
        public string $workflowState = 'draft',
    ) {
        if (
            preg_match('/\A[a-f0-9]{32}\z/', $id) !== 1
            || preg_match('/\A[a-f0-9]{32}\z/', $revisionId) !== 1
            || preg_match('/\A[a-z][a-z0-9]*(?:[-_][a-z0-9]+)*\z/', $typeId) !== 1
            || $revisionNumber < 1
            || !in_array($workflowState, ['draft', 'published', 'archived'], true)
        ) {
            throw new \InvalidArgumentException('Preview record identity is invalid');
        }
        foreach ($relationships as $fieldId => $targetIds) {
            if (
                !is_string($fieldId)
                || preg_match('/\A[a-z][A-Za-z0-9]*(?:[-_][a-z0-9]+)*\z/', $fieldId) !== 1
                || !is_array($targetIds)
            ) {
                throw new \InvalidArgumentException('Preview relationship is invalid');
            }
            $seen = [];
            foreach ($targetIds as $targetId) {
                if (
                    !is_string($targetId)
                    || preg_match('/\A[a-f0-9]{32}\z/', $targetId) !== 1
                    || isset($seen[$targetId])
                ) {
                    throw new \InvalidArgumentException('Preview relationship target is invalid');
                }
                $seen[$targetId] = true;
            }
        }
        foreach ($media as $asset) {
            if (!$asset instanceof PreviewMedia) {
                throw new \InvalidArgumentException('Preview media collection is invalid');
            }
        }
    }
}

final readonly class PreviewSnapshot
{
    /**
     * @param list<string> $locales
     * @param list<PreviewRecord> $records
     * @param list<string> $preparationLocales Languages among $locales drawn for review only.
     * @param list<array{key: string, locale: string, text: string}> $wording The installation's site wording in $locales where it differs from the profile's defaults.
     * @param list<PreviewMedia> $websiteMedia Prepared images without an owning record.
     */
    public function __construct(
        public string $profileId,
        public string $profileVersion,
        public array $locales,
        public array $records,
        public \DateTimeImmutable $createdAt,
        public ?\Utsuri\PortableCms\Conventional\Design\RegistryDocument $designRegistries = null,
        public ?string $profileChecksum = null,
        public ?\Utsuri\PortableCms\Conventional\Compiler\PublicUrlContext $publicUrlContext = null,
        public ?\Utsuri\PortableCms\Conventional\Compiler\PublishedSlugHistory $publishedSlugHistory = null,
        public bool $includeDrafts = false,
        public ?\Utsuri\PortableCms\Conventional\Publication\WebsitePublication $holdingPage = null,
        public ?string $defaultLocale = null,
        public array $preparationLocales = [],
        public array $wording = [],
        public ?\Utsuri\PortableCms\Conventional\Content\WebsiteIdentity $websiteIdentity = null,
        public array $websiteMedia = [],
    ) {
        $publishedSlugHistory?->assertScope($publicUrlContext, $profileId);
        foreach ($wording as $entry) {
            if (!is_array($entry) || count($entry) !== 3 || !is_string($entry['key'] ?? null) || !is_string($entry['text'] ?? null) || !in_array($entry['locale'] ?? null, $locales, true)) {
                throw new \InvalidArgumentException('Preview snapshot wording is invalid');
            }
        }
        if ($profileChecksum !== null && preg_match('/\A[a-f0-9]{64}\z/', $profileChecksum) !== 1) {
            throw new \InvalidArgumentException('Preview package identity is invalid');
        }
        if ($designRegistries !== null && $designRegistries->profileId() !== $profileId) {
            throw new \InvalidArgumentException('Preview design profile differs');
        }
        if (
            preg_match('/\A[a-z][a-z0-9]*(?:[-_][a-z0-9]+)*\z/', $profileId) !== 1
            || preg_match('/\A[0-9]+\.[0-9]+\.[0-9]+(?:[-+][A-Za-z0-9.-]+)?\z/', $profileVersion) !== 1
            || $locales === []
            || count($locales) > 16
        ) {
            throw new \InvalidArgumentException('Preview snapshot profile is invalid');
        }
        $localeIds = [];
        foreach ($locales as $locale) {
            if (
                !is_string($locale)
                || preg_match('/\A[a-z]{2,3}(?:-[A-Z]{2})?\z/', $locale) !== 1
                || isset($localeIds[$locale])
            ) {
                throw new \InvalidArgumentException('Preview snapshot locales are invalid');
            }
            $localeIds[$locale] = true;
        }
        if (($defaultLocale !== null && !isset($localeIds[$defaultLocale])) || !array_is_list($preparationLocales) || in_array($defaultLocale, $preparationLocales, true)
            || array_diff($preparationLocales, $locales) !== [] || count(array_unique($preparationLocales)) !== count($preparationLocales)) {
            throw new \InvalidArgumentException('Preview snapshot languages are invalid');
        }
        $recordIds = [];
        if (!array_is_list($websiteMedia)) {
            throw new \InvalidArgumentException('Preview website media is invalid');
        }
        foreach ($websiteMedia as $asset) {
            if (!$asset instanceof PreviewMedia || !str_starts_with($asset->mime, 'image/')) {
                throw new \InvalidArgumentException('Preview website media is invalid');
            }
        }
        foreach ($records as $record) {
            if (!$record instanceof PreviewRecord || isset($recordIds[$record->id])) {
                throw new \InvalidArgumentException('Preview snapshot records are invalid');
            }
            $recordIds[$record->id] = true;
        }
    }

    public function includes(PreviewRecord $record): bool
    {
        return $record->workflowState === 'published' || ($this->includeDrafts && $record->workflowState === 'draft');
    }

    /** Snapshots stored before languages were chosen draw every profile language with its default. */
    public function languages(\Utsuri\PortableCms\Conventional\Content\ProfileManifest $profile): \Utsuri\PortableCms\Conventional\Compiler\SnapshotLanguages
    {
        return new \Utsuri\PortableCms\Conventional\Compiler\SnapshotLanguages($this->locales, $this->defaultLocale ?? $profile->defaultLocale, $this->preparationLocales);
    }

    /**
     * The same frozen choice of languages, history and design with other records.
     * @param list<PreviewRecord> $records
     */
    public function with(array $records, bool $includeDrafts): self
    {
        return new self($this->profileId, $this->profileVersion, $this->locales, $records, $this->createdAt, $this->designRegistries, $this->profileChecksum, $this->publicUrlContext, $this->publishedSlugHistory, $includeDrafts, $this->holdingPage, $this->defaultLocale, $this->preparationLocales, $this->wording, $this->websiteIdentity, $this->websiteMedia);
    }
}

final readonly class PreviewArtifact
{
    public function __construct(
        public string $path,
        public string $mime,
        public int $byteSize,
        public string $checksum,
    ) {
        self::assertPath($path);
        if (
            preg_match('/\A[a-z0-9.+-]+\/[a-z0-9.+-]+(?:; charset=utf-8)?\z/', $mime) !== 1
            || $byteSize < 1
            || $byteSize > (in_array($mime, ['video/mp4', 'video/webm'], true) ? \Utsuri\PortableCms\Conventional\Media\VideoSupport::MAX_BYTES : PREVIEW_ARTIFACT_MAX_BYTES)
            || preg_match('/\A[a-f0-9]{64}\z/', $checksum) !== 1
        ) {
            throw new \InvalidArgumentException('Preview artifact metadata is invalid');
        }
    }

    public static function assertPath(string $path): void
    {
        if (
            preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9\/_.-]{0,499}\z/', $path) !== 1
            || str_contains($path, '..')
            || str_contains($path, '//')
            || str_ends_with($path, '/')
        ) {
            throw new \InvalidArgumentException('Preview artifact path is invalid');
        }
    }

    /** @return array{path: string, mime: string, byteSize: int, checksum: string} */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'mime' => $this->mime,
            'byteSize' => $this->byteSize,
            'checksum' => $this->checksum,
        ];
    }
}

final readonly class PreviewBuild
{
    /** @param list<PreviewArtifact> $artifacts */
    public function __construct(public array $artifacts)
    {
        if ($artifacts === [] || count($artifacts) > PREVIEW_ARTIFACT_MAX_FILES) {
            throw new \InvalidArgumentException('Preview artifact manifest is invalid');
        }
        $paths = [];
        foreach ($artifacts as $artifact) {
            if (!$artifact instanceof PreviewArtifact || isset($paths[$artifact->path])) {
                throw new \InvalidArgumentException('Preview artifact manifest contains duplicate paths');
            }
            $paths[$artifact->path] = true;
        }
        if (!isset($paths['index.html'])) {
            throw new \InvalidArgumentException('Preview artifact manifest has no root document');
        }
    }

    /** @return array{formatVersion: int, artifacts: list<array{path: string, mime: string, byteSize: int, checksum: string}>} */
    public function manifest(): array
    {
        return [
            'formatVersion' => 1,
            'artifacts' => array_map(
                static fn(PreviewArtifact $artifact): array => $artifact->toArray(),
                $this->artifacts,
            ),
        ];
    }

    /** @param array<string, mixed> $value */
    public static function fromManifest(array $value): self
    {
        if (($value['formatVersion'] ?? null) !== 1 || !is_array($value['artifacts'] ?? null)) {
            throw new \InvalidArgumentException('Preview artifact manifest is invalid');
        }
        $artifacts = [];
        foreach ($value['artifacts'] as $item) {
            if (!is_array($item)) {
                throw new \InvalidArgumentException('Preview artifact entry is invalid');
            }
            $keys = array_keys($item);
            sort($keys, SORT_STRING);
            if ($keys !== ['byteSize', 'checksum', 'mime', 'path']) {
                throw new \InvalidArgumentException('Preview artifact entry is invalid');
            }
            $artifacts[] = new PreviewArtifact(
                is_string($item['path']) ? $item['path'] : '',
                is_string($item['mime']) ? $item['mime'] : '',
                is_int($item['byteSize']) ? $item['byteSize'] : 0,
                is_string($item['checksum']) ? $item['checksum'] : '',
            );
        }
        return new self($artifacts);
    }
}
