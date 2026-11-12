<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Compiler\FrozenSnapshot;
use Utsuri\PortableCms\Conventional\Compiler\SearchSharingHead;
use Utsuri\PortableCms\Conventional\Content\EditorialControls;
use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Content\SearchSharing;
use Utsuri\PortableCms\Conventional\Http\Request;
use Utsuri\PortableCms\Conventional\Identity\IdentityService;
use Utsuri\PortableCms\Conventional\JsonSchemaValidator;

$tests['search sharing resolves optional overrides with explicit fallback provenance'] = static function (): void {
    $defaults = SearchSharing::from(null)->resolve('A project', 'A page-specific summary.', 'An office');
    assertSameValue('A project | An office', $defaults->searchTitle, 'default title should include the site');
    assertSameValue($defaults->searchTitle, $defaults->sharingTitle, 'sharing title should inherit search');
    assertSameValue('content_summary', $defaults->sources['description'], 'provenance should explain the default');
    assertSameValue('An office', SearchSharing::from([])->resolve('An office', '', 'An office')->searchTitle, 'home should not repeat the site name');
    $custom = SearchSharing::from(['searchTitle' => '  A custom title  ', 'description' => "A\nsummary", 'sharingTitle' => 'Shared'])->resolve('Title', 'Summary', 'Site');
    assertSameValue('A custom title', $custom->searchTitle, 'override is the complete search title');
    assertSameValue('A summary', $custom->sharingDescription, 'plain-text whitespace should normalize');
    assertSameValue('Shared', $custom->sharingTitle, 'social title can be independent');
    assertSameValue('override', $custom->sources['searchTitle'], 'provenance distinguishes editorial input');
    assertSameValue('Title | Site', SearchSharing::from(['searchTitle' => " \n "])->resolve('Title', '', 'Site')->searchTitle, 'blank restores default');
};

$tests['search sharing schema and domain reject unknown markup fields and bound hostile text'] = static function (): void {
    $schema = (string) file_get_contents(dirname(__DIR__, 2) . '/schemas/portable-cms-search-sharing-v1.schema.json');
    foreach ([[], ['searchTitle' => '<Test> & "quotes"'], ['description' => "line one\nline two"]] as $group) {
        $document = ['formatVersion' => 1, 'overrides' => $group === [] ? new stdClass() : $group];
        JsonSchemaValidator::validateJson(json_encode($document, JSON_THROW_ON_ERROR), $schema);
        assertSameValue(SearchSharing::from($group)->document(), SearchSharing::from(SearchSharing::from($group)->document())->document(), 'plain overrides round trip');
    }
    foreach ([['canonicalUrl' => 'https://evil.test'], ['headHtml' => '<script>'], ['searchTitle' => str_repeat('x', 241)], ['description' => "bad\0text"], ['sharingTitle' => []], true, ['x']] as $invalid) {
        try {
            SearchSharing::from($invalid);
            throw new RuntimeException('Invalid metadata was accepted');
        } catch (InvalidArgumentException) {
        }
    }
    $head = SearchSharingHead::render(SearchSharing::from(['searchTitle' => '</title><script>alert(1)</script>', 'description' => '" onload="bad'])->resolve('Title', '', 'Site'), 'Site');
    assertTrueValue(!str_contains($head, '<script>'), 'title context cannot introduce a script');
    assertTrueValue(str_contains($head, '&lt;/title&gt;') && str_contains($head, '&quot; onload=&quot;'), 'both output contexts must escape hostile text');
    assertSameValue(1, substr_count($head, '<title>'), 'one authoritative title');
    assertTrueValue(!str_contains(SearchSharingHead::render(SearchSharing::from(null)->resolve('Title', '', 'Site'), 'Site'), 'name="description"'), 'absent copy should not fabricate a description');
};

