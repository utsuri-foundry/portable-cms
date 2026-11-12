<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Content\ContentService;
use Utsuri\PortableCms\Conventional\Content\FieldDefinition;
use Utsuri\PortableCms\Conventional\Content\RecordSnapshot;
use Utsuri\PortableCms\Conventional\Content\ValidationFailure;
use Utsuri\PortableCms\Conventional\Content\VideoLimits;
use Utsuri\PortableCms\Conventional\Content\VideoReference;
use Utsuri\PortableCms\Conventional\Http\Request;
use Utsuri\PortableCms\Conventional\I18n\InvalidInput;
use Utsuri\PortableCms\Conventional\I18n\Translator;
use Utsuri\PortableCms\Conventional\Media\LibraryMedia;
use Utsuri\PortableCms\Conventional\Media\MediaDerivative;
use Utsuri\PortableCms\Conventional\Media\VideoMetadata;
use Utsuri\PortableCms\Conventional\View\VideoReferenceControl;

require_once __DIR__ . '/video-limits-fixture.php';

$tests['video field settings bound integers and sound while preserving old defaults'] = static function (): void {
    $settings = ['posterRequired' => true, 'maximumBytes' => 10485760];
    foreach (['maximumDurationSeconds' => 7200, 'maximumWidth' => 4096, 'maximumHeight' => 4096] as $key => $maximum) {
        foreach ([1, $maximum] as $valid) {
            new FieldDefinition('loop', 'Loop', 'video_reference', false, false, false, [...$settings, $key => $valid]);
        }
        foreach ([0, -1, $maximum + 1, 1.5, '1', null, true] as $bad) {
            try {
                new FieldDefinition('loop', 'Loop', 'video_reference', false, false, false, [...$settings, $key => $bad]);
                throw new RuntimeException('Invalid setting accepted');
            } catch (InvalidArgumentException) {
            }
        }
    }
    foreach (['allowed', 'none'] as $sound) {
        VideoLimits::settings([...$settings, 'sound' => $sound]);
    }
    foreach (['muted', '', false, null] as $sound) {
        try {
            VideoLimits::settings([...$settings, 'sound' => $sound]);
            throw new RuntimeException('Invalid sound setting accepted');
        } catch (InvalidArgumentException) {
        }
    }
    $field = new FieldDefinition('loop', 'Loop', 'video_reference', false, false, false, $settings);
    VideoLimits::assert($field, 100, []);
};

$tests['video inspection reports actual AAC Opus and Vorbis track presence including silent containers'] = static function (): void {
    foreach (['fitting' => false, 'sound' => true] as $variant => $audio) {
        $bytes = videoLimitsClip($variant);
        $facts = VideoMetadata::inspect('video/mp4', strlen($bytes), static fn(int $start, int $length): string => substr($bytes, $start, $length));
        assertSameValue($audio, $facts['hasAudio'], 'AAC track presence is not playback volume');
    }
    foreach ([null, 'A_OPUS', 'A_VORBIS'] as $audio) {
        $bytes = videoLimitsWebm($audio);
        $facts = VideoMetadata::inspect('video/webm', strlen($bytes), static fn(int $start, int $length): string => substr($bytes, $start, $length));
        assertSameValue($audio !== null, $facts['hasAudio'], 'WebM sound metadata');
    }
};

