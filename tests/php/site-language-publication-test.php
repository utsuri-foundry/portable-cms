<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Compiler\DiscoveryRoute;
use Utsuri\PortableCms\Conventional\Compiler\FrozenSnapshot;
use Utsuri\PortableCms\Conventional\Compiler\LanguageLayout;
use Utsuri\PortableCms\Conventional\Compiler\PublicUrlContext;
use Utsuri\PortableCms\Conventional\Compiler\PublishedSlugHistory;
use Utsuri\PortableCms\Conventional\Compiler\RouteDiscovery;
use Utsuri\PortableCms\Conventional\Compiler\SnapshotLanguages;
use Utsuri\PortableCms\Conventional\Preview\PreviewPreflightFailure;
use Utsuri\PortableCms\Conventional\Preview\PreviewRecord;
use Utsuri\PortableCms\Conventional\Preview\PreviewSnapshot;
use Utsuri\PortableCms\Conventional\Preview\ProfilePreviewCompiler;
use Utsuri\PortableCms\Profile\ReferenceStudio\ReferenceContent;
use Utsuri\PortableCms\Profile\ReferenceStudio\ReferenceStudioSiteBuilder;

/** @return array<string, mixed> The Reference Studio snapshot document, drawn in every language. */
function siteLanguageSnapshotDocument(): array
{
    $document = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/profiles/reference-studio/fixtures/snapshot.json'), true, 64, JSON_THROW_ON_ERROR);
    assertTrueValue(is_array($document), 'the reference snapshot is a document');
    return $document;
}

/**
 * @param array<string, mixed> $document
 * @return array<string, mixed>
 */
function siteLanguageRecord(array $document, string $id): array
{
    foreach ($document['records'] as $record) {
        if ($record['id'] === $id) {
            return $record;
        }
    }
    throw new RuntimeException('Reference record is missing');
}

/**
 * Every language key of every localized value in a record body, at any depth.
 * @return array<string, true>
 */
function siteLanguageKeys(mixed $value): array
{
    $keys = [];
    if (is_array($value)) {
        foreach ($value as $key => $item) {
            if (is_string($key) && preg_match('/\A[a-z]{2}\z/', $key) === 1) {
                $keys[$key] = true;
            }
            $keys += siteLanguageKeys($item);
        }
    }
    return $keys;
}

function siteLanguageRejected(callable $build, string $message): void
{
    try {
        $build();
    } catch (InvalidArgumentException|\Utsuri\PortableCms\Conventional\Publication\PublicationFailure) {
        return;
    }
    assertTrueValue(false, $message);
}

