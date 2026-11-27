<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\ApplicationFactory;
use Utsuri\PortableCms\Conventional\HostIo;
use Utsuri\PortableCms\Conventional\RuntimeConfiguration;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/engine/src/cms/conventional/bootstrap.php';

/** @param array<string, int|string|null|list<array<string, bool|int|null>>> $value */
function writeMediaWorkerHeartbeat(string $mediaRoot, array $value): void
{
    $temporary = tempnam($mediaRoot, '.worker-heartbeat-');
    if (!is_string($temporary)) {
        throw new RuntimeException('Worker heartbeat could not be created');
    }
    try {
        $bytes = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        if (HostIo::attempt(fn() => file_put_contents($temporary, $bytes, LOCK_EX))->value === false) {
            throw new RuntimeException('Worker heartbeat could not be written');
        }
        HostIo::required(fn() => chmod($temporary, 0600));
        if (!HostIo::attempt(fn() => rename($temporary, $mediaRoot . '/worker-heartbeat.json'))->value) {
            throw new RuntimeException('Worker heartbeat could not be committed');
        }
    } finally {
        if (is_file($temporary) || is_link($temporary)) {
            HostIo::cleanup(fn() => unlink($temporary));
        }
    }
}

/** Seconds since the previous pass finished, from its heartbeat: how often the host runs the worker. */
function mediaWorkerInterval(string $mediaRoot, int $now): ?int
{
    $path = $mediaRoot . '/worker-heartbeat.json';
    $bytes = is_file($path) ? HostIo::attempt(fn(): string|false => file_get_contents($path, false, null, 0, 65536))->value : false;
    $previous = is_string($bytes) ? json_decode($bytes, true) : null;
    $completed = is_array($previous) && is_string($previous['completedAt'] ?? null)
        ? DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $previous['completedAt'], new DateTimeZone('UTC'))
        : false;
    $interval = $completed === false ? null : $now - $completed->getTimestamp();
    return $interval !== null && $interval >= 1 && $interval <= 86400 ? $interval : null;
}

$options = getopt('', ['config:', 'max-jobs::', 'max-seconds::', 'worker-id::', 'help']);
if (isset($options['help'])) {
    fwrite(STDOUT, <<<'HELP'
Usage:
  php tools/conventional-media-worker.php --config=/private/cms.json \
    [--max-jobs=3] [--max-seconds=240] [--worker-id=cron-host]
HELP);
    fwrite(STDOUT, "\n");
    exit(0);
}

$configPath = getenv('CMS_ACTIVE_CONFIG') ?: (is_string($options['config'] ?? null) ? $options['config'] : '');
$maximumJobs = filter_var($options['max-jobs'] ?? 3, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1, 'max_range' => 10],
]);
$maximumSeconds = filter_var($options['max-seconds'] ?? 240, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1, 'max_range' => 300],
]);
$workerId = is_string($options['worker-id'] ?? null) && $options['worker-id'] !== ''
    ? $options['worker-id']
    : 'cron-' . substr(hash('sha256', gethostname() ?: 'host'), 0, 16);
if ($configPath === '' || !is_int($maximumJobs) || !is_int($maximumSeconds)) {
    fwrite(STDERR, "A private configuration path and bounded worker limits are required.\n");
    exit(64);
}

