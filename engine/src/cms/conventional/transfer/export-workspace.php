<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Transfer;

use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Export\ExportCapacity;
use Utsuri\PortableCms\Conventional\HostIo;
use Utsuri\PortableCms\Conventional\Identity\Clock;
use Utsuri\PortableCms\Conventional\Restore\FilesystemRestoreStore;
use Utsuri\PortableCms\Conventional\Restore\RestoreRejected;

/** An owned workspace permits recovery of unsealed capture chunks, never deletion of source files. */
final readonly class ExportWorkspace
{
    public function __construct(private FilesystemRestoreStore $root) {}

    /** Caller holds the workspace lock and has checked source pause and administrator authority. */
    public function prepare(OperatorContext $context, Clock $clock, int $seconds): FilesystemRestoreStore
    {
        $databaseIdentity = hash('sha256', json_encode([$context->config->databaseDsn, $context->config->tablePrefix->value], JSON_THROW_ON_ERROR));
        $expected = json_encode(['formatVersion' => 1, 'actorId' => $context->actorId, 'profileSha256' => $context->profile->checksum, 'sourceRootSha256' => hash('sha256', $context->config->mediaRoot), 'sourceDatabaseSha256' => $databaseIdentity, 'engineVersion' => ProfileManifest::ENGINE_VERSION], JSON_THROW_ON_ERROR) . "\n";
        if (!is_file($this->root->path('intent.json'))) {
            foreach (new \FilesystemIterator($this->root->root) as $entry) {
                if (!in_array($entry->getFilename(), ['restore.lock', 'intent.json.partial'], true) || !$entry->isFile() || $entry->isLink()) {
                    throw new RestoreRejected('export_requires_empty_private_workspace');
                }
            }
            if (is_file($this->root->path('intent.json.partial')) && !hash_equals(hash('sha256', $expected), hash('sha256', PackageFiles::read($this->root, 'intent.json.partial', 1024)))) {
                throw new RestoreRejected('unsealed_workspace_intent_requires_fresh_directory');
            }
            HostIo::required(fn(): bool => chmod($this->root->root, 0700));
            PackageFiles::commit($this->root, 'intent.json', $expected);
        } elseif (!hash_equals(hash('sha256', $expected), hash('sha256', PackageFiles::read($this->root, 'intent.json', 1024)))) {
            throw new RestoreRejected('source_export_intent_mismatch');
        }
        foreach (new \FilesystemIterator($this->root->root) as $entry) {
            if (!in_array($entry->getFilename(), ['restore.lock', 'intent.json', 'intent.json.partial', 'content', 'package.json', 'package.json.partial'], true) || $entry->isLink()) {
                throw new RestoreRejected('unexpected_export_workspace_file');
            }
        }
        $this->root->directories('content');
        $content = new FilesystemRestoreStore($this->root->path('content'), $clock, $seconds);
        if (!is_file($content->path('capture.json'))) {
            if (is_file($this->root->path('package.json'))) {
                throw new RestoreRejected('completed_workspace_capture_missing');
            }
            $this->recoverUnsealedCapture($content);
        }
        return $content;
    }

    private function recoverUnsealedCapture(FilesystemRestoreStore $content): void
    {
        $owned = [];
        foreach (new \FilesystemIterator($content->root) as $entry) {
            if ($entry->isLink()) {
                throw new RestoreRejected('unsafe_capture_recovery_path');
            }
            if ($entry->getFilename() === 'restore.lock' && $entry->isFile()) {
                continue;
            }
            if ($entry->getFilename() === 'capture.partial' && $entry->isFile() && $entry->getSize() <= ExportCapacity::MANIFEST_BYTES) {
                $owned[] = $content->path('capture.partial');
                continue;
            }
            if ($entry->getFilename() !== 'chunks' || !$entry->isDir()) {
                throw new RestoreRejected('unexpected_unsealed_capture_file');
            }
            foreach (new \FilesystemIterator($content->path('chunks')) as $chunk) {
                if (!$chunk->isFile() || $chunk->isLink() || preg_match('/\A[a-f0-9]{64}\.jsonl\z/', $chunk->getFilename()) !== 1 || $chunk->getSize() > ExportCapacity::CHUNK_BYTES || count($owned) >= ExportCapacity::MAX_CHUNKS + 1) {
                    throw new RestoreRejected('unsafe_capture_recovery_path');
                }
                $owned[] = $content->path('chunks/' . $chunk->getFilename());
            }
        }
        // Only fixed namespaces allocated after the matching durable intent may be removed.
        foreach ($owned as $path) {
            HostIo::required(static fn(): bool => unlink($path));
        }
        if (is_dir($content->path('chunks'))) {
            HostIo::required(fn(): bool => rmdir($content->path('chunks')));
        }
    }
}
