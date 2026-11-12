<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Upgrade;

/** Dependency-free dispatcher lease: must run before loading the selected engine's classes. */
final class RuntimeLease
{
    /** Present while an in-place upgrade or restore may leave the tables between versions. */
    public const MAINTENANCE = 'maintenance.json';

    /** @param resource $stream */
    private function __construct(private readonly mixed $stream) {}

    public static function acquire(string $root): self
    {
        $path = $root . '/runtime.lock';
        $permissions = self::io(fn() => fileperms($path));
        if (is_link($root) || is_link($path) || !is_file($path) || !is_int($permissions) || ($permissions & 0o077) !== 0) {
            throw new \RuntimeException('installation_lease_unavailable');
        }
        $stream = self::io(fn() => fopen($path, 'rb'));
        if (!is_resource($stream)) {
            throw new \RuntimeException('installation_lease_unavailable');
        }
        if (!self::io(fn() => flock($stream, LOCK_SH | LOCK_NB))) {
            fclose($stream);
            throw new \RuntimeException('installation_busy_retry');
        }
        // Checked under the lease: the marker outlives an interrupted operator process.
        if (file_exists($root . '/' . self::MAINTENANCE) || is_link($root . '/' . self::MAINTENANCE)) {
            flock($stream, LOCK_UN);
            fclose($stream);
            throw new \RuntimeException('installation_busy_retry');
        }
        return new self($stream);
    }

    public function __destruct()
    {
        if (is_resource($this->stream)) {
            flock($this->stream, LOCK_UN);
            fclose($this->stream);
        }
    }

    /** New direct worker invocations also participate, and stale workers fail before connecting. */
    public static function guard(string $config, string $runtime): ?self
    {
        $root = dirname($config) . '/upgrade';
        $environmentRoot = getenv('CMS_UPGRADE_ROOT');
        if (is_string($environmentRoot) && $environmentRoot !== '') {
            $root = $environmentRoot;
        } elseif (basename(dirname($config, 3)) === 'upgrade') {
            $root = dirname($config, 3);
        }
        if (!is_file($root . '/active.json')) {
            return null;
        }
        $lease = self::acquire($root);
        $active = self::pointer($root);
        if (realpath($config) !== realpath($active['config'] ?? '') || realpath($runtime) !== realpath($active['runtime'] ?? '')) {
            throw new \RuntimeException('stale_runtime_use_stable_worker_dispatcher');
        }
        return $lease;
    }

    /** @return array<string, mixed> */
    public static function pointer(string $root): array
    {
        $path = $root . '/active.json';
        if (is_link($path) || !is_file($path) || filesize($path) > 16_384) {
            throw new \RuntimeException('release_pointer_invalid');
        }
        $value = json_decode((string) self::io(fn() => file_get_contents($path)), true, 32, JSON_THROW_ON_ERROR);
        return is_array($value) ? $value : throw new \RuntimeException('release_pointer_invalid');
    }

    /**
     * This boot boundary cannot load a particular engine's HostIo before selecting that engine.
     * @template T
     * @param \Closure(): T $operation
     * @return T|false
     */
    private static function io(\Closure $operation): mixed
    {
        $warnings = 0;
        set_error_handler(static function () use (&$warnings): bool {
            $warnings++;
            return true;
        }, E_WARNING);
        try {
            $value = $operation();
            return $warnings === 0 ? $value : false;
        } finally {
            restore_error_handler();
        }
    }
}
