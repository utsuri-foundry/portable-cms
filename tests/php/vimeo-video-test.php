<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Compiler\ApachePublicDelivery;
use Utsuri\PortableCms\Conventional\Compiler\DiscoveryRoute;
use Utsuri\PortableCms\Conventional\Compiler\FrozenSnapshot;
use Utsuri\PortableCms\Conventional\Compiler\PublicUrlContext;
use Utsuri\PortableCms\Conventional\Compiler\RouteDiscovery;
use Utsuri\PortableCms\Conventional\Compiler\StructuredData;
use Utsuri\PortableCms\Conventional\Compiler\VimeoPlayer;
use Utsuri\PortableCms\Conventional\Compiler\VimeoPresentation;
use Utsuri\PortableCms\Conventional\Content\ContentService;
use Utsuri\PortableCms\Conventional\Content\EditorialForm;
use Utsuri\PortableCms\Conventional\Content\FieldDefinition;
use Utsuri\PortableCms\Conventional\Content\RecordSnapshot;
use Utsuri\PortableCms\Conventional\Content\ValidationFailure;
use Utsuri\PortableCms\Conventional\Content\VimeoVideo;
use Utsuri\PortableCms\Conventional\Export\NormalizedExport;
use Utsuri\PortableCms\Conventional\Http\Request;
use Utsuri\PortableCms\Conventional\Http\Response;
use Utsuri\PortableCms\Conventional\Http\SecurityHeaders;
use Utsuri\PortableCms\Conventional\I18n\InvalidInput;
use Utsuri\PortableCms\Conventional\I18n\Translator;
use Utsuri\PortableCms\Conventional\Import\ImportBody;
use Utsuri\PortableCms\Conventional\JsonSchemaValidator;
use Utsuri\PortableCms\Conventional\Media\LibraryMedia;
use Utsuri\PortableCms\Conventional\Media\MediaDerivative;
use Utsuri\PortableCms\Conventional\Preview\LivePreviewDocument;
use Utsuri\PortableCms\Conventional\Restore\RestorePlan;
use Utsuri\PortableCms\Conventional\Restore\RestoreRejected;
use Utsuri\PortableCms\Conventional\View\VimeoVideoControl;

require_once __DIR__ . '/vimeo-fixture.php';
require_once __DIR__ . '/restore-fixture.php';

$tests['vimeo references normalize public and unlisted addresses without interpreting arbitrary URLs'] = static function (): void {
    foreach (['https://vimeo.com/123456789' => null, 'HTTPS://VIMEO.COM/123456789' => null, 'https://vimeo.com/123456789/abcdef1234' => 'abcdef1234', 'https://player.vimeo.com/video/123456789?h=abcdef1234' => 'abcdef1234', 'https://player.vimeo.com/video/123456789' => null] as $address => $hash) {
        assertSameValue(['videoId' => '123456789', 'privacyHash' => $hash], VimeoVideo::address($address), 'the supported URL forms normalize identically');
    }
    $invalid = [
        '' => 'url_required', 'http://vimeo.com/123' => 'scheme', 'javascript:alert(1)' => 'scheme', '//vimeo.com/123' => 'scheme',
        'https://vimeo.com.evil.test/123' => 'host', 'https://evil.test/vimeo.com/123' => 'host', 'https://vimeo.com@evil.test/123' => 'host', 'https://user:pass@vimeo.com/123' => 'host', 'https://vimeo.com:443/123' => 'host', 'https://www.vimeo.com/123' => 'host',
        'https://vimeo.com/not-a-number' => 'id', 'https://vimeo.com/0' => 'id', 'https://vimeo.com/0123' => 'id', 'https://vimeo.com/' . str_repeat('1', 21) => 'id',
        'https://vimeo.com/123/short' => 'hash', 'https://vimeo.com/123/abc_def123' => 'hash', 'https://player.vimeo.com/video/123?h=' => 'hash',
        'https://player.vimeo.com/video/123?h=abcdef1234&autoplay=1' => 'address_invalid', 'https://vimeo.com/123?share=copy' => 'address_invalid', 'https://vimeo.com/123#fragment' => 'address_invalid', 'https://vimeo.com/%31' => 'address_invalid', "https://vimeo.com/123\n" => 'address_invalid', 'https://vimeo.com\\evil.test/123' => 'address_invalid',
    ];
    foreach ($invalid as $address => $reason) {
        try {
            VimeoVideo::address($address);
            throw new RuntimeException('Unsafe Vimeo address accepted: ' . $address);
        } catch (InvalidInput $error) {
            assertSameValue('vimeo.' . $reason, $error->text()->key, 'the editor receives a precise safe reason');
        }
    }
    foreach ([['formatVersion' => 1], [...vimeoValue(), 'url' => 'https://vimeo.com/123'], [...vimeoValue(), 'videoId' => 123], [...vimeoValue(), 'privacyHash' => 'bad'], [...vimeoValue(), 'poster' => '../private'], [...vimeoValue(), 'title' => ' '], [...vimeoValue(), 'title' => str_repeat('é', 201)], [...vimeoValue(), 'title' => "bad\nline"]] as $value) {
        try {
            VimeoVideo::from($value);
            throw new RuntimeException('Malformed canonical Vimeo value accepted');
        } catch (InvalidInput) {
        }
    }
    assertSameValue(vimeoValue(), VimeoVideo::from(vimeoValue())->document(), 'canonical bytes and private hash are retained');
    assertSameValue('https://player.vimeo.com/video/123456789?h=abcdef1234&dnt=1&autoplay=1', VimeoVideo::from(vimeoValue())->playerUrl(), 'privacy hash precedes playback parameters');
    assertSameValue('https://vimeo.com/123456789/abcdef1234', VimeoVideo::from(vimeoValue())->pageUrl(), 'fallback retains the unlisted hash');
};