$tests['video field limits reject each measured violation in pickers saves publish and nested portable graphs'] = static function (): void {
    $fixture = editorialMediaFixture();
    try {
        $package = videoLimitsPackage($fixture['root'] . '/profile', ['maximumDurationSeconds' => 15, 'maximumWidth' => 1920, 'maximumHeight' => 1080, 'sound' => 'none']);
        $profile = $package->manifest;
        $field = array_values(array_filter($profile->type('project')->fields, static fn(FieldDefinition $f): bool => $f->id === 'trailer'))[0];
        $video = str_repeat('e', 32);
        $poster = str_repeat('d', 32);
        $reference = ['mediaId' => $video, 'posterId' => $poster];
        $clock = new IdentityTestClock(new DateTimeImmutable('2026-10-25T12:00:00Z'));
        $repository = new MemoryRecordRepository();
        $service = new ContentService($repository, $profile, $clock, new IdentityTestRandom(), $fixture['library']);
        $fittingBody = [];
        foreach (['fitting', 'duration', 'width', 'height', 'sound', 'bytes'] as $variant) {
            $bytes = videoLimitsClip($variant === 'bytes' ? 'fitting' : $variant);
            $facts = VideoMetadata::inspect('video/mp4', strlen($bytes), static fn(int $start, int $length): string => substr($bytes, $start, $length));
            $size = $variant === 'bytes' ? 10485761 : strlen($bytes);
            $derivative = new MediaDerivative($video, 'prepared-video', 'derivatives/' . $video . '/' . $video . '-prepared-video.mp4', $video . '-prepared-video.mp4', 'video/mp4', $facts['width'], $facts['height'], $size, hash('sha256', $bytes), 'php-preencoded-video', '1', 1, $facts);
            $fixture['repository']->items = [$video => new LibraryMedia($video, str_repeat('1', 32), $variant, 'ready', 'video/mp4', $size, $facts['width'], $facts['height'], 1, null, $derivative), $poster => new LibraryMedia($poster, str_repeat('1', 32), 'Poster', 'ready', 'image/webp', 100, 160, 90, 1, null, new MediaDerivative($poster, '960-webp', 'unused', 'poster.webp', 'image/webp', 160, 90, 100, str_repeat('a', 64), 'test', '1', 1, []), \Utsuri\PortableCms\Conventional\Media\MediaText::from(['formatVersion' => 1, 'decorative' => true, 'translations' => []]))];
            // Recreate the request-scoped library to avoid sharing facts across synthetic identities.
            $library = new \Utsuri\PortableCms\Conventional\Media\MediaLibrary($fixture['repository'], new \Utsuri\PortableCms\Conventional\Media\FilesystemMediaStore($fixture['root']), $clock, new IdentityTestRandom());
            $service = new ContentService($repository, $profile, $clock, new IdentityTestRandom(), $library);
            $html = VideoReferenceControl::field($field, $reference, null, $profile, new Request('GET', '/'), $library, Translator::english());
            $saved = $service->create('project', ['title' => array_fill_keys($profile->locales, 'Loop'), 'trailer' => json_encode($reference, JSON_THROW_ON_ERROR)], str_repeat('a', 32));
            if ($variant === 'fitting') {
                assertTrueValue($saved instanceof RecordSnapshot, 'fitting silent clip saves');
                $fittingBody = $saved->body;
                assertTrueValue(!str_contains($html, ' disabled'), 'fitting option is available');
            } else {
                assertTrueValue($saved instanceof ValidationFailure && isset($saved->errors['trailer']), 'save refuses ' . $variant);
                assertTrueValue(str_contains($html, ' disabled aria-label='), 'picker disables ' . $variant);
                assertTrueValue(str_contains($html, '_retained_trailer__video'), 'native form preserves the invalid ID');
                $text = Translator::english()->message($saved->errors['trailer']);
                assertTrueValue(str_contains($text, $variant === 'sound' ? 'audio track' : 'maximum'), 'measured rule is named: ' . $text);
                $id = str_repeat('a', 32);
                $repository->records[$id] = new RecordSnapshot($id, 'project', 'draft', str_repeat('b', 32), 1, [...$fittingBody, 'trailer' => $reference], $clock->now());
                $published = $service->workflow()->transition('project', $id, 1, 'published', workflowTestActor());
                assertTrueValue($published instanceof ValidationFailure && isset($published->errors['trailer']), 'Publish refuses ' . $variant);
                assertSameValue('video.limit_' . $variant, $published->errors['trailer']->key, 'Publish names the violated video rule');
                $group = new FieldDefinition('section', 'Section', 'group', false, false, false, [], [$field]);
                try {
                    VideoReference::assertGraph([$group], ['section' => ['trailer' => $reference]], $profile->locales, static fn(string $id): string => $id === $video ? 'video/mp4' : 'image/webp', static fn(): int => $size, static fn(): array => $facts);
                    throw new RuntimeException('Nested portable graph accepted a violation');
                } catch (InvalidInput $error) {
                    assertSameValue('video.limit_' . $variant, $error->text()->key, 'same nested graph policy');
                }
            }
        }
        $retained = new Request('POST', '/', form: ['_retained_trailer__video' => $video, 'trailer__poster' => $poster]);
        assertSameValue($reference, VideoReference::input($retained, 'trailer', false, []), 'disabled selected identity is retained');
        assertSameValue(['mediaId' => '', 'posterId' => $poster], VideoReference::input(new Request('POST', '/', form: [...$retained->form, 'trailer__video' => '']), 'trailer', false, []), 'an explicit new choice wins over retention');
    } finally {
        removeMediaProcessingTree($fixture['root']);
    }
};

