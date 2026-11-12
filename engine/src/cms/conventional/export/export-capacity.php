<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Export;

/** Independent package limits; the legacy in-memory JSON envelope is not widened. */
final class ExportCapacity
{
    public const COLLECTIONS = ['records', 'revisions', 'relationships', 'media'];
    public const LIMITS = ['records' => 2_000, 'revisions' => 20_000, 'relationships' => 32_000, 'media' => 5_000];
    public const ROW_BYTES = 1_048_576;
    public const CHUNK_BYTES = 2_097_152;
    public const CHUNK_ROWS = 128;
    public const PACKAGE_BYTES = 536_870_912;
    public const MANIFEST_BYTES = 1_048_576;
    public const MAX_CHUNKS = 4096;
    public const SNAPSHOT_BYTES = 4_194_304;

    public static function limit(string $collection): int
    {
        return self::LIMITS[$collection] ?? throw new \InvalidArgumentException('Unknown export collection');
    }

    /** Source admission only: do not reinterpret the legacy reader's contract. */
    public static function requireLegacyRevision(mixed $revision): void
    {
        try {
            NormalizedExport::entry('revisions', $revision);
        } catch (\InvalidArgumentException) {
            // Only a row accepted by v2 is an expected legacy transport limitation.
            // Malformed metadata or a body beyond both formats still fails validation.
            NormalizedExport::entry('revisions', $revision, true);
            throw new ExportCapacityFailure('legacy_export_revision_requires_private_package');
        }
    }
}
