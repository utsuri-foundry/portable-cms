<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Transfer;

use Utsuri\PortableCms\Conventional\HostIo;
use Utsuri\PortableCms\Conventional\Restore\FilesystemRestoreStore;
use Utsuri\PortableCms\Conventional\Restore\RestoreRejected;

/** Checked private package metadata I/O. Completion never replaces another immutable intent. */
final class PackageFiles
{
    public static function read(FilesystemRestoreStore $store, string $key, int $maximumBytes): string
    {
        $path = $store->path($key);
        if (!is_file($path)) {
            throw new RestoreRejected('package_metadata_unavailable');
        }
        $size = filesize($path);
        if (!is_int($size) || $size < 2 || $size > $maximumBytes) {
            throw new RestoreRejected('package_metadata_size_limit');
        }
        $bytes = file_get_contents($path);
        if (!is_string($bytes) || strlen($bytes) !== $size) {
            throw new RestoreRejected('package_metadata_changed');
        }
        return $bytes;
    }

    public static function commit(FilesystemRestoreStore $store, string $key, string $bytes): void
    {
        $path = $store->path($key);
        if (is_file($path)) {
            if (!hash_equals(hash('sha256', $bytes), hash('sha256', self::read($store, $key, PackageManifest::MAX_BYTES)))) {
                throw new RestoreRejected('package_metadata_changed');
            }
            return;
        }
        $temporary = $store->path($key . '.partial');
        if (is_file($temporary)) {
            HostIo::required(static fn(): bool => unlink($temporary));
        }
        HostIo::createPrivateFile($temporary, $bytes);
        HostIo::required(static fn(): bool => link($temporary, $path));
        HostIo::required(static fn(): bool => unlink($temporary));
    }
}
