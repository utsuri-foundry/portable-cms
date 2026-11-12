<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Profile\ReferenceStudio;

use Utsuri\PortableCms\Conventional\Compiler\FilesystemMediaSource;
use Utsuri\PortableCms\Conventional\Compiler\FrozenMedia;
use Utsuri\PortableCms\Conventional\Compiler\FrozenRecord;
use Utsuri\PortableCms\Conventional\Compiler\FrozenSnapshot;
use Utsuri\PortableCms\Conventional\Compiler\LanguageLayout;
use Utsuri\PortableCms\Conventional\Compiler\ProfileRoutes;
use Utsuri\PortableCms\Conventional\Compiler\PublishedRecords;
use Utsuri\PortableCms\Conventional\Compiler\SharingSelections;
use Utsuri\PortableCms\Conventional\TextNormalizer;

final class ReferenceContent
{
    private readonly PublishedRecords $published;
    private readonly ProfileRoutes $routes;

    public function __construct(private readonly FrozenSnapshot $snapshot, private readonly \Utsuri\PortableCms\Conventional\Content\ProfileManifest $profile, LanguageLayout $layout = LanguageLayout::Prefixed)
    {
        $this->published = new PublishedRecords($snapshot);
        // Editors name each top-level page's address per language in the site wording.
        $addresses = [];
        foreach (['projects', 'articles', 'people', 'about', 'search', 'disciplines', 'sectors', 'settings'] as $page) {
            $addresses[$page] = 'address.' . $page;
        }
        $this->routes = new ProfileRoutes($profile, $this->published, ['discipline' => '/disciplines/', 'sector' => '/sectors/'], fn(FrozenRecord $record, string $locale): string => in_array($record->typeId, ['discipline', 'sector'], true) ? TextNormalizer::slug($this->localized($record, 'label', $locale)) : $this->localized($record, 'slug', $locale), $layout, $addresses);
    }

    /** A page's address in a language, from its path without a language prefix. */
    public function path(string $path, string $locale): string
    {
        return $this->routes->path($path, $locale);
    }

    /** @return list<FrozenRecord> */
    public function records(string $typeId): array
    {
        return $this->published->records($typeId);
    }

    public function discovery(): \Utsuri\PortableCms\Conventional\Compiler\RouteDiscovery
    {
        return $this->routes->discovery(
            fn(?FrozenRecord $record, string $group, string $locale): \Utsuri\PortableCms\Conventional\Content\SearchSharing => $record !== null ? $this->searchSharing($record, $locale) : $this->pageSearchSharing($group, $locale),
            ['/projects/' => 'projects', '/articles/' => 'articles', '/people/' => 'people', '/settings/' => 'settings', '/disciplines/' => 'disciplines', '/sectors/' => 'sectors', '/search/' => 'search'],
        );
    }

    public function titleFormat(): string
    {
        return $this->text($this->published->singleton('site-settings', false), 'searchTitleFormat') ?: 'title-site';
    }

    public function organizationName(): string
    {
        return $this->text($this->published->singleton('site-settings', false), 'organizationName');
    }

    public function sharingSelections(): SharingSelections
    {
        $default = SharingSelections::setting($this->published, 'site-settings', 'defaultSharingImage');
        $metadata = [];
        $covers = [];
        $discovery = $this->discovery();
        foreach ($discovery->paths() as $path) {
            $route = $discovery->route($path);
            [$kind, $id] = explode(':', $route->translationGroup, 2);
            $record = $kind === 'record' ? $this->publishedRecord($id) : (in_array($id, ['home', 'about', 'landing'], true) ? ($this->records($id)[0] ?? null) : null);
            $metadata[$path] = $record === null ? $this->pageSearchSharing($id, $route->locale) : $this->searchSharing($record, $route->locale);
            if ($kind === 'record' && $record !== null) {
                $covers[$path] = $this->imageMediaIds($record)[0] ?? null;
            }
        }
        return SharingSelections::resolve($this->snapshot, $discovery, static fn(string $path, string $locale): \Utsuri\PortableCms\Conventional\Content\SearchSharing => $metadata[$path], $default, static fn(string $path, string $locale): ?string => $covers[$path] ?? null);
    }

    public function one(string $typeId): FrozenRecord
    {
        return $this->published->singleton($typeId) ?? throw new \InvalidArgumentException('Reference singleton is missing');
    }

    public function publishedRecord(string $id): ?FrozenRecord
    {
        return $this->published->record($id);
    }

    public function media(string $id): FrozenMedia
    {
        $media = $this->snapshot->media($id);
        if (!$media instanceof FrozenMedia) {
            throw new \InvalidArgumentException('Reference media is not frozen');
        }
        return $media;
    }

