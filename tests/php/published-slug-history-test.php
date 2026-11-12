<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Compiler\ApachePublicDelivery;
use Utsuri\PortableCms\Conventional\Compiler\CompiledRedirect;
use Utsuri\PortableCms\Conventional\Compiler\DiscoveryRoute;
use Utsuri\PortableCms\Conventional\Compiler\PublicUrlContext;
use Utsuri\PortableCms\Conventional\Compiler\PublishedSlugHistory;
use Utsuri\PortableCms\Conventional\Compiler\RouteDiscovery;

/** @return array{releaseId: string, artifactSha256: string, activatedAt: string, verificationId: string} */
function publicationReceiptFixture(int $release = 1): array
{
    return ['releaseId' => str_pad(dechex($release), 32, '0', STR_PAD_LEFT), 'artifactSha256' => str_repeat('a', 64),
        'activatedAt' => '2026-10-04T12:00:00Z', 'verificationId' => str_repeat('b', 32)];
}

$tests['published slug history flattens successive moves independently per locale and ignores draft-only paths'] = static function (): void {
    $context = PublicUrlContext::from(['formatVersion' => 1, 'baseUrl' => 'https://studio.example/site']);
    $first = new RouteDiscovery($context, [new DiscoveryRoute('/en/first/', 'en', 'record:1'), new DiscoveryRoute('/ca/primer/', 'ca', 'record:1')]);
    $history = PublishedSlugHistory::afterVerifiedActivation($first, 'studio', publicationReceiptFixture());
    $second = new RouteDiscovery($context, [new DiscoveryRoute('/en/second/', 'en', 'record:1'), new DiscoveryRoute('/ca/primer/', 'ca', 'record:1')]);
    assertSameValue([['from' => '/en/first/', 'to' => '/en/second/', 'status' => 308]], array_map(static fn($redirect): array => $redirect->toArray(), $history->redirectsFor($second, 'studio')), 'draft build derives rules without changing published history');
    assertSameValue([], $history->document()['previousPaths'], 'building a draft is not a publication event');
    $history = PublishedSlugHistory::afterVerifiedActivation($second, 'studio', publicationReceiptFixture(2), $history);
    $third = new RouteDiscovery($context, [new DiscoveryRoute('/en/third/', 'en', 'record:1'), new DiscoveryRoute('/ca/tercer/', 'ca', 'record:1')]);
    $redirects = $history->redirectsFor($third, 'studio');
    assertSameValue(['/ca/primer/', '/en/first/', '/en/second/'], array_map(static fn($redirect): string => $redirect->from, $redirects), 'only previously activated paths enter history');
    assertSameValue(['/ca/tercer/', '/en/third/', '/en/third/'], array_map(static fn($redirect): string => $redirect->to, $redirects), 'all moves point directly to their own current language');
    $removed = new RouteDiscovery($context, [new DiscoveryRoute('/ca/primer/', 'ca', 'record:1')]);
    assertSameValue([], $history->redirectsFor($removed, 'studio'), 'removed English translation must not redirect to a guessed Catalan counterpart');
    $roundTrip = PublishedSlugHistory::from(json_decode(json_encode($history->document(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
    assertSameValue($history->document(), $roundTrip->document(), 'private verified history has a portable bounded representation');
    $schema = (string) file_get_contents(dirname(__DIR__, 2) . '/schemas/portable-cms-published-slugs-v1.schema.json');
    \Utsuri\PortableCms\Conventional\JsonSchemaValidator::validateJson(json_encode($history->document(), JSON_THROW_ON_ERROR), $schema);
    $canonical = json_decode($schema, true, flags: JSON_THROW_ON_ERROR);
    unset($canonical['$schema'], $canonical['$id'], $canonical['title']);
    $snapshotSchema = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/schemas/portable-cms-snapshot-v1.schema.json'), true, flags: JSON_THROW_ON_ERROR);
    assertSameValue($canonical, $snapshotSchema['properties']['publishedSlugHistory'], 'frozen history schema must match the standalone source contract');
    $exportSchema = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/schemas/portable-cms-export-v1.schema.json'), true, flags: JSON_THROW_ON_ERROR);
    assertSameValue($canonical, $exportSchema['properties']['publishedSlugHistory'], 'exported history schema must match the standalone source contract');
};

$tests['published history and delivery reject collisions chains unsafe context and invalid provenance'] = static function (): void {
    $context = PublicUrlContext::from(['formatVersion' => 1, 'baseUrl' => 'https://studio.example/site']);
    $first = new RouteDiscovery($context, [new DiscoveryRoute('/en/old.v1/', 'en', 'record:1')]);
    $history = PublishedSlugHistory::afterVerifiedActivation($first, 'studio', publicationReceiptFixture());
    $collision = new RouteDiscovery($context, [new DiscoveryRoute('/en/old.v1/', 'en', 'record:2'), new DiscoveryRoute('/en/new/', 'en', 'record:1')]);
    try {
        $history->redirectsFor($collision, 'studio');
        throw new RuntimeException('Previously published route was taken over');
    } catch (InvalidArgumentException) {
    }
    $next = new RouteDiscovery($context, [new DiscoveryRoute('/en/new/', 'en', 'record:1')]);
    $config = ApachePublicDelivery::configuration($context, $history->redirectsFor($next, 'studio'), $next->paths());
    assertTrueValue(str_contains($config, 'RedirectMatch 308 "^/site/en/old\\.v1/$" "https://studio.example/site/en/new/"'), 'exact escaped path includes validated deployment mount');
    assertTrueValue(!str_contains($config, '<IfModule') && !str_contains($config, 'unsafe-inline'), 'required modules cannot silently disappear and CSP stays strict');
    foreach ([new RouteDiscovery(PublicUrlContext::from(['formatVersion' => 1, 'baseUrl' => 'https://other.example']), [new DiscoveryRoute('/en/new/', 'en', 'record:1')])] as $other) {
        try {
            $history->redirectsFor($other, 'studio');
            throw new RuntimeException('Another publication domain accepted');
        } catch (InvalidArgumentException) {
        }
    }
    foreach ([[new CompiledRedirect('/en/old/', '/en/middle/'), new CompiledRedirect('/en/middle/', '/en/new/')], [new CompiledRedirect('/en/new/', '/en/old/')]] as $bad) {
        try {
            ApachePublicDelivery::configuration($context, $bad, ['/en/new/']);
            throw new RuntimeException('Unsafe redirect graph accepted');
        } catch (InvalidArgumentException) {
        }
    }
    $document = $history->document();
    $document['activatedAt'] = '2026-02-31T12:00:00Z';
    try {
        PublishedSlugHistory::from($document);
        throw new RuntimeException('Invalid verification timestamp accepted');
    } catch (InvalidArgumentException) {
    }
};
