<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Restore;

use Utsuri\PortableCms\Conventional\HostIo;

/** Local progress only, never a trusted substitute for final checksum verification. */
final readonly class FilesystemBatchCursor
{
    public function __construct(private FilesystemRestoreStore $store, private string $key, private string $sha256, private int $files)
    {
        if (preg_match('/\A[a-f0-9]{64}\z/', $sha256) !== 1 || $files < 0 || $files > 25032) {
            throw new RestoreRejected('invalid_batch_intent');
        }
    }

    public function read(): int
    {
        $path = $this->store->path($this->key);
        if (!is_file($path)) {
            return 0;
        }
        if (filesize($path) > 512) {
            throw new RestoreRejected('invalid_batch_progress');
        }
        $bytes = HostIo::attempt(static fn() => file_get_contents($path))->value;
        try {
            $value = is_string($bytes) ? json_decode($bytes, true, 8, JSON_THROW_ON_ERROR) : null;
        } catch (\JsonException) {
            throw new RestoreRejected('invalid_batch_progress');
        }
        if (!is_array($value) || count($value) !== 3 || array_diff(array_keys($value), ['sha256', 'files', 'next']) !== []
            || ($value['sha256'] ?? null) !== $this->sha256 || ($value['files'] ?? null) !== $this->files
            || !is_int($value['next'] ?? null) || $value['next'] < 0 || $value['next'] > $this->files) {
            throw new RestoreRejected('invalid_batch_progress');
        }
        return $value['next'];
    }

    /** Caller holds the root lock and has validated any existing checkpoint. */
    public function advance(int $next): void
    {
        if ($next < 0 || $next > $this->files) {
            throw new RestoreRejected('invalid_batch_progress');
        }
        $this->store->directories(dirname($this->key));
        $temporary = $this->store->path($this->key . '.partial');
        if (is_file($temporary)) {
            HostIo::required(static fn(): bool => unlink($temporary));
        }
        HostIo::createPrivateFile($temporary, json_encode(['sha256' => $this->sha256, 'files' => $this->files, 'next' => $next], JSON_THROW_ON_ERROR) . "\n");
        $path = $this->store->path($this->key);
        HostIo::required(static fn(): bool => rename($temporary, $path));
    }
}
