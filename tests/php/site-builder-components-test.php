<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Compiler\AssetSiteBuilder;
use Utsuri\PortableCms\Conventional\Compiler\CompiledRoute;
use Utsuri\PortableCms\Conventional\Compiler\DiscoveryRoute;
use Utsuri\PortableCms\Conventional\Compiler\DocumentHead;
use Utsuri\PortableCms\Conventional\Compiler\FrozenRecord;
use Utsuri\PortableCms\Conventional\Compiler\FrozenSnapshot;
use Utsuri\PortableCms\Conventional\Compiler\LanguageLayout;
use Utsuri\PortableCms\Conventional\Compiler\ProfileRoutes;
use Utsuri\PortableCms\Conventional\Compiler\ProfileSiteBuild;
use Utsuri\PortableCms\Conventional\Compiler\ProfileSiteBuilder;
use Utsuri\PortableCms\Conventional\Compiler\PublishedRecords;
use Utsuri\PortableCms\Conventional\Compiler\RouteDiscovery;
use Utsuri\PortableCms\Conventional\Compiler\SearchDocument;
use Utsuri\PortableCms\Conventional\Compiler\SearchIndexBuilder;
use Utsuri\PortableCms\Conventional\Compiler\SharingImage;
use Utsuri\PortableCms\Conventional\Compiler\SharingImages;
use Utsuri\PortableCms\Conventional\Compiler\SharingSelections;
use Utsuri\PortableCms\Conventional\Compiler\StructuredData;
use Utsuri\PortableCms\Conventional\Compiler\WebsiteIcon;
use Utsuri\PortableCms\Conventional\Content\ProfilePackage;
use Utsuri\PortableCms\Conventional\Content\SearchSharing;
use Utsuri\PortableCms\Conventional\I18n\InvalidInput;
use Utsuri\PortableCms\Conventional\JsonSchemaValidator;

/** @return array<string, mixed> */
function siteBuilderComponentDocument(): array
{
    $document = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/profiles/reference-studio/fixtures/snapshot.json'), true, flags: JSON_THROW_ON_ERROR);
    if (!is_array($document)) {
        throw new RuntimeException('Component fixture is unavailable');
    }
    return $document;
}

/** @param array<string, mixed> $document */
function siteBuilderComponentSnapshot(array $document): FrozenSnapshot
{
    return FrozenSnapshot::fromDocument($document, ProfilePackage::load(dirname(__DIR__, 2) . '/profiles/reference-studio'));
}

function siteBuilderComponentRejects(Closure $operation): void
{
    try {
        $operation();
        throw new RuntimeException('Invalid site builder input was accepted');
    } catch (InvalidArgumentException) {
    }
}

$tests['published records preserve snapshot selection and configurable deterministic ordering'] = static function (): void {
    $document = siteBuilderComponentDocument();
    $project = $document['records'][0];
    foreach (['10000000000000000000000000000005' => 10, '10000000000000000000000000000004' => 10, '10000000000000000000000000000006' => null] as $id => $order) {
        $extra = $project;
        $extra['id'] = $id;
        $extra['body']['sortOrder'] = $order;
        $extra['body']['position'] = $id === '10000000000000000000000000000006' ? 0 : 20;
        $document['records'][] = $extra;
    }
    $snapshot = siteBuilderComponentSnapshot($document);
    $records = new PublishedRecords($snapshot);
    $draft = '10000000000000000000000000000002';
    assertSameValue(null, $records->record($draft), 'saved drafts are unavailable in published builds');
    assertSameValue(['10000000000000000000000000000001', '10000000000000000000000000000004', '10000000000000000000000000000005', '10000000000000000000000000000006'], array_column($records->records('project'), 'id'), 'editor order ties use ID and absent order sorts last');
    assertSameValue('10000000000000000000000000000006', (new PublishedRecords($snapshot, 'position'))->records('project')[0]->id, 'another profile can map its own ordering field');
    assertSameValue('10000000000000000000000000000001', (new PublishedRecords($snapshot, null))->records('project')[0]->id, 'profiles can use ID ordering');
    $document['includeDrafts'] = true;
    assertSameValue('draft', (new PublishedRecords(siteBuilderComponentSnapshot($document)))->record($draft)?->workflowState, 'draft inclusion does not rewrite state');
    $document['records'][1]['workflowState'] = 'archived';
    assertSameValue(null, (new PublishedRecords(siteBuilderComponentSnapshot($document)))->record($draft), 'archives stay unavailable in draft-inclusive previews');
    assertSameValue([], $records->records('unknown'), 'absent types have no records');
};

