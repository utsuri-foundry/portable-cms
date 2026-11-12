<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Profile\ReferenceStudio;

use Utsuri\PortableCms\Conventional\Compiler\ArtifactUrl;
use Utsuri\PortableCms\Conventional\Http\Html;

final class Presentation
{
    public static function render(PageView $page, string $variant, ?\Utsuri\PortableCms\Conventional\Compiler\RouteDiscovery $discovery = null, ?\Utsuri\PortableCms\Conventional\Compiler\SharingImage $sharingImage = null, ?\Utsuri\PortableCms\Conventional\Compiler\WebsiteIcon $websiteIcon = null): string
    {
        if (!in_array($variant, self::variants(), true)) {
            throw new \InvalidArgumentException('Reference renderer variant is invalid');
        }
        $navigation = '';
        foreach ($page->navigation as $link) {
            $navigation .= '<a href="' . ArtifactUrl::escaped($link->href, $page->path) . '"'
                . ($link->current ? ' aria-current="page"' : '') . '>'
                . Html::text($link->label) . '</a>';
        }

        $metadata = '';
        foreach ($page->metadata as $item) {
            $metadata .= '<div><dt>' . Html::text($item->label) . '</dt><dd' . $page->markField($item->field) . '>'
                . Html::text($item->value) . '</dd></div>';
        }

        $credits = '';
        foreach ($page->credits as $item) {
            $credits .= '<div><dt' . ($page->fields?->itemAttribute('credits', $item->itemId, 'role') ?? '') . '>' . Html::text($item->role) . '</dt>'
                . '<dd' . ($page->fields?->itemAttribute('credits', $item->itemId, 'name') ?? '') . '>' . Html::text($item->name) . '</dd></div>';
        }

        $copy = '';
        foreach ($page->paragraphs as $paragraph) {
            $copy .= $paragraph instanceof \Utsuri\PortableCms\Conventional\Content\RichText
                ? $paragraph->html($page->path, isset($page->sources['copy']) && $page->fields !== null
                    ? static fn(int $position): string => $page->fields->blockAttribute($page->sources['copy'], $position) : null)
                : '<p>' . nl2br(Html::text($paragraph), false) . '</p>';
        }

        $media = '';
        foreach ($page->media as $item) {
            $media .= self::media($item, $page->path);
        }

        $cards = '';
        foreach ($page->cards as $card) {
            $preview = $card->preview === null ? '' : self::media($card->preview, $page->path, true);
            $cards .= '<li class="studio-index__item" data-index-item data-search-text="'
                . Html::attribute(mb_strtolower($card->title . ' ' . $card->summary)) . '">'
                . '<a href="' . ArtifactUrl::escaped($card->href, $page->path) . '" data-preview-trigger>'
                . '<span class="studio-index__title">' . Html::text($card->title) . '</span>'
                . ($card->summary === '' ? '' : '<span class="studio-index__summary">'
                    . Html::text($card->summary) . '</span>')
                . '</a>' . $preview . '</li>';
        }

        $searchSharing = ($page->searchSharing ?? \Utsuri\PortableCms\Conventional\Content\SearchSharing::from(null))
            ->resolve($page->title, $page->description, $page->siteName, $page->titleFormat, $page->websiteDescription);
        $structured = self::structured($page, $variant, $discovery);
        $class = 'studio-page studio-page--' . Html::attribute($variant) . ($page->composition === null ? '' : ' studio-page--composed');
        $search = $cards === '' || !in_array($variant, ['home', 'search'], true)
            ? ''
            : '<form class="studio-search" role="search" data-filter-form>'
                . '<label for="studio-query">' . Html::text($page->interface->searchLabel) . '</label>'
                . '<input id="studio-query" name="q" type="search" autocomplete="off"'
                . ' placeholder="' . Html::attribute($page->interface->searchPlaceholder) . '" data-filter-input>'
                . '<span hidden data-result-singular>' . Html::text($page->interface->resultSingular) . '</span>'
                . '<span hidden data-result-plural>' . Html::text($page->interface->resultPlural) . '</span>'
                . '<output for="studio-query" aria-live="polite" data-filter-status></output></form>';
        $main = '<main id="content" class="' . $class . '"><header class="studio-heading">'
            . '<p class="studio-heading__kicker">' . Html::text($page->siteName) . '</p>'
            . '<h1 class="cms-type-title"' . $page->mark('title') . '>' . Html::text($page->title) . '</h1></header>'
            . ($metadata === '' ? '' : '<dl class="studio-metadata">' . $metadata . '</dl>')
            . ($credits === '' ? '' : '<dl class="studio-metadata"' . $page->markField('credits') . '>' . $credits . '</dl>')
            . ($copy === '' ? '' : '<div class="studio-copy"' . $page->mark('copy') . '>' . $copy . '</div>')
            . ($page->website === null ? '' : '<p class="studio-copy"' . $page->mark('website') . '>' . \Utsuri\PortableCms\Conventional\Compiler\WebAddressLink::render($page->website->document())->value . '</p>')
            . ($page->blocks === null ? '' : $page->blocks->value)
            . $search
            . ($cards === '' ? '' : '<ul class="studio-index" data-filter-results' . $page->mark('cards') . '>' . $cards . '</ul>')
            . ($media === '' ? '' : '<div class="studio-media-grid">' . $media . '</div>')
            . ($page->composition === null ? '' : '<section class="studio-composition" aria-label="' . Html::attribute($page->interface->compositionLabel) . '"' . $page->mark('composition') . '>' . $page->composition->html . '</section>')
            . '</main>';

        return '<!doctype html><html lang="' . Html::attribute($page->locale) . '"><head>'
            . \Utsuri\PortableCms\Conventional\Compiler\DocumentHead::render($page->path, $page->locale, $page->siteName, $searchSharing, $discovery, $sharingImage, $structured, $websiteIcon, '/assets/icon.svg')
            . '<link rel="preload" href="' . ArtifactUrl::escaped('/assets/studio-sans-latin.woff2', $page->path) . '" as="font" type="font/woff2" crossorigin>'
            . '<link rel="preload" href="' . ArtifactUrl::escaped('/assets/studio-serif-latin.woff2', $page->path) . '" as="font" type="font/woff2" crossorigin>'
            . '<link rel="stylesheet" href="' . ArtifactUrl::escaped('/assets/site.css', $page->path) . '">'
            . '<link rel="stylesheet" href="' . ArtifactUrl::escaped('/assets/media-focal.css', $page->path) . '">'
            . '<link rel="stylesheet" href="' . ArtifactUrl::escaped('/assets/design.css', $page->path) . '">'
            . ($page->composition === null ? '' : '<link rel="stylesheet" href="' . ArtifactUrl::escaped('/assets/composition.css', $page->path) . '">')
            . '<script type="module" src="' . ArtifactUrl::escaped('/assets/search.js', $page->path) . '"></script></head><body class="cms-type-body">'
            . '<a class="skip-link" href="#content">' . Html::text($page->interface->skipToContent) . '</a>'
            . '<header class="studio-header"><a class="studio-name" href="' . ArtifactUrl::escaped('/', $page->path) . '">'
            . Html::text($page->siteName) . '</a><nav aria-label="'
            . Html::attribute($page->interface->primaryNavigation) . '">' . $navigation . '</nav></header>'
            . $main . '<footer class="studio-footer"><p>' . Html::text($page->siteName) . '</p>'
            . ($page->organizationName === '' || $page->organizationName === $page->siteName ? '' : '<p>' . Html::text($page->organizationName) . '</p>') . '</footer>'
            . '</body></html>';
    }

