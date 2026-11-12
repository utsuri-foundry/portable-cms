<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\EnvironmentReport;
use Utsuri\PortableCms\Conventional\Http\Response;
use Utsuri\PortableCms\Conventional\Media\FilesystemMediaStore;
use Utsuri\PortableCms\Conventional\Media\FilesystemUploadStorage;
use Utsuri\PortableCms\Conventional\Media\MediaContract;
use Utsuri\PortableCms\Conventional\Media\MediaSource;
use Utsuri\PortableCms\Conventional\Media\MediaUploadService;
use Utsuri\PortableCms\Conventional\Media\PreencodedVideoProcessor;
use Utsuri\PortableCms\Conventional\Media\UploadFailure;
use Utsuri\PortableCms\Conventional\Media\VideoSupport;
use Utsuri\PortableCms\Conventional\Media\VideoUploadPolicy;
use Utsuri\PortableCms\Conventional\Media\VideoUploadQualification;

require_once __DIR__ . '/trailer-fixture.php';

/** @return array<string, mixed> */
function trailerHostLimits(): array
{
    return ['fileUploads' => true, 'uploadMaxBytes' => 8 * 1048576, 'postMaxBytes' => 8 * 1048576,
        'memoryLimitBytes' => 64 * 1048576, 'maxExecutionSeconds' => 180, 'maxInputSeconds' => 180];
}

$tests['trailer qualification requires measured completion timeout PHP and disk evidence and reports its ceiling'] = static function (): void {
    $evidence = new VideoUploadQualification(VideoSupport::MAX_BYTES, 180, 60);
    $limits = trailerHostLimits();
    $headroom = 4 * VideoSupport::MAX_BYTES + 64 * 1048576;
    $policy = VideoUploadPolicy::qualify($evidence, $limits, $headroom, true);
    assertSameValue(VideoSupport::MAX_BYTES, $policy->maximumBytes, 'qualified trailers reach 256 MiB');
    assertSameValue([], $policy->reasons, 'every requirement is backed by evidence');
    assertSameValue(7 * 1048576, $policy->nativeMaximumBytes, 'multipart PHP limits remain visible independently of PUT parts');
    foreach (['memoryLimitBytes' => 32 * 1048576, 'maxExecutionSeconds' => 179, 'maxInputSeconds' => 179, 'uploadMaxBytes' => 1048576, 'postMaxBytes' => 5 * 1048576, 'fileUploads' => false] as $key => $value) {
        $declined = VideoUploadPolicy::qualify($evidence, [...$limits, $key => $value], $headroom, true);
        assertSameValue(VideoSupport::DEFAULT_BYTES, $declined->maximumBytes, 'regressed ' . $key . ' preserves the legacy limit');
        assertTrueValue($declined->reasons !== [], 'report explains refusal');
    }
    assertSameValue(VideoSupport::DEFAULT_BYTES, VideoUploadPolicy::qualify(null, $limits, $headroom, true)->maximumBytes, 'existing hosts never opt in automatically');
    assertSameValue(['storage_headroom_insufficient'], VideoUploadPolicy::qualify($evidence, $limits, $headroom - 1, true)->reasons, 'four copies and reserve are required');
    assertSameValue(['video_adapter_unavailable'], VideoUploadPolicy::qualify($evidence, $limits, $headroom, false)->reasons, 'unavailable host functions fail closed');
    assertSameValue(VideoSupport::MAX_BYTES, VideoUploadPolicy::qualify($evidence, [...$limits, 'maxExecutionSeconds' => 0, 'maxInputSeconds' => -1, 'memoryLimitBytes' => -1], $headroom, true)->maximumBytes, 'unlimited and inherited PHP settings retain the finite operator budget');
    foreach ([['maximumBytes' => VideoSupport::MAX_BYTES + 1, 'requestTimeoutSeconds' => 180, 'verifiedCompletionSeconds' => 60], ['maximumBytes' => VideoSupport::MAX_BYTES, 'requestTimeoutSeconds' => 60, 'verifiedCompletionSeconds' => 31], ['maximumBytes' => '268435456']] as $bad) {
        try {
            VideoUploadQualification::from($bad);
            throw new RuntimeException('Unbounded or unmeasured qualification accepted');
        } catch (InvalidArgumentException) {
        }
    }
    $report = EnvironmentReport::fromSnapshot(['limits' => $limits, 'storage' => ['privateStorage' => ['freeBytes' => $headroom]], 'media' => ['preencodedVideo' => ['enabled' => true]]], $evidence);
    assertSameValue($policy->report(), array_intersect_key($report['media']['preencodedVideo'], $policy->report()), 'host report uses the runtime policy');
    assertTrueValue(!str_contains(json_encode($report, JSON_THROW_ON_ERROR), '/tmp'), 'policy reports contain no host paths');
};

