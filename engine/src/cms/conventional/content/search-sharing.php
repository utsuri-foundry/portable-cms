<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Content;

/** Optional plain-text overrides for one locale; absence preserves existing content. */
final readonly class SearchSharing
{
    private function __construct(
        public string $searchTitle,
        public string $description,
        public string $sharingTitle,
        public string $sharingDescription,
        public bool $excludeFromSearch,
        public ?string $sharingImageId,
    ) {}

    public static function from(mixed $value, bool $alwaysExcludeFromSearch = false): self
    {
        if ($value === null) {
            $value = [];
        }
        if (!is_array($value) || ($value !== [] && array_is_list($value))
            || array_diff(array_keys($value), ['searchTitle', 'description', 'sharingTitle', 'sharingDescription', 'excludeFromSearch', 'sharingImage']) !== []
            || (array_key_exists('excludeFromSearch', $value) && !is_bool($value['excludeFromSearch']))) {
            throw new \InvalidArgumentException('Search and sharing overrides are invalid');
        }
        return new self(
            self::text($value['searchTitle'] ?? '', 240),
            self::text($value['description'] ?? '', 500),
            self::text($value['sharingTitle'] ?? '', 240),
            self::text($value['sharingDescription'] ?? '', 500),
            $alwaysExcludeFromSearch || ($value['excludeFromSearch'] ?? false),
            self::image($value['sharingImage'] ?? []),
        );
    }

    public function resolve(string $contentTitle, string $summary, string $siteName, string $titleFormat = 'title-site', string $websiteDescription = ''): EffectiveSearchSharing
    {
        $contentTitle = self::text($contentTitle, 240);
        $summary = self::text($summary, 500);
        $websiteDescription = self::text($websiteDescription, 500);
        $siteName = self::text($siteName, 180);
        if ($contentTitle === '' || $siteName === '') {
            throw new \InvalidArgumentException('Search metadata requires a content title and site name');
        }
        $formatted = match ($titleFormat) {
            'title-site' => $contentTitle . ' | ' . $siteName,
            'site-title' => $siteName . ' | ' . $contentTitle,
            'title-only' => $contentTitle,
            default => throw new \InvalidArgumentException('Search title format is invalid'),
        };
        $defaultTitle = $contentTitle === $siteName ? $siteName : $formatted;
        $title = $this->searchTitle === '' ? $defaultTitle : $this->searchTitle;
        $description = $this->description === '' ? ($summary === '' ? $websiteDescription : $summary) : $this->description;
        return new EffectiveSearchSharing(
            $title,
            $description,
            $this->sharingTitle === '' ? $title : $this->sharingTitle,
            $this->sharingDescription === '' ? $description : $this->sharingDescription,
            [
                'searchTitle' => $this->searchTitle === '' ? 'content_title' : 'override',
                'description' => $this->description === '' ? ($summary === '' && $websiteDescription !== '' ? 'website_default' : 'content_summary') : 'override',
                'sharingTitle' => $this->sharingTitle === '' ? 'search_title' : 'override',
                'sharingDescription' => $this->sharingDescription === '' ? 'search_description' : 'override',
            ],
        );
    }

    /** @return array{searchTitle: string, description: string, sharingTitle: string, sharingDescription: string, excludeFromSearch: bool, sharingImage: list<string>} */
    public function document(): array
    {
        return ['searchTitle' => $this->searchTitle, 'description' => $this->description,
            'sharingTitle' => $this->sharingTitle, 'sharingDescription' => $this->sharingDescription, 'excludeFromSearch' => $this->excludeFromSearch,
            'sharingImage' => $this->sharingImageId === null ? [] : [$this->sharingImageId]];
    }

    public function resolveImage(?string $coverId, ?string $defaultId): EffectiveSharingImage
    {
        self::image($coverId === null ? [] : [$coverId]);
        self::image($defaultId === null ? [] : [$defaultId]);
        return new EffectiveSharingImage(
            $this->sharingImageId ?? $coverId ?? $defaultId,
            $this->sharingImageId !== null ? 'override' : ($coverId !== null ? 'content_cover' : ($defaultId !== null ? 'website_default' : 'none')),
        );
    }

    private static function image(mixed $value): ?string
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > 1
            || ($value !== [] && (!is_string($value[0]) || preg_match('/\A[a-f0-9]{32}\z/', $value[0]) !== 1))) {
            throw new \InvalidArgumentException('Sharing image reference is invalid');
        }
        return $value[0] ?? null;
    }

    private static function text(mixed $value, int $limit): string
    {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value) > $limit
            || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value) === 1) {
            throw new \InvalidArgumentException('Search and sharing text is invalid');
        }
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }
}

final readonly class EffectiveSharingImage
{
    public function __construct(public ?string $mediaId, public string $source) {}
}

final readonly class EffectiveSearchSharing
{
    /** @param array{searchTitle: string, description: string, sharingTitle: string, sharingDescription: string} $sources */
    public function __construct(
        public string $searchTitle,
        public string $description,
        public string $sharingTitle,
        public string $sharingDescription,
        public array $sources,
    ) {}
}