$tests['vimeo profile and value schemas are additive and localized titles retain the ordinary field contract'] = static function (): void {
    $base = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/schemas/fixtures/profiles/inline-v2.accepted.json'), flags: JSON_THROW_ON_ERROR);
    foreach ([1, 2, 3] as $version) {
        $base->formatVersion = $version;
        $base->recordTypes[0]->fields[0]->type = 'vimeo_video';
        $base->recordTypes[0]->fields[0]->localized = true;
        $base->recordTypes[0]->fields[0]->settings = (object) [];
        assertSameValue('vimeo_video', fieldGroupsLoad($base)->types[0]->fields[0]->type, 'all profile versions accept the field');
    }
    $schema = (string) file_get_contents(dirname(__DIR__, 2) . '/schemas/portable-cms-vimeo-video-v1.schema.json');
    JsonSchemaValidator::validateJson(json_encode(vimeoValue(), JSON_THROW_ON_ERROR), $schema);
    JsonSchemaValidator::validateJson(json_encode(vimeoValue(null), JSON_THROW_ON_ERROR), $schema);
    $field = new FieldDefinition('film', 'Film', 'vimeo_video', true, false, false, []);
    $value = ['ca' => vimeoValue(), 'en' => [...vimeoValue(), 'title' => 'Evening film'], 'es' => null];
    assertSameValue($value, VimeoVideo::field($value, $field, ['ca', 'en', 'es'], false), 'titles are localized through the normal composite-value envelope');
    $required = new FieldDefinition('film', 'Film', 'vimeo_video', true, true, true, []);
    assertSameValue($value, VimeoVideo::field($value, $required, ['ca', 'en', 'es'], true, ['ca', 'en']), 'required public languages never require an entry in a language in preparation');
    VimeoVideo::assertFields([$required], ['film' => $value], ['ca', 'en', 'es'], static fn(string $id): string => 'image/webp');
    try {
        VimeoVideo::field($value, $required, ['ca', 'en', 'es'], true, ['ca', 'en', 'es']);
        throw new RuntimeException('A missing public language entry was accepted');
    } catch (InvalidInput) {
    }
    try {
        VimeoVideo::field([...$value, 'xx' => vimeoValue()], $field, ['ca', 'en', 'es'], false);
        throw new RuntimeException('Unknown value language accepted');
    } catch (InvalidInput) {
    }
    foreach (['en', 'ca', 'es', 'fr', 'de'] as $locale) {
        $t = Translator::load($locale);
        foreach (['url', 'title', 'poster', 'choose_poster', 'help', 'hash', 'scheme', 'value_invalid'] as $key) {
            assertTrueValue($t->text('vimeo.' . $key) !== '', 'every new administration message is translated');
        }
    }
};

