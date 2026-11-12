<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Content\EditorialControls;
use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Http\Request;
use Utsuri\PortableCms\Conventional\I18n\Translator;
use Utsuri\PortableCms\Conventional\Media\FilesystemMediaStore;
use Utsuri\PortableCms\Conventional\Media\LibraryMedia;
use Utsuri\PortableCms\Conventional\Media\MediaSource;
use Utsuri\PortableCms\Conventional\Media\PreencodedVideoProcessor;
use Utsuri\PortableCms\Conventional\View\ReferenceControls;
use Utsuri\PortableCms\Conventional\View\VideoReferenceControl;

/** @return array{library: \Utsuri\PortableCms\Conventional\Media\MediaLibrary, repository: MemoryMediaLibrary, root: string, id: string, source: string, frame: string} */
function videoPreviewFixture(): array
{
    $fixture = editorialMediaFixture();
    $id = str_repeat('e', 32);
    $bytes = (string) file_get_contents(dirname(__DIR__) . '/fixtures/video/prepared.mp4');
    mkdir($fixture['root'] . '/work', 0700);
    file_put_contents($fixture['root'] . '/work/source', $bytes);
    $source = hash('sha256', $bytes);
    $processed = (new PreencodedVideoProcessor())->process(new MediaSource($id, str_repeat('1', 32), str_repeat('a', 32), 'video/mp4', strlen($bytes), $source, 'originals/' . str_repeat('f', 32) . '/source'), $fixture['root'] . '/work/source', $fixture['root'] . '/work');
    (new FilesystemMediaStore($fixture['root']))->commitDerivative($processed->derivative, $fixture['root'] . '/work/' . $processed->derivative->filename . '.tmp');
    $fixture['repository']->items = [$id => new LibraryMedia($id, str_repeat('1', 32), 'Film <studio>.mp4', 'ready', 'video/mp4', strlen($bytes), 160, 90, 1, null, $processed->derivative)];
    $image = new Imagick();
    $image->newImage(160, 90, 'navy', 'webp');
    $frame = $image->getImageBlob();
    $image->clear();
    return [...$fixture, ...compact('id', 'source', 'frame')];
}

$tests['video preview cache is bounded private idempotent and never writes content revisions'] = static function (): void {
    $f = videoPreviewFixture();
    try {
        $app = contentTestApplication(library: $f['library'])['app'];
        $cookies = authenticatedTestCookies($app);
        $url = '/media/' . $f['id'] . '/video-frame';
        $media = $f['library']->find($f['id']);
        assertTrueValue($media !== null, 'prepared video exists');
        assertTrueValue(str_contains(ReferenceControls::thumbnail($media), 'data-video-frame-fallback'), 'legacy video has a neutral fallback');
        assertTrueValue(!str_contains(ReferenceControls::thumbnail($media), '…'), 'ready video never looks pending');
        foreach (['processing' => '…', 'needs_attention' => '!'] as $status => $marker) {
            $pending = new LibraryMedia($media->id, $media->ownerRecordId, $media->name, $status, $media->mime, $media->byteSize, $media->width, $media->height, 1, null, null);
            assertTrueValue(str_contains(ReferenceControls::thumbnail($pending), $marker), 'nonready video retains its status marker');
            $f['repository']->items[$f['id']] = $pending;
            assertSameValue(false, $f['library']->cacheVideoFrame($f['id'], $f['source'], $f['frame']), 'unready video cannot accept a frame');
        }
        $f['repository']->items[$f['id']] = $media;
        assertSameValue(null, $f['library']->thumbnail($f['id']), 'reading does not manufacture a frame');
        assertSameValue(false, is_dir($f['root'] . '/video-frames'), 'GET does not write the cache');
        $page = $app->handle(new Request('GET', '/media/' . $f['id'], cookies: $cookies));
        assertSameValue(200, $page->status, 'detail is accessible');
        assertTrueValue(str_contains($page->body, 'controls muted preload="metadata"'), 'detail has a native metadata-only player');
        assertTrueValue(str_contains($page->body, 'H.264') && str_contains($page->body, '0:01') && str_contains($page->body, '160 × 90'), 'detail exposes stored facts');
        $csrf = $cookies[\Utsuri\PortableCms\Conventional\Identity\IdentityService::CSRF_COOKIE];
        $form = ['_csrf' => $csrf, 'source' => $f['source'], 'frame' => base64_encode($f['frame'])];
        assertSameValue(403, $app->handle(new Request('POST', $url, contentMutationHeaders(), [...$form, '_csrf' => 'bad'], $cookies))->status, 'frame creation requires CSRF');
        assertSameValue(403, $app->handle(new Request('POST', $url, ['origin' => 'https://hostile.test'], $form, $cookies))->status, 'frame creation requires same origin');
        assertTrueValue($app->handle(new Request('POST', $url, contentMutationHeaders(), $form))->status !== 204, 'anonymous browser cannot write');
        foreach (['not-base64!', base64_encode('<svg/>'), base64_encode(str_repeat('x', 65537))] as $bad) {
            assertTrueValue(in_array($app->handle(new Request('POST', $url, contentMutationHeaders(), [...$form, 'frame' => $bad], $cookies))->status, [413, 422], true), 'hostile bytes are rejected');
        }
        $wrong = new Imagick();
        $wrong->newImage(16, 9, 'red', 'webp');
        assertSameValue(422, $app->handle(new Request('POST', $url, contentMutationHeaders(), [...$form, 'frame' => base64_encode($wrong->getImageBlob())], $cookies))->status, 'wrong scaled geometry is rejected');
        $wrong->clear();
        assertSameValue(409, $app->handle(new Request('POST', $url, contentMutationHeaders(), [...$form, 'source' => str_repeat('0', 64)], $cookies))->status, 'stale source checksum cannot attach a frame');
        assertSameValue(204, $app->handle(new Request('POST', $url, contentMutationHeaders(), $form, $cookies))->status, 'browser frame is accepted');
        assertSameValue(204, $app->handle(new Request('POST', $url, contentMutationHeaders(), $form, $cookies))->status, 'capture is idempotent');
        $other = new Imagick();
        $other->newImage(160, 90, 'red', 'webp');
        assertSameValue(204, $app->handle(new Request('POST', $url, contentMutationHeaders(), [...$form, 'frame' => base64_encode($other->getImageBlob())], $cookies))->status, 'a later browser may submit a different valid frame');
        $other->clear();
        assertSameValue($f['frame'], $f['library']->thumbnail($f['id']), 'thumbnail serves the captured WebP');
        assertSameValue(0, $f['repository']->writes, 'capture is not a content or metadata change');
        assertSameValue(0600, fileperms($f['root'] . '/video-frames/' . $f['id'] . '-' . $f['source'] . '.webp') & 0777, 'cache bytes stay private');
        $cached = $f['library']->find($f['id']);
        assertTrueValue($cached?->videoFrame !== null, 'subsequent reads discover the cache');
        assertTrueValue(str_contains(ReferenceControls::thumbnail($cached), '/thumbnail'), 'cached frame is rendered');
        $path = $f['root'] . '/video-frames/' . $f['id'] . '-' . $f['source'] . '.webp';
        unlink($path);
        symlink($f['root'] . '/work/source', $path);
        assertSameValue(null, $f['library']->thumbnail($f['id']), 'cache symlinks cannot expose originals');
        assertSameValue(503, $app->handle(new Request('POST', $url, contentMutationHeaders(), $form, $cookies))->status, 'cache symlink cannot be overwritten');
        unlink($path);
    } finally {
        removeMediaProcessingTree($f['root']);
    }
};