$tests['native search sharing controls retain locales validation conflicts and optional defaults'] = static function (): void {
    $profile = ProfileManifest::load(dirname(__DIR__, 2) . '/profiles/reference-studio/profile.json');
    $state = contentTestApplication(profileOverride: $profile);
    $app = $state['app'];
    $cookies = authenticatedTestCookies($app);
    $settings = $app->handle(new Request('POST', '/records/site-settings', contentMutationHeaders(), ['_csrf' => $cookies[IdentityService::CSRF_COOKIE], '_editorial_end' => '1', 'siteName' => 'An office', 'searchTitleFormat' => 'site-title'], $cookies));
    assertSameValue(303, $settings->status, 'revisioned website formatting saves through the ordinary singleton form');
    $form = ['_csrf' => $cookies[IdentityService::CSRF_COOKIE], '_editorial_end' => '1', '_present_searchSharing' => '1'];
    foreach ($profile->locales as $locale) {
        $form['title__' . $locale] = 'A project ' . $locale;
        $form[EditorialControls::childName('searchSharing_' . $locale, 'searchTitle')] = 'Search ' . $locale;
        $form[EditorialControls::childName('searchSharing_' . $locale, 'description')] = 'Summary ' . $locale;
    }
    $created = $app->handle(new Request('POST', '/records/project', contentMutationHeaders(), $form, $cookies));
    assertSameValue(303, $created->status, 'native form should save overrides with the record');
    $path = $created->headers()['location'][0];
    $id = basename($path);
    $record = $state['contentRepository']->records[$id];
    assertSameValue('Search en', $record->body['searchSharing']['en']['searchTitle'], 'each locale must persist');
    $get = $app->handle(new Request('GET', $path, cookies: $cookies));
    assertTrueValue(str_contains($get->body, '<span>SEO and sharing') && str_contains($get->body, 'data-search-sharing-editor><summary><span>Search result and social card</span>'), 'search and sharing has its own collapsed section');
    assertTrueValue(str_contains($get->body, 'Custom text: CA, ES, EN') && str_contains($get->body, 'Visible to search engines'), 'the collapsed summary states overrides and visibility');
    $sharingTitle = EditorialControls::childName('searchSharing_en', 'sharingTitle');
    assertTrueValue(str_contains($get->body, 'name="' . $sharingTitle . '" placeholder="Search en"'), 'an empty override shows the automatic value it will use');
    assertTrueValue(str_contains($get->body, 'Automatic: effective search title.'), 'automatic values state their source');
    assertTrueValue(str_contains($get->body, 'class="admin-serp__title">Search en</span>') && str_contains($get->body, 'class="admin-social__text">') && str_contains($get->body, '>Illustrative</span>'), 'native feedback shows effective copy and provenance without ranking guarantees');
    $form['_revision'] = '1';
    $name = EditorialControls::childName('searchSharing_en', 'description');
    $invalid = $app->handle(new Request('POST', $path, contentMutationHeaders(), [...$form, $name => str_repeat('x', 501)], $cookies));
    assertSameValue(422, $invalid->status, 'server enforces safety bounds without a search-engine length claim');
    assertTrueValue(str_contains($invalid->body, 'Search ca'), 'other locales remain available on validation failure');
    assertSameValue(422, $app->handle(new Request('POST', $path, contentMutationHeaders(), [...$form, $name => "Invalid\0control"], $cookies))->status, 'unsafe text must fail at save rather than poisoning later previews');
    assertSameValue(1, $state['contentRepository']->records[$id]->revisionNumber, 'failure must not save');
    $saved = $app->handle(new Request('POST', $path, contentMutationHeaders(), [...$form, $name => 'Updated summary'], $cookies));
    assertSameValue(303, $saved->status, 'valid update is native POST redirect GET');
    $conflict = $app->handle(new Request('POST', $path, contentMutationHeaders(), [...$form, $name => 'My stale summary'], $cookies));
    assertSameValue(409, $conflict->status, 'metadata shares the record concurrency boundary');
    assertTrueValue(str_contains($conflict->body, 'My stale summary'), 'stale copy remains recoverable');
    assertSameValue('Updated summary', $state['contentRepository']->records[$id]->body['searchSharing']['en']['description'], 'conflict must not overwrite metadata');
};

$tests['search title formats are bounded and explicit overrides remain complete titles'] = static function (): void {
    assertSameValue('Site | Title', SearchSharing::from(null)->resolve('Title', '', 'Site', 'site-title')->searchTitle, 'site-wide prefix');
    assertSameValue('Title', SearchSharing::from(null)->resolve('Title', '', 'Site', 'title-only')->searchTitle, 'title-only formatting');
    assertSameValue('Complete override', SearchSharing::from(['searchTitle' => 'Complete override'])->resolve('Title', '', 'Site', 'site-title')->searchTitle, 'complete override does not acquire another site suffix');
    foreach ([['excludeFromSearch' => 'true'], ['excludeFromSearch' => null]] as $bad) {
        try {
            SearchSharing::from($bad);
            throw new RuntimeException('Non-boolean indexing accepted');
        } catch (InvalidArgumentException) {
        }
    }
    try {
        SearchSharing::from(null)->resolve('Title', '', 'Site', '<script>');
        throw new RuntimeException('Arbitrary title template accepted');
    } catch (InvalidArgumentException) {
    }
};