$tests['legacy prepared videos are reinspected from checksum-verified bytes instead of assumed silent'] = static function (): void {
    $fixture = editorialMediaFixture();
    try {
        $id = str_repeat('e', 32);
        $bytes = videoLimitsClip('sound');
        $name = $id . '-prepared-video.mp4';
        $key = 'derivatives/' . $id . '/' . $name;
        mkdir($fixture['root'] . '/derivatives/' . $id, 0700, true);
        file_put_contents($fixture['root'] . '/' . $key, $bytes);
        $legacy = new MediaDerivative($id, 'prepared-video', $key, $name, 'video/mp4', 160, 90, strlen($bytes), hash('sha256', $bytes), 'php-preencoded-video', '1', 1, ['durationMilliseconds' => 1000, 'codec' => 'h264']);
        $media = new LibraryMedia($id, str_repeat('1', 32), 'Old clip', 'ready', 'video/mp4', strlen($bytes), 160, 90, 1, null, $legacy);
        assertSameValue(true, $fixture['library']->videoFacts($media)['hasAudio'], 'legacy audio is read from the stored MP4');
        $field = new FieldDefinition('loop', 'Loop', 'video_reference', false, false, false, ['posterRequired' => true, 'maximumBytes' => 10485760, 'sound' => 'none']);
        try {
            VideoLimits::assert($field, strlen($bytes), []);
            throw new RuntimeException('Unknown sound was treated as silence');
        } catch (InvalidInput $error) {
            assertSameValue('video.facts_unavailable', $error->text()->key, 'unknown is distinct from silent');
        }
    } finally {
        removeMediaProcessingTree($fixture['root']);
    }
};

$tests['exact video previews expose duration and sound to renderers and report each forbidden field measurement'] = static function (): void {
    $fixture = profilePreviewFixture();
    try {
        $package = videoLimitsPackage($fixture['root'] . '/profile', ['maximumDurationSeconds' => 15, 'maximumWidth' => 1920, 'maximumHeight' => 1080, 'sound' => 'none']);
        $compiler = new \Utsuri\PortableCms\Conventional\Preview\ProfilePreviewCompiler($package, $fixture['root'] . '/media', dirname(__DIR__, 2) . '/schemas');
        $id = str_repeat('e', 32);
        $name = $id . '-prepared-video.mp4';
        $key = 'derivatives/' . $id . '/' . $name;
        mkdir($fixture['root'] . '/media/derivatives/' . $id, 0700, true);
        foreach (['fitting', 'duration', 'width', 'height', 'sound'] as $variant) {
            $records = $fixture['snapshot']->records;
            $bytes = videoLimitsClip($variant);
            $facts = VideoMetadata::inspect('video/mp4', strlen($bytes), static fn(int $start, int $length): string => substr($bytes, $start, $length));
            file_put_contents($fixture['root'] . '/media/' . $key, $bytes);
            foreach ($records as $index => $record) {
                if ($record->typeId !== 'project' || $record->workflowState !== 'published') {
                    continue;
                }
                $body = [...$record->body, 'trailer' => ['mediaId' => $id, 'posterId' => $record->media[0]->id]];
                $asset = new \Utsuri\PortableCms\Conventional\Preview\PreviewMedia($id, $key, $name, 'video/mp4', strlen($bytes), hash('sha256', $bytes), $facts['width'], $facts['height']);
                $records[$index] = new \Utsuri\PortableCms\Conventional\Preview\PreviewRecord($record->id, $record->typeId, $record->revisionId, $record->revisionNumber, $body, $record->relationships, [...$record->media, $asset], $record->workflowState);
                break;
            }
            $snapshot = diagnosticSnapshot($fixture['snapshot'], $records, $package->checksum);
            if ($variant === 'fitting') {
                $frozen = $compiler->freeze($snapshot);
                assertSameValue(1000, $frozen->media($id)?->durationMilliseconds, 'renderer receives duration without file reads');
                assertSameValue(false, $frozen->media($id)?->hasAudio, 'renderer receives inspected sound presence');
            } else {
                try {
                    $compiler->preflight($snapshot);
                    throw new RuntimeException('Invalid video passed exact preflight');
                } catch (\Utsuri\PortableCms\Conventional\Preview\PreviewPreflightFailure $error) {
                    assertSameValue('video.limit_' . $variant, $error->issues[0]->key, 'preflight keeps the named rule');
                }
                try {
                    $compiler->compile($snapshot, $fixture['root'] . '/rejected-' . $variant);
                    throw new RuntimeException('Invalid video compiled');
                } catch (\Utsuri\PortableCms\Conventional\Preview\PreviewBuildFailure) {
                    assertTrueValue(!file_exists($fixture['root'] . '/rejected-' . $variant), 'no rejected artifact');
                }
            }
        }
    } finally {
        staticCompilerRemoveTree($fixture['root']);
    }
};

