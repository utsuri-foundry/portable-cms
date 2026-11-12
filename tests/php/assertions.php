<?php

declare(strict_types=1);

function assertSameValue(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf(
            "%s\nExpected: %s\nActual: %s",
            $message,
            var_export($expected, true),
            var_export($actual, true),
        ));
    }
}

function assertTrueValue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
