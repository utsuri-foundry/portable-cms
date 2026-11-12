<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Compiler\CompiledRoute;
use Utsuri\PortableCms\Conventional\Compiler\DiscoveryRoute;
use Utsuri\PortableCms\Conventional\Compiler\PublicUrlContext;
use Utsuri\PortableCms\Conventional\Compiler\RouteDiscovery;
use Utsuri\PortableCms\Conventional\Preview\PreviewSnapshot;

$tests['public URL context rejects unsafe values and pins the deployment base'] = static function (): void {
    $context = PublicUrlContext::from(['formatVersion' => 1, 'baseUrl' => 'https://studio.example/portfolio/']);
    assertSameValue('https://studio.example/portfolio/en/project/', $context->route('/en/project/'), 'origin and base path survive');
    assertSameValue($context->checksum(), PublicUrlContext::from($context->document())->checksum(), 'context provenance is deterministic');
    foreach (['http://studio.example', 'https://user:pass@studio.example', 'https://studio.example/?host=evil', 'https://studio.example/#fragment', 'https://studio.example/../', 'https://studio.example/%2e%2e', 'https://studio.example//nested/', "https://studio.example\n", 'https://STUDIO.example'] as $url) {
        try {
            PublicUrlContext::from(['formatVersion' => 1, 'baseUrl' => $url]);
            throw new RuntimeException('Unsafe public URL accepted');
        } catch (InvalidArgumentException) {
        }
    }
    foreach (['/../', '/./'] as $route) {
        assertSameValue(false, CompiledRoute::validRoute($route), 'route identities cannot traverse');
    }
};

$tests['discovery emits self canonicals reciprocal real translations and honest sitemap policy'] = static function (): void {
    $context = PublicUrlContext::from(['formatVersion' => 1, 'baseUrl' => 'https://studio.example/site']);
    $graph = new RouteDiscovery($context, [
        new DiscoveryRoute('/en/', 'en', 'home', fallbackPath: '/'),
        new DiscoveryRoute('/ca/', 'ca', 'home', fallbackPath: '/'),
        new DiscoveryRoute('/', 'en', 'home', canonicalPath: '/en/', fallbackPath: '/'),
        new DiscoveryRoute('/en/project/', 'en', 'project'),
        new DiscoveryRoute('/ca/projecte/', 'ca', 'project'),
        new DiscoveryRoute('/en/internal/', 'en', 'internal', false),
    ]);
    assertTrueValue(str_contains($graph->head('/en/project/'), 'rel="canonical" href="https://studio.example/site/en/project/"'), 'canonical identifies the actual self route');
    foreach (['/en/project/', '/ca/projecte/'] as $path) {
        $head = $graph->head($path);
        assertTrueValue(str_contains($head, 'hreflang="en" href="https://studio.example/site/en/project/"'), 'English counterpart is reciprocal');
        assertTrueValue(str_contains($head, 'hreflang="ca" href="https://studio.example/site/ca/projecte/"'), 'translated slugs are not guessed');
        assertTrueValue(!str_contains($head, 'hreflang="es"') && !str_contains($head, 'x-default'), 'absent languages and fallbacks are not fabricated');
    }
    assertTrueValue(str_contains($graph->head('/en/'), 'hreflang="x-default" href="https://studio.example/site/"'), 'a real fallback home exists');
    assertTrueValue(str_contains($graph->head('/en/internal/'), 'content="noindex,follow"'), 'page-level noindex is original HTML');
    assertTrueValue(!str_contains($graph->head('/en/internal/'), 'hreflang'), 'noindex is not a language alternate');
    assertSameValue(4, substr_count($graph->sitemap(), '<url>'), 'sitemap excludes noindex and duplicate canonical root');
    assertTrueValue(!str_contains($graph->sitemap(), '<lastmod>'), 'rebuild time is not content change time');
    assertTrueValue(!str_contains($graph->robots(), 'Disallow') && str_contains($graph->robots(), 'Sitemap: https://studio.example/site/sitemap.xml'), 'crawlers can observe noindex');
    assertSameValue('', (new RouteDiscovery(null, [new DiscoveryRoute('/en/', 'en', 'home')]))->head('/en/'), 'legacy input cannot invent a production host');
};

$tests['discovery rejects missing targets alias chains collisions and route set drift'] = static function (): void {
    foreach ([
        [new DiscoveryRoute('/en/', 'en', 'home', fallbackPath: '/')],
        [new DiscoveryRoute('/en/', 'en', 'home'), new DiscoveryRoute('/en/', 'en', 'other')],
        [new DiscoveryRoute('/en/', 'en', 'home'), new DiscoveryRoute('/en/other/', 'en', 'home')],
        [new DiscoveryRoute('/', 'en', 'home', canonicalPath: '/en/'), new DiscoveryRoute('/en/', 'en', 'home', canonicalPath: '/')],
        [new DiscoveryRoute('/en/', 'en', 'home', fallbackPath: '/ca/'), new DiscoveryRoute('/ca/', 'ca', 'home')],
        [new DiscoveryRoute('/en/', 'en', 'home'), new DiscoveryRoute('/es/', 'es', 'home', false, canonicalPath: '/en/')],
    ] as $routes) {
        try {
            new RouteDiscovery(null, $routes);
            throw new RuntimeException('Invalid discovery graph accepted');
        } catch (InvalidArgumentException) {
        }
    }
    $graph = new RouteDiscovery(null, [new DiscoveryRoute('/en/', 'en', 'home')]);
    try {
        $graph->assertRoutes([new CompiledRoute('/', '<!doctype html><html></html>')]);
        throw new RuntimeException('Drifted route set accepted');
    } catch (InvalidArgumentException) {
    }
};

$tests['frozen public URL context changes snapshot identity but never rewrites older bytes'] = static function (): void {
    $fixture = profilePreviewFixture();
    try {
        $source = $fixture['snapshot'];
        $context = PublicUrlContext::from(['formatVersion' => 1, 'baseUrl' => 'https://studio.example']);
        $snapshot = new PreviewSnapshot($source->profileId, $source->profileVersion, $source->locales, $source->records, $source->createdAt, $source->designRegistries, $source->profileChecksum, $context);
        $frozen = $fixture['compiler']->freeze($snapshot);
        assertSameValue($context->baseUrl, $frozen->publicUrlContext?->baseUrl, 'public URL context is frozen input');
        $different = new PreviewSnapshot($source->profileId, $source->profileVersion, $source->locales, $source->records, $source->createdAt, $source->designRegistries, $source->profileChecksum, PublicUrlContext::from(['formatVersion' => 1, 'baseUrl' => 'https://elsewhere.example/site']));
        assertTrueValue($frozen->id !== $fixture['compiler']->freeze($different)->id, 'changed publication context requires another exact build');
        $html = $fixture['package']->siteBuilder()->build($frozen)->routes[0]->html;
        assertTrueValue(str_contains($html, 'https://studio.example/ca/'), 'static rendering uses frozen production URLs');
        assertTrueValue(!str_contains($html, 'https://elsewhere.example'), 'later settings cannot rewrite approved output');
    } finally {
        staticCompilerRemoveTree($fixture['root']);
    }
};