    private static function structured(PageView $page, string $variant, ?\Utsuri\PortableCms\Conventional\Compiler\RouteDiscovery $routes): ?\Utsuri\PortableCms\Conventional\Compiler\StructuredData
    {
        if ($routes?->context === null || $variant === 'site-settings') {
            return null;
        }
        $breadcrumbs = [];
        foreach ($page->navigation as $index => $link) {
            if ($index === 0 || ($link->current && $link->href !== $page->path)) {
                $breadcrumbs[] = ['name' => $link->label, 'path' => $link->href];
            }
        }
        if ($variant === 'home') {
            $breadcrumbs = [];
        } else {
            $breadcrumbs[] = ['name' => $page->title, 'path' => $page->path];
        }
        return \Utsuri\PortableCms\Conventional\Compiler\StructuredData::page(
            $routes,
            $page->path,
            $page->locale,
            $page->title,
            $page->description,
            in_array($variant, ['home', 'about'], true) ? ($page->organizationName ?: $page->siteName) : null,
            $variant === 'article' && array_filter($page->navigation, static fn(NavigationLink $link): bool => $link->href === $page->path) === [],
            $breadcrumbs,
        );
    }

    private static function media(PageMedia $media, string $route, bool $preview = false): string
    {
        $responsive = $media->source === null ? '' : ' ' . \Utsuri\PortableCms\Conventional\Compiler\ResponsiveImage::attributes($media->source, $route, $preview ? '(max-width: 760px) 100vw, 33vw' : '100vw');
        return '<figure' . ($preview ? ' class="studio-preview" aria-hidden="true"' : '') . '>'
            . '<img class="' . \Utsuri\PortableCms\Conventional\Compiler\MediaFocalStyle::className($media->focalPoint) . '" src="' . ArtifactUrl::escaped($media->src, $route) . '" width="' . $media->width . '" height="'
            . $media->height . '" alt="' . Html::attribute($media->alternativeText) . '" loading="lazy"' . $responsive . '>'
            . ($media->caption === '' && $media->credit === '' ? '' : '<figcaption>' . Html::text($media->caption)
                . ($media->credit === '' ? '' : '<span class="studio-media-credit">' . ($media->caption === '' ? '' : ' · ') . Html::text($media->credit) . '</span>') . '</figcaption>')
            . '</figure>';
    }

    /** @return list<string> */
    private static function variants(): array
    {
        return [
            'project',
            'article',
            'person',
            'home',
            'about',
            'site-settings',
            'discipline',
            'sector',
            'search',
        ];
    }
}