    public function text(?FrozenRecord $record, string $field): string
    {
        $value = $record->body[$field] ?? '';
        return is_string($value) ? trim($value) : '';
    }

    public function localized(?FrozenRecord $record, string $field, string $locale, bool $required = true): string
    {
        $values = $record->body[$field] ?? null;
        $value = is_array($values) && is_string($values[$locale] ?? null) ? trim($values[$locale]) : '';
        if ($required && $value === '') {
            throw new \Utsuri\PortableCms\Conventional\Compiler\ProfileBuildFailure(\Utsuri\PortableCms\Conventional\Compiler\ProfileBuildReason::MissingTranslation, $record?->id, $record?->typeId, fieldId: $field, locale: $locale);
        }
        return $value;
    }

    /** @return list<string> */
    public function richText(FrozenRecord $record, string $field, string $locale): array
    {
        $translations = $record->body[$field] ?? null;
        $document = is_array($translations) ? ($translations[$locale] ?? null) : null;
        if (!is_array($document) || !is_array($document['nodes'] ?? null)) {
            return [];
        }
        $paragraphs = [];
        foreach ($document['nodes'] as $node) {
            if (!is_array($node)) {
                continue;
            }
            $text = trim($this->nodeText($node));
            if ($text !== '') {
                $paragraphs[] = $text;
            }
        }
        return $paragraphs;
    }

    /** @return list<\Utsuri\PortableCms\Conventional\Content\RichText> */
    public function richFragments(FrozenRecord $record, string $fieldId, string $locale): array
    {
        $document = $record->body[$fieldId][$locale] ?? null;
        if ($document === null) {
            return [];
        }
        foreach ($this->profile->type($record->typeId)->fields ?? [] as $field) {
            if ($field->id === $fieldId) {
                return [\Utsuri\PortableCms\Conventional\Content\RichText::from($document, $field)];
            }
        }
        throw new \InvalidArgumentException('Rich text field is unavailable');
    }

    public function route(FrozenRecord $record, string $locale): string
    {
        return $this->routes->record($record, $locale);
    }

    /** Live preview field marks for pages drawn from this record; nothing on published pages. */
    public function marks(FrozenRecord $record): \Utsuri\PortableCms\Conventional\Compiler\FieldMarks
    {
        return \Utsuri\PortableCms\Conventional\Compiler\FieldMarks::for($this->snapshot, $record);
    }

    /** @return list<PageCredit> */
    public function credits(FrozenRecord $record, string $locale): array
    {
        $credits = [];
        foreach ($record->body['credits'][$locale] ?? [] as $position => $item) {
            $id = $item['_itemId'] ?? \Utsuri\PortableCms\Conventional\Content\EditorialControls::legacyIdentity('credits_' . $locale, $position, $item);
            $credits[] = new PageCredit($id, $item['role'] ?? '', $item['name'] ?? '');
        }
        return $credits;
    }

    public function titleField(FrozenRecord $record): string
    {
        return match ($record->typeId) {
            'person' => 'name',
            'discipline', 'sector' => 'label',
            default => 'title',
        };
    }

    public function title(FrozenRecord $record, string $locale): string
    {
        return match ($record->typeId) {
            'project', 'article', 'landing' => $this->localized($record, 'title', $locale),
            'person' => $this->text($record, 'name'),
            'discipline', 'sector' => $this->localized($record, 'label', $locale),
            default => throw new \InvalidArgumentException('Reference record has no public title'),
        };
    }

    public function summary(FrozenRecord $record, string $locale): string
    {
        if ($record->typeId === 'article') {
            return $this->localized($record, 'excerpt', $locale, false);
        }
        if ($record->typeId === 'person') {
            return $this->localized($record, 'role', $locale, false);
        }
        if ($record->typeId === 'landing') {
            return mb_substr($this->blockText($record, $locale), 0, 1000);
        }
        $fragments = $this->richFragments($record, 'body', $locale);
        return ($fragments[0] ?? null)?->excerpt() ?? '';
    }

    public function searchSharing(FrozenRecord $record, string $locale): \Utsuri\PortableCms\Conventional\Content\SearchSharing
    {
        $group = $record->body['searchSharing'] ?? null;
        return \Utsuri\PortableCms\Conventional\Content\SearchSharing::from(is_array($group) ? ($group[$locale] ?? null) : null);
    }

    public function card(FrozenRecord $record, string $locale): PageCard
    {
        return \Utsuri\PortableCms\Conventional\Compiler\ProfileBuildFailure::atRecord($record, fn(): PageCard => $this->buildCard($record, $locale));
    }

