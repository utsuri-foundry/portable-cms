<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Compiler\DiscoveryRoute;
use Utsuri\PortableCms\Conventional\Compiler\PublicUrlContext;
use Utsuri\PortableCms\Conventional\Compiler\RouteDiscovery;
use Utsuri\PortableCms\Conventional\Compiler\StructuredData;
use Utsuri\PortableCms\Conventional\JsonSchemaValidator;

$tests['structured facts serialize safely in script context and pin their exact CSP hash'] = static function (): void {
    $routes = new RouteDiscovery(PublicUrlContext::from(['formatVersion' => 1, 'baseUrl' => 'https://studio.example/base']), [new DiscoveryRoute('/en/', 'en', 'home'), new DiscoveryRoute('/en/article/', 'en', 'article')]);
    $title = '</script><script>alert("x")</script> & café';
    $data = StructuredData::page($routes, '/en/article/', 'en', $title, 'A visible summary', null, true, [['name' => 'Home', 'path' => '/en/'], ['name' => $title, 'path' => '/en/article/']]);
    assertTrueValue($data !== null, 'visible Article and Breadcrumb facts must emit');
    assertTrueValue(!str_contains($data->json, '<') && !str_contains($data->json, '</script>'), 'JSON must escape script-closing text rather than rely on ordinary HTML escaping');
    assertSameValue($title, json_decode($data->json, true, flags: JSON_THROW_ON_ERROR)['@graph'][0]['headline'], 'text semantics survive escaping');
    assertSameValue(1, substr_count($data->html(), '<script '), 'hostile copy cannot introduce another script');
    assertSameValue('sha256-' . base64_encode(hash('sha256', $data->json, true)), $data->scriptHash(), 'CSP hashes exact inline bytes');
    assertTrueValue(str_contains($data->policy(), $data->scriptHash()) && !str_contains($data->policy(), 'unsafe-inline'), 'only the exact JSON block gets inline permission');
    JsonSchemaValidator::validateJson($data->json, (string) file_get_contents(dirname(__DIR__, 2) . '/schemas/portable-cms-structured-data-v1.schema.json'));
    foreach ([[['name' => 'Missing', 'path' => '/missing/']], [['name' => 'Home', 'path' => '/en/'], ['name' => 'Again', 'path' => '/en/']], [['name' => 'Article', 'path' => '/en/article/'], ['name' => 'Wrong last page', 'path' => '/en/']]] as $invalid) {
        try {
            StructuredData::page($routes, '/en/article/', 'en', 'Title', '', null, true, $invalid);
            throw new RuntimeException('Invalid breadcrumb accepted');
        } catch (InvalidArgumentException) {
        }
    }
};

$tests['reference structured output matches visible headings real navigation and organization'] = static function (): void {
    $fixture = staticCompilerFixture();
    $site = $fixture['builder']->build($fixture['snapshot']);
    $schema = (string) file_get_contents(dirname(__DIR__, 2) . '/schemas/portable-cms-structured-data-v1.schema.json');
    foreach ($site->routes as $route) {
        preg_match('/<script type="application\/ld\+json">(.*?)<\/script>/s', $route->html, $match);
        if (str_contains($route->route, '/settings/')) {
            assertSameValue([], $match, 'internal settings do not claim structured content');
            continue;
        }
        assertTrueValue(isset($match[1]), 'public page must carry reviewed visible facts');
        JsonSchemaValidator::validateJson($match[1], $schema);
        $data = json_decode($match[1], true, flags: JSON_THROW_ON_ERROR);
        $types = array_column($data['@graph'], '@type');
        if ($route->route === '/en/articles/measuring-shade/') {
            $article = array_values(array_filter($data['@graph'], static fn(array $item): bool => $item['@type'] === 'Article'))[0];
            assertTrueValue(str_contains($route->html, '>' . $article['headline'] . '</h1>'), 'structured headline must be the visible title, not an SEO override');
            assertTrueValue(!isset($article['datePublished']), 'do not manufacture dates from snapshot/build time');
        }
        if ($route->route === '/en/articles/') {
            assertTrueValue(!in_array('Article', $types, true), 'listing is not an article');
        }
        if ($route->route === '/en/') {
            assertTrueValue(in_array('Organization', $types, true), 'site identity is visible and meaningful on home');
        }
        assertTrueValue(str_contains($route->html, 'sha256-' . base64_encode(hash('sha256', $match[1], true))), 'route HTML pins its JSON block hash');
    }
};
