<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional;

/** @template T */
final readonly class HostIoResult
{
    /** @param T|false $value */
    public function __construct(public mixed $value, public int $warningCount) {}

    public function succeeded(): bool
    {
        return $this->warningCount === 0 && $this->value !== false;
    }
}

final class HostIoFailure extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('A required host operation failed');
    }
}

/** Narrow synchronous adapter boundary. Never wrap domain workflows or suspend here. */
final class HostIo
{
    /**
     * @template T
     * @param \Closure(): T $operation
     * @return HostIoResult<T>
     */
    public static function attempt(\Closure $operation): HostIoResult
    {
        $warnings = 0;
        // The raw warning can contain credentials or paths. Do not retain or log it.
        set_error_handler(static function () use (&$warnings): bool {
            $warnings++;
            return true;
        }, E_WARNING);
        try {
            $value = $operation();
            return new HostIoResult($warnings === 0 ? $value : false, $warnings);
        } finally {
            restore_error_handler();
        }
    }

    /** @param \Closure(): bool $operation */
    public static function required(\Closure $operation): void
    {
        if (!self::attempt($operation)->succeeded()) {
            throw new HostIoFailure();
        }
    }

    /** Exclusive creation transfers only a complete owner-only file to the caller. */
    public static function createPrivateFile(string $path, string $contents): void
    {
        $stream = self::attempt(static fn() => fopen($path, 'xb'))->value;
        if (!is_resource($stream)) {
            throw new HostIoFailure();
        }
        try {
            try {
                // Secure the empty file before writing configuration or credentials.
                self::required(static fn(): bool => chmod($path, 0600));
                if (self::attempt(static fn() => fwrite($stream, $contents))->value !== strlen($contents)) {
                    throw new HostIoFailure();
                }
                self::required(static fn(): bool => fflush($stream));
            } finally {
                self::cleanup(static fn(): bool => fclose($stream));
            }
        } catch (\Throwable $error) {
            self::cleanup(static fn(): bool => unlink($path));
            throw $error;
        }
    }

    /**
     * Cleanup cannot replace an in-flight failure. False is recorded, never silently ignored.
     * @param \Closure(): bool $operation
     */
    public static function cleanup(\Closure $operation): bool
    {
        try {
            $result = self::attempt($operation);
            if ($result->succeeded() && $result->value === true) {
                return true;
            }
        } catch (\Throwable) {
            // Cleanup is best effort; the owning workflow retains its primary exception.
        }
        self::recordFailure('cleanup_failed');
        return false;
    }

    public static function recordFailure(string $code): void
    {
        if (!in_array($code, ['cleanup_failed', 'diagnostic_write_failed'], true)) {
            throw new \InvalidArgumentException('Host diagnostic code is invalid');
        }
        // A failing system log must not recurse or expose its warning. No path/payload is included.
        try {
            self::attempt(static fn(): bool => error_log('portable_cms event=' . $code));
        } catch (\Throwable) {
            // The host may disable its logger. There is no safe second sink to recurse into.
        }
    }
}
