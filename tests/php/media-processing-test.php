<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Media\FilesystemMediaStore;
use Utsuri\PortableCms\Conventional\Media\ImagickRasterProcessor;
use Utsuri\PortableCms\Conventional\Media\MediaDerivative;
use Utsuri\PortableCms\Conventional\Media\MediaJob;
use Utsuri\PortableCms\Conventional\Media\MediaJobRepository;
use Utsuri\PortableCms\Conventional\Media\MediaJobRunner;
use Utsuri\PortableCms\Conventional\Media\MediaProcessingFailure;
use Utsuri\PortableCms\Conventional\Media\MediaSource;
use Utsuri\PortableCms\Conventional\Media\ProcessedMedia;
use Utsuri\PortableCms\Conventional\Media\RasterProcessor;

final class MemoryMediaJobRepository implements MediaJobRepository
{
    public bool $available = true;
    public ?MediaJob $job = null;
    public ?ProcessedMedia $completed = null;
    /** @var array{code: string, retryable: bool, retryAt: \DateTimeImmutable}|null */
    public ?array $failure = null;

    public function __construct(public MediaSource $mediaSource) {}

    public function claim(
        string $workerId,
        string $leaseToken,
        DateTimeImmutable $now,
        DateTimeImmutable $leaseExpiresAt,
    ): ?MediaJob {
        if (!$this->available) {
            return null;
        }
        $this->available = false;
        return $this->job = new MediaJob(
            str_repeat('c', 32),
            'running',
            [
                'formatVersion' => 1,
                'mediaId' => $this->mediaSource->mediaId,
                'expectedByteSize' => $this->mediaSource->byteSize,
                'declaredType' => $this->mediaSource->declaredType,
            ],
            1,
            3,
            $leaseToken,
            $leaseExpiresAt,
        );
    }

    public function source(MediaJob $job): MediaSource
    {
        return $this->mediaSource;
    }

    public function complete(
        MediaJob $job,
        ProcessedMedia $processed,
        string $auditId,
        DateTimeImmutable $now,
    ): void {
        $this->completed = $processed;
    }

    public function fail(
        MediaJob $job,
        string $errorCode,
        bool $retryable,
        DateTimeImmutable $retryAt,
        DateTimeImmutable $now,
    ): void {
        $this->failure = ['code' => $errorCode, 'retryable' => $retryable, 'retryAt' => $retryAt];
    }

    public function publicStatus(string $jobId): ?array
    {
        return null;
    }

    public function latestPublicStatus(): ?array
    {
        return null;
    }
}

final class FixtureRasterProcessor implements RasterProcessor
{
    public function __construct(private readonly bool $fail = false) {}

    public function process(MediaSource $source, string $sourcePath, string $workingDirectory): ProcessedMedia
    {
        if ($this->fail) {
            throw new MediaProcessingFailure('fixture_rejected', false, 'Fixture is invalid.');
        }
        $variant = '960-webp';
        $filename = $source->mediaId . '-' . $variant . '.webp';
        $bytes = 'fixture-webp-derivative';
        file_put_contents($workingDirectory . '/' . $filename . '.tmp', $bytes, LOCK_EX);
        return new ProcessedMedia(
            $source->checksum,
            $source->declaredType,
            1200,
            800,
            new MediaDerivative(
                $source->mediaId,
                $variant,
                'derivatives/' . $source->mediaId . '/' . $filename,
                $filename,
                'image/webp',
                960,
                640,
                strlen($bytes),
                hash('sha256', $bytes),
                'fixture',
                '1',
                1,
                ['maximumWidth' => 960],
            ),
        );
    }
}

function removeMediaProcessingTree(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }
    foreach (scandir($directory) ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $directory . '/' . $item;
        is_dir($path) && !is_link($path) ? removeMediaProcessingTree($path) : @unlink($path);
    }
    @rmdir($directory);
}

/** @return array{root: string, source: MediaSource} */
function mediaProcessingFixture(): array
{
    $root = sys_get_temp_dir() . '/portable-cms-processing-' . bin2hex(random_bytes(8));
    $mediaId = str_repeat('a', 32);
    $originalKey = 'originals/' . str_repeat('b', 32) . '/source';
    mkdir($root . '/originals/' . str_repeat('b', 32), 0700, true);
    $bytes = "\x89PNG\r\n\x1A\nfixture";
    file_put_contents($root . '/' . $originalKey, $bytes, LOCK_EX);
    chmod($root . '/' . $originalKey, 0600);
    return [
        'root' => $root,
        'source' => new MediaSource(
            $mediaId,
            str_repeat('d', 32),
            str_repeat('e', 32),
            'image/png',
            strlen($bytes),
            hash('sha256', $bytes),
            $originalKey,
        ),
    ];
}

