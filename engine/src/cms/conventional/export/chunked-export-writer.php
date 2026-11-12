<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Export;

use Utsuri\PortableCms\Conventional\HostIo;
use Utsuri\PortableCms\Conventional\Restore\FilesystemRestoreStore;
use Utsuri\PortableCms\Conventional\Restore\RestoreRejected;

/** Finite immutable chunks first; capture and complete-package markers are distinct. */
final readonly class ChunkedExportWriter
{
    public function __construct(private FilesystemRestoreStore $store, private ExportBudget $budget = new ExportBudget()) {}

    /** @param array<string, mixed> $metadata
     * @param \Closure(string): iterable<array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    public function capture(array $metadata, \Closure $rows): array
    {
        foreach (new \FilesystemIterator($this->store->root) as $entry) {
            if ($entry->getFilename() !== 'restore.lock' || !$entry->isFile() || $entry->isLink()) {
                throw new RestoreRejected('capture_requires_empty_private_directory');
            }
        }
        HostIo::required(fn(): bool => chmod($this->store->root, 0700));
        HostIo::required(fn(): bool => mkdir($this->store->path('chunks'), 0700));
        $collections = [];
        $total = $chunkCount = 0;
        foreach (ExportCapacity::COLLECTIONS as $kind) {
            $chunks = [];
            $buffer = '';
            $count = $bufferRows = 0;
            foreach ($rows($kind) as $row) {
                $this->budget->check();
                NormalizedExport::entry($kind, $row, true);
                $line = json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
                if (++$count > ExportCapacity::limit($kind) || strlen($line) > ExportCapacity::ROW_BYTES) {
                    throw new RestoreRejected('export_row_or_collection_capacity_exceeded');
                }
                if ($buffer !== '' && (strlen($buffer) + strlen($line) > ExportCapacity::CHUNK_BYTES || $bufferRows === ExportCapacity::CHUNK_ROWS)) {
                    $chunks[] = $this->chunk($buffer, $bufferRows);
                    $buffer = '';
                    $bufferRows = 0;
                    if (++$chunkCount > ExportCapacity::MAX_CHUNKS) {
                        throw new RestoreRejected('export_chunk_capacity_exceeded');
                    }
                }
                $buffer .= $line;
                $bufferRows++;
                $total += strlen($line);
                if ($total > ExportCapacity::PACKAGE_BYTES) {
                    throw new RestoreRejected('export_byte_capacity_exceeded');
                }
            }
            if ($buffer !== '') {
                $chunks[] = $this->chunk($buffer, $bufferRows);
                if (++$chunkCount > ExportCapacity::MAX_CHUNKS) {
                    throw new RestoreRejected('export_chunk_capacity_exceeded');
                }
            }
            $collections[$kind] = $chunks;
        }
        $manifest = ['formatVersion' => 2, 'metadata' => $metadata, 'collections' => $collections];
        if (strlen(self::json($manifest)) > ExportCapacity::MANIFEST_BYTES) {
            throw new RestoreRejected('export_manifest_capacity_exceeded');
        }
        // Complete graph checks precede the source audit commit and capture marker.
        new ChunkedExport($this->store, $manifest, $this->budget);
        return $manifest;
    }

    /** Written only after the attributed source capture transaction commits.
     * @param array<string, mixed> $manifest
     */
    public function sealCapture(array $manifest): void
    {
        $temporary = $this->store->path('capture.partial');
        HostIo::createPrivateFile($temporary, self::json($manifest));
        HostIo::required(fn(): bool => link($temporary, $this->store->path('capture.json')));
        HostIo::required(static fn(): bool => unlink($temporary));
    }

    /** @return array{sha256: string, byteSize: int, rowCount: int} */
    private function chunk(string $bytes, int $rows): array
    {
        $hash = hash('sha256', $bytes);
        HostIo::createPrivateFile($this->store->path('chunks/' . $hash . '.jsonl'), $bytes);
        return ['sha256' => $hash, 'byteSize' => strlen($bytes), 'rowCount' => $rows];
    }

    /** @param array<string, mixed> $manifest */
    private static function json(array $manifest): string
    {
        return json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    }
}
