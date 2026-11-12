<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Compiler\FrozenMedia;
use Utsuri\PortableCms\Conventional\Compiler\ResponsiveImage;
use Utsuri\PortableCms\Conventional\Media\ImagickRasterProcessor;
use Utsuri\PortableCms\Conventional\Media\MediaSource;
use Utsuri\PortableCms\Conventional\Media\RasterImageWidth;
use Utsuri\PortableCms\Conventional\Media\RasterWidths;

$tests['raster widths preserve small originals and prepare large originals within the single-job envelope'] = static function (): void {
    foreach ([[320, 180], [6000, 4000]] as [$width, $height]) {
        $fixture = mediaProcessingFixture();
        try {
            $path = $fixture['root'] . '/' . $fixture['source']->originalKey;
            $image = new Imagick();
            try {
                $image->newImage($width, $height, new ImagickPixel('#6f806a'));
                $image->setImageFormat('png');
                $image->writeImage($path);
            } finally {
                $image->clear();
                $image->destroy();
            }
            $work = $fixture['root'] . '/widths';
            mkdir($work, 0700);
            $actual = [];
            foreach (RasterWidths::WIDTHS as $target) {
                if ($target !== 960 && $target > $width) {
                    continue;
                }
                $source = new MediaSource($fixture['source']->mediaId, $fixture['source']->ownerRecordId, $fixture['source']->actorUserId, 'image/png', (int) filesize($path), (string) hash_file('sha256', $path), $fixture['source']->originalKey, $target);
                $started = hrtime(true);
                $prepared = (new ImagickRasterProcessor())->process($source, $path, $work)->derivative;
                assertTrueValue((hrtime(true) - $started) / 1_000_000_000 < 45, 'each width stays inside the existing image-work time limit');
                assertSameValue(min($target, $width), $prepared->width, 'no width is upscaled');
                assertSameValue((int) round($height * min($target, $width) / $width), $prepared->height, 'aspect ratio is preserved');
                assertSameValue($target, $prepared->parameters['maximumWidth'], 'recipe records the actual target');
                $file = $work . '/' . $prepared->filename . '.tmp';
                assertSameValue($prepared->checksum, hash_file('sha256', $file), 'every width has independently verified bytes');
                $actual[] = $prepared->width;
            }
            assertSameValue($width === 320 ? [320] : [480, 960, 1600, 2400], $actual, 'small and large originals have the finite available set');
        } finally {
            removeMediaProcessingTree($fixture['root']);
        }
    }
};

$tests['responsive image attributes sort available widths escape sizes and retain legacy fallback'] = static function (): void {
    $id = str_repeat('a', 32);
    $widths = [new RasterImageWidth(2400, 2400, 1600, 12, str_repeat('b', 64)), new RasterImageWidth(480, 480, 320, 4, str_repeat('c', 64))];
    $media = new FrozenMedia($id, str_repeat('d', 32), 'display_960_webp', 'image/webp', 8, str_repeat('e', 64), 960, 640, focalPoint: new \Utsuri\PortableCms\Conventional\Media\FocalPoint(25, 75), widths: $widths);
    foreach ($media->renditions() as $rendition) {
        assertSameValue($media->focalPoint->document(), $rendition->focalPoint->document(), 'every responsive rendition preserves the frozen focal point');
    }
    $html = ResponsiveImage::attributes($media, '/en/projects/example/', '(max-width: 760px) 100vw, 90vw "<&');
    assertTrueValue(str_contains($html, '../../../media/' . $id . '-display_480_webp.webp 480w') && str_contains($html, '2400w'), 'mount-independent srcset includes all frozen widths');
    assertTrueValue(strpos($html, '480w') < strpos($html, '960w') && strpos($html, '960w') < strpos($html, '2400w'), 'width descriptors are ordered');
    assertTrueValue(str_contains($html, '&quot;&lt;&amp;') && !str_contains($html, '90vw "<&'), 'sizes is escaped as an attribute');
    $legacy = new FrozenMedia($id, $media->ownerRecordId, $media->variant, $media->mime, 8, $media->checksum, 960, 640);
    assertTrueValue(str_contains(ResponsiveImage::attributes($legacy, '/en/', '100vw'), '960w') && !str_contains(ResponsiveImage::attributes($legacy, '/en/', '100vw'), '2400w'), 'pending or legacy images render only widths that exist');
    foreach ([new FrozenMedia($id, $media->ownerRecordId, 'prepared_video', 'video/mp4', 8, $media->checksum, 960, 640), new FrozenMedia($id, $media->ownerRecordId, 'bad" onerror="x', 'image/webp', 8, $media->checksum, 960, 640)] as $invalid) {
        try {
            ResponsiveImage::attributes($invalid, '/en/', '100vw');
            throw new RuntimeException('Unsafe responsive source accepted');
        } catch (InvalidArgumentException) {
        }
    }
};