$tests['settings helpers distinguish missing optional required and duplicate singletons'] = static function (): void {
    $document = siteBuilderComponentDocument();
    $settings = array_values(array_filter($document['records'], static fn(array $record): bool => $record['typeId'] === 'site-settings'))[0];
    $document['records'] = array_values(array_filter($document['records'], static fn(array $record): bool => $record['typeId'] !== 'site-settings'));
    $records = new PublishedRecords(siteBuilderComponentSnapshot($document));
    assertSameValue(null, $records->singleton('site-settings', false), 'optional settings can be absent');
    assertSameValue(null, SharingSelections::setting($records, 'site-settings', 'websiteIcon'), 'missing optional settings select no icon');
    siteBuilderComponentRejects(static fn() => SharingSelections::setting($records, 'site-settings', 'websiteIcon', true));
    $document['records'][] = $settings;
    $settings['id'] = str_repeat('f', 32);
    $document['records'][] = $settings;
    $duplicate = new PublishedRecords(siteBuilderComponentSnapshot($document));
    siteBuilderComponentRejects(static fn() => $duplicate->singleton('site-settings', false));
    $document['records'] = array_slice($document['records'], 0, -1);
    $last = count($document['records']) - 1;
    $document['records'][$last]['body']['websiteIcon'] = ['https://evil.example/icon.svg'];
    $bad = new PublishedRecords(siteBuilderComponentSnapshot($document));
    siteBuilderComponentRejects(static fn() => SharingSelections::setting($bad, 'site-settings', 'websiteIcon'));
};

$tests['profile routes derive manifest paths and explicit taxonomy segments with honest discovery'] = static function (): void {
    $fixture = staticCompilerFixture();
    $records = new PublishedRecords($fixture['snapshot']);
    $routes = new ProfileRoutes($fixture['package']->manifest, $records, ['discipline' => '/topics/'], static fn(FrozenRecord $record, string $locale): string => $record->typeId === 'discipline' ? \Utsuri\PortableCms\Conventional\TextNormalizer::slug($record->body['label'][$locale]) : $record->body['slug'][$locale]);
    $metadata = static fn(?FrozenRecord $record, string $group, string $locale): SearchSharing => SearchSharing::from(null, $group === 'site-settings');
    $graph = $routes->discovery($metadata, ['/search/' => 'search']);
    assertSameValue('/en/projects/archive-of-mist/', $routes->record($records->records('project')[0], 'en'), 'collection prefix comes from the manifest');
    assertSameValue('/en/about/', $routes->record($records->singleton('about'), 'en'), 'singleton path comes from the manifest');
    assertTrueValue(str_starts_with($routes->record($records->records('discipline')[0], 'en'), '/en/topics/'), 'taxonomy path and segment are explicit profile choices');
    assertSameValue('/ca/', $graph->canonicalPath('/'), 'root alias identifies the actual default locale');
    assertSameValue('ca', $graph->route('/')->locale, 'alias exposes its authored language');
    assertTrueValue(str_contains($graph->head('/en/settings/'), 'noindex,follow') && !str_contains($graph->sitemap(), '/en/settings/'), 'excluded utility stays absent from the sitemap');
    assertTrueValue(!in_array('/en/sectors/', $graph->paths(), true), 'undeclared taxonomy presentation is not invented');
    $graph->assertRoutes(array_map(static fn(string $path): CompiledRoute => new CompiledRoute($path, '<!doctype html><html></html>'), $graph->paths()));
    siteBuilderComponentRejects(static fn() => $routes->index('sector', 'en'));
    siteBuilderComponentRejects(static fn() => $routes->index('project', 'fr'));
    siteBuilderComponentRejects(static fn() => $routes->record($fixture['snapshot']->records[1], 'en'));
    siteBuilderComponentRejects(static fn() => $routes->discovery($metadata, ['/search/' => 'project']));
    assertSameValue(false, in_array('/', $routes->discovery($metadata, rootAlias: false)->paths(), true), 'profiles can omit the root alias');
};

