<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Compiler\FrozenMedia;
use Utsuri\PortableCms\Conventional\Compiler\FrozenSnapshot;
use Utsuri\PortableCms\Conventional\Compiler\ImagickSharingImageProcessor;
use Utsuri\PortableCms\Conventional\Content\EditorialControls;
use Utsuri\PortableCms\Conventional\Content\SearchSharing;
use Utsuri\PortableCms\Conventional\Http\Request;
use Utsuri\PortableCms\Conventional\Identity\IdentityService;

$tests['sharing processor emits hash addressed stripped JPEG with bounded verified geometry'] = static function (): void {
    $fixture = staticCompilerFixture();
    $source = $fixture['snapshot']->media[0];
    $path = $fixture['media']->file($source);
    $processor = new ImagickSharingImageProcessor();
    $limits = [Imagick::getResourceLimit(Imagick::RESOURCETYPE_MEMORY), Imagick::getResourceLimit(Imagick::RESOURCETYPE_TIME)];
    $output = $processor->process($source, $path);
    assertSameValue($output->bytes, $processor->process($source, $path)->bytes, 'same frozen source and encoder produce identical bytes');
    assertSameValue('sharing/' . hash('sha256', $output->bytes) . '.jpg', $output->image->path, 'path identifies actual output bytes');
    assertSameValue($source->checksum, $output->image->sourceSha256, 'recipe pins source display bytes');
    assertSameValue($limits, [Imagick::getResourceLimit(Imagick::RESOURCETYPE_MEMORY), Imagick::getResourceLimit(Imagick::RESOURCETYPE_TIME)], 'adapter restores owned resource limits');
    $image = new Imagick();
    try {
        $image->readImageBlob($output->bytes);
        assertSameValue('JPEG', $image->getImageFormat(), 'platform-compatible output rather than original or WebP URL');
        assertSameValue([1200, 630], [$image->getImageWidth(), $image->getImageHeight()], 'fixed uncropped sharing canvas');
        assertSameValue([], $image->getImageProfiles('*', false), 'no EXIF or embedded private profiles');
    } finally {
        $image->clear();
    }
    foreach ([new FrozenMedia($source->id, $source->ownerRecordId, $source->variant, $source->mime, $source->byteSize, str_repeat('a', 64), $source->width, $source->height),
        new FrozenMedia($source->id, $source->ownerRecordId, $source->variant, $source->mime, $source->byteSize, $source->checksum, 1, 1)] as $bad) {
        try {
            $processor->process($bad, $path);
            throw new RuntimeException('Changed sharing source accepted');
        } catch (InvalidArgumentException) {
        }
    }
};

