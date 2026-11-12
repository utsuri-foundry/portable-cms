<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Compiler\CompiledRoute;
use Utsuri\PortableCms\Conventional\Compiler\FrozenSnapshot;
use Utsuri\PortableCms\Conventional\Compiler\MediaPublicationValidation;
use Utsuri\PortableCms\Conventional\Compiler\ProfileSiteBuild;
use Utsuri\PortableCms\Conventional\Export\NormalizedExport;
use Utsuri\PortableCms\Conventional\Http\Request;
use Utsuri\PortableCms\Conventional\Identity\IdentityService;
use Utsuri\PortableCms\Conventional\Media\MediaText;

/** @return array{formatVersion: int, decorative: bool, translations: list<array{locale: string, alternativeText: string, caption: string, credit: string}>} */
function mediaTextFixture(): array
{
    return ['formatVersion' => 1, 'decorative' => false, 'translations' => [
        ['locale' => 'en', 'alternativeText' => 'Blue & cream, "painted" bands.', 'caption' => 'Colour study', 'credit' => 'Studio & Partners'],
        ['locale' => 'ca', 'alternativeText' => 'Franges blaves i crema.', 'caption' => '', 'credit' => 'Estudi'],
    ]];
}

$tests['media text validates hostile paths and resolves authored language fallback and decorative images'] = static function (): void {
    $document = mediaTextFixture();
    $text = MediaText::from($document, ['en', 'ca', 'es']);
    assertSameValue('Franges blaves i crema.', $text->localized('ca', 'en')['alternativeText'], 'authored translation wins');
    assertSameValue('Colour study', $text->localized('ca', 'en')['caption'], 'empty optional translation uses the authored default');
    assertSameValue('Estudi', $text->localized('ca', 'en')['credit'], 'credit has its own language fallback');
    assertSameValue($text->localized('en', 'en'), $text->localized('es', 'en'), 'missing translation falls back only to the default language');
    assertSameValue(false, $text->publishable('es', 'fr'), 'no arbitrary language is borrowed');
    assertSameValue(false, MediaText::from()->publishable('en', 'en'), 'missing legacy metadata is incomplete');
    $maximum = $document;
    $maximum['translations'][0]['alternativeText'] = str_repeat('é', 500);
    assertSameValue(500, mb_strlen(MediaText::from($maximum)->localized('en', 'en')['alternativeText']), 'the character bound accepts multibyte text at exactly 500');
    $document['decorative'] = true;
    assertSameValue('', MediaText::from($document)->localized('ca', 'en')['alternativeText'], 'explicit decorative flag emits empty alternative');
    assertSameValue(true, MediaText::from(['formatVersion' => 1, 'decorative' => true, 'translations' => []])->publishable('en', 'en'), 'decorative images require no invented description');
    foreach (['alternativeText', 'caption', 'credit'] as $field) {
        foreach (['<script>alert(1)</script>', "line\nfeed", "nul\0byte", "bad\xFF", str_repeat('é', 501)] as $hostile) {
            $bad = mediaTextFixture();
            $bad['translations'][0][$field] = $hostile;
            try {
                MediaText::from($bad);
                throw new RuntimeException('Hostile media text accepted');
            } catch (\Utsuri\PortableCms\Conventional\I18n\InvalidInput $error) {
                assertSameValue('media.text.invalid', $error->text()->key, 'one authoritative plain-text validation');
            }
        }
    }
    foreach ([['formatVersion' => 2], ['decorative' => 'true'], ['extra' => true], ['translations' => [$document['translations'][0], $document['translations'][0]]]] as $change) {
        try {
            MediaText::from([...mediaTextFixture(), ...$change]);
            throw new RuntimeException('Invalid media text shape accepted');
        } catch (\Utsuri\PortableCms\Conventional\I18n\InvalidInput) {
        }
    }
    try {
        MediaText::from(mediaTextFixture(), ['es']);
        throw new RuntimeException('Uninstalled media language accepted');
    } catch (\Utsuri\PortableCms\Conventional\I18n\InvalidInput) {
    }
};

$tests['native media metadata saves one revision and preserves all submitted text on conflicts and validation'] = static function (): void {
    $fixture = editorialMediaFixture();
    try {
        $state = contentTestApplication(library: $fixture['library']);
        $app = $state['app'];
        $cookies = authenticatedTestCookies($app);
        $id = array_key_first($fixture['repository']->items);
        $post = ['_csrf' => $cookies[IdentityService::CSRF_COOKIE], '_revision' => '1', '_media_text' => '1', 'name' => 'Colour study', 'alternativeText__en' => 'Blue & cream, "painted" bands.', 'caption__en' => 'Study & sketch', 'credit__en' => 'Author'];
        assertSameValue(303, $app->handle(new Request('POST', '/media/' . $id, contentMutationHeaders(), $post, $cookies))->status, 'native metadata save');
        $media = $fixture['library']->find($id);
        assertSameValue(2, $media->metadataRevision, 'name and descriptions share one revision');
        assertSameValue('Study & sketch', $media->text->localized('es', 'en')['caption'], 'saved metadata is authoritative');
        $conflict = $app->handle(new Request('POST', '/media/' . $id, contentMutationHeaders(), [...$post, 'caption__en' => 'Unsaved caption', 'credit__en' => 'Unsaved credit', 'decorative' => '1'], $cookies));
        assertSameValue(409, $conflict->status, 'metadata has optimistic concurrency');
        foreach (['Unsaved caption', 'Unsaved credit', 'name="decorative" value="1" checked'] as $input) {
            assertTrueValue(str_contains($conflict->body, $input), 'conflict preserves all input');
        }
        $invalid = $app->handle(new Request('POST', '/media/' . $id, contentMutationHeaders(), [...$post, '_revision' => '2', 'alternativeText__en' => '<img src=x onerror=alert(1)>'], $cookies));
        assertSameValue(422, $invalid->status, 'hostile description rejected');
        assertTrueValue(str_contains($invalid->body, '&lt;img src=x onerror=alert(1)&gt;'), 'validation preserves escaped submitted text');
        assertSameValue(1, $fixture['repository']->writes, 'conflicts and invalid text do not write');
    } finally {
        removeUploadTestTree($fixture['root']);
    }
};