$tests['localized indexing stays public but leaves sitemap and reciprocal alternates'] = static function (): void {
    $fixture = staticCompilerFixture();
    $document = json_decode((string) file_get_contents($fixture['package']->root . '/fixtures/snapshot.json'), true, flags: JSON_THROW_ON_ERROR);
    foreach ($document['records'] as &$record) {
        if ($record['id'] === '10000000000000000000000000000001') {
            $record['body']['searchSharing'] = ['en' => ['excludeFromSearch' => true]];
        }
        if ($record['typeId'] === 'site-settings') {
            $record['body']['searchTitleFormat'] = 'site-title';
        }
        if ($record['typeId'] === 'home') {
            $record['body']['searchSharing'] = ['ca' => ['excludeFromSearch' => true]];
        }
    }
    unset($record);
    $site = $fixture['builder']->build(FrozenSnapshot::fromDocument($document, $fixture['package']));
    $english = array_values(array_filter($site->routes, static fn($route): bool => $route->route === '/en/projects/archive-of-mist/'))[0]->html;
    assertTrueValue(str_contains($english, '<title>Reference Studio | Archive of Mist</title>'), 'revisioned website policy affects default titles');
    assertTrueValue(str_contains($english, 'content="noindex,follow"') && str_contains($english, '>Archive of Mist</h1>'), 'noindex is not access control');
    assertTrueValue(!str_contains($site->discovery->sitemap(), '/en/projects/archive-of-mist/'), 'noindex leaves sitemap');
    assertTrueValue(!str_contains($site->discovery->head('/es/projects/archivo-de-la-niebla/'), 'hreflang="en"'), 'eligible translations cannot advertise noindex counterparts');
    assertTrueValue(!str_contains($site->discovery->head('/en/'), 'x-default'), 'a noindex root cannot be a fallback');
};

$tests['frozen website uses localized metadata overrides without changing visible content'] = static function (): void {
    $fixture = staticCompilerFixture();
    $document = json_decode((string) file_get_contents($fixture['package']->root . '/fixtures/snapshot.json'), true, flags: JSON_THROW_ON_ERROR);
    foreach ($document['records'] as &$record) {
        if ($record['id'] === '10000000000000000000000000000001') {
            $record['body']['searchSharing'] = ['en' => ['searchTitle' => 'Archive & landscape', 'description' => 'A distinct search summary.', 'sharingTitle' => 'Shared archive']];
        }
    }
    unset($record);
    $snapshot = FrozenSnapshot::fromDocument($document, $fixture['package']);
    $site = $fixture['builder']->build($snapshot);
    $english = array_values(array_filter($site->routes, static fn($route): bool => $route->route === '/en/projects/archive-of-mist/'))[0]->html;
    assertTrueValue(str_contains($english, '<title>Archive &amp; landscape</title>'), 'title must use frozen localized override');
    assertTrueValue(str_contains($english, 'property="og:title" content="Shared archive"'), 'sharing title must be generated in original HTML');
    assertTrueValue(str_contains($english, 'content="A distinct search summary."'), 'description must be present without JavaScript');
    assertTrueValue(str_contains($english, '>Archive of Mist</h1>'), 'SEO title does not alter the visible heading');
    $spanish = array_values(array_filter($site->routes, static fn($route): bool => $route->route === '/es/projects/archivo-de-la-niebla/'))[0]->html;
    assertTrueValue(!str_contains($spanish, 'Shared archive'), 'missing override must not borrow a different language');
};

$tests['every search and sharing source the resolver reports has an interface label'] = static function (): void {
    $source = (string) file_get_contents(dirname(__DIR__, 2) . '/engine/src/cms/conventional/content/search-sharing.php');
    preg_match_all("/'(override|content_[a-z_]+|website_default|none|search_[a-z_]+)'/", $source, $matches);
    $sources = array_values(array_unique($matches[1]));
    assertTrueValue(in_array('override', $sources, true) && in_array('content_cover', $sources, true), 'the resolver reports overrides and covers');
    foreach (\Utsuri\PortableCms\Conventional\I18n\InterfaceLanguage::SUPPORTED as $language) {
        $translator = \Utsuri\PortableCms\Conventional\I18n\Translator::load($language);
        foreach ($sources as $name) {
            assertTrueValue($translator->has('seo.source.' . $name), "{$language} labels the {$name} source");
        }
    }
};
