<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Content\LocaleCoverage;
use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Content\RecordSnapshot;
use Utsuri\PortableCms\Conventional\View\AdminChrome;
use Utsuri\PortableCms\Conventional\View\AdminShell;
use Utsuri\PortableCms\Conventional\View\Pages;
use Utsuri\PortableCms\Conventional\View\RecordListing;
use Utsuri\PortableCms\Conventional\View\ThemePreference;

function adminTestChrome(ThemePreference $theme = ThemePreference::System): AdminChrome
{
    return new AdminChrome(
        str_repeat('c', 32),
        'Nora <Editor>',
        $theme,
        '/records/project',
        ['project' => 2, 'article' => 1],
        new DateTimeImmutable('2026-10-03T12:00:00Z'),
    );
}

$tests['administration stylesheet is tokenized themed local and responsive'] = static function (): void {
    $path = dirname(__DIR__, 2) . '/engine/src/cms/conventional/client/admin.css';
    $css = file_get_contents($path);
    $size = filesize($path);
    assertTrueValue(is_string($css) && is_int($size), 'administration stylesheet should be readable');
    assertTrueValue($size > 5_000 && $size < 65_536, 'administration stylesheet should stay intentionally bounded');
    assertTrueValue(str_contains($css, ':focus-visible'), 'visible keyboard focus should be explicit');
    assertTrueValue(str_contains($css, '@media (max-width: 56rem)'), 'shell should adapt below wide layouts');
    assertTrueValue(str_contains($css, '@media (max-width: 42rem)'), 'tables should adapt for narrow viewports');
    assertTrueValue(str_contains($css, '@media (prefers-reduced-motion: reduce)'), 'motion preferences should be respected');
    assertTrueValue(str_contains($css, '@media (pointer: coarse)'), 'touch keeps generous targets');
    assertTrueValue(str_contains($css, ':root:not([data-theme="light"])') && str_contains($css, ':root[data-theme="dark"]'), 'system and explicit dark themes share one token set');
    assertTrueValue(str_contains($css, '@supports selector(:popover-open)'), 'phone navigation is a progressive popover');
    assertTrueValue(!str_contains($css, 'http://') && !str_contains($css, 'https://'), 'admin CSS must not fetch remotely');
    assertTrueValue(!str_contains($css, 'Inter,'), 'admin type uses installed system fonts only');
    // Outside the token blocks, components must not introduce literal colors.
    $components = (string) preg_replace('/:root[^{]*\{[^}]*\}|@media \(prefers-color-scheme: dark\) \{\s*:root[^{]*\{[^}]*\}\s*\}/s', '', $css);
    assertSameValue(0, preg_match_all('/#[0-9a-fA-F]{3,8}\b/', $components), 'components take every color from tokens');
};