$tests['published routes require frozen authored alternatives or explicit decoration including layout cells'] = static function (): void {
    $fixture = staticCompilerFixture();
    $document = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/profiles/reference-studio/fixtures/snapshot.json'), true, 64, JSON_THROW_ON_ERROR);
    $document['media'][0]['text'] = mediaTextFixture();
    $snapshot = FrozenSnapshot::fromDocument($document, $fixture['package']);
    $src = '../../../media/' . $snapshot->media[0]->id . '-display_1600_webp.webp';
    $route = static fn(string $alt): ProfileSiteBuild => new ProfileSiteBuild([new CompiledRoute('/en/projects/study/', '<!doctype html><html lang="en"><body><figure><img src="' . $src . '" alt="' . $alt . '"></figure></body></html>')], [], [], [$snapshot->media[0]->id]);
    MediaPublicationValidation::assertSite($route('Blue &amp; cream, &quot;painted&quot; bands.'), $snapshot);
    foreach (['', 'A page title, image 1'] as $invented) {
        try {
            MediaPublicationValidation::assertSite($route($invented), $snapshot);
            throw new RuntimeException('Missing or generated alternative accepted');
        } catch (\Utsuri\PortableCms\Conventional\I18n\InvalidInput $error) {
            assertTrueValue(str_contains($error->getMessage(), '/en/projects/study/'), 'publication report identifies the actual page');
        }
    }
    $document['media'][0]['text']['decorative'] = true;
    MediaPublicationValidation::assertSite($route(''), FrozenSnapshot::fromDocument($document, $fixture['package']));
    unset($document['media'][0]['text']);
    try {
        MediaPublicationValidation::assertSite($route(''), FrozenSnapshot::fromDocument($document, $fixture['package']));
        throw new RuntimeException('Legacy missing description was treated as decorative');
    } catch (\Utsuri\PortableCms\Conventional\I18n\InvalidInput) {
    }
    $site = $fixture['builder']->build($fixture['snapshot']);
    $html = implode('', array_map(static fn(CompiledRoute $route): string => $route->html, $site->routes));
    assertTrueValue(str_contains($html, 'Abstract study with a gradient'), 'profile uses authored image descriptions');
    assertTrueValue(str_contains($html, 'cms-media-credit'), 'layout image cells render authored caption and credit');
    assertTrueValue(!str_contains($html, 'image 1"'), 'generated page-title alternatives are removed');
};

$tests['normalized media text retains metadata revision and rejects hostile or uninstalled translations'] = static function (): void {
    $document = normalizedExportFixture();
    $document['media'][0]['text'] = mediaTextFixture();
    $document['media'][0]['metadataRevision'] = 7;
    $export = new NormalizedExport($document);
    assertSameValue($document['media'], $export->document['media'], 'export is lossless for descriptions and revisions');
    foreach (['extra' => 'extra', 'alternativeText' => '<b>text</b>', 'locale' => 'fr'] as $field => $value) {
        $bad = $document;
        $bad['media'][0]['text']['translations'][0][$field] = $value;
        try {
            new NormalizedExport($bad);
            throw new RuntimeException('Unsafe normalized metadata accepted');
        } catch (\InvalidArgumentException) {
        }
    }
};

$tests['preview preflight identifies missing media descriptions and uses its immutable frozen metadata'] = static function (): void {
    $fixture = profilePreviewFixture();
    try {
        $snapshot = $fixture['snapshot'];
        $records = [];
        foreach ($snapshot->records as $record) {
            $media = [];
            foreach ($record->media as $asset) {
                $media[] = new \Utsuri\PortableCms\Conventional\Preview\PreviewMedia($asset->id, $asset->objectKey, $asset->fileName, $asset->mime, $asset->byteSize, $asset->checksum, $asset->width, $asset->height);
            }
            $records[] = new \Utsuri\PortableCms\Conventional\Preview\PreviewRecord($record->id, $record->typeId, $record->revisionId, $record->revisionNumber, $record->body, $record->relationships, $media, $record->workflowState);
        }
        $missing = new \Utsuri\PortableCms\Conventional\Preview\PreviewSnapshot($snapshot->profileId, $snapshot->profileVersion, $snapshot->locales, $records, $snapshot->createdAt, $snapshot->designRegistries, $snapshot->profileChecksum);
        try {
            $fixture['compiler']->preflight($missing);
            throw new RuntimeException('Preview queued with incomplete published image metadata');
        } catch (\Utsuri\PortableCms\Conventional\Preview\PreviewPreflightFailure $error) {
            assertSameValue('preflight.issue.media_text', $error->issues[0]->key, 'native preview gets a specific correction');
            assertTrueValue(str_starts_with($error->issues[0]->parameters['route'], '/'), 'correction identifies rendered page');
        }
        $fixture['compiler']->preflight($snapshot);
        $frozen = $fixture['compiler']->freeze($snapshot);
        assertSameValue($snapshot->records[0]->media[0]->text?->document(), $frozen->media($snapshot->records[0]->media[0]->id)?->text?->document(), 'preview adapter preserves immutable metadata exactly');
    } finally {
        staticCompilerRemoveTree($fixture['root']);
    }
};
