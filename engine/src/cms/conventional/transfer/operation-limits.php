<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Transfer;

use Utsuri\PortableCms\Conventional\Restore\RestoreRejected;

final readonly class OperationLimits
{
    public function __construct(public int $seconds = 120, public int $batches = 25032, public int $files = 64)
    {
        if ($seconds < 1 || $seconds > 600 || $batches < 1 || $batches > 25032 || $files < 1 || $files > 64) {
            throw new RestoreRejected('invalid_operation_limits');
        }
    }

    /** @param array<string, mixed> $options */
    public static function fromOptions(array $options): self
    {
        $values = [];
        foreach (['maximum-seconds' => 120, 'maximum-batches' => 25032, 'maximum-files' => 64] as $key => $default) {
            $value = filter_var($options[$key] ?? (string) $default, FILTER_VALIDATE_INT);
            if (!is_int($value)) {
                throw new RestoreRejected('invalid_operation_limits');
            }
            $values[] = $value;
        }
        return new self(...$values);
    }

    public static function incomplete(\Throwable $error): bool
    {
        return $error instanceof RestoreRejected && in_array($error->reason, ['export_runtime_budget_exhausted', 'runtime_budget_exhausted_resume_required'], true);
    }
}
