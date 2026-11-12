<?php

declare(strict_types=1);

require_once __DIR__ . '/trailer-fixture.php';

use Utsuri\PortableCms\Conventional\Content\ContentService;
use Utsuri\PortableCms\Conventional\Content\EditorialForm;
use Utsuri\PortableCms\Conventional\Content\FieldDefinition;
use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Content\ValidationFailure;
use Utsuri\PortableCms\Conventional\Content\VideoReference;
use Utsuri\PortableCms\Conventional\Http\Request;
use Utsuri\PortableCms\Conventional\Http\RequestBody;
use Utsuri\PortableCms\Conventional\I18n\Translator;
use Utsuri\PortableCms\Conventional\Media\FilesystemMediaStore;
use Utsuri\PortableCms\Conventional\Media\FilesystemUploadStorage;
use Utsuri\PortableCms\Conventional\Media\LibraryMedia;
use Utsuri\PortableCms\Conventional\Media\MediaLibraryHttp;
use Utsuri\PortableCms\Conventional\Media\MediaProcessingFailure;
use Utsuri\PortableCms\Conventional\Media\MediaSource;
use Utsuri\PortableCms\Conventional\Media\MediaUploadService;
use Utsuri\PortableCms\Conventional\Media\PreencodedVideoProcessor;
use Utsuri\PortableCms\Conventional\Media\UploadFailure;
use Utsuri\PortableCms\Conventional\Media\VideoMetadata;
use Utsuri\PortableCms\Conventional\View\VideoReferenceControl;

$tests['prepared video metadata accepts real MP4 and WebM through bounded reads and rejects hostile containers'] = static function (): void {
    foreach (['mp4' => 'video/mp4', 'webm' => 'video/webm'] as $extension => $mime) {
        $bytes = (string) file_get_contents(dirname(__DIR__) . '/fixtures/video/prepared.' . $extension);
        $readBytes = 0;
        $facts = VideoMetadata::inspect($mime, strlen($bytes), static function (int $offset, int $length) use ($bytes, &$readBytes): string {
            $readBytes += $length;
            return substr($bytes, $offset, $length);
        });
        assertSameValue(160, $facts['width'], 'width comes from the prepared container');
        assertSameValue(90, $facts['height'], 'height comes from the prepared container');
        assertSameValue(1000, $facts['durationMilliseconds'], 'duration is bounded metadata');
        assertTrueValue($readBytes < 2048, 'metadata inspection skips sample payloads');
        $spoof = $extension === 'mp4' ? pack('N', 16) . 'ftypisom' . pack('N', 0) : "\x1a\x45\xdf\xa3\xff";
        $mutated = $extension === 'mp4' ? str_replace('avc1', 'encv', $bytes) : str_replace('V_VP9', 'V_AV1', $bytes);
        foreach ([$spoof, substr($bytes, 0, 32), $bytes . 'trailing garbage', $mutated] as $bad) {
            try {
                VideoMetadata::inspect($mime, strlen($bad), static fn(int $offset, int $length): string => substr($bad, $offset, $length));
                throw new RuntimeException('Malformed or unsupported video was accepted');
            } catch (MediaProcessingFailure $failure) {
                assertSameValue('invalid_preencoded_video', $failure->codeName, 'invalid containers fail terminally');
                assertSameValue(false, $failure->retryable, 'unsupported bytes never remain queued for missing infrastructure');
            }
        }
        try {
            VideoMetadata::inspect($mime, \Utsuri\PortableCms\Conventional\Media\VideoSupport::MAX_BYTES + 1, static fn(int $offset, int $length): string => '');
            throw new RuntimeException('Unbounded video was accepted');
        } catch (MediaProcessingFailure) {
        }
    }
};