$tests['restore and current-content import inspect portable blobs before enforcing video rules'] = static function (): void {
    $root = staticCompilerTempRoot();
    try {
        $package = videoLimitsPackage($root . '/profile', ['maximumDurationSeconds' => 15, 'maximumWidth' => 1920, 'maximumHeight' => 1080, 'sound' => 'none']);
        mkdir($root . '/inventory/blobs', 0700, true);
        $store = new \Utsuri\PortableCms\Conventional\Restore\FilesystemRestoreStore($root . '/inventory');
        mkdir($root . '/destination', 0700);
        $repository = new class implements \Utsuri\PortableCms\Conventional\Restore\RestoreRepository {
            public function inspect(\Utsuri\PortableCms\Conventional\Restore\RestorePlan $plan, string $destinationIdentity, string $actorId): string
            {
                return 'empty';
            }
            public function reserve(\Utsuri\PortableCms\Conventional\Restore\RestorePlan $plan, string $destinationIdentity, string $actorId, DateTimeImmutable $now): string
            {
                throw new RuntimeException('Rejected video reached reservation');
            }
            public function commit(\Utsuri\PortableCms\Conventional\Restore\RestorePlan $plan, string $destinationIdentity, string $actorId, DateTimeImmutable $now): void
            {
                throw new RuntimeException('Rejected video reached commit');
            }
        };
        $clock = new IdentityTestClock(new DateTimeImmutable('2026-10-25T12:00:00Z'));
        $service = new \Utsuri\PortableCms\Conventional\Restore\RestoreService($repository, new \Utsuri\PortableCms\Conventional\Restore\FilesystemRestoreStore($root . '/destination'), $clock);
        foreach (['fitting', 'duration', 'width', 'height', 'sound'] as $variant) {
            $document = RestoreTestFixture::document($package);
            foreach ($document['media'] as $image) {
                copy($package->root . '/fixtures/media/' . $image['id'] . '-display_1600_webp.webp', $root . '/inventory/blobs/' . $image['original']['sha256']);
            }
            $bytes = videoLimitsClip($variant);
            $facts = VideoMetadata::inspect('video/mp4', strlen($bytes), static fn(int $start, int $length): string => substr($bytes, $start, $length));
            $sha256 = hash('sha256', $bytes);
            file_put_contents($root . '/inventory/blobs/' . $sha256, $bytes);
            $id = str_repeat('e', 32);
            $recordId = $document['records'][0]['id'];
            $document['revisions'][0]['body']['trailer'] = ['mediaId' => $id, 'posterId' => $document['media'][0]['id']];
            $identity = ['mime' => 'video/mp4', 'byteSize' => strlen($bytes), 'sha256' => $sha256];
            $document['media'][] = ['id' => $id, 'ownerRecordId' => $recordId, 'status' => 'ready', 'originalName' => 'Loop.mp4', 'declaredType' => 'video/mp4', 'detectedType' => 'video/mp4', 'width' => $facts['width'], 'height' => $facts['height'], 'original' => $identity,
                'derivatives' => [['variant' => 'prepared-video', 'fileName' => $id . '-prepared-video.mp4', ...$identity, 'width' => $facts['width'], 'height' => $facts['height'], 'processorId' => 'php-preencoded-video', 'processorVersion' => '1', 'recipeVersion' => 1, 'parameters' => ['durationMilliseconds' => $facts['durationMilliseconds'], 'codec' => 'h264'], 'createdAt' => $document['createdAt']]], 'createdBy' => str_repeat('a', 32), 'createdAt' => $document['createdAt'], 'updatedAt' => $document['createdAt']];
            if ($variant === 'sound') {
                try {
                    $store->verify('blobs/' . $sha256, new \Utsuri\PortableCms\Conventional\Restore\RestoreFile('source', $sha256, strlen($bytes), 'video/mp4', 160, 90, 1000, false));
                    throw new RuntimeException('Forged silent recipe metadata passed restore');
                } catch (\Utsuri\PortableCms\Conventional\Restore\RestoreRejected $error) {
                    assertSameValue('blob_video_metadata_mismatch', $error->reason, 'portable recipes cannot forge silence for future field choices');
                }
            }
            $export = new \Utsuri\PortableCms\Conventional\Export\NormalizedExport($document);
            file_put_contents($root . '/inventory/export.json', $export->json());
            $plan = new \Utsuri\PortableCms\Conventional\Restore\RestorePlan($export, hash('sha256', $export->json()), $package, $clock, new IdentityTestRandom());
            $report = $service->dryRun($plan, $store, str_repeat('a', 32));
            assertSameValue($variant === 'fitting' ? 'compatible' : 'blocked', $report['status'], 'actual restore dry run checks field rules');
            if ($variant !== 'fitting') {
                assertTrueValue(str_contains($report['issues'][0]['reason'], $variant === 'sound' ? 'audio track' : 'maximum'), 'restore reports the violated field limit');
                try {
                    $service->apply($plan, $store, str_repeat('a', 32), $plan->sha256);
                    throw new RuntimeException('Restore accepted an incompatible clip');
                } catch (\Utsuri\PortableCms\Conventional\Restore\RestoreRejected $error) {
                    assertTrueValue(str_contains($error->reason, $variant === 'sound' ? 'audio track' : 'maximum'), 'restore apply keeps the measured rule');
                }
            }
            if ($variant === 'fitting') {
                $translated = $document;
                $translated['profile']['locales'][] = 'it';
                $translated['siteLanguages'] = \Utsuri\PortableCms\Conventional\Content\SiteLanguages::defaults($package->manifest)->document();
                $translated['siteLanguages']['languages'][] = ['locale' => 'it', 'state' => 'preparation'];
                $translated['revisions'][0]['body']['searchSharing'] = ['it' => []];
                $store->assertVideoFields(new \Utsuri\PortableCms\Conventional\Export\NormalizedExport($translated), $package->manifest);
            }
            foreach ([$export, \Utsuri\PortableCms\Conventional\Import\ImportSource::from($export)] as $source) {
                try {
                    $store->assertVideoFields($source, $package->manifest);
                    assertSameValue('fitting', $variant, 'only a conforming transfer is accepted');
                } catch (InvalidInput $error) {
                    assertSameValue('video.limit_' . $variant, $error->text()->key, 'actual bytes override missing or false legacy recipe claims');
                }
            }
        }
    } finally {
        staticCompilerRemoveTree($root);
    }
};

