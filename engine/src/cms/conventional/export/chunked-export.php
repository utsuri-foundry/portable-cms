<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Export;

use Utsuri\PortableCms\Conventional\Restore\FilesystemRestoreStore;
use Utsuri\PortableCms\Conventional\Restore\RestoreRejected;

/** Verified JSON-lines chunks. Only current-revision offsets, not body histories, are indexed. */
final class ChunkedExport implements ExportDataset
{
    /** @var array<string, mixed> */
    private readonly array $header;
    /** @var array<string, list<array{sha256: string, byteSize: int, rowCount: int}>> */
    private readonly array $chunks;
    /** @var array<string, int> */
    private array $counts = [];
    /** @var array<string, array{string, int, int, string}> */
    private array $current = [];
    /** @var array<string, true> */
    private array $wanted = [];

    /** @param array<string, mixed> $manifest */
    public function __construct(private readonly FilesystemRestoreStore $store, array $manifest, private readonly ExportBudget $budget = new ExportBudget())
    {
        ExportMemory::chunked(0);
        if (array_diff(array_keys($manifest), ['formatVersion', 'metadata', 'collections']) !== [] || count($manifest) !== 3
            || ($manifest['formatVersion'] ?? null) !== 2 || !is_array($manifest['metadata'] ?? null)
            || !is_array($manifest['collections'] ?? null) || array_diff(ExportCapacity::COLLECTIONS, array_keys($manifest['collections'])) !== []
            || array_diff(array_keys($manifest['collections']), ExportCapacity::COLLECTIONS) !== []) {
            throw new RestoreRejected('invalid_chunked_export_manifest');
        }
        $this->header = $manifest['metadata'];
        // The portable meaning of metadata is exactly v1, with no collections hidden in it.
        $empty = array_fill_keys(ExportCapacity::COLLECTIONS, []);
        if (array_intersect_key($this->header, $empty) !== []) {
            throw new RestoreRejected('invalid_chunked_export_metadata');
        }
        new NormalizedExport($this->header + $empty);
        $seen = [];
        $bytes = $chunkCount = $largestUnit = 0;
        foreach ($manifest['collections'] as $kind => $chunks) {
            if (!is_array($chunks) || !array_is_list($chunks)) {
                throw new RestoreRejected('invalid_chunk_inventory');
            }
            $count = 0;
            foreach ($chunks as $chunk) {
                if (!is_array($chunk) || count($chunk) !== 3 || array_diff(array_keys($chunk), ['sha256', 'byteSize', 'rowCount']) !== []
                    || !is_string($chunk['sha256'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/', $chunk['sha256']) !== 1
                    || isset($seen[$chunk['sha256']]) || !is_int($chunk['byteSize'] ?? null) || $chunk['byteSize'] < 3 || $chunk['byteSize'] > ExportCapacity::CHUNK_BYTES
                    || !is_int($chunk['rowCount'] ?? null) || $chunk['rowCount'] < 1 || $chunk['rowCount'] > ExportCapacity::CHUNK_ROWS) {
                    throw new RestoreRejected('invalid_chunk_inventory');
                }
                $seen[$chunk['sha256']] = true;
                $count += $chunk['rowCount'];
                $bytes += $chunk['byteSize'];
                $largestUnit = max($largestUnit, $chunk['byteSize']);
                if (++$chunkCount > ExportCapacity::MAX_CHUNKS || $bytes > ExportCapacity::PACKAGE_BYTES || $count > ExportCapacity::limit($kind)) {
                    throw new RestoreRejected('chunked_export_capacity_exceeded');
                }
            }
            $this->counts[$kind] = $count;
        }
        ExportMemory::chunked($largestUnit);
        $this->chunks = $manifest['collections'];
        foreach ($this->rows('records') as $record) {
            NormalizedExport::entry('records', $record);
            $this->wanted[$record['currentRevisionId']] = true;
        }
        ExportGraph::validate($this->header, $this->rows(...), ExportCapacity::LIMITS, true);
        $this->wanted = [];
    }

    public function metadata(): array
    {
        return $this->header;
    }

    public function count(string $collection): int
    {
        ExportCapacity::limit($collection);
        return $this->counts[$collection];
    }

    public function rows(string $collection): iterable
    {
        ExportCapacity::limit($collection);
        foreach ($this->chunks[$collection] as $chunk) {
            $this->budget->check();
            $key = 'chunks/' . $chunk['sha256'] . '.jsonl';
            $path = $this->store->path($key);
            $stream = \Utsuri\PortableCms\Conventional\HostIo::attempt(static fn() => fopen($path, 'rb'))->value;
            if (!is_resource($stream)) {
                throw new RestoreRejected('missing_export_chunk');
            }
            try {
                $hash = hash_init('sha256');
                $bytes = $rows = 0;
                while (!feof($stream)) {
                    $this->budget->check();
                    $offset = ftell($stream);
                    $line = \Utsuri\PortableCms\Conventional\HostIo::attempt(static fn() => fgets($stream, ExportCapacity::ROW_BYTES + 2))->value;
                    if ($line === false && feof($stream)) {
                        break;
                    }
                    if (!is_int($offset) || !is_string($line) || !str_ends_with($line, "\n") || strlen($line) > ExportCapacity::ROW_BYTES
                        || ($bytes += strlen($line)) > $chunk['byteSize'] || ++$rows > $chunk['rowCount']) {
                        throw new RestoreRejected('invalid_export_chunk');
                    }
                    hash_update($hash, $line);
                    $row = self::decode($line);
                    if ($collection === 'revisions' && is_string($row['id'] ?? null) && isset($this->wanted[$row['id']])) {
                        $this->current[$row['id']] = [$key, $offset, strlen($line), hash('sha256', $line)];
                    }
                    yield $row;
                }
                if ($bytes !== $chunk['byteSize'] || $rows !== $chunk['rowCount'] || !hash_equals($chunk['sha256'], hash_final($hash))) {
                    throw new RestoreRejected('changed_export_chunk');
                }
            } finally {
                \Utsuri\PortableCms\Conventional\HostIo::cleanup(static fn(): bool => fclose($stream));
            }
        }
    }

    public function revision(string $id): array
    {
        [$key, $offset, $length, $hash] = $this->current[$id] ?? throw new RestoreRejected('missing_current_revision');
        $stream = \Utsuri\PortableCms\Conventional\HostIo::attempt(fn() => fopen($this->store->path($key), 'rb'))->value;
        if (!is_resource($stream)) {
            throw new RestoreRejected('missing_export_chunk');
        }
        try {
            $this->budget->check();
            if (\Utsuri\PortableCms\Conventional\HostIo::attempt(static fn() => fseek($stream, $offset))->value !== 0) {
                throw new RestoreRejected('changed_export_chunk');
            }
            $line = \Utsuri\PortableCms\Conventional\HostIo::attempt(static fn() => fgets($stream, $length + 1))->value;
            if (!is_string($line) || strlen($line) !== $length || !hash_equals($hash, hash('sha256', $line))) {
                throw new RestoreRejected('changed_export_chunk');
            }
            return self::decode($line);
        } finally {
            \Utsuri\PortableCms\Conventional\HostIo::cleanup(static fn(): bool => fclose($stream));
        }
    }

    /** @return array<string, mixed> */
    private static function decode(string $line): array
    {
        try {
            $row = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new RestoreRejected('invalid_export_chunk_json');
        }
        if (!is_array($row) || array_is_list($row)) {
            throw new RestoreRejected('invalid_export_chunk_row');
        }
        return $row;
    }
}