$tests['administration shell groups navigation exposes current state and owns account controls'] = static function (): void {
    $profile = ProfileManifest::load(dirname(__DIR__, 2) . '/profiles/reference-studio/profile.json');
    $html = Pages::dashboard(str_repeat('a', 32), '<Editor>', $profile, [
        'status' => 'needs_attention',
        'attempts' => 3,
        'maxAttempts' => 3,
        'errorCode' => 'processor_failed',
    ], adminTestChrome());
    assertTrueValue(str_contains($html, 'href="/assets/admin.css?v=' . AdminShell::STYLESHEET_VERSION . '"'), 'every page loads the versioned stylesheet');
    assertTrueValue(str_contains($html, 'src="/assets/admin-shell.js?v=' . AdminShell::SCRIPT_VERSION . '"'), 'shell island is a same-origin module');
    assertTrueValue(str_contains($html, '<script src="/assets/admin-start.js?v=1"></script>'), 'the shell owns one parser-blocking same-origin bootstrap');
    assertTrueValue(strpos($html, '/assets/admin-start.js?v=1') < strpos($html, '/assets/admin.css?v='), 'the early marker runs before styles and body can paint');
    assertSameValue(0, preg_match('/<script(?![^>]*\bsrc=)[^>]*>/', $html), 'early enhancement needs no inline script or CSP exception');
    assertSameValue(1, substr_count($html, '<main '), 'administration pages should expose one main landmark');
    assertTrueValue(str_contains($html, 'href="#main-content"'), 'shell should provide a skip link');
    assertTrueValue(str_contains($html, 'aria-label="CMS navigation"'), 'global navigation should be labelled');
    foreach (['Content', 'Pages', 'Library', 'Publish', 'Website'] as $group) {
        assertTrueValue(str_contains($html, '>' . $group . '</h2>'), "{$group} navigation group should exist");
    }
    assertTrueValue(!str_contains($html, '>Workflow</h2>'), 'export and recovery no longer compete with editorial navigation');
    assertTrueValue(str_contains($html, 'href="/" aria-current="page"><span>Overview</span>'), 'current navigation is programmatically exposed');
    assertTrueValue(str_contains($html, '<span>Projects</span><span class="admin-navigation__count">2</span>'), 'collections show record counts');
    assertTrueValue(str_contains($html, 'popovertarget="admin-menu"') && str_contains($html, 'id="admin-menu" popover'), 'phone menu is a native popover');
    assertTrueValue(str_contains($html, '>Reference Studio</span>') && !str_contains($html, '>reference-studio</span>'), 'installation name replaces the raw identifier');
    assertSameValue(1, substr_count($html, 'action="/logout"'), 'sign out lives once in the shell');
    assertTrueValue(str_contains($html, 'action="/settings/theme"') && str_contains($html, 'name="_return" value="/records/project"'), 'appearance form returns to the current page');
    assertTrueValue(str_contains($html, 'Nora &lt;Editor&gt;') && str_contains($html, '&lt;Editor&gt;'), 'identities remain escaped');
    assertTrueValue(str_contains($html, 'role="alert"'), 'media failure should be announced as an alert');
    assertTrueValue(str_contains($html, 'action="/jobs/wake"'), 'recovery appears only when processing needs it');
    $quiet = Pages::dashboard(str_repeat('a', 32), 'Editor', $profile, ['status' => 'completed'], adminTestChrome());
    assertTrueValue(!str_contains($quiet, 'action="/jobs/wake"'), 'recovery stays hidden while processing is healthy');
};

$tests['theme preference renders only allowlisted values into engine documents'] = static function (): void {
    $page = Pages::error(404, str_repeat('a', 24));
    assertSameValue($page, AdminShell::withTheme($page, ThemePreference::System), 'system leaves the document untouched');
    assertTrueValue(str_starts_with(AdminShell::withTheme($page, ThemePreference::Dark), '<!doctype html><html lang="en" data-theme="dark">'), 'dark is applied server-side');
    assertSameValue('{"a":1}', AdminShell::withTheme('{"a":1}', ThemePreference::Dark), 'non-document bodies are never rewritten');
    assertSameValue(ThemePreference::System, ThemePreference::fromCookie('"><script>'), 'hostile cookies fall back to system');
    assertSameValue('/', AdminChrome::returnPath('//evil.example'), 'protocol-relative returns are rejected');
    assertSameValue('/', AdminChrome::returnPath('/a/../b'), 'traversal-shaped returns are rejected');
    assertSameValue('/media', AdminChrome::returnPath('/media'), 'ordinary routes are preserved');
};