$tests['250 MiB trailer resumes in 5 MiB parts prepares unchanged and streams bounded ranges'] = static function (): void {
    $root = sys_get_temp_dir() . '/portable-trailer-' . bin2hex(random_bytes(8));
    $repository = new MemoryUploadRepository();
    $record = str_repeat('a', 32);
    $actor = str_repeat('b', 32);
    $repository->records[$record] = ['typeId' => 'project', 'revisionNumber' => 1];
    $clock = new IdentityTestClock(new DateTimeImmutable('2026-10-06T12:00:00Z'));
    $random = new IdentityTestRandom();
    $free = (float) (4 * VideoSupport::MAX_BYTES + 64 * 1048576);
    $disk = static function (string $path) use (&$free): float {
        return $free;
    };
    $storage = new FilesystemUploadStorage($root, $disk, $clock);
    $size = 250 * 1048576;
    $policy = VideoUploadPolicy::qualify(new VideoUploadQualification(VideoSupport::MAX_BYTES, 180, 60), trailerHostLimits(), 8 * 1024 * 1048576, true);
    memory_reset_peak_usage();
    $baseline = memory_get_usage(true);
    try {
        $package = trailerQualifiedPackage($root . '/qualified-profile');
        $service = new MediaUploadService($repository, $storage, $clock, $random, true, $policy, $package->manifest);
        $checksum = trailerFixture($root . '/fixture.mp4', $size);
        $input = fopen($root . '/fixture.mp4', 'rb');
        if (!is_resource($input)) {
            throw new RuntimeException('Fixture stream unavailable');
        }
        try {
            $session = $service->create('project', $record, 1, 'Trailer.mp4', 'video/mp4', $size, 'trailer', $actor);
            assertSameValue(50, $session->partCount, '250 MiB uses exactly fifty parts');
            assertSameValue(MediaContract::PART_BYTES, $session->partSize, 'part contract stays 5 MiB');
            assertSameValue($clock->now()->modify('+24 hours')->format('c'), $session->expiresAt->format('c'), 'resume window remains 24 hours');
            for ($number = 1; $number <= $session->partCount; $number++) {
                $part = tmpfile();
                if (!is_resource($part)) {
                    throw new RuntimeException('Part stream unavailable');
                }
                try {
                    $length = MediaContract::expectedPartSize($session, $number);
                    assertSameValue($length, stream_copy_to_stream($input, $part, $length), 'each part is read without buffering the trailer');
                    rewind($part);
                    $service->uploadPart($session->id, $number, $length, $part, $actor);
                    $free -= $length;
                } finally {
                    fclose($part);
                }
                if ($number === 1) {
                    $clock->current = $clock->now()->modify('+1 hour');
                    $policy = VideoUploadPolicy::qualify(new VideoUploadQualification(VideoSupport::MAX_BYTES, 180, 60), trailerHostLimits(), (int) $free, true);
                    assertSameValue(VideoSupport::DEFAULT_BYTES, $policy->maximumBytes, 'stored parts lower the new-upload ceiling without preventing resume');
                    $service = new MediaUploadService($repository, new FilesystemUploadStorage($root, $disk, $clock), $clock, $random, true, $policy, $package->manifest);
                    $resumed = $service->create('project', $record, 1, 'Trailer.mp4', 'video/mp4', $size, 'trailer', $actor);
                    assertSameValue($session->id, $resumed->id, 'interrupted browser resumes its existing session');
                    assertSameValue(1, count($resumed->parts), 'committed part survives restart');
                }
            }
        } finally {
            fclose($input);
        }
        $completed = $service->complete($session->id, $actor);
        assertSameValue($completed, $service->complete($session->id, $actor), 'large completion remains idempotent');
        $original = $root . '/' . $session->objectKey;
        assertSameValue($checksum, hash_file('sha256', $original), 'resumed assembly retains every byte');
        assertSameValue(1, count($repository->jobs), 'restart produces one preparation job');
        mkdir($root . '/work', 0700);
        $source = new MediaSource(str_repeat('c', 32), $record, $actor, 'video/mp4', $size, $checksum, $session->objectKey);
        $prepared = (new PreencodedVideoProcessor())->process($source, $original, $root . '/work');
        $store = new FilesystemMediaStore($root);
        $store->commitDerivative($prepared->derivative, $root . '/work/' . $prepared->derivative->filename . '.tmp');
        $file = $store->readVideo($prepared->derivative);
        $response = Response::file($file, 'video/mp4', 'bytes=0-11');
        assertSameValue(206, $response->status, 'playback reads a tiny opening range');
        assertSameValue(['bytes 0-11/' . $size], $response->headers()['content-range'], 'range identifies the full large file');
        assertSameValue('ftyp', substr(videoResponseBytes($response), 4, 4), 'range bytes are the original MP4 header');
        $tail = Response::file($file, 'video/mp4', 'bytes=' . ($size - 1) . '-');
        assertSameValue("\0", videoResponseBytes($tail), 'tail range does not read the preceding 250 MiB');
        $output = fopen($root . '/streamed.mp4', 'xb');
        if (!is_resource($output)) {
            throw new RuntimeException('Stream destination unavailable');
        }
        try {
            Response::file($file, 'video/mp4')->writeBodyTo($output);
        } finally {
            fclose($output);
        }
        assertSameValue($checksum, hash_file('sha256', $root . '/streamed.mp4'), 'full delivery also streams unchanged bytes');
        $document = RestoreTestFixture::document($package);
        $time = $document['media'][0]['createdAt'];
        $facts = ['mime' => 'video/mp4', 'byteSize' => $size, 'sha256' => $checksum];
        $document['revisions'][0]['body']['trailer'] = ['mediaId' => $source->mediaId, 'posterId' => $document['media'][0]['id']];
        $document['media'][] = ['id' => $source->mediaId, 'ownerRecordId' => $document['records'][0]['id'], 'status' => 'ready', 'originalName' => 'Trailer.mp4', 'declaredType' => 'video/mp4', 'detectedType' => 'video/mp4', 'width' => 160, 'height' => 90, 'original' => $facts,
            'derivatives' => [['variant' => 'prepared-video', 'fileName' => $prepared->derivative->filename, ...$facts, 'width' => 160, 'height' => 90, 'processorId' => 'php-preencoded-video', 'processorVersion' => '1', 'recipeVersion' => 1, 'parameters' => $prepared->derivative->parameters, 'createdAt' => $time]], 'createdBy' => $actor, 'createdAt' => $time, 'updatedAt' => $time];
        $export = new \Utsuri\PortableCms\Conventional\Export\NormalizedExport($document);
        $plan = new \Utsuri\PortableCms\Conventional\Restore\RestorePlan($export, hash('sha256', $export->json()), $package, $clock, $random);
        mkdir($root . '/inventory', 0700);
        mkdir($root . '/inventory/blobs', 0700);
        foreach ($document['media'] as $media) {
            copy($media['id'] === $source->mediaId ? $original : $package->root . '/fixtures/media/' . $media['id'] . '-display_1600_webp.webp', $root . '/inventory/blobs/' . $media['original']['sha256']);
        }
        mkdir($root . '/restored', 0700);
        $inventory = new \Utsuri\PortableCms\Conventional\Restore\FilesystemRestoreStore($root . '/inventory', $clock);
        $destination = new \Utsuri\PortableCms\Conventional\Restore\FilesystemRestoreStore($root . '/restored', $clock);
        assertSameValue(true, $destination->stage($plan, $inventory, 8)['complete'], 'accepted large trailers fit the normalized restore inventory and stream into private storage');
        foreach (array_filter($plan->files, static fn($file): bool => $file->mime === 'video/mp4') as $file) {
            assertSameValue($checksum, hash_file('sha256', $root . '/restored/' . $file->key), 'restored original and delivery bytes remain identical');
        }
        assertTrueValue(memory_get_peak_usage(true) - $baseline < 16 * 1048576, 'upload inspection preparation and delivery never buffer a whole trailer');
    } finally {
        removeUploadTestTree($root);
    }
};

