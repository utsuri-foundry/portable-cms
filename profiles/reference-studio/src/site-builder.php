<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Profile\ReferenceStudio;

use Utsuri\PortableCms\Conventional\Compiler\CompiledRoute;
use Utsuri\PortableCms\Conventional\Compiler\CompiledSearchIndex;
use Utsuri\PortableCms\Conventional\Compiler\FrozenRecord;
use Utsuri\PortableCms\Conventional\Compiler\FrozenSnapshot;
use Utsuri\PortableCms\Conventional\Compiler\LanguageLayout;
use Utsuri\PortableCms\Conventional\Compiler\ProfileSiteBuild;
use Utsuri\PortableCms\Conventional\Content\ProfilePackage;

final class ReferenceStudioSiteBuilder extends \Utsuri\PortableCms\Conventional\Compiler\AssetSiteBuilder implements \Utsuri\PortableCms\Conventional\Preview\PreviewStartupRequirements
{
    /** @var array<string, \Closure> */
    private array $renderers = [];
    private ?\Utsuri\PortableCms\Conventional\Compiler\RouteDiscovery $discovery = null;
    private ?\Utsuri\PortableCms\Conventional\Compiler\SharingImages $sharingImages = null;
    private ?\Utsuri\PortableCms\Conventional\Compiler\WebsiteIcon $websiteIcon = null;
    private ?\Utsuri\PortableCms\Conventional\Compiler\SharingSelections $selections = null;

    public function requiredSingletons(): array
    {
        return ['home', 'about'];
    }

    /** Reference Studio publishes every language under its prefix; the root layout is built for tests and audits. */
    public function __construct(private readonly ProfilePackage $package, private readonly LanguageLayout $layout = LanguageLayout::Prefixed)
    {
        if ($package->manifest->id !== 'reference-studio') {
            throw new \InvalidArgumentException('Reference builder requires the Reference Studio package');
        }
        foreach ($package->manifest->renderers as $definition) {
            $renderer = require $package->rendererPath($definition->id);
            if (!$renderer instanceof \Closure) {
                throw new \InvalidArgumentException('Reference renderer entrypoint is invalid');
            }
            $this->renderers[$definition->id] = $renderer;
        }
    }

    protected function sharingSelections(FrozenSnapshot $snapshot): \Utsuri\PortableCms\Conventional\Compiler\SharingSelections
    {
        return (new ReferenceContent($snapshot, $this->package->manifest, $this->layout))->sharingSelections();
    }

    protected function selectedWebsiteIcon(FrozenSnapshot $snapshot): ?string
    {
        return null;
    }

    protected function buildSite(FrozenSnapshot $snapshot, ?\Utsuri\PortableCms\Conventional\Compiler\SharingImages $images, ?\Utsuri\PortableCms\Conventional\Compiler\WebsiteIcon $icon): ProfileSiteBuild
    {
        $this->websiteIcon = $icon;
        $content = new ReferenceContent($snapshot, $this->package->manifest, $this->layout);
        $this->discovery = $content->discovery();
        $this->sharingImages = $images;
        $this->selections = $content->sharingSelections();
        $identity = $snapshot->websiteIdentity ?? \Utsuri\PortableCms\Conventional\Content\WebsiteIdentity::defaults($snapshot->profileId);
        $siteName = $identity->name;
        $routes = [];
        $search = new ReferenceSearch($snapshot, $content);
        $searchIndexes = [];
        foreach ($snapshot->locales as $locale) {
            $localeRoutes = $this->localeRoutes($content, $locale, $siteName);
            array_push($routes, ...$localeRoutes);
            $searchIndexes[] = new CompiledSearchIndex($locale, $search->index($locale));
        }
        if ($content->path('/', $snapshot->defaultLocale) !== '/') {
            $routes[] = new CompiledRoute('/', $this->render('home', $this->homeView($content, $snapshot->defaultLocale, $siteName, '/')));
        }

        $mediaIds = [];
        foreach (['project', 'article', 'person', 'home'] as $typeId) {
            foreach ($content->records($typeId) as $record) {
                foreach ($content->mediaIds($record) as $mediaId) {
                    $mediaIds[$mediaId] = true;
                }
            }
        }
        $selectedMedia = array_keys($mediaIds);
        sort($selectedMedia, SORT_STRING);
        return new ProfileSiteBuild($routes, $searchIndexes, [], $selectedMedia, $this->discovery);
    }