$tests['administration record list filters natively and summarizes languages'] = static function (): void {
    $profile = ProfileManifest::load(dirname(__DIR__, 2) . '/profiles/reference-studio/profile.json');
    $type = $profile->type('project');
    assertTrueValue($type !== null, 'project fixture type should exist');
    $record = new RecordSnapshot(
        str_repeat('1', 32),
        'project',
        'draft',
        str_repeat('2', 32),
        4,
        ['title' => ['ca' => '<Pati>', 'es' => 'Patio', 'en' => ''], 'slug' => ['ca' => 'pati']],
        new DateTimeImmutable('2026-10-03T10:11:12Z'),
    );
    $html = Pages::recordList(str_repeat('a', 32), $profile, $type, [$record], adminTestChrome());
    assertTrueValue(str_contains($html, '<table class="admin-table">'), 'records should use a semantic table');
    foreach (['Title', 'Languages', 'Status', 'Updated'] as $heading) {
        assertTrueValue(str_contains($html, '<th scope="col">' . $heading . '</th>'), "{$heading} heading should exist");
        assertTrueValue(str_contains($html, 'data-column="' . strtolower($heading) . '"'), "{$heading} narrow layout hook should be language independent");
    }
    assertTrueValue(str_contains($html, '&lt;Pati&gt;'), 'record labels should remain escaped');
    assertTrueValue(str_contains($html, '>Draft</span>'), 'workflow state is human text');
    assertTrueValue(str_contains($html, '>/pati</span>'), 'the public slug identifies the record');
    assertTrueValue(str_contains($html, 'EN<span class="admin-visually-hidden"> missing Title, Slug</span>'), 'missing translations are announced');
    assertTrueValue(str_contains($html, '>Today, 10:11</time>'), 'update times are readable relative to the injected clock');
    assertTrueValue(str_contains($html, 'method="get" action="/records/project" role="search"'), 'search is a native GET form');
    assertTrueValue(str_contains($html, 'href="/records/project/new">New project</a>'), 'the primary action creates the collection item');
    assertTrueValue(str_contains($html, 'href="/records/project" aria-current="page"><span>Projects</span>'), 'the owning record type should be current');
    $filtered = Pages::recordList(str_repeat('a', 32), $profile, $type, [], adminTestChrome(), new RecordListing([], '<q>', 'published', ['draft' => 1, 'published' => 1]));
    assertTrueValue(str_contains($filtered, 'No matching projects') && str_contains($filtered, 'value="&lt;q&gt;"'), 'empty searches explain themselves and keep escaped input');
    assertTrueValue(str_contains($filtered, 'href="/records/project?q=%3Cq%3E&amp;status=published" aria-current="page"'), 'status filters keep the query');
};