$tests['trailer upload refuses oversize profile bounds and disk exhaustion before allocating work'] = static function (): void {
    $root = sys_get_temp_dir() . '/portable-trailer-refusal-' . bin2hex(random_bytes(8));
    $repository = new MemoryUploadRepository();
    $record = str_repeat('a', 32);
    $actor = str_repeat('b', 32);
    $repository->records[$record] = ['typeId' => 'project', 'revisionNumber' => 1];
    $clock = new IdentityTestClock(new DateTimeImmutable());
    $random = new IdentityTestRandom();
    $policy = new VideoUploadPolicy(VideoSupport::MAX_BYTES, [], 180);
    $storage = new FilesystemUploadStorage($root, static fn(string $path): float => 64 * 1048576.0);
    $qualified = new MediaUploadService($repository, $storage, $clock, $random, true, $policy);
    $legacy = new MediaUploadService($repository, $storage, $clock, $random, true);
    $profile = \Utsuri\PortableCms\Conventional\Content\ProfileManifest::load(dirname(__DIR__, 2) . '/profiles/reference-studio/profile.json');
    $bounded = new MediaUploadService($repository, $storage, $clock, $random, true, $policy, $profile);
    try {
        foreach ([[$qualified, 'video/mp4', VideoSupport::MAX_BYTES + 1, 'upload_too_large'], [$legacy, 'video/mp4', VideoSupport::DEFAULT_BYTES + 1, 'upload_too_large'], [$bounded, 'video/mp4', 60 * 1048576, 'upload_too_large'], [$qualified, 'image/jpeg', MediaContract::MAX_BYTES + 1, 'upload_too_large'], [$qualified, 'video/mp4', 60 * 1048576, 'storage_headroom_insufficient']] as [$service, $mime, $size, $code]) {
            try {
                $service->create('project', $record, 1, 'Trailer.mp4', $mime, $size, 'refused', $actor);
                throw new RuntimeException('Unsafe upload accepted');
            } catch (UploadFailure $failure) {
                assertSameValue($code, $failure->codeName, 'refusal has a stable code');
            }
        }
        assertSameValue([], $repository->sessions, 'refused files allocate no session');
        assertSameValue([], $repository->jobs, 'refused files allocate no job');
    } finally {
        removeUploadTestTree($root);
    }
};