$tests['snapshot languages keep only the drawn languages and fill languages in preparation from the primary one'] = static function (): void {
    $package = staticCompilerFixture()['package'];
    $full = siteLanguageSnapshotDocument();
    $projected = (new SnapshotLanguages(['ca', 'es'], 'es'))->document($full, $package->manifest);
    assertSameValue(['ca', 'es'], $projected['profile']['locales'], 'only the public languages are drawn');
    assertSameValue('es', $projected['profile']['defaultLocale'], 'the primary language is the default');
    foreach ($projected['records'] as $record) {
        assertTrueValue(!isset(siteLanguageKeys($record['body'])['en']), 'no record value of a language that is off enters the snapshot: ' . $record['id']);
    }
    foreach ($projected['media'] as $media) {
        assertSameValue([], array_values(array_filter($media['text']['translations'] ?? [], static fn(array $entry): bool => $entry['locale'] === 'en')), 'media text drops languages that are off');
    }
    assertSameValue(['ca' => 'Arxiu de la boira', 'es' => 'Archivo de la niebla'], siteLanguageRecord($projected, '10000000000000000000000000000001')['body']['title'], 'public languages keep their own text');
    assertTrueValue(!isset($projected['preparationLocales']), 'a public build has no languages in preparation');

    $review = new SnapshotLanguages(['ca', 'es', 'en'], 'ca', ['en']);
    $draft = siteLanguageRecord($full, '10000000000000000000000000000002');
    $type = $package->manifest->type('project');
    assertTrueValue($type !== null, 'projects are installed');
    $filled = $review->record($type, $draft['body']);
    assertSameValue('Taller de llum', $filled['title']['en'], 'a missing value in a language in preparation shows the primary language');
    assertSameValue('taller-de-llum', $filled['slug']['en'], 'its address segment too, so the page can be drawn');
    assertSameValue('Taller de luz', $filled['title']['es'], 'public languages never borrow text');
    assertSameValue($filled, $review->record($type, $filled), 'projecting twice changes nothing');
    $untitled = $draft['body'];
    $untitled['title'] = ['ca' => '', 'es' => 'Taller de luz', 'en' => ''];
    assertSameValue('', $review->record($type, $untitled)['title']['en'], 'nothing is borrowed when the primary language is missing too');
    assertSameValue(['en'], $review->document($full, $package->manifest)['preparationLocales'], 'a review build names its languages in preparation');
    foreach ([
        static fn() => new SnapshotLanguages([], 'ca'),
        static fn() => new SnapshotLanguages(['ca', 'es'], 'en'),
        static fn() => new SnapshotLanguages(['ca', 'es'], 'ca', ['ca']),
        static fn() => new SnapshotLanguages(['ca', 'es'], 'ca', ['en']),
        static fn() => (new SnapshotLanguages(['es', 'ca'], 'ca'))->assertProfile($package->manifest),
        // Russian is outside the catalog's Latin script; Polish needs letters the profile's fonts lack.
        static fn() => (new SnapshotLanguages(['ca', 'pl'], 'ca'))->assertProfile($package->manifest),
        static fn() => (new SnapshotLanguages(['ca', 'ru'], 'ca'))->assertProfile($package->manifest),
        static fn() => (new SnapshotLanguages(['pt', 'ca'], 'ca'))->assertProfile($package->manifest),
    ] as $index => $invalid) {
        siteLanguageRejected($invalid, 'invalid snapshot languages are refused: ' . $index);
    }
    // A language the installation added inside the envelope follows the profile's own.
    (new SnapshotLanguages(['ca', 'pt'], 'ca'))->assertProfile($package->manifest);
};

$tests['a snapshot of the public languages publishes only those with the primary language at the root'] = static function (): void {
    $root = staticCompilerTempRoot();
    try {
        $fixture = staticCompilerFixture();
        $document = (new SnapshotLanguages(['ca', 'es'], 'es'))->document(siteLanguageSnapshotDocument(), $fixture['package']->manifest);
        $fixture['snapshot'] = FrozenSnapshot::fromDocument($document, $fixture['package']);
        $result = staticCompilerBuild($fixture, $root . '/artifact');
        $paths = array_column($result->files, 'path');
        assertSameValue([], array_values(array_filter($paths, static fn(string $path): bool => str_starts_with($path, 'en/'))), 'no page is published in a language that is off');
        assertTrueValue(in_array('search/es.json', $paths, true) && in_array('search/ca.json', $paths, true) && !in_array('search/en.json', $paths, true), 'search indexes follow the public languages');
        assertSameValue(['ca', 'es'], array_values(array_unique(array_column($result->manifest['routeIdentities'], 'locale'))), 'the route graph follows the public languages');
        assertTrueValue(!isset($result->manifest['preparationLocales']), 'a public build is releasable');
        $home = (string) file_get_contents($result->outputRoot . '/index.html');
        assertTrueValue(str_contains($home, '<html lang="es"'), 'the root shows the primary language');
        foreach ($result->files as $file) {
            if (str_starts_with($file['mime'], 'text/') || $file['mime'] === 'application/json' || str_starts_with($file['mime'], 'application/xml')) {
                $bytes = (string) file_get_contents($result->outputRoot . '/' . $file['path']);
                assertTrueValue(!str_contains($bytes, 'Archive of Mist') && !str_contains($bytes, 'hreflang="en"'), 'no text or link of a language that is off reaches ' . $file['path']);
            }
        }

        $full = siteLanguageSnapshotDocument();
        $unprojected = $full;
        $unprojected['profile']['locales'] = ['ca', 'es'];
        $reordered = $document;
        $reordered['profile']['locales'] = ['es', 'ca'];
        $foreignPrimary = $document;
        $foreignPrimary['profile']['defaultLocale'] = 'en';
        $primaryInPreparation = $full;
        $primaryInPreparation['preparationLocales'] = ['ca'];
        foreach (['values of a language that is off' => $unprojected, 'languages out of profile order' => $reordered, 'a primary language that is not drawn' => $foreignPrimary, 'a primary language in preparation' => $primaryInPreparation] as $reason => $invalid) {
            siteLanguageRejected(static fn() => FrozenSnapshot::fromDocument($invalid, $fixture['package']), 'a snapshot with ' . $reason . ' is refused');
        }
    } finally {
        staticCompilerRemoveTree($root);
    }
};

