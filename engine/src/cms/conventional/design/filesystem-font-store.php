<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Design;

use Utsuri\PortableCms\Conventional\I18n\InvalidInput;

final class FilesystemFontStore implements FontStore
{
    private readonly string $root;
    public function __construct(string $privateRoot, private readonly \Utsuri\PortableCms\Conventional\Identity\RandomSource $random = new \Utsuri\PortableCms\Conventional\Identity\SystemRandomSource())
    {
        if (is_link($privateRoot) || !is_dir($privateRoot) && !mkdir($privateRoot, 0700, true)) {
            throw new \RuntimeException('Private font storage is unavailable.');
        }
        $this->root = realpath($privateRoot) ?: throw new \RuntimeException('Private font storage is unavailable.');
    }
    public function put(string $bytes): string
    {
        Woff2::verify($bytes, true);
        $checksum = hash('sha256', $bytes);
        $path = $this->root . '/' . $checksum . '.woff2';
        $lockPath = $this->root . '/font-store.lock';
        if (is_link($this->root) || is_link($path) || is_link($lockPath)) {
            throw new \RuntimeException('Private font storage is unsafe.');
        }
        $lock = fopen($lockPath, 'c+b');
        if (!is_resource($lock)) {
            throw new \RuntimeException('Private font lock is unavailable.');
        }
        $pending = null;
        try {
            if (!chmod($lockPath, 0600) || !flock($lock, LOCK_EX | LOCK_NB)) {
                throw new FontRejected('design.font_error.another_font_is_being_stored_try');
            }
            if (!is_file($path)) {
                $candidate = $this->root . '/pending-' . bin2hex($this->random->bytes(16));
                if (file_exists($candidate) || is_link($candidate)) {
                    throw new \RuntimeException('Private font staging path is occupied.');
                }
                $handle = fopen($candidate, 'xb');
                if (!is_resource($handle)) {
                    throw new \RuntimeException('Private font could not be created.');
                }
                $pending = $candidate;
                try {
                    if (!chmod($pending, 0600) || fwrite($handle, $bytes) !== strlen($bytes) || !fflush($handle)) {
                        throw new \RuntimeException('Private font could not be stored.');
                    }
                } finally {
                    fclose($handle);
                }
                if (!rename($pending, $path)) {
                    throw new \RuntimeException('Private font could not be committed.');
                }
            }
            $this->file($checksum, strlen($bytes));
            return $checksum;
        } finally {
            try {
                if ($pending !== null && is_file($pending) && !unlink($pending)) {
                    throw new \RuntimeException('Private font staging cleanup failed.');
                }
            } finally {
                fclose($lock);
            }
        }
    }

    public function file(string $checksum, int $byteSize): string
    {
        if (!RegistryDocument::hash($checksum)) {
            throw new InvalidInput('design.error.font_checksum');
        }
        $path = $this->root . '/' . $checksum . '.woff2';
        if (is_link($path) || !is_file($path) || filesize($path) !== $byteSize || hash_file('sha256', $path) !== $checksum) {
            throw new \RuntimeException('Private font integrity check failed.');
        }
        return $path;
    }
}