$tests['video limit changes require a content migration and identify their affected record type'] = static function (): void {
    $old = upgradeProfileDocument();
    foreach (['maximumDurationSeconds' => 15, 'maximumWidth' => 1920, 'maximumHeight' => 1080, 'sound' => 'none', 'maximumBytes' => 10485760] as $setting => $value) {
        $new = $old;
        foreach ($new['recordTypes'] as &$type) {
            foreach ($type['fields'] as &$field) {
                if ($field['type'] === 'video_reference') {
                    $field['settings'][$setting] = $value;
                }
            }
            unset($field);
        }
        unset($type);
        $changes = \Utsuri\PortableCms\Conventional\Upgrade\ProfileManifestChanges::classify($old, $new);
        assertTrueValue($changes->affecting !== [], 'tightened ' . $setting . ' needs an explicit migration');
        assertSameValue(['project'], $changes->affectedTypes(), 'dry-run can enumerate affected project records');
    }
    foreach (['en', 'ca', 'es', 'fr', 'de'] as $language) {
        $t = Translator::load($language);
        foreach (['duration', 'width', 'height', 'bytes'] as $limit) {
            assertTrueValue($t->text('video.limit_' . $limit, ['field' => 'Loop', 'actual' => 20, 'limit' => 15]) !== '', 'localized measured rule');
        }
        assertTrueValue($t->text('video.limit_sound', ['field' => 'Loop']) !== '', 'localized sound rule');
    }
};