$tests['media runner leases commits and completes one immutable derivative'] = static function (): void {
    $fixture = mediaProcessingFixture();
    try {
        $repository = new MemoryMediaJobRepository($fixture['source']);
        $runner = new MediaJobRunner(
            $repository,
            new FilesystemMediaStore($fixture['root']),
            new FixtureRasterProcessor(),
            new IdentityTestClock(new DateTimeImmutable('2026-09-30T20:00:00Z')),
            new IdentityTestRandom(),
        );
        $result = $runner->runOne('test-worker');
        assertSameValue(true, $result->completed, 'claimed derivative should complete');
        assertTrueValue($result->elapsedMilliseconds >= 0, 'completed work should report elapsed time');
        assertTrueValue($result->peakPhpMemoryBytes > 0, 'completed work should report peak PHP memory');
        if (PHP_OS_FAMILY === 'Linux') {
            assertTrueValue(
                is_int($result->peakResidentMemoryBytes) && $result->peakResidentMemoryBytes > 0,
                'completed work should report peak resident process memory on Linux',
            );
            assertTrueValue(
                is_int($result->residentMemoryBeforeBytes) && $result->residentMemoryBeforeBytes > 0,
                'completed work should sample current resident memory before processing on Linux',
            );
            assertTrueValue(
                is_int($result->residentMemoryAfterBytes) && $result->residentMemoryAfterBytes > 0,
                'completed work should sample current resident memory after processing on Linux',
            );
        }
        assertTrueValue($repository->completed instanceof ProcessedMedia, 'completion metadata should be durable');
        $derivative = $repository->completed->derivative;
        $path = $fixture['root'] . '/' . $derivative->objectKey;
        assertSameValue(true, is_file($path), 'verified derivative should be committed privately');
        assertSameValue($derivative->checksum, hash_file('sha256', $path), 'committed bytes should match metadata');
    } finally {
        removeMediaProcessingTree($fixture['root']);
    }
};

$tests['media runner records a bounded terminal processor failure'] = static function (): void {
    $fixture = mediaProcessingFixture();
    try {
        $repository = new MemoryMediaJobRepository($fixture['source']);
        $runner = new MediaJobRunner(
            $repository,
            new FilesystemMediaStore($fixture['root']),
            new FixtureRasterProcessor(true),
            new IdentityTestClock(new DateTimeImmutable('2026-09-30T20:00:00Z')),
            new IdentityTestRandom(),
        );
        $result = $runner->runOne('test-worker');
        assertSameValue(false, $result->completed, 'invalid media should not complete');
        assertTrueValue($result->elapsedMilliseconds >= 0, 'failed work should report elapsed time');
        assertTrueValue($result->peakPhpMemoryBytes > 0, 'failed work should report peak PHP memory');
        if (PHP_OS_FAMILY === 'Linux') {
            assertTrueValue(
                is_int($result->residentMemoryBeforeBytes) && is_int($result->residentMemoryAfterBytes),
                'failed work should retain both resident-memory samples on Linux',
            );
        }
        assertSameValue('fixture_rejected', $repository->failure['code'] ?? null, 'failure should retain stable code');
        assertSameValue(false, $repository->failure['retryable'] ?? null, 'invalid media should not retry');
    } finally {
        removeMediaProcessingTree($fixture['root']);
    }
};

$tests['private derivative storage refuses a symbolic-link escape'] = static function (): void {
    $fixture = mediaProcessingFixture();
    $outside = sys_get_temp_dir() . '/portable-cms-processing-outside-' . bin2hex(random_bytes(8));
    mkdir($outside, 0700);
    mkdir($fixture['root'] . '/derivatives', 0700);
    symlink($outside, $fixture['root'] . '/derivatives/' . $fixture['source']->mediaId);
    try {
        $repository = new MemoryMediaJobRepository($fixture['source']);
        $runner = new MediaJobRunner(
            $repository,
            new FilesystemMediaStore($fixture['root']),
            new FixtureRasterProcessor(),
            new IdentityTestClock(new DateTimeImmutable('2026-09-30T20:00:00Z')),
            new IdentityTestRandom(),
        );
        $result = $runner->runOne('test-worker');
        assertSameValue(false, $result->completed, 'unsafe derivative path should not complete');
        assertSameValue([], array_values(array_diff(scandir($outside) ?: [], ['.', '..'])), 'no bytes may escape storage');
    } finally {
        @unlink($fixture['root'] . '/derivatives/' . $fixture['source']->mediaId);
        removeMediaProcessingTree($fixture['root']);
        @rmdir($outside);
    }
};