$tests['administration editor leads with the record and keeps every locale without script'] = static function (): void {
    $profile = ProfileManifest::load(dirname(__DIR__, 2) . '/profiles/reference-studio/profile.json');
    $project = $profile->type('project');
    assertTrueValue($project !== null, 'Reference Studio project type should exist');
    $record = new RecordSnapshot(str_repeat('1', 32), 'project', 'published', str_repeat('2', 32), 3, ['title' => ['ca' => 'Arxiu', 'es' => 'Archivo', 'en' => '']], new DateTimeImmutable('2026-10-02T08:00:00Z'));
    $html = Pages::recordForm(str_repeat('b', 32), $profile, $project, $record->body, $record, chrome: adminTestChrome());
    assertTrueValue(str_contains($html, '<h1>Arxiu</h1>'), 'the header names the record being edited');
    assertTrueValue(str_contains($html, 'Saved <time datetime="2026-10-02T08:00:00+00:00" title="2026-10-02 08:00 Z">Yesterday, 08:00</time>'), 'save time is readable');
    $positions = [];
    foreach (['content' => 'Content', 'media' => 'Media', 'layout' => 'Layout', 'relationships' => 'Links', 'discovery' => 'SEO and sharing'] as $id => $group) {
        $positions[] = strpos($html, 'id="section-' . $id . '"');
        assertTrueValue(str_contains($html, 'data-section-link="' . $id . '"><span>' . $group . '</span>'), "{$group} appears in the outline");
    }
    assertSameValue($positions, array_values(array_filter($positions, 'is_int')), 'every editorial section is rendered');
    $sorted = $positions;
    sort($sorted);
    assertSameValue($sorted, $positions, 'sections follow the order editors work in: content first');
    $section = static function (string $id, ?string $source = null) use ($html): string {
        $source ??= $html;
        $start = strpos($source, 'id="section-' . $id . '"');
        $end = strpos($source, '</section>', (int) $start);
        return is_int($start) && is_int($end) ? substr($source, $start, $end - $start) : '';
    };
    $rail = substr($html, (int) strpos($html, 'class="admin-editor__rail"'));
    $properties = $section('details');
    assertTrueValue(!str_contains($rail, 'id="section-properties"') && str_contains($properties, '>Details<'), 'Details remain together in the main editor');
    foreach (['year', 'sortOrder', 'featured', 'publishedOn', 'status'] as $name) {
        assertTrueValue(str_contains($properties, 'name="' . $name . '"'), "{$name} stays in Details");
    }
    assertTrueValue(str_contains($html, 'id="section-details"'), 'Details keep a single section');
    assertSameValue(1, substr_count($section('content'), 'data-locale-tag'), 'a fully localized section carries one language badge');
    assertTrueValue(!str_contains($section('relationships'), 'data-section-toggle'), 'a section with a field required for publishing stays open');
    $article = $profile->type('article');
    assertTrueValue($article !== null, 'Reference Studio article type should exist');
    $articleHtml = Pages::recordForm(str_repeat('b', 32), $profile, $article, ['title' => ['ca' => 'Text']], null, chrome: adminTestChrome());
    $links = $section('relationships', $articleHtml);
    assertTrueValue(str_contains($links, 'data-section-toggle') && str_contains($links, 'Nothing added yet'), 'an empty optional section starts collapsed with a summary');
    $filled = Pages::recordForm(str_repeat('b', 32), $profile, $article, ['title' => ['ca' => 'Text'], 'sectors' => [str_repeat('5', 32)]], null, chrome: adminTestChrome());
    assertTrueValue(!str_contains($section('relationships', $filled), 'data-section-toggle'), 'an optional section with a value starts open');
    assertTrueValue(!str_contains($section('content'), 'data-section-toggle'), 'sections with required fields never collapse');
    assertTrueValue(str_contains($section('discovery'), 'name="slug__ca"'), 'the page address stays in SEO and sharing');
    assertTrueValue(str_contains($html, 'name="trailer__video"') && !str_contains($html, 'name="trailer"'), 'video uses native asset choices without a raw data input');
    foreach (['Català', 'Español', 'English'] as $locale) {
        assertTrueValue(str_contains($html, '>' . $locale . '</label>'), "{$locale} label should remain available");
    }
    assertTrueValue(str_contains($html, 'data-record-locale-switcher data-script-control="editor" hidden'), 'one record-level language switch stays native-hidden until the shell enables enhancement');
    assertTrueValue(!str_contains($html, 'data-locale-switcher'), 'per-field language tabs are gone');
    assertTrueValue(str_contains($html, 'data-locale-coverage="en" class="admin-facts__missing">Missing Title'), 'rail summarizes missing translations');
    assertTrueValue(str_contains($html, 'name="title__ca"') && str_contains($html, 'name="related[]"'), 'canonical field names must not change');
    assertTrueValue(str_contains($html, 'class="admin-editor__rail"') && str_contains($html, 'class="admin-button--primary" data-record-save data-script-fallback="editor">Save changes</button>'), 'save stays in the rail as the one native primary action without implying a status transition');
    assertSameValue(1, substr_count($html, 'admin-button--primary'), 'the editor has one primary action');
    assertTrueValue(str_contains($html, 'data-save-status'), 'save state should have a stable status boundary');
    assertTrueValue(str_contains($html, 'role="switch"'), 'booleans are native switches');
    assertTrueValue(!str_contains($html, 'admin-technical') && !str_contains($html, 'data-structured-field'), 'ordinary editors see no raw data');
    $upload = Pages::recordForm(str_repeat('b', 32), $profile, $project, $record->body, $record, mediaUploadEnabled: true, chrome: adminTestChrome());
    $media = strpos($upload, 'id="section-media"');
    $relationships = strpos($upload, 'id="section-relationships"');
    $panel = strpos($upload, 'data-media-upload ');
    assertTrueValue(is_int($media) && is_int($relationships) && is_int($panel) && $media < $panel && $panel < $relationships, 'upload sits inside the Media section');
    assertTrueValue(str_contains($upload, 'form="media-upload-form"') && str_contains($upload, '<form id="media-upload-form"'), 'upload controls submit their own form');
    $technical = Pages::recordForm(str_repeat('b', 32), $profile, $project, ['layout' => ['hostile' => '</pre><script>alert(1)</script>']], technicalData: true);
    assertTrueValue(!str_contains($technical, '<details class="admin-technical">'), 'implemented native controls need no technical fallback');
    assertSameValue(0, preg_match('/<(?:input|textarea)\b[^>]*\sname="layout"/', $technical), 'technical source must be read-only');
    assertTrueValue(!str_contains($technical, '<script>alert(1)</script>'), 'technical source must remain escaped');
};