$tests['prepared video upload fails closed by default completes idempotently and rejects signature-only files before queuing'] = static function (): void {
    $root = sys_get_temp_dir() . '/portable-video-upload-' . bin2hex(random_bytes(8));
    $repository = new MemoryUploadRepository();
    $record = str_repeat('a', 32);
    $actor = str_repeat('b', 32);
    $repository->records[$record] = ['typeId' => 'project', 'revisionNumber' => 1];
    $clock = new IdentityTestClock(new DateTimeImmutable('2026-10-06T12:00:00Z'));
    $storage = new FilesystemUploadStorage($root);
    $random = new IdentityTestRandom();
    $disabled = new MediaUploadService($repository, $storage, $clock, $random);
    try {
        try {
            $disabled->create('project', $record, 1, 'film.mp4', 'video/mp4', 100, 'disabled', $actor);
            throw new RuntimeException('Unqualified video upload was accepted');
        } catch (UploadFailure $failure) {
            assertSameValue('unsupported_media_type', $failure->codeName, 'capability absence fails before a job');
        }
        assertSameValue([], $repository->jobs, 'no impossible work is queued');
        $service = new MediaUploadService($repository, $storage, $clock, $random, true);
        foreach (['mp4', 'webm'] as $extension) {
            $bytes = (string) file_get_contents(dirname(__DIR__) . '/fixtures/video/prepared.' . $extension);
            $session = $service->create('project', $record, 1, 'Film ' . $extension . '.' . $extension, 'video/' . $extension, strlen($bytes), $extension, $actor);
            $body = RequestBody::fromString($bytes)->open();
            try {
                $service->uploadPart($session->id, 1, strlen($bytes), $body, $actor);
            } finally {
                fclose($body);
            }
            $result = $service->complete($session->id, $actor);
            assertSameValue($result, $service->complete($session->id, $actor), 'repeated completion returns the same media and job');
        }
        $bad = pack('N', 16) . 'ftypisom' . pack('N', 0);
        $session = $service->create('project', $record, 1, 'spoof.mp4', 'video/mp4', strlen($bad), 'spoof', $actor);
        $body = RequestBody::fromString($bad)->open();
        try {
            $service->uploadPart($session->id, 1, strlen($bad), $body, $actor);
        } finally {
            fclose($body);
        }
        try {
            $service->complete($session->id, $actor);
            throw new RuntimeException('Signature-only video was queued');
        } catch (UploadFailure $failure) {
            assertSameValue('invalid_preencoded_video', $failure->codeName, 'container validation precedes durable acceptance');
        }
        assertSameValue(2, count($repository->jobs), 'only qualified prepared containers enter the worker queue');
        assertSameValue('aborted', $repository->find($session->id)?->status, 'invalid upload is terminal');
    } finally {
        removeUploadTestTree($root);
    }
};