try {
    $runtimeLease ??= \Utsuri\PortableCms\Conventional\Upgrade\RuntimeLease::guard($configPath, dirname(__DIR__));
    $seconds = static fn(): float => hrtime(true) / 1_000_000_000;
    $workerDeadline = $seconds() + $maximumSeconds;
    $configuration = RuntimeConfiguration::load($configPath);
    $publication = ApplicationFactory::publicationRunner($configuration);
    $publicationFailed = false;
    try {
        $publication?->startScheduled();
    } catch (Throwable) {
        $publicationFailed = true;
    }
    (new \Utsuri\PortableCms\Conventional\Database\MariaDbSiteSequence($configuration->connect()))->prune(new DateTimeImmutable('now', new DateTimeZone('UTC')));
    $residentMemoryAtStart = \Utsuri\PortableCms\Conventional\Media\MediaJobRunner::currentResidentMemoryBytes();
    $mediaRunner = ApplicationFactory::mediaRunner($configuration);
    $previewRunner = ApplicationFactory::previewRunner($configuration);
    $pass = (new \Utsuri\PortableCms\Conventional\MediaPreviewWorker(
        static fn() => $mediaRunner->runOne($workerId),
        static fn() => $previewRunner->runOne($workerId),
        $seconds,
    ))->run($maximumJobs, $workerDeadline);
    $results = $pass['media'];
    $previewResults = $pass['preview'];
    $queued = (new \Utsuri\PortableCms\Conventional\Database\MariaDbWorkerQueue($configuration->connect()))->queued();
    $completed = count(array_filter($results, static fn($result): bool => $result->completed));
    $failed = count($results) - $completed;
    $elapsedMilliseconds = array_sum(array_map(
        static fn($result): int => $result->elapsedMilliseconds,
        $results,
    ));
    $peakPhpMemoryBytes = $results === [] ? memory_get_peak_usage(true) : max(array_map(
        static fn($result): int => $result->peakPhpMemoryBytes,
        $results,
    ));
    $residentMeasurements = array_values(array_filter(array_map(
        static fn($result): ?int => $result->peakResidentMemoryBytes,
        $results,
    ), static fn(?int $value): bool => $value !== null));
    $usage = getrusage();
    $emptyPeakResidentMemory = PHP_OS_FAMILY === 'Linux' && is_int($usage['ru_maxrss'] ?? null)
        ? $usage['ru_maxrss'] * 1024
        : null;
    $residentMemoryAtEnd = \Utsuri\PortableCms\Conventional\Media\MediaJobRunner::currentResidentMemoryBytes();
    $completedAt = time();
    writeMediaWorkerHeartbeat($configuration->mediaRoot, [
        'formatVersion' => 2,
        'completedAt' => gmdate('Y-m-d\TH:i:s\Z', $completedAt),
        'intervalSeconds' => mediaWorkerInterval($configuration->mediaRoot, $completedAt),
        'processed' => count($results),
        'completed' => $completed,
        'failed' => $failed,
        'mediaProcessed' => count($results),
        'mediaCompleted' => $completed,
        'mediaFailed' => $failed,
        'mediaQueued' => $queued['media'],
        'previewQueued' => $queued['preview'],
        'previewProcessed' => count($previewResults),
        'previewCompleted' => count(array_filter(
            $previewResults,
            static fn($result): bool => $result->completed,
        )),
        'previewFailed' => count(array_filter(
            $previewResults,
            static fn($result): bool => !$result->completed,
        )),
        'elapsedMilliseconds' => $elapsedMilliseconds,
        'peakPhpMemoryBytes' => $peakPhpMemoryBytes,
        'peakResidentMemoryBytes' => $residentMeasurements === []
            ? $emptyPeakResidentMemory
            : max($residentMeasurements),
        'residentMemoryAtStartBytes' => $residentMemoryAtStart,
        'residentMemoryAtEndBytes' => $residentMemoryAtEnd,
        'residentMemoryGrowthBytes' => is_int($residentMemoryAtStart) && is_int($residentMemoryAtEnd)
            ? $residentMemoryAtEnd - $residentMemoryAtStart
            : null,
        'mediaRuns' => array_map(
            static fn($result, int $index): array => [
                'sequence' => $index + 1,
                'completed' => $result->completed,
                'elapsedMilliseconds' => $result->elapsedMilliseconds,
                'peakPhpMemoryBytes' => $result->peakPhpMemoryBytes,
                'peakResidentMemoryBytes' => $result->peakResidentMemoryBytes,
                'residentMemoryBeforeBytes' => $result->residentMemoryBeforeBytes,
                'residentMemoryAfterBytes' => $result->residentMemoryAfterBytes,
            ],
            $results,
            array_keys($results),
        ),
    ]);
    $previewFailed = count(array_filter(
        $previewResults,
        static fn($result): bool => !$result->completed,
    ));
    if ($publication !== null && !$publicationFailed) {
        for ($batch = 0; $batch < 32 && $seconds() < $workerDeadline && ($batch === 0 || $publication->runnable()); ++$batch) {
            try {
                $publication->runBatch(8);
            } catch (Throwable) {
                $publicationFailed = true;
                // Recovery remains durable and is resumed by the next bounded invocation.
                break;
            }
        }
    }
    fwrite(STDOUT, sprintf(
        "CMS worker processed %d media job(s) and %d preview job(s): %d failed. Publication: %s.\n",
        count($results),
        count($previewResults),
        $failed + $previewFailed,
        $publicationFailed ? 'failed; recovery or feed delivery pending' : ($publication === null ? 'disabled' : ($publication->runnable() ? 'pending' : ($publication->head()->pendingId === null ? 'idle or settled' : 'needs operator attention'))),
    ));
    exit($failed === 0 && $previewFailed === 0 && !$publicationFailed ? 0 : 1);
} catch (Throwable $error) {
    fwrite(STDERR, 'Media worker failed (' . $error::class . ").\n");
    exit(1);
}
