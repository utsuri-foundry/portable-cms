<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Media;

use Utsuri\PortableCms\Conventional\HostIo;
use Utsuri\PortableCms\Conventional\Identity\Clock;
use Utsuri\PortableCms\Conventional\Identity\RandomSource;

final readonly class MediaRunResult
{
    public function __construct(
        public bool $claimed,
        public bool $completed,
        public ?string $jobId,
        public ?string $errorCode,
        public int $elapsedMilliseconds,
        public int $peakPhpMemoryBytes,
        public ?int $peakResidentMemoryBytes,
        public ?int $residentMemoryBeforeBytes,
        public ?int $residentMemoryAfterBytes,
    ) {}
}

final class MediaJobRunner
{
    /** @param (callable(array<string, int|string|null>): void)|null $observe */
    public function __construct(
        private readonly MediaJobRepository $repository,
        private readonly FilesystemMediaStore $storage,
        private readonly MediaProcessor $processor,
        private readonly Clock $clock,
        private readonly RandomSource $random,
        private readonly mixed $observe = null,
    ) {}

    public function runOne(string $workerId): MediaRunResult
    {
        $started = hrtime(true);
        $residentMemoryBefore = self::currentResidentMemoryBytes();
        if (function_exists('memory_reset_peak_usage')) {
            memory_reset_peak_usage();
        }
        $now = $this->clock->now();
        $job = $this->repository->claim(
            $workerId,
            bin2hex($this->random->bytes(32)),
            $now,
            $now->modify('+15 minutes'),
        );
        if ($job === null) {
            [$elapsed, $peakPhpMemory, $peakResidentMemory, $residentMemoryAfter] = self::measure($started);
            return new MediaRunResult(
                false,
                false,
                null,
                null,
                $elapsed,
                $peakPhpMemory,
                $peakResidentMemory,
                $residentMemoryBefore,
                $residentMemoryAfter,
            );
        }
        $this->record(['event' => 'media_job_claimed', 'jobId' => $job->id, 'attempt' => $job->attempts]);
        $workingDirectory = null;
        try {
            $source = $this->repository->source($job);
            $sourcePath = $this->storage->sourcePath($source);
            $workingDirectory = $this->storage->workingDirectory($job);
            $processed = $this->processor->process($source, $sourcePath, $workingDirectory);
            $temporary = $workingDirectory . '/' . $processed->derivative->filename . '.tmp';
            $this->storage->commitDerivative($processed->derivative, $temporary);
            $this->repository->complete(
                $job,
                $processed,
                bin2hex($this->random->bytes(16)),
                $this->clock->now(),
            );
            [$elapsed, $peakPhpMemory, $peakResidentMemory, $residentMemoryAfter] = self::measure($started);
            $this->record([
                'event' => 'media_job_completed',
                'jobId' => $job->id,
                'attempt' => $job->attempts,
                'elapsedMilliseconds' => $elapsed,
                'peakPhpMemoryBytes' => $peakPhpMemory,
                'peakResidentMemoryBytes' => $peakResidentMemory,
                'residentMemoryBeforeBytes' => $residentMemoryBefore,
                'residentMemoryAfterBytes' => $residentMemoryAfter,
            ]);
            return new MediaRunResult(
                true,
                true,
                $job->id,
                null,
                $elapsed,
                $peakPhpMemory,
                $peakResidentMemory,
                $residentMemoryBefore,
                $residentMemoryAfter,
            );
        } catch (\Throwable $error) {
            $failure = $error instanceof MediaProcessingFailure
                ? $error
                : new MediaProcessingFailure('processing_failed', true, 'Media processing failed.', $error);
            $delay = $job->attempts <= 1 ? 60 : 5 * 60;
            try {
                $this->repository->fail(
                    $job,
                    $failure->codeName,
                    $failure->retryable,
                    $this->clock->now()->modify('+' . $delay . ' seconds'),
                    $this->clock->now(),
                );
            } catch (\Throwable $reportError) {
                $this->record([
                    'event' => 'media_job_failure_report_failed',
                    'jobId' => $job->id,
                    'error' => $reportError::class,
                ]);
                throw $reportError;
            }
            [$elapsed, $peakPhpMemory, $peakResidentMemory, $residentMemoryAfter] = self::measure($started);
            $this->record([
                'event' => 'media_job_failed',
                'jobId' => $job->id,
                'attempt' => $job->attempts,
                'errorCode' => $failure->codeName,
                'elapsedMilliseconds' => $elapsed,
                'peakPhpMemoryBytes' => $peakPhpMemory,
                'peakResidentMemoryBytes' => $peakResidentMemory,
                'residentMemoryBeforeBytes' => $residentMemoryBefore,
                'residentMemoryAfterBytes' => $residentMemoryAfter,
            ]);
            return new MediaRunResult(
                true,
                false,
                $job->id,
                $failure->codeName,
                $elapsed,
                $peakPhpMemory,
                $peakResidentMemory,
                $residentMemoryBefore,
                $residentMemoryAfter,
            );
        } finally {
            if ($workingDirectory !== null) {
                $this->storage->removeWorkingDirectory($workingDirectory);
            }
        }
    }

    /** @return list<MediaRunResult> */
    public function runBounded(string $workerId, int $maximumJobs, int $maximumSeconds): array
    {
        $maximumJobs = max(1, min(10, $maximumJobs));
        $maximumSeconds = max(1, min(300, $maximumSeconds));
        $started = hrtime(true);
        $results = [];
        while (count($results) < $maximumJobs) {
            if ((hrtime(true) - $started) / 1_000_000_000 >= $maximumSeconds) {
                break;
            }
            $result = $this->runOne($workerId);
            if (!$result->claimed) {
                break;
            }
            $results[] = $result;
        }
        return $results;
    }

    /** @param array<string, int|string|null> $entry */
    private function record(array $entry): void
    {
        if (is_callable($this->observe)) {
            ($this->observe)($entry);
        }
    }

    public static function currentResidentMemoryBytes(): ?int
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            return null;
        }
        $status = HostIo::attempt(fn() => file_get_contents('/proc/self/status'))->value;
        if (
            !is_string($status)
            || preg_match('/^VmRSS:\s+([0-9]+)\s+kB$/m', $status, $matches) !== 1
        ) {
            return null;
        }
        $kilobytes = filter_var($matches[1], FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => intdiv(PHP_INT_MAX, 1024)],
        ]);
        return is_int($kilobytes) ? $kilobytes * 1024 : null;
    }

    /** @return array{int, int, int|null, int|null} */
    private static function measure(int $started): array
    {
        $usage = getrusage();
        $maximumResidentKilobytes = $usage['ru_maxrss'] ?? null;
        return [
            max(0, (int) round((hrtime(true) - $started) / 1_000_000)),
            memory_get_peak_usage(true),
            PHP_OS_FAMILY === 'Linux' && is_int($maximumResidentKilobytes)
                ? $maximumResidentKilobytes * 1024
                : null,
            self::currentResidentMemoryBytes(),
        ];
    }
}