$tests['vimeo native editor keeps invalid addresses visible and saves a prepared poster and title without a frame'] = static function (): void {
    $root = sys_get_temp_dir() . '/portable-vimeo-form-' . bin2hex(random_bytes(8));
    $media = editorialMediaFixture();
    try {
        $package = vimeoPackage($root);
        $profile = $package->manifest;
        $type = $profile->type('project');
        $field = array_values(array_filter($type->fields, static fn($field): bool => $field->id === 'film'))[0];
        $id = vimeoValue()['poster'];
        $media['repository']->items[$id] = new LibraryMedia($id, str_repeat('1', 32), 'Evening poster.webp', 'ready', 'image/webp', 100, 960, 540, 1, null, new MediaDerivative($id, '960-webp', 'unused', 'poster.webp', 'image/webp', 960, 540, 100, str_repeat('a', 64), 'test', '1', 1, []));
        $request = new Request('POST', '/', form: ['_present_film' => '1', '_editorial_end' => '1', 'title__ca' => 'Film', 'title__es' => 'Film', 'title__en' => 'Film', 'film__url' => 'https://player.vimeo.com/video/123456789?h=abcdef1234', 'film__poster' => $id, 'film__title' => vimeoValue()['title']]);
        $input = EditorialForm::input($request, $type, $profile, null);
        $service = new ContentService(new MemoryRecordRepository(), $profile, new IdentityTestClock(new DateTimeImmutable('2026-10-26T12:00:00Z')), new IdentityTestRandom(), $media['library']);
        $saved = $service->create('project', $input, str_repeat('a', 32));
        assertTrueValue($saved instanceof RecordSnapshot, 'native controls save without exposing JSON');
        assertSameValue(vimeoValue(), $saved->body['film'], 'only the normalized reference enters a revision');
        $html = VimeoVideoControl::field($field, $saved->body['film'], null, $profile, $request, $media['library'], Translator::english());
        assertTrueValue(str_contains($html, '/media/' . $id . '/thumbnail') && str_contains($html, 'Evening poster.webp'), 'the chosen poster and its name are visible');
        assertTrueValue(str_contains($html, 'Evening study &lt;film&gt;') && str_contains($html, 'https://vimeo.com/123456789/abcdef1234'), 'title and normalized address are escaped and linked');
        assertTrueValue(!str_contains($html, '<iframe') && !str_contains($html, 'player.vimeo.com'), 'administration never embeds the player');
        $badInput = [...$input, 'film' => json_encode(['url' => 'https://evil.test/123', 'poster' => $id, 'title' => 'Unsaved title'], JSON_THROW_ON_ERROR)];
        $invalid = $service->create('project', $badInput, str_repeat('a', 32));
        assertTrueValue($invalid instanceof ValidationFailure && isset($invalid->errors['film']), 'unsafe addresses fail as field validation');
        $badHtml = VimeoVideoControl::field($field, $invalid->values['film'], 'Correct this field', $profile, $request, $media['library'], Translator::english());
        assertTrueValue(str_contains($badHtml, 'value="https://evil.test/123"') && str_contains($badHtml, 'Unsaved title'), 'the editor can correct the complete original input');
        assertTrueValue(!str_contains($badHtml, 'href="https://evil.test'), 'invalid input never becomes a destination');
        $media['repository']->items[$id] = new LibraryMedia($id, str_repeat('1', 32), 'Not an image', 'ready', 'video/mp4', 100, 960, 540, 1, null, null);
        $invalidPoster = $service->create('project', $input, str_repeat('a', 32));
        assertTrueValue($invalidPoster instanceof ValidationFailure && isset($invalidPoster->errors['film']), 'forged non-image posters fail on save');
    } finally {
        removeMediaProcessingTree($media['root']);
        removeMediaProcessingTree($root);
    }
};