    private function buildCard(FrozenRecord $record, string $locale): PageCard
    {
        $preview = null;
        $mediaIds = $this->imageMediaIds($record);
        if ($mediaIds !== []) {
            $preview = $this->pageMedia($this->media($mediaIds[0]), $locale);
        }
        return new PageCard(
            $this->title($record, $locale),
            $this->route($record, $locale),
            $this->summary($record, $locale),
            $preview,
        );
    }

    /** @return list<PageMedia> */
    public function pageMediaFor(FrozenRecord $record, string $locale): array
    {
        $result = [];
        foreach ($this->imageMediaIds($record) as $mediaId) {
            $result[] = $this->pageMedia($this->media($mediaId), $locale);
        }
        return $result;
    }

    /** @return list<string> */
    public function imageMediaIds(FrozenRecord $record): array
    {
        return array_values(array_filter($this->mediaIds($record), fn(string $id): bool => str_starts_with($this->media($id)->mime, 'image/')));
    }

    /** @return list<string> */
    public function mediaIds(FrozenRecord $record): array
    {
        $ids = [];
        $videos = [];
        $type = $this->profile->type($record->typeId);
        foreach ($type->fields as $field) {
            if ($field->type === 'video_reference' && !$field->localized) {
                $selection = \Utsuri\PortableCms\Conventional\Content\VideoReference::from($record->body[$field->id] ?? null);
                if ($selection !== null) {
                    $videos = array_values(array_unique([...$videos, $selection['mediaId'], $selection['posterId']]));
                }
            }
            if ($field->type === 'composition' && !$field->localized) {
                $ids = array_values(array_unique([...$ids, ...\Utsuri\PortableCms\Conventional\Content\Composition::from($record->body[$field->id] ?? null, $field, $type, $this->snapshot->designRegistries)->mediaIds()]));
            }
        }
        foreach (['gallery', 'portrait'] as $field) {
            $value = $record->body[$field] ?? null;
            if (is_array($value) && array_is_list($value)) {
                return array_values(array_unique([...$ids, ...array_values(array_filter($value, static fn(mixed $id): bool => is_string($id))), ...$videos]));
            }
        }
        return array_values(array_unique([...$ids, ...$videos]));
    }

    public function blocks(FrozenRecord $record, string $locale, string $path): \Utsuri\PortableCms\Conventional\View\TrustedHtml
    {
        $field = array_column($this->profile->type($record->typeId)->fields, null, 'id')['blocks'];
        $html = '';
        foreach (\Utsuri\PortableCms\Conventional\Content\Blocks::items($field, $record->body['blocks'] ?? null, $this->snapshot->locales, true) as $entry) {
            if ($entry['locale'] !== $locale) {
                continue;
            }
            $values = $entry['item']['values'];
            $escape = \Utsuri\PortableCms\Conventional\Http\Html::text(...);
            $mark = fn(string $child): string => $this->marks($record)->itemAttribute($field->id, $entry['item']['_itemId'], $child);
            $html .= match ($entry['item']['typeId']) {
                'hero' => '<section class="studio-copy"' . $mark('heading') . '><h2' . $mark('heading') . '>' . $escape($values['heading']) . '</h2><p' . $mark('intro') . '>' . $escape($values['intro']) . '</p></section>',
                'quote' => '<figure class="studio-copy"' . $mark('quote') . '><blockquote><p' . $mark('quote') . '>' . $escape($values['quote']) . '</p></blockquote><figcaption' . $mark('attribution') . '>' . $escape($values['attribution']) . '</figcaption></figure>',
                'call-to-action' => '<p class="studio-copy"' . $mark('target') . '><a' . $mark('label') . ' href="' . \Utsuri\PortableCms\Conventional\Compiler\ArtifactUrl::escaped($this->route($this->publishedRecord($values['target']) ?? throw new \InvalidArgumentException('Block target is not public'), $locale), $path) . '">' . $escape($values['label']) . '</a></p>',
                default => throw new \InvalidArgumentException('Reference block type has no renderer'),
            };
        }
        return \Utsuri\PortableCms\Conventional\View\TrustedHtml::fromEngineMarkup($html);
    }

    public function blockText(FrozenRecord $record, string $locale): string
    {
        return \Utsuri\PortableCms\Conventional\Content\Blocks::text(array_column($this->profile->type($record->typeId)->fields, null, 'id')['blocks'], $record->body['blocks'] ?? null, $this->snapshot->locales, $locale);
    }

