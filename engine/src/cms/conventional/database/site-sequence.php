<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Database;

/** Integers remain exact in PHP, SQL and JSON consumers. Zero means no sequenced changes. */
final class SiteSequence
{
    public const MAXIMUM = 9_007_199_254_740_991;
    public const HISTORY_LIMIT = 100_000;
    public const RETENTION_DAYS = 30;
    public const PRUNE_BATCH = 1_000;

    public static function value(mixed $value): int
    {
        if (!is_int($value) || $value < 0 || $value > self::MAXIMUM) {
            throw new \InvalidArgumentException('Site sequence is outside its exact integer range');
        }
        return $value;
    }
}