$tests['vimeo frozen helper and conditional site policy preserve old bytes and allow only explicit playback'] = static function (): void {
    $root = sys_get_temp_dir() . '/portable-vimeo-compile-' . bin2hex(random_bytes(8));
    try {
        $package = vimeoPackage($root);
        $document = json_decode((string) file_get_contents($root . '/fixtures/snapshot.json'), true, 64, JSON_THROW_ON_ERROR);
        $document['profile']['packageSha256'] = $package->checksum;
        $empty = FrozenSnapshot::fromDocument($document, $package);
        assertSameValue(false, VimeoPresentation::enabled($package->manifest, $empty), 'a declaration alone never relaxes policy');
        $fixture = staticCompilerFixture();
        $original = $fixture['compiler']->compile($fixture['package'], $fixture['snapshot'], $fixture['builder'], $fixture['media'], $fixture['request'], $root . '/original');
        $withoutValue = $fixture['compiler']->compile($package, $empty, $package->siteBuilder(), $fixture['media'], $fixture['request'], $root . '/empty');
        assertSameValue($original->files, $withoutValue->files, 'every public file byte remains unchanged without a Vimeo value');
        assertTrueValue(!isset($withoutValue->manifest['frameSources']) && !is_file($root . '/empty/' . VimeoPresentation::SCRIPT), 'no capability flag or unused player assets enter a legacy artifact');
        $document['records'][0]['body']['film'] = vimeoValue();
        $snapshot = FrozenSnapshot::fromDocument($document, $package);
        assertSameValue(true, VimeoPresentation::enabled($package->manifest, $snapshot), 'a validated frozen value grants the single frame source');
        assertSameValue(vimeoValue(), $snapshot->records[0]->body['film'], 'frozen values retain the canonical contract');
        $poster = $snapshot->media(vimeoValue()['poster']);
        $html = VimeoPlayer::render(vimeoValue(), $poster, '/films/evening/', 'en', defaultLocale: $snapshot->defaultLocale)->value;
        assertTrueValue(str_contains($html, '<button') && str_contains($html, ' hidden>Play Evening study &lt;film&gt;'), 'the native named button is enhanced from a safe hidden state');
        assertTrueValue(str_contains($html, 'href="https://vimeo.com/123456789/abcdef1234"') && !str_contains($html, '<iframe'), 'ordinary links work without JavaScript and no frame is preloaded');
        $plain = VimeoPlayer::render(vimeoValue(), $poster, '/films/evening/', 'en', scripts: false)->value;
        assertTrueValue(!str_contains($plain, '<button') && str_contains($plain, '<a '), 'explicit script-free rendering retains the link');
        $context = PublicUrlContext::from(['formatVersion' => 1, 'baseUrl' => 'https://example.test']);
        $routes = new RouteDiscovery($context, [new DiscoveryRoute('/film/', 'en', 'film')]);
        $structured = StructuredData::page($routes, '/film/', 'en', 'Film', '', null, true, []);
        $before = '<!doctype html><html><head>' . $structured->policyMeta() . '</head><body>' . $html . '</body></html>';
        assertSameValue($before, VimeoPresentation::document($before, '/film/', false), 'non-Vimeo documents retain every byte');
        $after = VimeoPresentation::document($before, '/film/', true);
        assertTrueValue(str_contains($after, 'frame-src https://player.vimeo.com') && str_contains($after, $structured->scriptHash()), 'the meta policy adds the frame while keeping the structured-data hash');
        assertTrueValue(str_contains($after, 'integrity="sha256-') && str_contains($after, VimeoPresentation::SCRIPT), 'the one reviewed module has exact integrity metadata');
        $old = ApachePublicDelivery::configuration($context, [], ['/film/']);
        $new = ApachePublicDelivery::configuration($context, [], ['/film/'], vimeo: true);
        assertSameValue($old, str_replace('; frame-src https://player.vimeo.com', '', $new), 'Apache adds only the frame directive');
        assertSameValue($structured->policy(), str_replace('; frame-src https://player.vimeo.com', '', $structured->policy(true)), 'every other meta directive is unchanged');
        $compiled = $fixture['compiler']->compile($package, $snapshot, $package->siteBuilder(), $fixture['media'], $fixture['request'], $root . '/out');
        assertSameValue([VimeoPresentation::ORIGIN], $compiled->manifest['frameSources'], 'verified artifacts freeze their explicit permission');
        assertTrueValue(is_file($root . '/out/' . VimeoPresentation::SCRIPT), 'the installed runtime supplies the public player module');
        $matched = array_filter($compiled->files, static fn(array $file): bool => str_ends_with($file['path'], 'index.html'));
        assertTrueValue($matched !== [], 'fictional profile produced complete pages');
        foreach ($matched as $file) {
            assertTrueValue(str_contains((string) file_get_contents($root . '/out/' . $file['path']), 'frame-src https://player.vimeo.com'), 'all page meta policies match the site header');
        }
        $previewId = str_repeat('a', 32);
        $previewRoot = $root . '/previews/artifacts/' . $previewId;
        mkdir(dirname($previewRoot), 0700, true);
        rename($root . '/out', $previewRoot);
        $entries = array_map(static fn(array $file): \Utsuri\PortableCms\Conventional\Preview\PreviewArtifact => new \Utsuri\PortableCms\Conventional\Preview\PreviewArtifact($file['path'], $file['mime'], $file['byteSize'], $file['sha256']), $compiled->files);
        $bytes = (string) file_get_contents($previewRoot . '/artifact.json');
        $entries[] = new \Utsuri\PortableCms\Conventional\Preview\PreviewArtifact('artifact.json', 'application/json', strlen($bytes), hash('sha256', $bytes));
        $build = new \Utsuri\PortableCms\Conventional\Preview\PreviewBuild($entries);
        $repository = new MemoryPreviewRepository();
        $now = new DateTimeImmutable('2026-10-26T12:00:00Z');
        $repository->previews[$previewId] = new \Utsuri\PortableCms\Conventional\Preview\PreviewStatus($previewId, 'ready', str_repeat('b', 32), null, $build->manifest(), $now, $now);
        $service = new \Utsuri\PortableCms\Conventional\Preview\PreviewService($repository, $package->manifest, new IdentityTestClock($now), new IdentityTestRandom(), new \Utsuri\PortableCms\Conventional\Preview\FilesystemPreviewStore($root . '/previews'));
        assertTrueValue(!isset($build->manifest()['frameSources']), 'the queue envelope is distinct from the compiler manifest');
        assertSameValue(VimeoPresentation::scriptHash(VimeoPresentation::assets()[VimeoPresentation::SCRIPT]['body']), $service->vimeoScriptHash($previewId), 'permission and integrity come from verified frozen files');
        $response = $service->artifactResponse($previewId, 'en/projects/archive-of-mist/index.html', '/previews/' . $previewId . '/artifact/');
        assertSameValue(true, $response?->vimeoFrame(), 'the real artifact response carries its frozen permission');
        file_put_contents($previewRoot . '/' . VimeoPresentation::SCRIPT, 'tampered');
        try {
            $service->vimeoScriptHash($previewId);
            throw new RuntimeException('A changed player script was allowed');
        } catch (\Utsuri\PortableCms\Conventional\Preview\PreviewBuildFailure) {
        }
        foreach (['privacyHash' => 'malformed', 'poster' => str_repeat('f', 32), 'title' => ''] as $key => $bad) {
            $invalid = $document;
            $invalid['records'][0]['body']['film'][$key] = $bad;
            try {
                FrozenSnapshot::fromDocument($invalid, $package);
                throw new RuntimeException('Malformed Vimeo value entered an exact or public snapshot');
            } catch (\Utsuri\PortableCms\Conventional\Compiler\ProfileBuildFailure|InvalidInput) {
            }
        }
    } finally {
        removeMediaProcessingTree($root);
    }
};