    public function composition(FrozenRecord $record, string $locale, ?string $artifactRoute = null): ?\Utsuri\PortableCms\Conventional\View\CompositionView
    {
        $type = $this->profile->type($record->typeId);
        foreach ($type->fields as $field) {
            if ($field->type !== 'composition' || $field->localized || !is_array($record->body[$field->id] ?? null) || ($record->body[$field->id]['rows'] ?? []) === []) {
                continue;
            }
            $layout = \Utsuri\PortableCms\Conventional\Content\Composition::from($record->body[$field->id], $field, $type, $this->snapshot->designRegistries);
            return \Utsuri\PortableCms\Conventional\View\CompositionView::render(
                $layout,
                $this->snapshot->designRegistries,
                '/records/' . $record->typeId . '/' . $record->id . ':' . $field->id,
                function (array $source) use ($record, $type, $locale, $artifactRoute): string {
                    return \Utsuri\PortableCms\Conventional\View\CompositionAssets::render(
                        $source,
                        $type,
                        $record->body,
                        $locale,
                        function (string $id) use ($locale): \Utsuri\PortableCms\Conventional\View\CompositionMedia {
                            $media = $this->media($id);
                            $view = $this->pageMedia($media, $locale);
                            return new \Utsuri\PortableCms\Conventional\View\CompositionMedia($view->src, $view->width, $view->height, $view->alternativeText, $media->mime, $view->caption, $view->credit, $view->focalPoint);
                        },
                        $artifactRoute ?? $this->route($record, $locale),
                        function (\Utsuri\PortableCms\Conventional\Content\RecordReference $link) use ($locale): ?string {
                            $target = $this->publishedRecord($link->recordId);
                            return $target === null || $target->typeId !== $link->typeId ? null : $this->route($target, $locale);
                        },
                        $this->snapshot->defaultLocale,
                    );
                },
            );
        }
        return null;
    }

    public function interface(string $locale): InterfaceCopy
    {
        $text = fn(string $key): string => $this->wording('interface.' . $key, $locale);
        return new InterfaceCopy($text('skipToContent'), $text('primaryNavigation'), $text('searchLabel'), $text('searchPlaceholder'), $text('resultSingular'), $text('resultPlural'), $text('compositionLabel'));
    }

    /** The site wording for a key in a language, as editors wrote it. */
    public function wording(string $key, string $locale): string
    {
        return $this->snapshot->wording->text($key, $locale);
    }

    public function defaultDescription(string $locale): string
    {
        return $this->localized($this->published->singleton('site-settings', false), 'defaultSearchDescription', $locale, false);
    }

    public function pageSearchSharing(string $page, string $locale): \Utsuri\PortableCms\Conventional\Content\SearchSharing
    {
        $settings = $this->published->singleton('site-settings', false);
        foreach ($this->profile->type('site-settings')->fields as $field) {
            if (($field->settings['searchSharing']['pagePath'] ?? null) === '/' . $page . '/') {
                return \Utsuri\PortableCms\Conventional\Content\SearchSharing::from($settings->body[$field->id][$locale] ?? null, ($field->settings['searchSharing']['indexing'] ?? null) === 'excluded');
            }
        }
        return \Utsuri\PortableCms\Conventional\Content\SearchSharing::from(null);
    }

    /** @return array<string, string> Page titles and navigation links by page, and the project metadata labels. */
    public function labels(string $locale): array
    {
        $labels = [];
        foreach (['home', 'projects', 'articles', 'people', 'about', 'search', 'disciplines', 'sectors', 'settings'] as $page) {
            $labels[$page] = $this->wording('page.' . $page, $locale);
        }
        return [...$labels, 'year' => $this->wording('metadata.year', $locale), 'status' => $this->wording('metadata.status', $locale)];
    }

    private function pageMedia(FrozenMedia $media, string $locale): PageMedia
    {
        if ($media->width === null || $media->height === null) {
            throw new \InvalidArgumentException('Reference visual media requires dimensions');
        }
        $text = ($media->text ?? \Utsuri\PortableCms\Conventional\Media\MediaText::from())->localized($locale, $this->snapshot->defaultLocale);
        return new PageMedia(
            '/' . FilesystemMediaSource::publicPath($media),
            $media->width,
            $media->height,
            $text['alternativeText'],
            $text['caption'],
            $text['credit'],
            $media->focalPoint,
            $media,
        );
    }

    /** @param array<string, mixed> $node */
    private function nodeText(array $node): string
    {
        $text = is_string($node['text'] ?? null) ? $node['text'] : '';
        foreach ($node['children'] ?? [] as $child) {
            if (is_array($child)) {
                $text .= $this->nodeText($child);
            }
        }
        return $text;
    }
}