$tests['profile routes can serve the primary language at the root and every other language under its prefix'] = static function (): void {
    $fixture = staticCompilerFixture();
    $records = new PublishedRecords($fixture['snapshot']);
    $segment = static fn(FrozenRecord $record, string $locale): string => $record->typeId === 'discipline' ? \Utsuri\PortableCms\Conventional\TextNormalizer::slug($record->body['label'][$locale]) : $record->body['slug'][$locale];
    $routes = new ProfileRoutes($fixture['package']->manifest, $records, ['discipline' => '/topics/'], $segment, LanguageLayout::PrimaryAtRoot);
    $metadata = static fn(?FrozenRecord $record, string $group, string $locale): SearchSharing => SearchSharing::from(null, $group === 'site-settings');
    $graph = $routes->discovery($metadata, ['/search/' => 'search']);
    $project = $records->records('project')[0];
    assertSameValue('/projects/arxiu-de-la-boira/', $routes->record($project, 'ca'), 'the primary language has no prefix');
    assertSameValue('/en/projects/archive-of-mist/', $routes->record($project, 'en'), 'other languages keep their prefix');
    assertSameValue(['/', '/en/', '/es/'], [$routes->path('/', 'ca'), $routes->path('/', 'en'), $routes->path('/', 'es')], 'each language has its home page');
    assertSameValue('/', $graph->canonicalPath('/'), 'the root is the primary home page itself, not an alias');
    assertSameValue('ca', $graph->route('/')->locale, 'the root is in the primary language');
    assertTrueValue(!in_array('/ca/', $graph->paths(), true) && !in_array('/ca/about/', $graph->paths(), true), 'no page is published twice');
    $head = $graph->head('/en/about/');
    foreach (['ca' => '/about/', 'es' => '/es/about/', 'en' => '/en/about/', 'x-default' => '/about/'] as $language => $path) {
        assertTrueValue(str_contains($head, '<link rel="alternate" hreflang="' . $language . '" href="' . $graph->context?->route($path) . '">'), 'the alternates name ' . $language);
    }
    assertTrueValue(str_contains($graph->head('/es/'), 'hreflang="x-default" href="' . $graph->context?->route('/') . '"'), 'x-default points to the root');
    assertTrueValue(!str_contains($graph->head('/en/settings/'), 'hreflang') && !str_contains($graph->sitemap(), '/settings/'), 'excluded pages stay out of alternates and the sitemap');
    assertTrueValue(str_contains($graph->sitemap(), '<loc>' . $graph->context?->route('/') . '</loc>') && !str_contains($graph->sitemap(), '/ca/'), 'the sitemap lists the root once');
    $graph->assertRoutes(array_map(static fn(string $path): CompiledRoute => new CompiledRoute($path, '<!doctype html><html></html>'), $graph->paths()));
    foreach (['/es/' => 'clash', '/en/news/' => 'news'] as $path => $group) {
        siteBuilderComponentRejects(static fn() => $routes->discovery($metadata, [$path => $group]));
    }
    siteBuilderComponentRejects(static fn() => (new ProfileRoutes($fixture['package']->manifest, $records, ['discipline' => '/es/'], $segment, LanguageLayout::PrimaryAtRoot))->index('discipline', 'ca'));
    $prefixed = (new ProfileRoutes($fixture['package']->manifest, $records, ['discipline' => '/topics/'], $segment))->discovery($metadata, ['/search/' => 'search']);
    assertSameValue('/ca/', $prefixed->canonicalPath('/'), 'the default layout keeps every language prefixed');
};

$tests['profile route helper rejects traversal encoded separators and missing slugs before rendering'] = static function (): void {
    $fixture = staticCompilerFixture();
    $metadata = static fn(?FrozenRecord $record, string $group, string $locale): SearchSharing => SearchSharing::from(null);
    foreach (['../', '/./', '//evil/', '/%2e%2e/', '/bad?query/', '/bad#fragment/', '/bad\\path/'] as $path) {
        siteBuilderComponentRejects(static fn() => (new ProfileRoutes($fixture['package']->manifest, new PublishedRecords($fixture['snapshot'])))->discovery($metadata, [$path => 'bad']));
        siteBuilderComponentRejects(static fn() => new ProfileRoutes($fixture['package']->manifest, new PublishedRecords($fixture['snapshot']), ['discipline' => $path]));
    }
    foreach (['', '.', '..', '../escape', '%2f', 'two/parts', '<script>', "line\nbreak"] as $slug) {
        $document = siteBuilderComponentDocument();
        $document['records'][0]['body']['slug']['en'] = $slug;
        $records = new PublishedRecords(siteBuilderComponentSnapshot($document));
        $routes = new ProfileRoutes($fixture['package']->manifest, $records);
        siteBuilderComponentRejects(static fn() => $routes->record($records->records('project')[0], 'en'));
    }
};