$tests['vimeo preview permissions retain legacy restrictions and isolate scripted shared pages'] = static function (): void {
    $assets = VimeoPresentation::assets();
    $hash = VimeoPresentation::scriptHash($assets[VimeoPresentation::SCRIPT]['body']);
    $normal = SecurityHeaders::apply(new Response(200, 'page', sameOriginFrame: true), 'test', true);
    assertTrueValue(str_contains($normal->headers()['content-security-policy'][0], "script-src 'none'"), 'legacy shared previews still refuse scripts');
    assertTrueValue(!str_contains($normal->headers()['content-security-policy'][0], 'player.vimeo.com'), 'legacy previews still refuse Vimeo');
    $shared = SecurityHeaders::apply((new Response(200, 'page', sameOriginFrame: true))->withVimeoFrame($hash), 'test', true);
    $policy = $shared->headers()['content-security-policy'][0];
    assertTrueValue(str_contains($policy, "script-src 'sha256-" . $hash . "'") && !str_contains($policy, "script-src 'self'"), 'the reviewed module is the only shared-preview script allowed');
    assertTrueValue(str_contains($policy, 'frame-src https://player.vimeo.com'), 'the same player host is allowed');
    assertSameValue(['strict-origin-when-cross-origin'], $shared->headers()['referrer-policy'], 'domain-locked embeds retain the ordinary site referrer policy');
    $workspace = SecurityHeaders::apply((new Response(200, 'form'))->withVimeoWorkspace(), 'test', true);
    assertTrueValue(str_contains($workspace->headers()['content-security-policy'][0], "frame-src 'self' https://player.vimeo.com"), 'the administration keeps its live page and permits only the sibling Vimeo player');
    assertSameValue(['strict-origin-when-cross-origin'], $workspace->headers()['referrer-policy'], 'the sibling player inherits the authoring origin referrer');
    $viewer = \Utsuri\PortableCms\Conventional\View\PreviewSharePage::viewer('/shared-previews/' . str_repeat('a', 32) . '/', Translator::english(), '/shared-preview-files/' . str_repeat('a', 32) . '/' . str_repeat('b', 64) . '/');
    assertTrueValue(str_contains($viewer, 'sandbox="allow-scripts"'), 'the shared shell grants scripts without a CMS origin or sandbox escape');
    $isolated = SecurityHeaders::apply((new Response(200, 'page'))->asIsolated(false)->withVimeoFrame($hash), 'test', true);
    assertTrueValue(str_contains($isolated->headers()['content-security-policy'][0], "script-src 'self'") && str_contains($isolated->headers()['content-security-policy'][0], 'frame-src https://player.vimeo.com'), 'isolated shares use public scripts plus frozen Vimeo permission');
    assertSameValue(['no-referrer'], $isolated->headers()['referrer-policy'], 'file addresses are never leaked to the provider');
    assertTrueValue(!str_contains($isolated->headers()['content-security-policy'][0], 'allow-popups'), 'shared pages cannot open the administration in a popup');
    $live = SecurityHeaders::apply((new Response(200, 'page'))->asIsolated()->withVimeoFrame($hash), 'test', true);
    assertTrueValue(str_contains($live->headers()['content-security-policy'][0], 'sandbox allow-scripts') && !str_contains($live->headers()['content-security-policy'][0], 'allow-same-origin'), 'live preview isolation is retained');
    $document = new LivePreviewDocument('/film/', $assets, [], [], true);
    assertSameValue($document->document(), LivePreviewDocument::fromDocument($document->document())->document(), 'permission follows the frozen live render');
    try {
        LivePreviewDocument::fromDocument([...$document->document(), 'frameSources' => ['https://evil.test']]);
        throw new RuntimeException('A foreign frame origin was accepted');
    } catch (RuntimeException $error) {
        assertTrueValue(str_contains($error->getMessage(), 'frame sources'), 'stored permissions fail closed');
    }
};