$tests['a review build with a language in preparation draws it from the primary language and is marked unreleasable'] = static function (): void {
    $root = staticCompilerTempRoot();
    try {
        $fixture = staticCompilerFixture();
        $full = siteLanguageSnapshotDocument();
        foreach ($full['records'] as $index => $record) {
            if ($record['id'] === '30000000000000000000000000000001') {
                $full['records'][$index]['body']['title']['en'] = '';
            }
        }
        $document = (new SnapshotLanguages(['ca', 'es', 'en'], 'ca', ['en']))->document($full, $fixture['package']->manifest);
        $fixture['snapshot'] = FrozenSnapshot::fromDocument($document, $fixture['package']);
        assertSameValue(['en'], $fixture['snapshot']->preparationLocales, 'the frozen snapshot keeps its languages in preparation');
        $result = staticCompilerBuild($fixture, $root . '/artifact');
        assertSameValue(['en'], $result->manifest['preparationLocales'] ?? null, 'the artifact says it carries languages in preparation');
        $article = (string) file_get_contents($result->outputRoot . '/en/articles/measuring-shade/index.html');
        assertTrueValue(str_contains($article, '<h1 class="cms-type-title">Mesurar l&apos;ombra</h1>'), 'an untranslated title shows the primary language');
    } finally {
        staticCompilerRemoveTree($root);
    }
};

$tests['published history sends addresses of a language turned off to the primary language and forgets them when it returns'] = static function (): void {
    $context = PublicUrlContext::from(['formatVersion' => 1, 'baseUrl' => 'https://studio.example/site']);
    $routes = static function (array $locales, string $primary, bool $extra) use ($context): RouteDiscovery {
        $all = [];
        foreach ($locales as $locale) {
            $all[] = new DiscoveryRoute('/' . $locale . '/', $locale, 'page:home');
            $all[] = new DiscoveryRoute('/' . $locale . '/x-' . $locale . '/', $locale, 'record:1');
        }
        if ($extra) {
            $all[] = new DiscoveryRoute('/en/only/', 'en', 'record:2');
        }
        $all[] = new DiscoveryRoute('/', $primary, 'page:home', canonicalPath: '/' . $primary . '/');
        return new RouteDiscovery($context, $all);
    };
    $history = PublishedSlugHistory::afterVerifiedActivation($routes(['ca', 'es', 'en'], 'ca', true), 'studio', publicationReceiptFixture());
    $off = $routes(['ca', 'es'], 'es', false);
    $redirects = array_map(static fn($redirect): array => [$redirect->from, $redirect->to], $history->redirectsFor($off, 'studio', 'es'));
    assertSameValue([['/en/', '/es/'], ['/en/only/', '/es/'], ['/en/x-en/', '/es/x-es/']], $redirects, 'pages go to their counterpart in the primary language, or to its home page');
    assertSameValue([], $history->redirectsFor($off, 'studio'), 'without a primary language nothing is guessed');
    $history = PublishedSlugHistory::afterVerifiedActivation($off, 'studio', publicationReceiptFixture(2), $history);
    assertSameValue(['/en/', '/en/only/', '/en/x-en/'], array_column($history->document()['previousPaths'], 'path'), 'the language that is off stays in history');
    assertSameValue(3, count($history->redirectsFor($off, 'studio', 'es')), 'later releases keep redirecting it');
    $on = $routes(['ca', 'es', 'en'], 'es', false);
    assertSameValue([], $history->redirectsFor($on, 'studio', 'es'), 'turning the language on again serves its pages at their addresses');
    $history = PublishedSlugHistory::afterVerifiedActivation($on, 'studio', publicationReceiptFixture(3), $history);
    assertSameValue(['/en/only/'], array_column($history->document()['previousPaths'], 'path'), 'returning pages leave the history');
};

