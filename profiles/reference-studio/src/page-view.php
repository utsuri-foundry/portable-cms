<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Profile\ReferenceStudio;

final readonly class NavigationLink
{
    public function __construct(
        public string $label,
        public string $href,
        public bool $current = false,
    ) {
        self::text($label, 100);
        self::route($href);
    }

    private static function text(string $value, int $maximum): void
    {
        if (trim($value) === '' || mb_strlen($value) > $maximum) {
            throw new \InvalidArgumentException('Reference navigation label is invalid');
        }
    }

    private static function route(string $value): void
    {
        if (strlen($value) > 500 || preg_match('#\A/(?:[A-Za-z0-9._~-]+/)*\z#', $value) !== 1) {
            throw new \InvalidArgumentException('Reference navigation route is invalid');
        }
    }
}

final readonly class PageMetadata
{
    /** @param string $field The record field that supplies the value, for live preview linking. */
    public function __construct(public string $label, public string $value, public string $field = '')
    {
        if ($field !== '') {
            \Utsuri\PortableCms\Conventional\Compiler\FieldMarks::none()->attribute($field);
        }
        if (
            trim($label) === ''
            || trim($value) === ''
            || mb_strlen($label) > 100
            || mb_strlen($value) > 240
        ) {
            throw new \InvalidArgumentException('Reference page metadata is invalid');
        }
    }
}

final readonly class PageCredit
{
    public function __construct(public string $itemId, public string $role, public string $name)
    {
        \Utsuri\PortableCms\Conventional\Compiler\FieldMarks::none()->itemAttribute('credits', $itemId, 'name');
        if (mb_strlen($role) > 100 || mb_strlen($name) > 160) {
            throw new \InvalidArgumentException('Reference credit is invalid');
        }
    }
}

final readonly class PageMedia
{
    public function __construct(
        public string $src,
        public int $width,
        public int $height,
        public string $alternativeText,
        public string $caption = '',
        public string $credit = '',
        public \Utsuri\PortableCms\Conventional\Media\FocalPoint $focalPoint = new \Utsuri\PortableCms\Conventional\Media\FocalPoint(),
        public ?\Utsuri\PortableCms\Conventional\Compiler\FrozenMedia $source = null,
    ) {
        if (
            preg_match('#\A/(?:[A-Za-z0-9._~-]+/)*[A-Za-z0-9._~-]+\z#', $src) !== 1
            || $width < 1
            || $height < 1
            || mb_strlen($alternativeText) > 500
            || mb_strlen($caption) > 500
            || mb_strlen($credit) > 500
        ) {
            throw new \InvalidArgumentException('Reference page media is invalid');
        }
    }
}

final readonly class PageCard
{
    public function __construct(
        public string $title,
        public string $href,
        public string $summary = '',
        public ?PageMedia $preview = null,
    ) {
        if (
            trim($title) === ''
            || mb_strlen($title) > 240
            || mb_strlen($summary) > 1000
            || strlen($href) > 500
            || preg_match('#\A/(?:[A-Za-z0-9._~-]+/)*\z#', $href) !== 1
        ) {
            throw new \InvalidArgumentException('Reference page card is invalid');
        }
    }
}

final readonly class InterfaceCopy
{
    public function __construct(
        public string $skipToContent,
        public string $primaryNavigation,
        public string $searchLabel,
        public string $searchPlaceholder,
        public string $resultSingular,
        public string $resultPlural,
        public string $compositionLabel,
    ) {
        foreach ([
            $skipToContent,
            $primaryNavigation,
            $searchLabel,
            $searchPlaceholder,
            $resultSingular,
            $resultPlural,
            $compositionLabel,
        ] as $value) {
            if (trim($value) === '' || mb_strlen($value) > 160) {
                throw new \InvalidArgumentException('Reference interface copy is invalid');
            }
        }
    }
}

final readonly class PageView
{
    /**
     * @param list<NavigationLink> $navigation
     * @param list<string|\Utsuri\PortableCms\Conventional\Content\RichText> $paragraphs
     * @param list<PageMetadata> $metadata
     * @param list<PageMedia> $media
     * @param list<PageCard> $cards
     * @param list<PageCredit> $credits
     * @param array<'title'|'copy'|'cards'|'composition'|'website', string> $sources The record field behind each part of the page.
     */
    public function __construct(
        public string $locale,
        public string $siteName,
        public string $title,
        public string $path,
        public array $navigation,
        public array $paragraphs = [],
        public array $metadata = [],
        public array $media = [],
        public array $cards = [],
        public string $description = '',
        public ?InterfaceCopy $interface = null,
        public ?\Utsuri\PortableCms\Conventional\View\CompositionView $composition = null,
        public ?\Utsuri\PortableCms\Conventional\Content\SearchSharing $searchSharing = null,
        public string $titleFormat = 'title-site',
        public string $organizationName = '',
        public string $websiteDescription = '',
        public ?\Utsuri\PortableCms\Conventional\Content\WebAddress $website = null,
        public ?\Utsuri\PortableCms\Conventional\Compiler\FieldMarks $fields = null,
        public array $sources = [],
        public ?\Utsuri\PortableCms\Conventional\View\TrustedHtml $blocks = null,
        public array $credits = [],
    ) {
        foreach ($sources as $part => $field) {
            if (!in_array($part, ['title', 'copy', 'cards', 'composition', 'website'], true)) {
                throw new \InvalidArgumentException('Reference page source is invalid');
            }
            \Utsuri\PortableCms\Conventional\Compiler\FieldMarks::none()->attribute($field);
        }
        if (
            preg_match('/\A[a-z]{2,3}(?:-[A-Z]{2})?\z/', $locale) !== 1
            || trim($siteName) === ''
            || trim($title) === ''
            || mb_strlen($siteName) > 180
            || mb_strlen($organizationName) > 160
            || mb_strlen($title) > 240
            || mb_strlen($description) > 500
            || strlen($path) > 500
            || preg_match('#\A/(?:[A-Za-z0-9._~-]+/)*\z#', $path) !== 1
        ) {
            throw new \InvalidArgumentException('Reference page identity is invalid');
        }
        self::instances($navigation, NavigationLink::class, 32);
        self::instances($metadata, PageMetadata::class, 64);
        self::instances($media, PageMedia::class, 128);
        self::instances($cards, PageCard::class, 5000);
        self::instances($credits, PageCredit::class, 32);
        if (count($paragraphs) > 256) {
            throw new \InvalidArgumentException('Reference page copy is invalid');
        }
        foreach ($paragraphs as $paragraph) {
            if ($paragraph instanceof \Utsuri\PortableCms\Conventional\Content\RichText) {
                continue;
            }
            if (!is_string($paragraph) || mb_strlen($paragraph) > 10_000) {
                throw new \InvalidArgumentException('Reference page copy is invalid');
            }
        }
        if (!$interface instanceof InterfaceCopy) {
            throw new \InvalidArgumentException('Reference page interface copy is required');
        }
    }

    /** The live preview mark for the field behind one part of the page; nothing on published pages. */
    public function mark(string $part): string
    {
        return $this->markField($this->sources[$part] ?? '');
    }

    public function markField(string $field): string
    {
        return $field === '' || $this->fields === null ? '' : $this->fields->attribute($field);
    }

    /** @param list<object> $values */
    private static function instances(array $values, string $class, int $maximum): void
    {
        if (count($values) > $maximum) {
            throw new \InvalidArgumentException('Reference page collection is too large');
        }
        foreach ($values as $value) {
            if (!$value instanceof $class) {
                throw new \InvalidArgumentException('Reference page collection is invalid');
            }
        }
    }
}