$tests['vimeo exports restore and import retain external references while remapping only the owned poster'] = static function (): void {
    $root = sys_get_temp_dir() . '/portable-vimeo-portable-' . bin2hex(random_bytes(8));
    try {
        $package = vimeoPackage($root);
        $type = $package->manifest->type('project');
        $document = RestoreTestFixture::document($package);
        $document['revisions'][0]['body']['film'] = vimeoValue();
        $clock = new IdentityTestClock(new DateTimeImmutable('2026-10-26T12:00:00Z'));
        $export = new NormalizedExport($document);
        new RestorePlan($export, hash('sha256', $export->json()), $package, $clock, new IdentityTestRandom());
        $body = ['film' => vimeoValue()];
        $newId = str_repeat('e', 32);
        $remapped = ImportBody::remap($type->fields, $body, $package->manifest->locales, [], [vimeoValue()['poster'] => $newId]);
        assertSameValue([...vimeoValue(), 'poster' => $newId], $remapped['film'], 'provider id hash and title survive unchanged');
        assertSameValue([vimeoValue()['poster']], ImportBody::mediaIds($type->fields, $body, $package->manifest->locales), 'only the owned poster travels as media');
        foreach (['privacyHash' => 'malformed', 'poster' => str_repeat('f', 32), 'title' => ''] as $key => $bad) {
            $invalid = $document;
            $invalid['revisions'][0]['body']['film'][$key] = $bad;
            try {
                $poison = new NormalizedExport($invalid);
                new RestorePlan($poison, hash('sha256', $poison->json()), $package, $clock, new IdentityTestRandom());
                throw new RuntimeException('Poisoned Vimeo export was restored');
            } catch (RestoreRejected|InvalidInput) {
            }
        }
    } finally {
        removeMediaProcessingTree($root);
    }
};