$tests['disk exhaustion and assembly timeout preserve uploaded parts for a successful completion retry'] = static function (): void {
    $root = sys_get_temp_dir() . '/portable-trailer-retry-' . bin2hex(random_bytes(8));
    $repository = new MemoryUploadRepository();
    $record = str_repeat('a', 32);
    $actor = str_repeat('b', 32);
    $repository->records[$record] = ['typeId' => 'project', 'revisionNumber' => 1];
    $clock = new IdentityTestClock(new DateTimeImmutable('2026-10-06T12:00:00Z'));
    $random = new IdentityTestRandom();
    $advancing = new class implements \Utsuri\PortableCms\Conventional\Identity\Clock {
        private int $calls = 0;

        public function now(): DateTimeImmutable
        {
            return (new DateTimeImmutable('2026-10-06T12:00:00Z'))->modify('+' . (30 * $this->calls++) . ' seconds');
        }
    };
    $free = 8.0 * 1024 * 1048576;
    $storage = new FilesystemUploadStorage($root, static function (string $path) use (&$free): float {
        return $free;
    }, $advancing);
    $service = new MediaUploadService($repository, $storage, $clock, $random, true);
    try {
        $bytes = (string) file_get_contents(dirname(__DIR__) . '/fixtures/video/prepared.mp4');
        $session = $service->create('project', $record, 1, 'Trailer.mp4', 'video/mp4', strlen($bytes), 'retry', $actor);
        $part = \Utsuri\PortableCms\Conventional\Http\RequestBody::fromString($bytes)->open();
        try {
            $service->uploadPart($session->id, 1, strlen($bytes), $part, $actor);
        } finally {
            fclose($part);
        }
        $free = 0.0;
        foreach (['storage_headroom_insufficient', 'upload_completion_timeout'] as $code) {
            try {
                $service->complete($session->id, $actor);
                throw new RuntimeException('Unsafe completion was accepted');
            } catch (UploadFailure $failure) {
                assertSameValue($code, $failure->codeName, 'completion refuses unavailable resources');
            }
            assertSameValue(1, count($repository->find($session->id)->parts), 'committed parts remain available for retry');
            assertSameValue([], $repository->jobs, 'incomplete assembly never queues preparation');
            $free = 8.0 * 1024 * 1048576;
        }
        $service = new MediaUploadService($repository, new FilesystemUploadStorage($root, clock: $clock), $clock, $random, true);
        assertSameValue('completed', $service->complete($session->id, $actor)['session']->status, 'retry after host recovery completes once');
        assertSameValue(1, count($repository->jobs), 'recovery queues exactly one job');
    } finally {
        removeUploadTestTree($root);
    }
};