$tests['video preview visual choices work through native actions and leave players unloaded'] = static function (): void {
    $f = videoPreviewFixture();
    try {
        $profile = ProfileManifest::load(dirname(__DIR__, 2) . '/profiles/reference-studio/profile.json');
        $type = $profile->type('project');
        $field = array_values(array_filter($type->fields, static fn($field): bool => $field->id === 'trailer'))[0];
        $input = EditorialControls::apply(new Request('POST', '/', form: ['_editor_action' => 'select-video:trailer:' . $f['id']]), $type, $profile, ['trailer' => null], new IdentityTestRandom());
        assertSameValue(['mediaId' => $f['id'], 'posterId' => ''], $input['trailer'], 'native choice updates only its reference');
        $html = VideoReferenceControl::field($field, $input['trailer'], null, $profile, new Request('GET', '/'), $f['library'], Translator::english());
        assertTrueValue(!str_contains($html, '<select'), 'video uses visual cards');
        assertTrueValue(str_contains($html, 'controls muted preload="none"'), 'record player fetches nothing before play');
        assertTrueValue(!str_contains($html, 'data-video-capture="auto"'), 'record pickers never prefetch candidate clips');
        assertTrueValue(str_contains($html, 'aria-label="Film &lt;studio&gt;.mp4"'), 'native player has an escaped filename label');
        assertTrueValue(str_contains($html, 'data-video-choose="video"'), 'native candidates also support the island');
        assertTrueValue(str_contains($html, 'alt=""'), 'redundant thumbnails are decorative');
        $cleared = EditorialControls::apply(new Request('POST', '/', form: ['_editor_action' => 'clear-video:trailer']), $type, $profile, $input, new IdentityTestRandom());
        assertSameValue(null, $cleared['trailer'], 'native clear restores an empty reference');
        foreach (['en', 'ca', 'es', 'fr', 'de'] as $locale) {
            $t = Translator::load($locale);
            assertTrueValue($t->text('video.duration_unknown') !== '' && $t->text('video.codec', ['codec' => 'H.264']) !== '', 'video labels exist in every locale');
        }
    } finally {
        removeMediaProcessingTree($f['root']);
    }
};