$tests['prepared video worker retains verified bytes and private delivery rejects tampering and symlinks'] = static function (): void {
    $fixture = editorialMediaFixture();
    $root = $fixture['root'];
    $id = str_repeat('e', 32);
    $bytes = (string) file_get_contents(dirname(__DIR__) . '/fixtures/video/prepared.mp4');
    mkdir($root . '/work', 0700);
    $sourcePath = $root . '/work/source';
    file_put_contents($sourcePath, $bytes);
    $source = new MediaSource($id, str_repeat('1', 32), str_repeat('a', 32), 'video/mp4', strlen($bytes), hash('sha256', $bytes), 'originals/' . str_repeat('f', 32) . '/source');
    try {
        $processed = (new PreencodedVideoProcessor())->process($source, $sourcePath, $root . '/work');
        $store = new FilesystemMediaStore($root);
        $store->commitDerivative($processed->derivative, $root . '/work/' . $processed->derivative->filename . '.tmp');
        assertSameValue($bytes, videoResponseBytes(\Utsuri\PortableCms\Conventional\Http\Response::file($store->readVideo($processed->derivative), 'video/mp4')), 'prepared delivery bytes are unchanged');
        $fixture['repository']->items[$id] = new LibraryMedia($id, str_repeat('1', 32), 'Studio film.mp4', 'ready', 'video/mp4', strlen($bytes), 160, 90, 1, null, $processed->derivative);
        assertSameValue(null, $fixture['library']->thumbnail($id), 'image routes never return a video or an original');
        $profile = ProfileManifest::load(dirname(__DIR__, 2) . '/profiles/reference-studio/profile.json');
        $content = new ContentService(new MemoryRecordRepository(), $profile, new IdentityTestClock(new DateTimeImmutable()), new IdentityTestRandom(), $fixture['library']);
        $http = new MediaLibraryHttp($fixture['library'], $content, $profile);
        $session = new \Utsuri\PortableCms\Conventional\Identity\SessionContext('session', 'authenticated', str_repeat('a', 32), 'admin@example.test', 'Admin', 'administrator', 'token', 'csrf', new DateTimeImmutable('+1 hour'), false);
        $response = $http->handle(new Request('GET', '/media/' . $id . '/video', headers: ['range' => 'bytes=0-11']), $session, 'test');
        assertSameValue(206, $response->status, 'private native playback supports byte ranges');
        assertSameValue(substr($bytes, 0, 12), videoResponseBytes($response), 'ranges preserve exact source bytes');
        assertSameValue(['video/mp4'], $response->headers()['content-type'], 'video delivery has the verified MIME');
        $tail = $http->handle(new Request('GET', '/media/' . $id . '/video', headers: ['range' => 'bytes=12-']), $session, 'test');
        assertSameValue(substr($bytes, 12), videoResponseBytes($tail), 'open-ended ranges preserve the remaining bytes');
        foreach (['bytes=12-0', 'bytes=' . strlen($bytes) . '-', 'bytes=0-1,4-5', 'bytes=-10', 'bytes=999999999999999999999-'] as $invalidRange) {
            $rejected = $http->handle(new Request('GET', '/media/' . $id . '/video', headers: ['range' => $invalidRange]), $session, 'test');
            assertSameValue(416, $rejected->status, 'invalid and unsupported ranges fail closed');
            assertSameValue('', $rejected->body, 'rejected ranges disclose no video bytes');
        }
        assertSameValue(405, $http->handle(new Request('POST', '/media/' . $id . '/video'), $session, 'test')->status, 'video delivery cannot mutate media');
        $path = $root . '/' . $processed->derivative->objectKey;
        file_put_contents($path, $bytes . 'changed');
        assertSameValue(404, $http->handle(new Request('GET', '/media/' . $id . '/video'), $session, 'test')->status, 'tampered bytes fail closed');
        unlink($path);
        symlink($sourcePath, $path);
        assertSameValue(404, $http->handle(new Request('GET', '/media/' . $id . '/video'), $session, 'test')->status, 'links never expose private originals');
        unlink($path);
    } finally {
        removeMediaProcessingTree($root);
    }
};

