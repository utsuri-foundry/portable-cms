<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Compiler\ApachePublicDelivery;
use Utsuri\PortableCms\Conventional\Compiler\PublicUrlContext;
use Utsuri\PortableCms\Conventional\Content\ProfileAsset;
use Utsuri\PortableCms\Conventional\Content\SpeculationRules;
use Utsuri\PortableCms\Conventional\Http\SecurityHeaders;
use Utsuri\PortableCms\Conventional\Preview\FilesystemPreviewStore;
use Utsuri\PortableCms\Conventional\Preview\PreviewJob;
use Utsuri\PortableCms\Conventional\Preview\PreviewService;
use Utsuri\PortableCms\Conventional\Preview\PreviewStatus;

$tests['speculation rules reject hostile targets malformed documents unsupported features and overruns'] = static function (): void {
    $valid = ['prerender' => [['where' => ['and' => [['href_matches' => 'en/projects/*'], ['not' => ['selector_matches' => '.excluded']]]], 'eagerness' => 'moderate']], 'prefetch' => [['urls' => ['en/about/']]]];
    $compiled = json_decode(SpeculationRules::compile(json_encode($valid, JSON_THROW_ON_ERROR)), true, flags: JSON_THROW_ON_ERROR);
    assertSameValue('*', $compiled['prerender'][0]['where']['and'][0]['href_matches'], 'guard is outside all authored boolean predicates');
    assertSameValue('ruleset', $compiled['prefetch'][0]['relative_to'], 'lists resolve from the artifact root in every mount');
    $invalid = ['[]', '{}', 'null', '{', str_repeat(' ', 32769), '{"prerender":[]}', '{"script":[]}', '{"prerender":[{"urls":["en/"],"where":{}}]}', '{"prerender":[{"urls":["en/"],"eagerness":null}]}', '{"prerender":[{"urls":["en/"],"requires":[]}]}', '{"prerender":[{"where":{"selector_matches":"a[href*=secret]"}}]}'];
    foreach (['https://evil.example/', '//evil.example/', '/logout/', '../login/', 'en/../logout/', 'en/%2e%2e/', 'javascript:alert(1)', 'en/\\evil/', "en/\n", 'en/?secret=x', '*://*/*'] as $target) {
        foreach (['urls' => [$target], 'where' => ['href_matches' => $target]] as $key => $value) {
            $invalid[] = json_encode(['prerender' => [[$key => $value]]], JSON_THROW_ON_ERROR);
        }
    }
    $predicate = ['selector_matches' => '.next'];
    for ($depth = 0; $depth < 10; $depth++) {
        $predicate = ['not' => $predicate];
    }
    foreach ([['prerender' => array_fill(0, 33, ['urls' => ['en/']])], ['prefetch' => [['urls' => array_fill(0, 65, 'en/')]]], ['prerender' => [['where' => $predicate]]]] as $document) {
        $invalid[] = json_encode($document, JSON_THROW_ON_ERROR);
    }
    foreach ($invalid as $bytes) {
        try {
            SpeculationRules::compile($bytes);
            throw new RuntimeException('Invalid rules accepted: ' . substr($bytes, 0, 100));
        } catch (InvalidArgumentException $error) {
            assertTrueValue(str_contains($error->getMessage(), 'Profile speculation rules'), 'profile error explains the bounded contract');
        }
    }
};

