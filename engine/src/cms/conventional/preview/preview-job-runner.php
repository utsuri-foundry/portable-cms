<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Preview;

use Utsuri\PortableCms\Conventional\Identity\Clock;
use Utsuri\PortableCms\Conventional\Identity\RandomSource;

final readonly class PreviewRunResult
{
    public function __construct(
        public bool $claimed,
        public bool $completed,
        public ?string $jobId,
        public ?string $previewId,
        public ?string $errorCode,
        public int $elapsedMilliseconds,
    ) {}
}

final class PreviewJobRunner
{
    /** @param (callable(array<string, int|string|null>): void)|null $observe */
    public function __construct(
        private readonly PreviewRepository $repository,
        private readonly FilesystemPreviewStore $storage,
        private readonly PreviewCompiler $compiler,
        private readonly Clock $clock,
        private readonly RandomSource $random,
        private readonly mixed $observe = null,
    ) {}

    public function runOne(string $workerId): PreviewRunResult
    {
        $started = hrtime(true);
        $now = $this->clock->now();
        $job = $this->repository->claim(
            $workerId,
            bin2hex($this->random->bytes(32)),
            $now,
            $now->modify('+15 minutes'),
        );
        if ($job === null) {
            return new PreviewRunResult(false, false, null, null, null, self::elapsed($started));
        }
        $this->record(['event' => 'preview_job_claimed', 'jobId' => $job->id, 'previewId' => $job->previewId]);
        $workingPath = null;
        try {
            $snapshot = $this->repository->snapshot($job);
            $workingPath = $this->storage->workingPath($job);
            $build = $this->compiler->compile($snapshot, $workingPath);
            $this->storage->commit($job, $workingPath, $build);
            $this->repository->complete(
                $job,
                $build,
                bin2hex($this->random->bytes(16)),
                $this->clock->now(),
            );
            $elapsed = self::elapsed($started);
            $this->record([
                'event' => 'preview_job_completed',
                'jobId' => $job->id,
                'previewId' => $job->previewId,
                'elapsedMilliseconds' => $elapsed,
            ]);
            return new PreviewRunResult(true, true, $job->id, $job->previewId, null, $elapsed);
        } catch (\Throwable $error) {
            $failure = $error instanceof PreviewBuildFailure
                ? $error
                : new PreviewBuildFailure('preview_build_failed', true, 'Preview build failed.', $error);
            $delay = $job->attempts <= 1 ? 60 : 5 * 60;
            $this->repository->fail(
                $job,
                $failure->codeName,
                $failure->retryable,
                $this->clock->now()->modify('+' . $delay . ' seconds'),
                $this->clock->now(),
                $failure->diagnostics,
            );
            $elapsed = self::elapsed($started);
            $this->record([
                'event' => 'preview_job_failed',
                'jobId' => $job->id,
                'previewId' => $job->previewId,
                'errorCode' => $failure->codeName,
                'elapsedMilliseconds' => $elapsed,
            ]);
            return new PreviewRunResult(
                true,
                false,
                $job->id,
                $job->previewId,
                $failure->codeName,
                $elapsed,
            );
        } finally {
            if ($workingPath !== null) {
                $this->storage->removeWorkingPath($workingPath);
            }
        }
    }

    /** @return list<PreviewRunResult> */
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

    private static function elapsed(int $started): int
    {
        return max(0, (int) round((hrtime(true) - $started) / 1_000_000));
    }

    /** @param array<string, int|string|null> $entry */
    private function record(array $entry): void
    {
        if (is_callable($this->observe)) {
            ($this->observe)($entry);
        }
    }
}