$tests['Imagick processor emits a bounded metadata-stripped WebP derivative'] = static function (): void {
    $fixture = mediaProcessingFixture();
    $sourcePath = $fixture['root'] . '/' . $fixture['source']->originalKey;
    $working = $fixture['root'] . '/imagick-work';
    mkdir($working, 0700);
    $image = new Imagick();
    $image->newImage(1200, 800, new ImagickPixel('#8c7258'));
    $image->setImageFormat('png');
    $image->setImageProperty('comment', 'private fixture metadata');
    $image->writeImage($sourcePath);
    $image->clear();
    $image->destroy();
    $bytes = filesize($sourcePath);
    $checksum = hash_file('sha256', $sourcePath);
    $source = new MediaSource(
        $fixture['source']->mediaId,
        $fixture['source']->ownerRecordId,
        $fixture['source']->actorUserId,
        'image/png',
        (int) $bytes,
        (string) $checksum,
        $fixture['source']->originalKey,
    );
    try {
        $processed = (new ImagickRasterProcessor())->process($source, $sourcePath, $working);
        assertSameValue(1200, $processed->width, 'processor should retain source width metadata');
        assertSameValue(960, $processed->derivative->width, 'processor should create the bounded width');
        assertSameValue('image/webp', $processed->derivative->mime, 'processor should emit WebP');
        assertSameValue(2, $processed->derivative->recipeVersion, 'bounded source policy should version the recipe');
        assertSameValue(
            25_000_000,
            $processed->derivative->parameters['maximumSourcePixels'] ?? null,
            'recipe provenance should retain the decoded-pixel ceiling',
        );
        $derivativePath = $working . '/' . $processed->derivative->filename . '.tmp';
        assertSameValue(true, is_file($derivativePath), 'processor should write a private temporary derivative');
        assertSameValue(true, str_starts_with((string) file_get_contents($derivativePath), 'RIFF'), 'output should have WebP signature');
        $verified = new Imagick($derivativePath);
        assertSameValue('', (string) $verified->getImageProperty('comment'), 'private metadata should be stripped');
        $verified->clear();
        $verified->destroy();
    } finally {
        removeMediaProcessingTree($fixture['root']);
    }
};

$tests['Imagick processor rejects over-limit geometry from headers before pixel decode'] = static function (): void {
    $fixture = mediaProcessingFixture();
    $sourcePath = $fixture['root'] . '/' . $fixture['source']->originalKey;
    $working = $fixture['root'] . '/imagick-work';
    mkdir($working, 0700);
    $image = new Imagick();
    $image->newImage(1, 1, new ImagickPixel('#8c7258'));
    $image->setImageFormat('png');
    $image->writeImage($sourcePath);
    $image->clear();
    $image->destroy();
    $bytes = (string) file_get_contents($sourcePath);
    $dimensions = pack('NN', 5_000, 5_001);
    $bytes = substr_replace($bytes, $dimensions, 16, 8);
    $bytes = substr_replace($bytes, pack('N', crc32(substr($bytes, 12, 17))), 29, 4);
    file_put_contents($sourcePath, $bytes, LOCK_EX);
    $source = new MediaSource(
        $fixture['source']->mediaId,
        $fixture['source']->ownerRecordId,
        $fixture['source']->actorUserId,
        'image/png',
        strlen($bytes),
        hash('sha256', $bytes),
        $fixture['source']->originalKey,
    );
    try {
        try {
            (new ImagickRasterProcessor())->process($source, $sourcePath, $working);
            throw new RuntimeException('over-limit geometry unexpectedly decoded');
        } catch (MediaProcessingFailure $error) {
            assertSameValue(
                'source_geometry_rejected',
                $error->codeName,
                '25,000,001 header pixels should be rejected before full decode',
            );
        }
        assertSameValue([], array_values(array_diff(scandir($working) ?: [], ['.', '..'])), 'rejection should write no derivative');
    } finally {
        removeMediaProcessingTree($fixture['root']);
    }
};