$tests['sharing helper resolves overrides covers defaults aliases and prepared images by frozen identity'] = static function (): void {
    $snapshot = staticCompilerFixture()['snapshot'];
    $graph = new RouteDiscovery($snapshot->publicUrlContext, [new DiscoveryRoute('/work/', 'en', 'record:work'), new DiscoveryRoute('/default/', 'es', 'page:default'), new DiscoveryRoute('/override/', 'ca', 'page:override')]);
    $first = $snapshot->media[0];
    $second = $snapshot->media[1];
    $selections = SharingSelections::resolve($snapshot, $graph, static fn(string $path, string $locale): SearchSharing => SearchSharing::from($path === '/override/' ? ['sharingImage' => [$second->id]] : null), $second->id, static fn(string $path, string $locale): ?string => $path === '/work/' ? $first->id : null);
    assertSameValue([$second->id, $second->id, $first->id], array_values($selections->byRoute), 'actual routes are sorted while selection precedence is explicit');
    assertSameValue([$first->id, $second->id], $selections->mediaIds(), 'preparation deduplicates and sorts media IDs');
    $image = new SharingImage($first->id, $first->checksum, str_repeat('a', 64), 3, 'fixture', $first->text, $snapshot->defaultLocale);
    assertSameValue($image, $selections->image('/work/', new SharingImages([$image])), 'prepared image follows the selected frozen media');
    siteBuilderComponentRejects(static fn() => $selections->image('/override/', new SharingImages([$image])));
    siteBuilderComponentRejects(static fn() => $selections->image('/absent/', new SharingImages()));
    $none = SharingSelections::resolve($snapshot, $graph, static fn(string $path, string $locale): SearchSharing => SearchSharing::from(null));
    assertSameValue(null, $none->image('/work/', new SharingImages()), 'unselected image is intentionally absent');
};

$tests['sharing helper rejects unsupported absent and undescribed frozen media for the actual route locale'] = static function (): void {
    foreach (['image/svg+xml', 'video/mp4', 'image/avif'] as $mime) {
        $document = siteBuilderComponentDocument();
        $unsupported = $document['media'][0];
        $unsupported['id'] = str_repeat('f', 32);
        $unsupported['mime'] = $mime;
        unset($unsupported['widths']);
        $document['media'][] = $unsupported;
        $snapshot = siteBuilderComponentSnapshot($document);
        $graph = new RouteDiscovery(null, [new DiscoveryRoute('/work/', 'en', 'work')]);
        siteBuilderComponentRejects(static fn() => SharingSelections::resolve($snapshot, $graph, static fn(string $path, string $locale): SearchSharing => SearchSharing::from(null), $unsupported['id']));
    }
    $snapshot = staticCompilerFixture()['snapshot'];
    $graph = new RouteDiscovery(null, [new DiscoveryRoute('/work/', 'en', 'work')]);
    siteBuilderComponentRejects(static fn() => SharingSelections::resolve($snapshot, $graph, static fn(string $path, string $locale): SearchSharing => SearchSharing::from(null), str_repeat('f', 32)));
    $document = siteBuilderComponentDocument();
    $document['media'][0]['text'] = ['formatVersion' => 1, 'decorative' => false, 'translations' => []];
    $snapshot = siteBuilderComponentSnapshot($document);
    try {
        SharingSelections::resolve($snapshot, $graph, static fn(string $path, string $locale): SearchSharing => SearchSharing::from(null), $snapshot->media[0]->id);
        throw new RuntimeException('Missing sharing alternative accepted');
    } catch (InvalidInput $error) {
        assertTrueValue(str_contains($error->getMessage(), '/work/'), 'diagnostic identifies the real page without guessing its locale prefix');
    }
    $graph = new RouteDiscovery(null, [new DiscoveryRoute('/work/', 'fr', 'work')]);
    siteBuilderComponentRejects(static fn() => SharingSelections::resolve($snapshot, $graph, static fn(string $path, string $locale): SearchSharing => SearchSharing::from(null)));
};