    /** @return list<CompiledRoute> */
    private function localeRoutes(ReferenceContent $content, string $locale, string $siteName): array
    {
        $labels = $content->labels($locale);
        $routes = [];
        $homePath = $content->path('/', $locale);
        $routes[] = new CompiledRoute(
            $homePath,
            $this->render('home', $this->homeView($content, $locale, $siteName, $homePath)),
        );
        foreach ([
            'project' => ['path' => $content->path('/projects/', $locale), 'label' => 'projects'],
            'article' => ['path' => $content->path('/articles/', $locale), 'label' => 'articles'],
            'person' => ['path' => $content->path('/people/', $locale), 'label' => 'people'],
        ] as $typeId => $definition) {
            $records = $content->records($typeId);
            $routes[] = new CompiledRoute(
                $definition['path'],
                $this->render($typeId, new PageView(
                    $locale,
                    $siteName,
                    $labels[$definition['label']],
                    $definition['path'],
                    $this->navigation($content, $locale, $definition['label']),
                    searchSharing: $content->pageSearchSharing($definition['label'], $locale),
                    cards: array_map(
                        static fn(FrozenRecord $record): PageCard => $content->card($record, $locale),
                        $records,
                    ),
                    interface: $content->interface($locale),
                    titleFormat: $content->titleFormat(),
                    organizationName: $content->organizationName(),
                    websiteDescription: $content->defaultDescription($locale),
                )),
            );
            foreach ($records as $record) {
                $routes[] = new CompiledRoute(
                    $content->route($record, $locale),
                    $this->render($typeId, $this->recordView($content, $record, $locale, $siteName)),
                );
            }
        }

        $landing = $content->records('landing')[0] ?? null;
        $landingPath = $content->path('/landing/', $locale);
        $routes[] = new CompiledRoute($landingPath, $this->render('about', new PageView(
            $locale,
            $siteName,
            $landing === null ? $this->package->manifest->type('landing')->singularLabel : $content->localized($landing, 'title', $locale),
            $landingPath,
            $this->navigation($content, $locale, 'landing'),
            interface: $content->interface($locale),
            titleFormat: $content->titleFormat(),
            organizationName: $content->organizationName(),
            websiteDescription: $content->defaultDescription($locale),
            blocks: $landing === null ? null : $content->blocks($landing, $locale, $landingPath),
        )));
        $about = $content->one('about');
        $routes[] = new CompiledRoute($content->path('/about/', $locale), $this->render('about', new PageView(
            $locale,
            $siteName,
            $labels['about'],
            $content->path('/about/', $locale),
            $this->navigation($content, $locale, 'about'),
            $this->aboutCopy($content, $about, $locale),
            description: $content->summary($about, $locale),
            interface: $content->interface($locale),
            titleFormat: $content->titleFormat(),
            organizationName: $content->organizationName(),
            websiteDescription: $content->defaultDescription($locale),
            searchSharing: $content->searchSharing($about, $locale),
            fields: $content->marks($about),
            sources: ['copy' => 'body'],
        )));
        $routes[] = new CompiledRoute($content->path('/settings/', $locale), $this->render('site-settings', new PageView(
            $locale,
            $siteName,
            $labels['settings'],
            $content->path('/settings/', $locale),
            $this->navigation($content, $locale, 'settings'),
            [$siteName],
            searchSharing: $content->pageSearchSharing('settings', $locale),
            interface: $content->interface($locale),
            titleFormat: $content->titleFormat(),
            organizationName: $content->organizationName(),
            websiteDescription: $content->defaultDescription($locale),
        )));
        foreach (['discipline' => 'disciplines', 'sector' => 'sectors'] as $typeId => $labelId) {
            $indexPath = $content->path('/' . $labelId . '/', $locale);
            $terms = $content->records($typeId);
            $routes[] = new CompiledRoute($indexPath, $this->render($typeId, new PageView(
                $locale,
                $siteName,
                $labels[$labelId],
                $indexPath,
                $this->navigation($content, $locale, $labelId),
                searchSharing: $content->pageSearchSharing($labelId, $locale),
                cards: array_map(
                    static fn(FrozenRecord $record): PageCard => $content->card($record, $locale),
                    $terms,
                ),
                interface: $content->interface($locale),
                titleFormat: $content->titleFormat(),
                organizationName: $content->organizationName(),
                websiteDescription: $content->defaultDescription($locale),
            )));
            foreach ($terms as $term) {
                $routes[] = new CompiledRoute(
                    $content->route($term, $locale),
                    $this->render($typeId, $this->taxonomyView($content, $term, $locale, $siteName)),
                );
            }
        }
        $allCards = $this->allCards($content, $locale);
        $routes[] = new CompiledRoute($content->path('/search/', $locale), $this->render('search', new PageView(
            $locale,
            $siteName,
            $labels['search'],
            $content->path('/search/', $locale),
            $this->navigation($content, $locale, 'search'),
            searchSharing: $content->pageSearchSharing('search', $locale),
            cards: $allCards,
            interface: $content->interface($locale),
            titleFormat: $content->titleFormat(),
            organizationName: $content->organizationName(),
            websiteDescription: $content->defaultDescription($locale),
        )));
        return $routes;
    }