$tests['native video and poster forms preserve named selections and authoritative validation rejects forged media'] = static function (): void {
    $fixture = editorialMediaFixture();
    try {
        $profile = ProfileManifest::load(dirname(__DIR__, 2) . '/profiles/reference-studio/profile.json');
        $type = $profile->type('project');
        $field = array_values(array_filter($type->fields, static fn(FieldDefinition $field): bool => $field->id === 'trailer'))[0];
        $videoId = str_repeat('e', 32);
        $posterId = str_repeat('d', 32);
        $bytes = (string) file_get_contents(dirname(__DIR__) . '/fixtures/video/prepared.mp4');
        mkdir($fixture['root'] . '/work', 0700);
        file_put_contents($fixture['root'] . '/work/source', $bytes);
        $processed = (new PreencodedVideoProcessor())->process(new MediaSource($videoId, str_repeat('1', 32), str_repeat('a', 32), 'video/mp4', strlen($bytes), hash('sha256', $bytes), 'originals/' . str_repeat('f', 32) . '/source'), $fixture['root'] . '/work/source', $fixture['root'] . '/work');
        $fixture['repository']->items = [
            $videoId => new LibraryMedia($videoId, str_repeat('1', 32), 'Prepared studio film', 'ready', 'video/mp4', strlen($bytes), 160, 90, 1, null, $processed->derivative),
            $posterId => new LibraryMedia($posterId, str_repeat('1', 32), 'Separate poster', 'ready', 'image/webp', 512, 960, 540, 1, null, new \Utsuri\PortableCms\Conventional\Media\MediaDerivative($posterId, '960-webp', 'derivatives/' . $posterId . '/' . $posterId . '-960-webp.webp', $posterId . '-960-webp.webp', 'image/webp', 960, 540, 512, str_repeat('a', 64), 'fixture', '1', 1, [])),
        ];
        $selection = ['mediaId' => $videoId, 'posterId' => $posterId];
        $html = VideoReferenceControl::field($field, $selection, null, $profile, new Request('GET', '/'), $fixture['library'], Translator::english());
        assertTrueValue(str_contains($html, 'Prepared studio film') && str_contains($html, 'Separate poster'), 'asset names are visible');
        assertTrueValue(!str_contains($html, 'textarea') && !str_contains($html, 'type="text"'), 'editors never type identities or JSON');
        $form = ['_editorial_end' => '1', '_present_trailer' => '1', 'trailer__video' => $videoId, 'trailer__poster' => $posterId];
        $input = EditorialForm::input(new Request('POST', '/', form: $form), $type, $profile, null);
        assertSameValue($selection, json_decode($input['trailer'], true, 4, JSON_THROW_ON_ERROR), 'native successful controls project the portable reference');
        VideoReference::assertGraph([$field], ['trailer' => $selection], $profile->locales, static fn(string $id): ?string => $fixture['repository']->find($id)?->mime);
        foreach ([['mediaId' => $posterId, 'posterId' => $videoId], ['mediaId' => $videoId], ['mediaId' => $videoId, 'posterId' => $posterId, 'url' => 'https://example.test/']] as $bad) {
            try {
                VideoReference::assertGraph([$field], ['trailer' => $bad], $profile->locales, static fn(string $id): ?string => $fixture['repository']->find($id)?->mime);
                throw new RuntimeException('Forged video reference was accepted');
            } catch (InvalidArgumentException) {
            }
        }
        $repository = new MemoryRecordRepository();
        $clock = new IdentityTestClock(new DateTimeImmutable());
        $service = new ContentService($repository, $profile, $clock, new IdentityTestRandom(), $fixture['library']);
        $value = $service->create('project', ['trailer' => json_encode(['mediaId' => $posterId, 'posterId' => $videoId], JSON_THROW_ON_ERROR)], str_repeat('a', 32));
        assertTrueValue($value instanceof ValidationFailure && isset($value->errors['trailer']), 'PHP rejects wrong media kinds before persistence');
    } finally {
        removeMediaProcessingTree($fixture['root']);
    }
};

$tests['prepared video capability report fails closed when host copy support is absent'] = static function (): void {
    $root = dirname(__DIR__, 2) . '/engine/src/cms/conventional';
    $code = 'require ' . var_export($root . '/host-io.php', true) . '; require ' . var_export($root . '/database-report.php', true) . '; require ' . var_export($root . '/environment-report.php', true) . '; echo json_encode(Utsuri\\PortableCms\\Conventional\\EnvironmentReport::collect([]));';
    $process = proc_open([PHP_BINARY, '-d', 'disable_functions=copy', '-r', $code], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Host capability subprocess is unavailable');
    }
    fclose($pipes[0]);
    $json = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    assertSameValue(0, proc_close($process), 'standalone host probe runs without the CMS bootstrap: ' . $errors);
    $report = json_decode(is_string($json) ? $json : '', true, 64, JSON_THROW_ON_ERROR);
    assertSameValue(false, $report['media']['preencodedVideo']['enabled'], 'missing copy support cannot advertise video preparation');
};