$tests['standard head composes escaped metadata discovery structured data and both icon paths'] = static function (): void {
    $snapshot = staticCompilerFixture()['snapshot'];
    $routes = new RouteDiscovery($snapshot->publicUrlContext, [new DiscoveryRoute('/en/', 'en', 'home'), new DiscoveryRoute('/ca/', 'ca', 'home')]);
    $metadata = SearchSharing::from(['description' => 'A <description>'])->resolve('A <title>', '', 'Studio');
    $structured = StructuredData::page($routes, '/en/', 'en', 'A <title>', 'A <description>', 'Studio', false, []);
    $source = $snapshot->media[0];
    $image = new SharingImage($source->id, $source->checksum, str_repeat('a', 64), 3, 'fixture', $source->text, $snapshot->defaultLocale);
    $head = DocumentHead::render('/en/', 'en', 'Studio', $metadata, $routes, $image, $structured, packagedSvgIcon: '/assets/icon.svg');
    assertTrueValue(str_contains($head, '<title>A &lt;title&gt; | Studio</title>') && !str_contains($head, '<description>'), 'metadata cannot become markup');
    assertTrueValue(str_contains($head, 'hreflang="ca"') && str_contains($head, 'property="og:image:alt"'), 'discovery and authored sharing alternatives are present');
    assertTrueValue(str_contains($head, 'http-equiv="Content-Security-Policy"') && str_contains($head, 'type="application/ld+json"'), 'structured data retains its exact CSP hash');
    assertTrueValue(str_contains($head, 'href="../assets/icon.svg"'), 'reviewed packaged icon is mount-independent');
    $files = [];
    foreach ([32, 180, 512] as $size) {
        $hash = hash('sha256', (string) $size);
        $files[] = ['size' => $size, 'path' => 'icons/' . $hash . '.png', 'sha256' => $hash, 'byteSize' => 3];
    }
    $icon = new WebsiteIcon($source->id, $source->checksum, 'fixture', $files);
    $head = DocumentHead::render('/en/', 'en', 'Studio', $metadata, $routes, icon: $icon, packagedSvgIcon: '/assets/icon.svg');
    assertTrueValue(str_contains($head, 'apple-touch-icon') && !str_contains($head, 'assets/icon.svg'), 'generated icons take precedence over packaged fallback');
    $legacy = DocumentHead::render('/en/', 'en', 'Studio', $metadata);
    assertTrueValue(!str_contains($legacy, 'canonical') && !str_contains($legacy, 'rel="icon"'), 'optional absent capabilities invent no metadata');
    siteBuilderComponentRejects(static fn() => DocumentHead::render('/../', 'en', 'Studio', $metadata));
    siteBuilderComponentRejects(static fn() => DocumentHead::render('/en/', 'ca', 'Studio', $metadata, $routes));
    siteBuilderComponentRejects(static fn() => DocumentHead::render('/en/', 'en', 'Studio', $metadata, packagedSvgIcon: '//evil.example/icon.svg'));
    siteBuilderComponentRejects(static fn() => DocumentHead::render('/en/', 'en', 'Studio', $metadata, sharingImage: $image));
};

$tests['search builder preserves unicode normalization maximum weights ties and insertion order'] = static function (): void {
    $index = new SearchIndexBuilder('en');
    $b = new SearchDocument(str_repeat('b', 32), 'project', '/en/second/', 'Second');
    $a = new SearchDocument(str_repeat('a', 32), 'article', '/en/first/', "First\nLine", str_repeat('d', 32));
    $index->add($b, [['text' => 'Café café 2026', 'weight' => 4], ['text' => 'CAFÉ', 'weight' => 16]]);
    $index->add($a, [['text' => 'Cafe 2026', 'weight' => 16]]);
    $built = $index->build();
    assertSameValue([$b->id, $a->id], array_column($built->document['documents'], 'id'), 'document order is profile-owned');
    assertSameValue("First\nLine", $built->document['documents'][1]['title'], 'valid authored title whitespace is preserved in result display');
    assertSameValue(['2026', 'cafe'], array_column($built->document['terms'], 'token'), 'accent folded tokens are sorted and numeric tokens stay strings');
    assertSameValue([['documentId' => $a->id, 'weight' => 16], ['documentId' => $b->id, 'weight' => 16]], $built->document['terms'][1]['postings'], 'repeated tokens take maximum weight, ties sort by ID');
    JsonSchemaValidator::validateJson(json_encode($built->document, JSON_THROW_ON_ERROR), (string) file_get_contents(dirname(__DIR__, 2) . '/schemas/portable-cms-search-v1.schema.json'));
    assertSameValue($built->document, $index->build()->document, 'building has no hidden mutable effects');
};

