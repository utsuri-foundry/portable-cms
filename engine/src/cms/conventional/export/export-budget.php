<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Export;

use Utsuri\PortableCms\Conventional\Identity\Clock;
use Utsuri\PortableCms\Conventional\Identity\SystemClock;

/** One invocation, not a promise that a partial package is complete. */
final readonly class ExportBudget
{
    private \DateTimeImmutable $deadline;

    public function __construct(private Clock $clock = new SystemClock(), int $seconds = 120)
    {
        if ($seconds < 1 || $seconds > 600) {
            throw new \InvalidArgumentException('Invalid export runtime budget');
        }
        $this->deadline = $clock->now()->modify('+' . $seconds . ' seconds');
    }

    public function check(): void
    {
        if ($this->clock->now() >= $this->deadline) {
            throw new \Utsuri\PortableCms\Conventional\Restore\RestoreRejected('export_runtime_budget_exhausted');
        }
    }
}