$tests['speculation asset declarations and Apache delivery preserve strict script execution'] = static function (): void {
    $asset = new ProfileAsset('navigation.json', 'speculation-rules', SpeculationRules::MIME, 100, str_repeat('a', 64));
    assertSameValue(true, SpeculationRules::asset($asset), 'role explicitly opts in');
    foreach ([['assets/rules.json', 'speculation-rules', SpeculationRules::MIME, 100], ['artifact.json', 'speculation-rules', SpeculationRules::MIME, 100], ['rules.json', 'other', SpeculationRules::MIME, 100], ['rules.json', 'speculation-rules', 'application/json', 100], ['rules.json', 'speculation-rules', SpeculationRules::MIME, 32769]] as [$path, $role, $mime, $size]) {
        try {
            new ProfileAsset($path, $role, $mime, $size, str_repeat('a', 64));
            throw new RuntimeException('Invalid asset accepted');
        } catch (InvalidArgumentException) {
        }
    }
    $context = PublicUrlContext::from(['formatVersion' => 1, 'baseUrl' => 'https://studio.example/site']);
    $plain = ApachePublicDelivery::configuration($context, [], ['/']);
    assertTrueValue(!str_contains($plain, 'Speculation-Rules'), 'no declaration produces no header');
    $rules = ApachePublicDelivery::configuration($context, [], ['/'], $asset->path);
    assertTrueValue(str_contains($rules, 'Speculation-Rules \'"/site/navigation.json"\'') && str_contains($rules, 'ForceType ' . SpeculationRules::MIME), 'structured header and MIME are exact');
    assertTrueValue(str_contains($rules, "script-src 'self';") && !str_contains($rules, 'inline-speculation-rules') && !str_contains($rules, 'unsafe-inline'), 'script policy remains unchanged');
};

$tests['preview speculation delivery uses verified frozen rules in private artifact mounts'] = static function (): void {
    $fixture = profilePreviewFixture();
    try {
        $job = new PreviewJob(str_repeat('1', 32), str_repeat('2', 32), 1, 3, str_repeat('3', 64), new DateTimeImmutable('2026-10-03T12:15:00Z'));
        $store = new FilesystemPreviewStore($fixture['root'] . '/previews');
        $output = $store->workingPath($job);
        $build = $fixture['compiler']->compile($fixture['snapshot'], $output);
        $store->commit($job, $output, $build);
        $repository = new MemoryPreviewRepository();
        $now = $fixture['snapshot']->createdAt;
        $repository->previews[$job->previewId] = new PreviewStatus($job->previewId, 'ready', $job->id, null, $build->manifest(), $now, $now);
        $service = new PreviewService($repository, $fixture['package']->manifest, new IdentityTestClock($now), new IdentityTestRandom(), $store);
        foreach (['/previews/' . $job->previewId . '/artifact/', '/shared-previews/' . str_repeat('a', 32) . '/artifact/'] as $mount) {
            $page = $service->artifactResponse($job->previewId, 'en/projects/archive-of-mist/index.html', $mount);
            assertTrueValue($page !== null, 'ready page exists');
            $page = SecurityHeaders::apply($page, 'test', true);
            assertSameValue(['"' . $mount . 'navigation-rules.json"'], $page->headers()['speculation-rules'], 'header stays within its authorized mount');
            assertSameValue(['no-store'], $page->headers()['cache-control'], 'private cache policy is retained');
            assertSameValue(null, $service->artifactResponse($job->previewId, 'navigation-rules.json', $mount)?->headers()['speculation-rules'] ?? null, 'only HTML gets the header');
        }
        assertSameValue(SpeculationRules::MIME, $service->artifact($job->previewId, 'navigation-rules.json')?->mime, 'rules MIME is exact');
        assertSameValue(SpeculationRules::compile((string) file_get_contents($fixture['package']->root . '/navigation-rules.json')), $service->artifact($job->previewId, 'navigation-rules.json')?->contents, 'rules bytes are frozen and mount-independent');
        $old = $repository->previews[$job->previewId];
        $legacy = $build->manifest();
        $legacy['artifacts'] = array_values(array_filter($legacy['artifacts'], static fn(array $entry): bool => $entry['mime'] !== SpeculationRules::MIME));
        $repository->previews[$job->previewId] = new PreviewStatus($old->id, 'ready', $old->jobId, null, $legacy, $now, $now);
        assertSameValue(null, $service->artifactResponse($job->previewId, 'index.html', '/previews/' . $job->previewId . '/artifact/')?->headers()['speculation-rules'] ?? null, 'legacy previews never acquire live rules');
        $service->delete($job->previewId, str_repeat('b', 32));
        assertSameValue(null, $service->artifactResponse($job->previewId, 'index.html', '/previews/' . $job->previewId . '/artifact/'), 'deletion excludes rules and speculative pages');
    } finally {
        staticCompilerRemoveTree($fixture['root']);
    }
};