$tests['search builder rejects hostile routes duplicate documents and unbounded fields without partial additions'] = static function (): void {
    siteBuilderComponentRejects(static fn() => new SearchIndexBuilder('bad_locale'));
    foreach (['/../', '/%2f/', '//evil/', '/work/?q=1', '/work/#fragment'] as $route) {
        siteBuilderComponentRejects(static fn() => new SearchDocument(str_repeat('a', 32), 'project', $route, 'Title'));
    }
    siteBuilderComponentRejects(static fn() => new SearchDocument(str_repeat('a', 32), 'project', '/work/', '', 'bad-id'));
    $index = new SearchIndexBuilder('en');
    $document = new SearchDocument(str_repeat('a', 32), 'project', '/work/', 'Title');
    foreach ([0, 256] as $weight) {
        siteBuilderComponentRejects(static fn() => $index->add($document, [['text' => 'token', 'weight' => $weight]]));
    }
    siteBuilderComponentRejects(static fn() => $index->add($document, [['text' => str_repeat('a', 101), 'weight' => 1]]));
    siteBuilderComponentRejects(static fn() => $index->add($document, [['text' => str_repeat('a', 1024 * 1024 + 1), 'weight' => 1]]));
    assertSameValue([], $index->build()->document['documents'], 'a rejected document contributes no documents or postings');
    $index->add($document, [['text' => 'Title', 'weight' => 16]]);
    siteBuilderComponentRejects(static fn() => $index->add($document, []));
};

$tests['asset builder implements all existing entrypoints without leaking assets after interruption'] = static function (): void {
    $snapshot = staticCompilerFixture()['snapshot'];
    $selections = SharingSelections::resolve($snapshot, new RouteDiscovery(null, [new DiscoveryRoute('/en/', 'en', 'home')]), static fn(string $path, string $locale): SearchSharing => SearchSharing::from(null), $snapshot->media[0]->id);
    $builder = new class ($selections) extends AssetSiteBuilder {
        /** @var list<array{?SharingImages, ?WebsiteIcon}> */
        public array $calls = [];
        public bool $fail = false;
        public function __construct(private readonly SharingSelections $selections) {}
        protected function sharingSelections(FrozenSnapshot $snapshot): SharingSelections
        {
            return $this->selections;
        }
        protected function selectedWebsiteIcon(FrozenSnapshot $snapshot): ?string
        {
            return SharingSelections::setting(new PublishedRecords($snapshot), 'absent', 'icon');
        }
        protected function buildSite(FrozenSnapshot $snapshot, ?SharingImages $images, ?WebsiteIcon $icon): ProfileSiteBuild
        {
            $this->calls[] = [$images, $icon];
            if ($this->fail) {
                throw new RuntimeException('Injected renderer interruption');
            }
            return new ProfileSiteBuild([new CompiledRoute('/en/', '<!doctype html><html></html>')], [], [], []);
        }
    };
    $images = new SharingImages();
    $files = [];
    foreach ([32, 180, 512] as $size) {
        $hash = hash('sha256', (string) $size);
        $files[] = ['size' => $size, 'path' => 'icons/' . $hash . '.png', 'sha256' => $hash, 'byteSize' => 3];
    }
    $icon = new WebsiteIcon($snapshot->media[0]->id, $snapshot->media[0]->checksum, 'fixture', $files);
    assertSameValue([$snapshot->media[0]->id], $builder->sharingMediaIds($snapshot), 'selection delegates to validated deduplicated helper');
    assertSameValue(null, $builder->websiteIconMediaId($snapshot), 'icon selection can be absent');
    $builder->build($snapshot);
    $builder->buildWithSharing($snapshot, $images);
    $builder->fail = true;
    try {
        $builder->buildWithAssets($snapshot, $images, $icon);
        throw new LogicException('Injected renderer failure was ignored');
    } catch (RuntimeException) {
    }
    $builder->fail = false;
    $builder->build($snapshot);
    assertSameValue([[null, null], [$images, null], [$images, $icon], [null, null]], $builder->calls, 'assets enter explicit per-build arguments and do not survive failure');
    $document = siteBuilderComponentDocument();
    unset($document['publicUrlContext']);
    $builder->buildWithAssets(siteBuilderComponentSnapshot($document), $images, null);
    assertSameValue([null, null], $builder->calls[4], 'no public origin means no sharing image metadata');
    $legacy = new class implements ProfileSiteBuilder {
        public function build(FrozenSnapshot $snapshot): ProfileSiteBuild
        {
            return new ProfileSiteBuild([new CompiledRoute('/', '<!doctype html><html></html>')], [], [], []);
        }
    };
    assertSameValue('/', $legacy->build($snapshot)->routes[0]->route, 'the original builder interface remains independently usable');
};
