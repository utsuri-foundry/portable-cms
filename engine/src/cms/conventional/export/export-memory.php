<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Export;

/** Native PHP memory qualification, before constructing a large tree or collection. */
final class ExportMemory
{
    public static function available(): int
    {
        $raw = (string) ini_get('memory_limit');
        if ($raw === '-1') {
            return PHP_INT_MAX;
        }
        if (preg_match('/\A([0-9]+)([KMG]?)\z/i', $raw, $match) !== 1) {
            throw new ExportCapacityFailure('unqualified_php_memory_budget');
        }
        $factor = 1024 ** (int) strpos(' KMG', strtoupper($match[2] ?: ' '));
        if ((int) $match[1] > intdiv(PHP_INT_MAX, $factor)) {
            throw new ExportCapacityFailure('unqualified_php_memory_budget');
        }
        $limit = (int) $match[1] * $factor;
        return max(0, $limit - memory_get_usage(true));
    }

    public static function legacy(int $bodyBytes): void
    {
        if (self::available() < max(67_108_864, $bodyBytes * 16)) {
            throw new ExportCapacityFailure('legacy_export_requires_chunked_memory');
        }
    }

    public static function chunked(int $unitBytes = ExportCapacity::ROW_BYTES): void
    {
        $required = 67_108_864 + min(ExportCapacity::ROW_BYTES, max(0, $unitBytes)) * 64;
        if (self::available() < $required) {
            throw new \Utsuri\PortableCms\Conventional\Restore\RestoreRejected('insufficient_chunked_export_memory_budget');
        }
    }
}