$tests['sharing selection resolves locale override cover and website default from frozen media'] = static function (): void {
    $fixture = staticCompilerFixture();
    $document = json_decode((string) file_get_contents($fixture['package']->root . '/fixtures/snapshot.json'), true, flags: JSON_THROW_ON_ERROR);
    foreach ($document['records'] as &$record) {
        if ($record['id'] === '10000000000000000000000000000001') {
            $record['body']['searchSharing'] = ['en' => ['sharingImage' => ['d0000000000000000000000000000002']]];
        }
    }
    unset($record);
    $fixture['snapshot'] = FrozenSnapshot::fromDocument($document, $fixture['package']);
    $root = staticCompilerTempRoot();
    try {
        $built = staticCompilerBuild($fixture, $root . '/artifact');
        $images = array_column($built->manifest['sharingImages'], null, 'mediaId');
        assertSameValue(2, count($images), 'shared images are prepared once per selected media, not per language or route');
        $override = $images['d0000000000000000000000000000002'];
        $cover = $images['d0000000000000000000000000000001'];
        foreach (['en/projects/archive-of-mist/index.html' => $override, 'ca/projects/arxiu-de-la-boira/index.html' => $cover, 'en/about/index.html' => $override] as $path => $image) {
            $html = (string) file_get_contents($built->outputRoot . '/' . $path);
            assertTrueValue(str_contains($html, 'property="og:image" content="https://reference-studio.example/' . $image['path'] . '"'), 'actual public URL follows frozen selection');
            assertTrueValue(str_contains($html, 'content="summary_large_image"') && str_contains($html, 'property="og:image:alt"'), 'social card includes dimensions and localized alternative text');
            $locale = explode('/', $path)[0];
            $authored = $fixture['snapshot']->media($image['mediaId'])->text->localized($locale, $fixture['snapshot']->defaultLocale)['alternativeText'];
            assertTrueValue(str_contains($html, 'property="og:image:alt" content="' . \Utsuri\PortableCms\Conventional\Http\Html::attribute($authored) . '"'), 'social metadata uses frozen authored alternative rather than page title');
            assertSameValue($image['sha256'], hash_file('sha256', $built->outputRoot . '/' . $image['path']), 'published bytes match artifact identity');
        }
        assertTrueValue(str_contains((string) file_get_contents($built->outputRoot . '/en/settings/index.html'), 'property="og:image"'), 'utility pages inherit the website sharing image while retaining noindex');
        assertTrueValue(!in_array('d0000000000000000000000000000003', array_keys($images), true), 'unreferenced draft-only media stays private');
    } finally {
        staticCompilerRemoveTree($root);
    }
    foreach ($document['records'] as &$record) {
        if ($record['id'] === '10000000000000000000000000000001') {
            $record['body']['searchSharing']['en']['sharingImage'] = [str_repeat('f', 32)];
        }
    }
    unset($record);
    try {
        $fixture['builder']->sharingMediaIds(FrozenSnapshot::fromDocument($document, $fixture['package']));
        throw new RuntimeException('Unavailable sharing image accepted');
    } catch (InvalidArgumentException) {
    }
};

$tests['native localized sharing image controls save recover conflicts and reject unavailable selections'] = static function (): void {
    $fixture = editorialMediaFixture();
    try {
        $profile = staticCompilerFixture()['package']->manifest;
        $state = contentTestApplication(profileOverride: $profile, library: $fixture['library']);
        $app = $state['app'];
        $cookies = authenticatedTestCookies($app);
        $mediaId = array_key_first($fixture['repository']->items);
        $name = EditorialControls::childName('searchSharing_en', 'sharingImage');
        $form = ['_csrf' => $cookies[IdentityService::CSRF_COOKIE], '_editorial_end' => '1', '_present_searchSharing' => '1', $name => [$mediaId]];
        foreach ($profile->locales as $locale) {
            $form['title__' . $locale] = 'Project ' . $locale;
        }
        $response = $app->handle(new Request('POST', '/records/project', contentMutationHeaders(), $form, $cookies));
        assertSameValue(303, $response->status, 'image picker shares the ordinary native save boundary');
        $path = $response->headers()['location'][0];
        $record = $state['contentRepository']->records[basename($path)];
        assertSameValue([$mediaId], $record->body['searchSharing']['en']['sharingImage'], 'localized override persists as portable media ID');
        $saved = $app->handle(new Request('POST', $path, contentMutationHeaders(), [...$form, '_revision' => '1'], $cookies));
        assertSameValue(303, $saved->status, 'subsequent edit saves');
        assertSameValue(409, $app->handle(new Request('POST', $path, contentMutationHeaders(), [...$form, '_revision' => '1'], $cookies))->status, 'stale sharing selection does not overwrite newer revision');
        assertSameValue(422, $app->handle(new Request('POST', $path, contentMutationHeaders(), [...$form, '_revision' => '2', $name => [str_repeat('f', 32)]], $cookies))->status, 'unavailable selected image fails at save');
        foreach ([['sharingImage' => ['https://private.example/original.jpg']], ['sharingImage' => [$mediaId, $mediaId]], ['sharingImage' => 'bad']] as $bad) {
            try {
                SearchSharing::from($bad);
                throw new RuntimeException('Unbounded image override accepted');
            } catch (InvalidArgumentException) {
            }
        }
    } finally {
        removeUploadTestTree($fixture['root']);
    }
};