    private function homeView(ReferenceContent $content, string $locale, string $siteName, string $path): PageView
    {
        return \Utsuri\PortableCms\Conventional\Compiler\ProfileBuildFailure::atRoute($path, fn(): PageView => $this->buildHomeView($content, $locale, $siteName, $path));
    }

    private function buildHomeView(ReferenceContent $content, string $locale, string $siteName, string $path): PageView
    {
        $home = $content->one('home');
        return new PageView(
            $locale,
            $siteName,
            $siteName,
            $path,
            $this->navigation($content, $locale, 'home'),
            [$content->localized($home, 'introduction', $locale)],
            cards: array_map(static function (string $id) use ($content, $locale, $home): PageCard {
                $target = $content->publishedRecord($id);
                if (!$target instanceof FrozenRecord) {
                    throw new \Utsuri\PortableCms\Conventional\Compiler\ProfileBuildFailure(\Utsuri\PortableCms\Conventional\Compiler\ProfileBuildReason::Relationship, $home->id, $home->typeId, fieldId: 'featuredContent');
                }
                return $content->card($target, $locale);
            }, $home->targets('featuredContent')),
            description: $content->localized($home, 'introduction', $locale),
            interface: $content->interface($locale),
            titleFormat: $content->titleFormat(),
            organizationName: $content->organizationName(),
            websiteDescription: $content->defaultDescription($locale),
            searchSharing: $content->searchSharing($home, $locale),
            composition: $content->composition($home, $locale, $path),
            fields: $content->marks($home),
            sources: ['copy' => 'introduction', 'cards' => 'featuredContent', 'composition' => 'collage'],
        );
    }

    private function recordView(
        ReferenceContent $content,
        FrozenRecord $record,
        string $locale,
        string $siteName,
    ): PageView {
        return \Utsuri\PortableCms\Conventional\Compiler\ProfileBuildFailure::atRecord($record, fn(): PageView => $this->buildRecordView($content, $record, $locale, $siteName));
    }

    private function buildRecordView(
        ReferenceContent $content,
        FrozenRecord $record,
        string $locale,
        string $siteName,
    ): PageView {
        $metadata = [];
        $labels = $content->labels($locale);
        if (is_int($record->body['year'] ?? null)) {
            $metadata[] = new PageMetadata($labels['year'], (string) $record->body['year'], 'year');
        }
        if (is_string($record->body['status'] ?? null) && $record->body['status'] !== '') {
            $metadata[] = new PageMetadata($labels['status'], $content->wording('status.' . $record->body['status'], $locale), 'status');
        }
        $paragraphs = $content->richFragments($record, 'body', $locale);
        $composition = $content->composition($record, $locale);
        if ($record->typeId === 'person') {
            $role = $content->localized($record, 'role', $locale, false);
            $paragraphs = $role === '' ? [] : [$role];
        }
        $cards = [];
        foreach (['related', 'relatedProjects'] as $fieldId) {
            foreach ($record->targets($fieldId) as $targetId) {
                $target = $content->publishedRecord($targetId);
                if ($target instanceof FrozenRecord) {
                    $cards[] = $content->card($target, $locale);
                }
            }
        }
        return new PageView(
            $locale,
            $siteName,
            $content->title($record, $locale),
            $content->route($record, $locale),
            $this->navigation($content, $locale, $this->navigationId($record->typeId)),
            $composition === null ? $paragraphs : [],
            $metadata,
            $composition === null ? $content->pageMediaFor($record, $locale) : [],
            $cards,
            $content->summary($record, $locale),
            $content->interface($locale),
            $composition,
            $content->searchSharing($record, $locale),
            $content->titleFormat(),
            $content->organizationName(),
            $content->defaultDescription($locale),
            website: $record->typeId === 'person' && ($record->body['website'][$locale] ?? null) !== null
                ? \Utsuri\PortableCms\Conventional\Content\WebAddress::from($record->body['website'][$locale]) : null,
            fields: $content->marks($record),
            sources: [
                'title' => $content->titleField($record),
                'copy' => $record->typeId === 'person' ? 'role' : 'body',
                'cards' => $record->typeId === 'article' ? 'relatedProjects' : 'related',
                'composition' => 'layout',
                'website' => 'website',
            ],
            credits: $record->typeId === 'project' ? $content->credits($record, $locale) : [],
        );
    }

