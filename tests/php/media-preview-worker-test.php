<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Media\MediaRunResult;
use Utsuri\PortableCms\Conventional\MediaPreviewWorker;
use Utsuri\PortableCms\Conventional\Preview\PreviewRunResult;

/** @return array{media: list<MediaRunResult>, preview: list<PreviewRunResult>, claims: list<string>} */
function workerPassFixture(int $mediaJobs, int $previewJobs, int $budget = 3, float $duration = 1.0, bool $completed = true): array
{
    $time = 0.0;
    $claims = [];
    $worker = new MediaPreviewWorker(
        static function () use (&$mediaJobs, &$time, &$claims, $duration, $completed): MediaRunResult {
            $claims[] = 'media';
            $time += $duration;
            $claimed = $mediaJobs-- > 0;
            return new MediaRunResult($claimed, $claimed && $completed, null, null, 0, 0, null, null, null);
        },
        static function () use (&$previewJobs, &$time, &$claims, $duration, $completed): PreviewRunResult {
            $claims[] = 'preview';
            $time += $duration;
            $claimed = $previewJobs-- > 0;
            return new PreviewRunResult($claimed, $claimed && $completed, null, null, null, 0);
        },
        static function () use (&$time): float {
            return $time;
        },
    );
    return [...$worker->run($budget, 10.0), 'claims' => $claims];
}

$tests['worker reserves a preview claim under sustained media load'] = static function (): void {
    for ($pass = 0; $pass < 3; ++$pass) {
        $result = workerPassFixture(100, 100);
        assertSameValue(['preview', 'media', 'media'], $result['claims'], 'every busy pass starts a preview and retains media capacity');
        assertSameValue(1, count($result['preview']), 'one preview consumes the reserved slot');
        assertSameValue(2, count($result['media']), 'both lanes share the three-job cap');
    }
    assertSameValue(['preview'], workerPassFixture(100, 100, 1)['claims'], 'a one-job pass still serves previews first');
};

$tests['worker lends unused slots and stops probing empty lanes'] = static function (): void {
    assertSameValue(3, count(workerPassFixture(100, 0)['media']), 'media uses the whole budget when previews are empty');
    assertSameValue(3, count(workerPassFixture(0, 100)['preview']), 'previews use the whole budget when media is empty');
    $mixed = workerPassFixture(1, 100);
    assertSameValue(1, count($mixed['media']), 'a short media queue drains');
    assertSameValue(2, count($mixed['preview']), 'remaining capacity returns to previews');
    assertSameValue(['preview', 'media'], workerPassFixture(0, 0)['claims'], 'empty queues terminate without spinning');
    assertSameValue(10, count(workerPassFixture(0, 100, 100, 0)['preview']), 'hard job ceiling remains ten');
};

$tests['worker shares one deadline and counts failed claims against the budget'] = static function (): void {
    assertSameValue(['preview'], workerPassFixture(100, 100, 3, 10)['claims'], 'a slow preview prevents another claim at the deadline');
    assertSameValue(['preview', 'media'], workerPassFixture(100, 100, 3, 6)['claims'], 'media does not receive a fresh time budget');
    $failed = workerPassFixture(100, 100, completed: false);
    assertSameValue(['preview', 'media', 'media'], $failed['claims'], 'failures consume slots without preventing the other lane');
    assertSameValue(false, $failed['preview'][0]->completed, 'preview failure remains visible');
    assertSameValue(false, $failed['media'][0]->completed, 'media failure remains visible');
    $never = static fn(): never => throw new RuntimeException('No claim may start after the deadline');
    assertSameValue(['media' => [], 'preview' => []], (new MediaPreviewWorker($never, $never, static fn(): float => 10.0))->run(3, 10.0), 'setup can exhaust the shared budget');
};