$tests['frozen image widths reject duplicate unknown and forged metadata and preserve old snapshots'] = static function (): void {
    $width = new RasterImageWidth(480, 480, 320, 8, str_repeat('a', 64));
    assertSameValue($width->document(), RasterImageWidth::fromDocument([$width->document()])[0]->document(), 'prepared facts survive JSON projection');
    foreach ([[$width->document(), $width->document()], [[...$width->document(), 'variant' => 'display_9999_webp']], [[...$width->document(), 'width' => 481]], [[...$width->document(), 'sha256' => '<script>']], [[...$width->document(), 'path' => '../../private']]] as $invalid) {
        try {
            RasterImageWidth::fromDocument($invalid);
            throw new RuntimeException('Invalid width metadata accepted');
        } catch (InvalidArgumentException) {
        }
    }
    $fixture = staticCompilerFixture();
    $root = staticCompilerTempRoot();
    try {
        $document = json_decode((string) file_get_contents($fixture['package']->root . '/fixtures/snapshot.json'), true, 64, JSON_THROW_ON_ERROR);
        foreach ($document['media'] as &$media) {
            unset($media['widths']);
        }
        unset($media);
        $legacy = \Utsuri\PortableCms\Conventional\Compiler\FrozenSnapshot::fromDocument($document, $fixture['package']);
        assertSameValue([], $legacy->media($document['media'][0]['id'])->widths, 'legacy snapshots freeze no new files');
    } finally {
        staticCompilerRemoveTree($root);
    }
};

$tests['restore rejects forged responsive recipes before staging while preserving legacy derivatives'] = static function (): void {
    $plan = restoreFixturePlan(static function (array &$document): void {
        $media = &$document['media'][0];
        $extra = $media['derivatives'][0];
        $extra['variant'] = '480-webp';
        $extra['fileName'] = $media['id'] . '-480-webp.webp';
        $extra['width'] = 480;
        $extra['height'] = 720;
        $media['derivatives'][] = $extra;
    });
    assertSameValue(7, count($plan->files), 'a valid extra width joins the immutable restore inventory');
    foreach (['fileName', 'mime', 'width'] as $field) {
        try {
            restoreFixturePlan(static function (array &$document) use ($plan, $field): void {
                $document['media'] = restoreFixtureExport($plan)->document['media'];
                $document['media'][0]['derivatives'][1][$field] = match ($field) {
                    'fileName' => 'different.webp', 'mime' => 'video/mp4', 'width' => 960,
                };
            });
            throw new RuntimeException('Forged responsive recipe accepted');
        } catch (\Utsuri\PortableCms\Conventional\Restore\RestoreRejected $error) {
            assertSameValue('invalid_raster_width_recipe', $error->reason, 'restore rejects incompatible declared width facts');
        }
    }
};

$tests['rendered width aliases enforce the same authored alternative as their default image'] = static function (): void {
    $snapshot = staticCompilerFixture()['snapshot'];
    $media = $snapshot->media[0];
    $width = $media->renditions()[1];
    $path = '/' . \Utsuri\PortableCms\Conventional\Compiler\FilesystemMediaSource::publicPath($width);
    $alternative = $media->text->localized('en', $snapshot->defaultLocale)['alternativeText'];
    foreach ([$path, \Utsuri\PortableCms\Conventional\Compiler\ArtifactUrl::relative($path, '/en/')] as $url) {
        foreach ([false, true] as $inSrcset) {
            foreach ([$alternative, 'An invented page-title alternative'] as $alt) {
                $source = $inSrcset ? 'src="/assets/icon.svg" srcset="' . \Utsuri\PortableCms\Conventional\Http\Html::attribute($url) . ' 480w"' : 'src="' . \Utsuri\PortableCms\Conventional\Http\Html::attribute($url) . '"';
                $route = new \Utsuri\PortableCms\Conventional\Compiler\CompiledRoute('/en/', '<!doctype html><html lang="en"><img ' . $source . ' alt="' . \Utsuri\PortableCms\Conventional\Http\Html::attribute($alt) . '"></html>');
                $site = new \Utsuri\PortableCms\Conventional\Compiler\ProfileSiteBuild([$route], [], [], [$media->id]);
                try {
                    \Utsuri\PortableCms\Conventional\Compiler\MediaPublicationValidation::assertSite($site, $snapshot);
                    assertSameValue($alternative, $alt, 'a renderer cannot bypass descriptions by using an extra-width URL');
                } catch (\Utsuri\PortableCms\Conventional\I18n\InvalidInput $error) {
                    assertSameValue('preflight.issue.media_text', $error->text()->key, 'the real rendered width gets the stable media diagnostic');
                    assertTrueValue($alt !== $alternative, 'the authored alternative is accepted for every width alias');
                }
            }
        }
    }
};
