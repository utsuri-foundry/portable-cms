<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Restore;

use Utsuri\PortableCms\Conventional\Design\Woff2;
use Utsuri\PortableCms\Conventional\Export\NormalizedExport;
use Utsuri\PortableCms\Conventional\JsonSchemaValidator;

final readonly class RestoreInput
{
    public function __construct(public \Utsuri\PortableCms\Conventional\Export\ExportDataset $export, public string $sha256) {}
}

/** Flat checksum inventory. Files are streamed, never extracted or executed. */
final class FilesystemRestoreStore
{
    private readonly \DateTimeImmutable $deadline;

    public function __construct(public readonly string $root, private readonly \Utsuri\PortableCms\Conventional\Identity\Clock $clock = new \Utsuri\PortableCms\Conventional\Identity\SystemClock(), int $maximumSeconds = 180)
    {
        if ($root === '' || $root === '/' || realpath($root) !== $root || !is_dir($root) || is_link($root)) {
            throw new RestoreRejected('unsafe_storage_root');
        }
        if ($maximumSeconds < 1 || $maximumSeconds > 600) {
            throw new RestoreRejected('invalid_runtime_budget');
        }
        $this->deadline = $clock->now()->modify('+' . $maximumSeconds . ' seconds');
    }

    public function export(string $schemaPath, string $key = 'export.json'): \Utsuri\PortableCms\Conventional\Export\ExportDataset
    {
        return $this->load($schemaPath, $key)->export;
    }

