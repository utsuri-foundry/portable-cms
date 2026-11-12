<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Upgrade;

use Utsuri\PortableCms\Conventional\HostIo;

final class UpgradeFailure extends \RuntimeException
{
    public function __construct(public readonly string $codeName)
    {
        parent::__construct($codeName);
    }
}

/** Private, bounded, checksum-addressed upgrade storage. No archive extraction. */
final class UpgradeFiles
{
    public const MAX_BYTES = 2_147_483_648;
    public const MAX_FILES = 20_000;

    public static function root(string $path): string
    {
        $resolved = realpath($path);
        if ($resolved === false || $resolved === '/' || !is_dir($resolved) || is_link($path) || $resolved !== rtrim($path, '/')) {
            throw new UpgradeFailure('unsafe_upgrade_root');
        }
        return $resolved;
    }

    public static function within(string $path, string $root): bool
    {
        return $path === $root || str_starts_with($path, $root . '/');
    }

    public static function path(string $root, string $relative): string
    {
        if (preg_match('#\A[a-zA-Z0-9_.-]+(?:/[a-zA-Z0-9_.-]+)*\z#', $relative) !== 1) {
            throw new UpgradeFailure('unsafe_upgrade_path');
        }
        $cursor = $root;
        foreach (explode('/', $relative) as $part) {
            if ($part === '.' || $part === '..') {
                throw new UpgradeFailure('unsafe_upgrade_path');
            }
            $cursor .= '/' . $part;
            if (is_link($cursor)) {
                throw new UpgradeFailure('upgrade_symlink_refused');
            }
        }
        return $cursor;
    }

    /** @return array<string, array{sha256: string, bytes: int}> */
    public static function inventory(string $root): array
    {
        self::root($root);
        $files = [];
        $total = 0;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $entry) {
            $relative = substr($entry->getPathname(), strlen($root) + 1);
            $path = self::path($root, $relative);
            if ($entry->isDir()) {
                continue;
            }
            if (!$entry->isFile()) {
                throw new UpgradeFailure('upgrade_special_file_refused');
            }
            $size = filesize($path);
            $checksum = hash_file('sha256', $path);
            if (!is_int($size) || !is_string($checksum) || count($files) >= self::MAX_FILES || ($total += $size) > self::MAX_BYTES) {
                throw new UpgradeFailure('upgrade_inventory_limit');
            }
            $files[$relative] = ['sha256' => $checksum, 'bytes' => $size];
        }
        ksort($files, SORT_STRING);
        return $files;
    }

    /** @param array<string, array{sha256: string, bytes: int}> $inventory */
    public static function copy(string $source, string $destination, array $inventory): void
    {
        if (file_exists($destination) || is_link($destination)) {
            throw new UpgradeFailure('upgrade_destination_exists');
        }
        HostIo::required(fn() => mkdir($destination, 0700, true));
        foreach ($inventory as $relative => $facts) {
            $from = self::path($source, $relative);
            $to = self::path($destination, $relative);
            if (!is_dir(dirname($to))) {
                HostIo::required(fn() => mkdir(dirname($to), 0700, true));
            }
            HostIo::required(fn() => copy($from, $to));
            HostIo::required(fn() => chmod($to, 0600));
            if (filesize($to) !== $facts['bytes'] || hash_file('sha256', $to) !== $facts['sha256']) {
                throw new UpgradeFailure('upgrade_copy_checksum_failed');
            }
        }
        if (self::inventory($destination) !== $inventory) {
            throw new UpgradeFailure('upgrade_copy_inventory_failed');
        }
    }

    /** @return array<string, mixed> */
    public static function json(string $path): array
    {
        if (is_link($path) || !is_file($path) || filesize($path) > 4_194_304) {
            throw new UpgradeFailure('upgrade_document_unavailable');
        }
        $value = json_decode((string) HostIo::attempt(fn() => file_get_contents($path))->value, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($value) || array_is_list($value)) {
            throw new UpgradeFailure('upgrade_document_invalid');
        }
        return $value;
    }

    public static function atomic(string $path, string $bytes): void
    {
        if (is_link($path)) {
            throw new UpgradeFailure('upgrade_symlink_refused');
        }
        $temporary = dirname($path) . '/.upgrade-' . bin2hex(random_bytes(12));
        try {
            HostIo::createPrivateFile($temporary, $bytes);
            HostIo::required(fn() => rename($temporary, $path));
        } finally {
            if (is_file($temporary)) {
                HostIo::cleanup(fn() => unlink($temporary));
            }
        }
    }

    /** @param array<string, mixed> $value */
    public static function writeJson(string $path, array $value): void
    {
        self::atomic($path, json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    }

    /** @param array<string, mixed> $value */
    public static function digest(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