    private function taxonomyView(
        ReferenceContent $content,
        FrozenRecord $term,
        string $locale,
        string $siteName,
    ): PageView {
        return \Utsuri\PortableCms\Conventional\Compiler\ProfileBuildFailure::atRecord($term, fn(): PageView => $this->buildTaxonomyView($content, $term, $locale, $siteName));
    }

    private function buildTaxonomyView(
        ReferenceContent $content,
        FrozenRecord $term,
        string $locale,
        string $siteName,
    ): PageView {
        $cards = [];
        foreach (['project', 'article'] as $typeId) {
            foreach ($content->records($typeId) as $record) {
                foreach ($record->relationships as $relationship) {
                    if ($relationship->targetRecordId === $term->id) {
                        $cards[] = $content->card($record, $locale);
                        break;
                    }
                }
            }
        }
        return new PageView(
            $locale,
            $siteName,
            $content->title($term, $locale),
            $content->route($term, $locale),
            $this->navigation($content, $locale, $this->navigationId($term->typeId)),
            searchSharing: $content->searchSharing($term, $locale),
            cards: $cards,
            interface: $content->interface($locale),
            titleFormat: $content->titleFormat(),
            organizationName: $content->organizationName(),
            websiteDescription: $content->defaultDescription($locale),
            fields: $content->marks($term),
            sources: ['title' => $content->titleField($term)],
        );
    }

    /** @return list<string|\Utsuri\PortableCms\Conventional\Content\RichText> */
    private function aboutCopy(ReferenceContent $content, FrozenRecord $about, string $locale): array
    {
        $paragraphs = $content->richFragments($about, 'body', $locale);
        $contact = $about->body['contact'] ?? null;
        if (is_array($contact)) {
            $email = is_string($contact['email'] ?? null) ? trim($contact['email']) : '';
            $cities = is_array($contact['city'] ?? null) ? $contact['city'] : [];
            $city = is_string($cities[$locale] ?? null) ? trim($cities[$locale]) : '';
            $contactLine = implode(' · ', array_filter([$email, $city], static fn(string $part): bool => $part !== ''));
            if ($contactLine !== '') {
                $paragraphs[] = $contactLine;
            }
        }
        return $paragraphs;
    }

    /** @return list<PageCard> */
    private function allCards(ReferenceContent $content, string $locale): array
    {
        $cards = [];
        foreach (['project', 'article', 'person', 'landing'] as $typeId) {
            foreach ($content->records($typeId) as $record) {
                $cards[] = $content->card($record, $locale);
            }
        }
        return $cards;
    }

    /** @return list<NavigationLink> */
    private function navigation(ReferenceContent $content, string $locale, string $current): array
    {
        $labels = $content->labels($locale);
        return [
            new NavigationLink($labels['home'], $content->path('/', $locale), $current === 'home'),
            new NavigationLink($labels['projects'], $content->path('/projects/', $locale), $current === 'projects'),
            new NavigationLink($labels['articles'], $content->path('/articles/', $locale), $current === 'articles'),
            new NavigationLink($labels['people'], $content->path('/people/', $locale), $current === 'people'),
            new NavigationLink($labels['about'], $content->path('/about/', $locale), $current === 'about'),
            new NavigationLink($labels['search'], $content->path('/search/', $locale), $current === 'search'),
        ];
    }

    private function navigationId(string $typeId): string
    {
        return match ($typeId) {
            'project' => 'projects',
            'article' => 'articles',
            'person' => 'people',
            'discipline' => 'disciplines',
            'sector' => 'sectors',
            default => throw new \InvalidArgumentException('Reference navigation type is unsupported'),
        };
    }

    private function render(string $id, PageView $page): string
    {
        return \Utsuri\PortableCms\Conventional\Compiler\ProfileBuildFailure::atRoute($page->path, fn(): string => $this->renderPage($id, $page));
    }

    private function renderPage(string $id, PageView $page): string
    {
        $renderer = $this->renderers[$id] ?? null;
        if (!$renderer instanceof \Closure) {
            throw new \InvalidArgumentException('Reference renderer is unavailable');
        }
        $html = $renderer($page, $this->discovery, $this->sharingImages === null ? null : $this->selections?->image($page->path, $this->sharingImages), $this->websiteIcon);
        if (!is_string($html)) {
            throw new \RuntimeException('Reference renderer returned an invalid document');
        }
        return $html;
    }
}
