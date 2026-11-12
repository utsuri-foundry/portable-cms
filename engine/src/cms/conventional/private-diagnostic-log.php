<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional;

/** Only already-redacted entries cross this adapter. Logging cannot replace a workflow error. */
final class PrivateDiagnosticLog
{
    public static function append(?string $path, string $entry): void
    {
        if ($path === null) {
            return;
        }
        if (is_link($path) || (file_exists($path) && !is_file($path))) {
            HostIo::recordFailure('diagnostic_write_failed');
            return;
        }
        $stream = HostIo::attempt(static fn() => fopen($path, 'c+b'))->value;
        if (!is_resource($stream)) {
            HostIo::recordFailure('diagnostic_write_failed');
            return;
        }
        $locked = false;
        try {
            $locked = HostIo::attempt(static fn() => flock($stream, LOCK_EX | LOCK_NB))->value === true;
            if (!$locked
                || HostIo::attempt(static fn() => chmod($path, 0600))->value !== true
                || HostIo::attempt(static fn() => fseek($stream, 0, SEEK_END))->value !== 0
                || HostIo::attempt(static fn() => fwrite($stream, $entry))->value !== strlen($entry)
                || HostIo::attempt(static fn() => fflush($stream))->value !== true) {
                HostIo::recordFailure('diagnostic_write_failed');
            }
        } finally {
            if ($locked) {
                HostIo::cleanup(static fn(): bool => flock($stream, LOCK_UN));
            }
            HostIo::cleanup(static fn(): bool => fclose($stream));
        }
    }
}
