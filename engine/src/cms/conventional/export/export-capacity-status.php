<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Export;

final class ExportCapacityFailure extends \RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('Export capacity requires the private chunked-package workflow');
    }
}

interface ExportCapacityRepository
{
    public function capacity(): ExportCapacityStatus;
}

/** Advisory live counts. Capture still enforces its own consistent-snapshot bounds. */
final readonly class ExportCapacityStatus
{
    /** @param array{records: int, revisions: int, relationships: int, media: int} $counts */
    public function __construct(public array $counts, public int $revisionBytes, public int $largestRevision, public int $currentBytes, public int $mediaMetadataBytes = 0, public int $derivativeCount = 0)
    {
        if (count($counts) !== 4 || array_diff(array_keys($counts), ExportCapacity::COLLECTIONS) !== []) {
            throw new \InvalidArgumentException('Invalid export capacity collections');
        }
        foreach ([...array_values($counts), $revisionBytes, $largestRevision, $currentBytes, $mediaMetadataBytes, $derivativeCount] as $value) {
            if (!is_int($value) || $value < 0) {
                throw new \InvalidArgumentException('Invalid export capacity facts');
            }
        }
    }

    public function chunkedRequired(): bool
    {
        return $this->counts['records'] > EXPORT_MAX_RECORDS || $this->counts['revisions'] > EXPORT_MAX_REVISIONS
            || $this->counts['relationships'] > EXPORT_MAX_RELATIONSHIPS || $this->counts['media'] > EXPORT_MAX_MEDIA
            || $this->payloadBytes() >= EXPORT_MAX_JSON_BYTES || $this->derivativeCount > EXPORT_MAX_DERIVATIVES;
    }

    /** Raw column sizes qualify source admission; the writer separately checks normalized bytes. */
    public function payloadBytes(): int
    {
        return $this->revisionBytes + $this->mediaMetadataBytes;
    }

    /** Conservative metadata allowance before legacy collection arrays are materialized. */
    public function legacyMemoryBytes(): int
    {
        return $this->payloadBytes() + ExportCapacity::MANIFEST_BYTES
            + ($this->counts['records'] + $this->counts['revisions'] + $this->counts['relationships']) * 1024
            + $this->counts['media'] * 4096 + $this->derivativeCount * 2048;
    }

    public function approaching(): bool
    {
        foreach ($this->counts as $kind => $count) {
            if ($count >= ExportCapacity::limit($kind) * 0.8) {
                return true;
            }
        }
        return $this->payloadBytes() >= ExportCapacity::PACKAGE_BYTES * 0.8
            || $this->derivativeCount >= EXPORT_MAX_DERIVATIVES * 0.8
            || $this->largestRevision >= ExportCapacity::ROW_BYTES * 0.8
            || $this->currentBytes >= ExportCapacity::SNAPSHOT_BYTES * 0.8;
    }

    public function exceeded(): bool
    {
        foreach ($this->counts as $kind => $count) {
            if ($count > ExportCapacity::limit($kind)) {
                return true;
            }
        }
        return $this->payloadBytes() > ExportCapacity::PACKAGE_BYTES || $this->derivativeCount > EXPORT_MAX_DERIVATIVES
            || $this->largestRevision >= ExportCapacity::ROW_BYTES || $this->currentBytes > ExportCapacity::SNAPSHOT_BYTES;
    }
}