$tests['locale coverage reports text missing in one language only'] = static function (): void {
    $profile = ProfileManifest::load(dirname(__DIR__, 2) . '/profiles/reference-studio/profile.json');
    $project = $profile->type('project');
    assertTrueValue($project !== null, 'project fixture type should exist');
    $rich = static fn(string $text): array => ['formatVersion' => 1, 'nodes' => [['type' => 'paragraph', 'children' => [['text' => $text, 'marks' => []]]]]];
    $missing = LocaleCoverage::missing($project, [
        'title' => ['ca' => 'Arxiu', 'es' => ' ', 'en' => 'Archive'],
        'body' => ['ca' => $rich('Text'), 'es' => $rich('   '), 'en' => $rich('paragraph')],
    ], $profile);
    assertSameValue(['Title', 'Body'], $missing['es'], 'blank text and blank rich text are missing');
    assertSameValue([], $missing['en'], 'block type names are not mistaken for text');
    assertSameValue([], LocaleCoverage::missing($project, [], $profile)['ca'], 'fields empty everywhere are not translation gaps');
};

$tests['administration focused pages share styling without application navigation'] = static function (): void {
    $profile = ProfileManifest::load(dirname(__DIR__, 2) . '/profiles/reference-studio/profile.json');
    $login = Pages::login(str_repeat('c', 32), '<admin>', 'credentials', $profile);
    assertTrueValue(str_contains($login, 'class="admin-auth"'), 'login should use the focused shell');
    assertTrueValue(str_contains($login, '&lt;admin&gt;'), 'login values should remain escaped');
    assertTrueValue(str_contains($login, '<strong>Reference Studio</strong>'), 'login names the installation');
    assertTrueValue(!str_contains($login, 'CMS navigation'), 'login should not disclose profile navigation');

    $error = Pages::error(403, '<reference>');
    assertTrueValue(str_contains($error, 'Request rejected'), 'safe errors should retain their stable heading');
    assertTrueValue(str_contains($error, '&lt;reference&gt;'), 'safe error references should remain escaped');
    assertTrueValue(str_contains($error, 'href="/">Go to Overview</a>'), 'errors always offer a way back');
    assertTrueValue(!str_contains($error, 'CMS navigation'), 'safe errors should remain focused');
};

$tests['unavailable profile capabilities stay explicit and leave navigation'] = static function (): void {
    $profile = ProfileManifest::load(dirname(__DIR__, 2) . '/profiles/reference-studio/profile.json');
    $chrome = new AdminChrome(str_repeat('d', 32), 'Editor', unavailable: ['previews']);
    $html = Pages::featureUnavailable(str_repeat('d', 32), $profile, 'Exact previews', 'No local compiler is installed.', 'previews', $chrome);
    assertTrueValue(str_contains($html, 'Capability unavailable'), 'unavailable capability should be named');
    assertTrueValue(str_contains($html, 'No local compiler is installed.'), 'safe remediation should be visible');
    assertTrueValue(!str_contains($html, 'href="/previews"'), 'unavailable destinations are omitted from navigation');
    $overview = Pages::dashboard(str_repeat('d', 32), 'Editor', $profile, null, $chrome);
    assertTrueValue(str_contains($overview, 'Not available'), 'Overview explains the missing capability');
};
