<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Export;

/** Repeatable content reads, without requiring retained revision bodies in memory. */
interface ExportDataset
{
    /** @return array<string, mixed> Portable metadata, without the four collections. */
    public function metadata(): array;

    /** @return iterable<array<string, mixed>> */
    public function rows(string $collection): iterable;

    public function count(string $collection): int;

    /** @return array<string, mixed> The named current revision, never an invented body. */
    public function revision(string $id): array;
}