$tests['Reference Studio can publish its primary language at the root and the others under their prefix'] = static function (): void {
    $root = staticCompilerTempRoot();
    try {
        $fixture = staticCompilerFixture();
        $fixture['builder'] = new ReferenceStudioSiteBuilder($fixture['package'], LanguageLayout::PrimaryAtRoot);
        $result = staticCompilerBuild($fixture, $root . '/artifact');
        $paths = array_column($result->files, 'path');
        assertSameValue([], array_values(array_filter($paths, static fn(string $path): bool => str_starts_with($path, 'ca/'))), 'the primary language has no prefix');
        foreach (['index.html', 'about/index.html', 'projects/arxiu-de-la-boira/index.html', 'en/index.html', 'en/projects/archive-of-mist/index.html', 'es/about/index.html', 'search/ca.json'] as $path) {
            assertTrueValue(in_array($path, $paths, true), 'the build publishes ' . $path);
        }
        $home = (string) file_get_contents($result->outputRoot . '/index.html');
        assertTrueValue(str_contains($home, '<html lang="ca"') && str_contains($home, 'hreflang="x-default" href="https://reference-studio.example/"'), 'the root is the primary home page and the default for other languages');
        $about = (string) file_get_contents($result->outputRoot . '/en/about/index.html');
        foreach (['ca' => '/about/', 'es' => '/es/about/', 'en' => '/en/about/', 'x-default' => '/about/'] as $language => $path) {
            assertTrueValue(str_contains($about, 'hreflang="' . $language . '" href="https://reference-studio.example' . $path . '"'), 'the English page names its ' . $language . ' alternate');
        }
        assertTrueValue(str_contains($about, 'href="../../en/projects/"') && str_contains((string) file_get_contents($result->outputRoot . '/about/index.html'), 'href="../projects/"'), 'navigation stays within each language');
        assertSameValue(['path' => '/', 'locale' => 'ca', 'translationGroup' => 'page:home'], array_values(array_filter($result->manifest['routeIdentities'], static fn(array $route): bool => $route['path'] === '/'))[0], 'the root is the primary home page in the published route identities');
    } finally {
        staticCompilerRemoveTree($root);
    }
};

$tests['published history redirects addresses moved by a change of layout or primary language'] = static function (): void {
    $fixture = staticCompilerFixture();
    $snapshot = static fn(string $primary): FrozenSnapshot => FrozenSnapshot::fromDocument((new SnapshotLanguages(['ca', 'es', 'en'], $primary))->document(siteLanguageSnapshotDocument(), $fixture['package']->manifest), $fixture['package']);
    $graph = static fn(string $primary, LanguageLayout $layout): RouteDiscovery => (new ReferenceContent($snapshot($primary), $fixture['package']->manifest, $layout))->discovery();
    $pairs = static fn(array $redirects): array => array_map(static fn($redirect): array => [$redirect->from, $redirect->to], $redirects);
    $prefixed = $graph('ca', LanguageLayout::Prefixed);
    $history = PublishedSlugHistory::afterVerifiedActivation($prefixed, 'reference-studio', publicationReceiptFixture());

    $atRoot = $graph('ca', LanguageLayout::PrimaryAtRoot);
    $moved = $pairs($history->redirectsFor($atRoot, 'reference-studio', 'ca'));
    $catalan = array_values(array_filter($prefixed->publicationRoutes(), static fn(array $route): bool => $route['locale'] === 'ca'));
    assertSameValue(count($catalan), count($moved), 'every page of the primary language moves to the root');
    foreach ($moved as [$from, $to]) {
        assertSameValue(substr($from, 3), $to, 'the primary language loses its prefix: ' . $from);
    }
    $history = PublishedSlugHistory::afterVerifiedActivation($atRoot, 'reference-studio', publicationReceiptFixture(2), $history);

    $spanish = $pairs($history->redirectsFor($graph('es', LanguageLayout::PrimaryAtRoot), 'reference-studio', 'es'));
    foreach ([['/es/about/', '/about/'], ['/es/projects/archivo-de-la-niebla/', '/projects/archivo-de-la-niebla/'], ['/projects/arxiu-de-la-boira/', '/ca/projects/arxiu-de-la-boira/']] as $redirect) {
        assertTrueValue(in_array($redirect, $spanish, true), 'a new primary language moves ' . $redirect[0]);
    }
    assertSameValue([], array_values(array_filter($spanish, static fn(array $redirect): bool => in_array($redirect[0], ['/', '/about/', '/projects/'], true))), 'addresses the same page keeps in the new primary language stay served');

    $back = $pairs($history->redirectsFor($graph('ca', LanguageLayout::Prefixed), 'reference-studio', 'ca'));
    assertTrueValue(in_array(['/about/', '/ca/about/'], $back, true) && in_array(['/projects/arxiu-de-la-boira/', '/ca/projects/arxiu-de-la-boira/'], $back, true), 'prefixing the primary language again redirects its root addresses');
    assertSameValue([], array_values(array_filter($back, static fn(array $redirect): bool => $redirect[0] === '/')), 'the root stays the home page');
};