    /** Digest and validated document come from the same bounded read. */
    public function load(string $schemaPath, string $key = 'export.json'): RestoreInput
    {
        $path = $this->path($key);
        if (!is_file($path)) {
            throw new RestoreRejected('export_unreadable');
        }
        $size = filesize($path);
        if (!is_int($size) || $size < 2 || $size > 33_554_432) {
            throw new RestoreRejected('export_size_limit');
        }
        $memoryLimit = (string) ini_get('memory_limit');
        if ($memoryLimit !== '-1' && preg_match('/\A([0-9]+)([KMG]?)\z/i', $memoryLimit, $match) === 1) {
            $limit = (int) $match[1] * (1024 ** (int) strpos(' KMG', strtoupper($match[2] ?: ' ')));
            if ($limit - memory_get_usage(true) < max(67_108_864, $size * 16)) {
                throw new RestoreRejected('insufficient_export_memory_budget');
            }
        }
        if ($size <= \Utsuri\PortableCms\Conventional\Export\ExportCapacity::MANIFEST_BYTES) {
            $bytes = file_get_contents($path);
            if (!is_string($bytes) || strlen($bytes) !== $size) {
                throw new RestoreRejected('export_unreadable');
            }
            try {
                $manifest = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new RestoreRejected('invalid_export_contract');
            }
            if (is_array($manifest) && ($manifest['formatVersion'] ?? null) === 2) {
                $schema = file_get_contents(dirname($schemaPath) . '/portable-cms-export-v2.schema.json');
                if (!is_string($schema)) {
                    throw new RestoreRejected('export_schema_unreadable');
                }
                try {
                    JsonSchemaValidator::validateJson($bytes, $schema);
                    return new RestoreInput(new \Utsuri\PortableCms\Conventional\Export\ChunkedExport($this, $manifest), hash('sha256', $bytes));
                } catch (\InvalidArgumentException) {
                    throw new RestoreRejected('invalid_chunked_export_contract_or_graph');
                }
            }
        }
        $json = file_get_contents($path);
        $schema = file_get_contents($schemaPath);
        if (!is_string($json) || strlen($json) !== $size || !is_string($schema)) {
            throw new RestoreRejected('export_unreadable');
        }
        try {
            JsonSchemaValidator::validateExportJson($json, $schema);
            $document = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($document)) {
                throw new \InvalidArgumentException('Invalid root');
            }
            return new RestoreInput(new NormalizedExport($document), hash('sha256', $json));
        } catch (\InvalidArgumentException | \JsonException) {
            throw new RestoreRejected('invalid_export_contract');
        }
    }

    public function exportChecksum(string $key = 'export.json'): string
    {
        $path = $this->path($key);
        if (!is_file($path)) {
            throw new RestoreRejected('export_unreadable');
        }
        $hash = hash_file('sha256', $path);
        return is_string($hash) ? $hash : throw new RestoreRejected('export_unreadable');
    }

    public function assertUnoccupied(RestorePlan $plan): void
    {
        if (!function_exists('link') || !is_writable($this->root)) {
            throw new RestoreRejected('private_no_replace_commit_unavailable');
        }
        foreach (['originals', 'derivatives', 'fonts', 'restore-stage'] as $namespace) {
            $path = $this->path($namespace);
            if (is_dir($path)) {
                $this->emptyNamespace($path, 0);
            } elseif (file_exists($path)) {
                throw new RestoreRejected('destination_files_not_empty');
            }
        }
        foreach ($plan->files as $file) {
            if (file_exists($this->path($file->key))) {
                throw new RestoreRejected('destination_files_not_empty');
            }
        }
    }

    private function emptyNamespace(string $path, int $depth): void
    {
        $this->budget();
        if ($depth > 3) {
            throw new RestoreRejected('destination_files_not_empty');
        }
        foreach (new \FilesystemIterator($path) as $entry) {
            if ($entry->isLink()) {
                throw new RestoreRejected('unsafe_blob_path');
            }
            if ($entry->isDir()) {
                $this->emptyNamespace($entry->getPathname(), $depth + 1);
            } elseif ($path !== $this->root . '/fonts' || $entry->getFilename() !== 'font-store.lock' || !$entry->isFile()) {
                throw new RestoreRejected('destination_files_not_empty');
            }
        }
    }

    public function assertOutside(string $documentRoot): void
    {
        if (realpath($documentRoot) !== $documentRoot || !is_dir($documentRoot) || $this->root === $documentRoot || str_starts_with($this->root, $documentRoot . '/')) {
            throw new RestoreRejected('storage_must_be_outside_document_root');
        }
    }

    public function assertExport(RestorePlan $plan): void
    {
        $path = $this->path('export.json');
        if (!is_file($path) || filesize($path) > 33_554_432) {
            throw new RestoreRejected('export_changed');
        }
        $json = file_get_contents($path);
        if (!is_string($json) || !hash_equals($plan->sha256, hash('sha256', $json))) {
            throw new RestoreRejected('export_changed');
        }
    }

    /** @return list<array{sha256: string, reason: string}> */
    public function inventory(RestorePlan $plan): array
    {
        $issues = [];
        foreach ($plan->files as $file) {
            try {
                $this->verify('blobs/' . $file->sha256, $file);
            } catch (RestoreRejected $error) {
                $issues[] = ['sha256' => $file->sha256, 'reason' => $error->reason];
                if (count($issues) >= 32) {
                    break;
                }
            }
        }
        if ($issues === []) {
            try {
                $this->assertVideoFields($plan->export, $plan->package->manifest);
            } catch (\Utsuri\PortableCms\Conventional\I18n\InvalidInput $error) {
                $issues[] = ['sha256' => $plan->sha256, 'reason' => $error->getMessage()];
            }
        }
        return $issues;
    }

    /** @return array{width: int, height: int, durationMilliseconds: int, codec: string, hasAudio: bool} */
    public function videoFacts(RestoreFile $file): array
    {
        $path = $this->verify('blobs/' . $file->sha256, $file);
        return \Utsuri\PortableCms\Conventional\Media\PreencodedVideoProcessor::inspectFile($path, $file->mime, $file->byteSize);
    }

    /** Field validation uses actual checksum-verified bytes, including legacy exports without audio metadata. */
    public function assertVideoFields(\Utsuri\PortableCms\Conventional\Export\ExportDataset $export, \Utsuri\PortableCms\Conventional\Content\ProfileManifest $profile): void
    {
        $media = [];
        $hasVideo = false;
        foreach ($export->rows('media') as $item) {
            // Retain reference facts, not potentially large text and derivative inventories.
            $media[$item['id']] = ['detectedType' => $item['detectedType'], 'original' => $item['original']];
            $hasVideo = $hasVideo || str_starts_with($item['original']['mime'], 'video/');
        }
        if (!$hasVideo) {
            return;
        }
        $metadata = $export->metadata();
        $languages = isset($metadata['siteLanguages'])
            ? \Utsuri\PortableCms\Conventional\Content\SiteLanguages::from($metadata['siteLanguages'], $profile)
            : \Utsuri\PortableCms\Conventional\Content\SiteLanguages::defaults($profile);
        $profile = $languages->installed($profile);
        $records = [];
        foreach ($export->rows('records') as $record) {
            $records[$record['id']] = $record['typeId'];
        }
        $facts = [];
        foreach ($export->rows('revisions') as $revision) {
            $this->budget();
            $type = $profile->type($records[$revision['recordId']] ?? '');
            if ($type === null) {
                continue;
            }
            \Utsuri\PortableCms\Conventional\Content\VideoReference::assertGraph(
                $type->fields,
                $revision['body'],
                $profile->locales,
                static fn(string $id): ?string => $media[$id]['detectedType'] ?? null,
                static fn(string $id): ?int => $media[$id]['original']['byteSize'] ?? null,
                function (string $id) use ($media, &$facts): array {
                    $original = $media[$id]['original'];
                    return $facts[$id] ??= $this->videoFacts(new RestoreFile('source', $original['sha256'], $original['byteSize'], $original['mime']));
                },
            );
        }
    }

    public function verify(string $key, RestoreFile $file): string
    {
        $this->budget();
        $path = $this->path($key);
        clearstatcache(true, $path);
        if (!is_file($path) || filesize($path) !== $file->byteSize || hash_file('sha256', $path) !== $file->sha256) {
            throw new RestoreRejected('missing_or_changed_blob');
        }
        if ($file->mime === 'font/woff2') {
            $bytes = file_get_contents($path);
            if (!is_string($bytes)) {
                throw new RestoreRejected('font_unreadable');
            }
            try {
                Woff2::verify($bytes, true);
            } catch (\InvalidArgumentException) {
                throw new RestoreRejected('invalid_sanitized_font');
            }
        } elseif (str_starts_with($file->mime, 'video/')) {
            try {
                $facts = \Utsuri\PortableCms\Conventional\Media\PreencodedVideoProcessor::inspectFile($path, $file->mime, $file->byteSize);
                if (($file->width !== null && $facts['width'] !== $file->width) || ($file->height !== null && $facts['height'] !== $file->height)) {
                    throw new RestoreRejected('blob_video_geometry_mismatch');
                }
                if (($file->durationMilliseconds !== null && $facts['durationMilliseconds'] !== $file->durationMilliseconds) || ($file->hasAudio !== null && $facts['hasAudio'] !== $file->hasAudio)) {
                    throw new RestoreRejected('blob_video_metadata_mismatch');
                }
            } catch (\Utsuri\PortableCms\Conventional\Media\MediaProcessingFailure) {
                throw new RestoreRejected('invalid_preencoded_video');
            }
        } elseif ((new \finfo(FILEINFO_MIME_TYPE))->file($path) !== $file->mime) {
            throw new RestoreRejected('blob_mime_mismatch');
        } elseif (str_starts_with($file->mime, 'image/')) {
            $geometry = getimagesize($path);
            if ($geometry === false || ($file->width !== null && ($geometry[0] !== $file->width || $geometry[1] !== $file->height))) {
                throw new RestoreRejected('blob_geometry_mismatch');
            }
        }
        return $path;
    }

    /** Verified raster facts enter import planning through this filesystem/image boundary.
     * @return array{width: int, height: int}
     */
    public function imageGeometry(string $key, RestoreFile $file): array
    {
        $path = $this->verify($key, $file);
        if (!str_starts_with($file->mime, 'image/')) {
            throw new RestoreRejected('raster_original_required');
        }
        $geometry = \Utsuri\PortableCms\Conventional\HostIo::attempt(fn() => getimagesize($path))->value;
        if (!is_array($geometry)) {
            throw new RestoreRejected('blob_geometry_mismatch');
        }
        return ['width' => $geometry[0], 'height' => $geometry[1]];
    }

    /** A private root-wide lock protects the journal/filesystem recovery boundary. */
    public function exclusive(callable $operation): mixed
    {
        $path = $this->path('restore.lock');
        $handle = fopen($path, 'c+b');
        if ($handle === false) {
            throw new RestoreRejected('lock_unavailable');
        }
        try {
            if (!chmod($path, 0600)) {
                throw new RestoreRejected('private_file_permissions_failed');
            }
            if (!flock($handle, LOCK_EX | LOCK_NB)) {
                throw new RestoreRejected('restore_busy');
            }
            return $operation();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @return array{prepared: int, complete: bool} */
    public function stage(RestorePlan $plan, self $inventory, int $maximumFiles, bool $checkpointed = false): array
    {
        if ($maximumFiles < 1 || $maximumFiles > 64) {
            throw new RestoreRejected('invalid_stage_limit');
        }
        if ($checkpointed || $plan->export instanceof \Utsuri\PortableCms\Conventional\Export\ChunkedExport) {
            return $this->stageChunked($plan, $inventory, $maximumFiles);
        }
        $staged = 0;
        $prepared = 0;
        foreach ($plan->files as $file) {
            $this->budget();
            $target = $this->path($file->key);
            if (is_file($target)) {
                $this->verify($file->key, $file);
                $prepared++;
                continue;
            }
            if ($staged === $maximumFiles) {
                continue;
            }
            $source = $inventory->verify('blobs/' . $file->sha256, $file);
            $stageKey = 'restore-stage/' . $plan->export->metadata()['exportId'] . '/' . hash('sha256', $file->key);
            $this->directories(dirname($stageKey));
            $temporary = $this->path($stageKey);
            // This partial file belongs exclusively to the matching durable restore.
            if (is_file($temporary) && !unlink($temporary)) {
                throw new RestoreRejected('stage_recovery_failed');
            }
            $this->copyVerified($source, $temporary, $file);
            $this->directories(dirname($file->key));
            if (!link($temporary, $target)) {
                throw new RestoreRejected('file_commit_failed');
            }
            if (!unlink($temporary)) {
                throw new RestoreRejected('stage_cleanup_failed');
            }
            $this->verify($file->key, $file);
            $prepared++;
            $staged++;
        }
        return ['prepared' => $prepared, 'complete' => $prepared === count($plan->files)];
    }

    /** @return array{prepared: int, complete: bool} */
    private function stageChunked(RestorePlan $plan, self $inventory, int $maximumFiles): array
    {
        $prefix = 'restore-stage/' . $plan->export->metadata()['exportId'];
        $progress = new FilesystemBatchCursor($this, $prefix . '/files.json', $plan->sha256, count($plan->files));
        $next = $progress->read();
        $work = 0;
        while ($next < count($plan->files) && $work++ < $maximumFiles) {
            $file = $plan->files[$next];
            $target = $this->path($file->key);
            if (!is_file($target)) {
                $source = $inventory->verify('blobs/' . $file->sha256, $file);
                $this->directories($prefix);
                $temporary = $this->path($prefix . '/' . hash('sha256', $file->key));
                if (is_file($temporary) && !unlink($temporary)) {
                    throw new RestoreRejected('stage_recovery_failed');
                }
                $this->copyVerified($source, $temporary, $file);
                $this->directories(dirname($file->key));
                if (!link($temporary, $target) || !unlink($temporary)) {
                    throw new RestoreRejected('file_commit_failed');
                }
            }
            $this->verify($file->key, $file);
            $progress->advance(++$next);
        }
        if ($next === count($plan->files)) {
            // A cursor never authorizes bytes: recheck the complete retained destination before SQL.
            foreach ($plan->files as $index => $file) {
                if (!is_file($this->path($file->key))) {
                    $progress->advance($index);
                }
                $this->verify($file->key, $file);
            }
        }
        return ['prepared' => $next, 'complete' => $next === count($plan->files)];
    }

    /**
     * Producer for a fresh operator-selected private package, not a public download.
     * @param (\Closure(RestoreFile): string)|null $sourceKey Maps canonical restore keys to private source storage keys.
     */
    public function package(RestorePlan $plan, self $source, ?\Closure $sourceKey = null): void
    {
        if ((new \FilesystemIterator($this->root))->valid()) {
            throw new RestoreRejected('package_destination_not_empty');
        }
        $this->directories('blobs');
        $seen = [];
        foreach ($plan->files as $file) {
            $path = $source->verify($sourceKey === null ? $file->key : $sourceKey($file), $file);
            if (!isset($seen[$file->sha256])) {
                $this->copyVerified($path, $this->path('blobs/' . $file->sha256), $file);
                $seen[$file->sha256] = true;
            }
        }
        if (!$plan->export instanceof NormalizedExport) {
            throw new RestoreRejected('chunked_package_requires_capture_workflow');
        }
        $json = $plan->export->json();
        $handle = fopen($this->path('export.json'), 'x+b');
        if ($handle === false) {
            throw new RestoreRejected('package_commit_failed');
        }
        try {
            if (!chmod($this->path('export.json'), 0600)) {
                throw new RestoreRejected('private_file_permissions_failed');
            }
            if (fwrite($handle, $json) !== strlen($json) || !fflush($handle) || !fsync($handle)) {
                throw new RestoreRejected('package_commit_failed');
            }
        } finally {
            fclose($handle);
        }
    }

    private function copyVerified(string $source, string $destination, RestoreFile $file): void
    {
        $input = fopen($source, 'rb');
        if ($input === false) {
            throw new RestoreRejected('blob_unreadable');
        }
        $output = fopen($destination, 'x+b');
        if ($output === false) {
            fclose($input);
            throw new RestoreRejected('stage_unavailable');
        }
        try {
            if (!chmod($destination, 0600)) {
                throw new RestoreRejected('private_file_permissions_failed');
            }
            $hash = hash_init('sha256');
            $count = 0;
            while (!feof($input)) {
                $this->budget();
                $chunk = fread($input, 131072);
                if ($chunk === false) {
                    throw new RestoreRejected('blob_read_failed');
                }
                $count += strlen($chunk);
                if ($count > $file->byteSize || fwrite($output, $chunk) !== strlen($chunk)) {
                    throw new RestoreRejected('blob_copy_failed');
                }
                hash_update($hash, $chunk);
            }
            if ($count !== $file->byteSize || hash_final($hash) !== $file->sha256 || !fflush($output) || !fsync($output)) {
                throw new RestoreRejected('blob_changed_during_copy');
            }
        } finally {
            fclose($input);
            fclose($output);
        }
    }

    /** Bounded recoverable producer; immutable checksum names never overwrite a different blob. */
    public function copyPackageBlob(string $source, RestoreFile $file): void
    {
        $this->directories('blobs');
        $temporary = $this->path('blobs/' . $file->sha256 . '.partial');
        $target = $this->path('blobs/' . $file->sha256);
        if (is_file($target)) {
            $this->verify('blobs/' . $file->sha256, $file);
            return;
        }
        if (is_file($temporary) && !unlink($temporary)) {
            throw new RestoreRejected('package_stage_recovery_failed');
        }
        $this->copyVerified($source, $temporary, $file);
        if (!link($temporary, $target) || !unlink($temporary)) {
            throw new RestoreRejected('package_blob_commit_failed');
        }
        $this->verify('blobs/' . $file->sha256, $file);
    }

    /** Reject symlinks in every component, including not-yet-created paths. */
    public function path(string $key): string
    {
        if (is_link($this->root) || realpath($this->root) !== $this->root) {
            throw new RestoreRejected('unsafe_storage_root');
        }
        if (strlen($key) > 255 || preg_match('/\A[a-zA-Z0-9_.-]+(?:\/[a-zA-Z0-9_.-]+)*\z/', $key) !== 1) {
            throw new RestoreRejected('unsafe_blob_path');
        }
        $path = $this->root;
        foreach (explode('/', $key) as $component) {
            $path .= '/' . $component;
            if ($component === '.' || $component === '..' || is_link($path) || (file_exists($path) && !is_file($path) && !is_dir($path))) {
                throw new RestoreRejected('unsafe_blob_path');
            }
        }
        return $path;
    }

    public function directories(string $key): void
    {
        if ($key === '.') {
            return;
        }
        $prefix = '';
        foreach (explode('/', $key) as $component) {
            $prefix = $prefix === '' ? $component : $prefix . '/' . $component;
            $path = $this->path($prefix);
            if (!is_dir($path) && !mkdir($path, 0700)) {
                throw new RestoreRejected('stage_directory_failed');
            }
        }
    }

    private function budget(): void
    {
        if ($this->clock->now() >= $this->deadline) {
            throw new RestoreRejected('runtime_budget_exhausted_resume_required');
        }
    }
}