$tests['preview checks require values only in public languages'] = static function (): void {
    $package = staticCompilerFixture()['package'];
    $full = siteLanguageSnapshotDocument();
    $records = [];
    foreach ($full['records'] as $record) {
        if ($record['id'] === '30000000000000000000000000000001') {
            $record['body']['title']['en'] = '';
        }
        $records[] = new PreviewRecord($record['id'], $record['typeId'], str_repeat('a', 32), 1, $record['body'], [], [], $record['workflowState']);
    }
    $compiler = new ProfilePreviewCompiler($package, sys_get_temp_dir(), dirname(__DIR__, 2) . '/schemas');
    $snapshot = static fn(array $locales, array $preparation): PreviewSnapshot => new PreviewSnapshot('reference-studio', $package->manifest->version, $locales, $records, new DateTimeImmutable('2026-10-15T09:00:00Z'), profileChecksum: $package->checksum, defaultLocale: 'ca', preparationLocales: $preparation);
    $compiler->checkRequired($snapshot(['ca', 'es', 'en'], ['en']));
    $compiler->checkRequired($snapshot(['ca', 'es'], []));
    try {
        $compiler->checkRequired($snapshot(['ca', 'es', 'en'], []));
        assertTrueValue(false, 'a public language must be complete');
    } catch (PreviewPreflightFailure $failure) {
        assertSameValue(1, count($failure->issues), 'the missing English title is the only issue');
    }
};

$tests['the holding page shows its message in the public languages with the primary one as its language'] = static function (): void {
    $root = staticCompilerTempRoot();
    try {
        $package = staticCompilerFixture()['package'];
        $settings = \Utsuri\PortableCms\Conventional\Publication\WebsitePublication::defaults($package->manifest->id, $package->manifest->locales);
        $snapshot = new PreviewSnapshot($package->manifest->id, $package->manifest->version, ['ca', 'es'], [], new DateTimeImmutable('2026-10-15T09:00:00Z'), profileChecksum: $package->checksum, publicUrlContext: PublicUrlContext::from(['formatVersion' => 1, 'baseUrl' => 'https://studio.example']), holdingPage: $settings->only(['ca', 'es']), defaultLocale: 'es');
        (new \Utsuri\PortableCms\Conventional\Compiler\HoldingPageCompiler(dirname(__DIR__, 2) . '/schemas', $root, $package))->compile($snapshot, $root . '/holding');
        $page = (string) file_get_contents($root . '/holding/index.html');
        assertTrueValue(str_contains($page, '<html lang="es"') && str_contains($page, 'Próximamente.') && str_contains($page, 'Properament.'), 'the public languages speak and the primary one is the document language');
        assertTrueValue(!str_contains($page, 'Coming soon.'), 'a language that is off says nothing');
        siteLanguageRejected(static fn() => $settings->only(['ca', 'fr']), 'settings cannot speak a language they have no message for');
    } finally {
        staticCompilerRemoveTree($root);
    }
};
