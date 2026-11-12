<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Http;

use Utsuri\PortableCms\Conventional\HostIo;

/** Verified immutable file, retained on the same descriptor through range delivery. */
final class FileBody implements \Utsuri\PortableCms\Conventional\Media\MediaDelivery
{
    /** @param resource $handle */
    private function __construct(private mixed $handle, public readonly int $byteSize) {}

    public function size(): int
    {
        return $this->byteSize;
    }

    public static function verified(string $path, int $byteSize, string $checksum): self
    {
        $handle = HostIo::attempt(fn() => fopen($path, 'rb'))->value;
        if (!is_resource($handle)) {
            throw new \RuntimeException('File body is unavailable');
        }
        try {
            $stat = HostIo::attempt(fn() => fstat($handle))->value;
            $hash = hash_init('sha256');
            $read = HostIo::attempt(fn() => hash_update_stream($hash, $handle))->value;
            if (!is_array($stat) || ($stat['size'] ?? null) !== $byteSize || $read !== $byteSize
                || !hash_equals($checksum, hash_final($hash))) {
                throw new \RuntimeException('File body verification failed');
            }
            return new self($handle, $byteSize);
        } catch (\Throwable $error) {
            fclose($handle);
            throw $error;
        }
    }

    /** @param resource $output */
    public function writeTo(mixed $output, int $start, int $length): void
    {
        if (!is_resource($output) || $start < 0 || $length < 1 || $start + $length > $this->byteSize
            || HostIo::attempt(fn() => fseek($this->handle, $start))->value !== 0) {
            throw new \RuntimeException('File range is unavailable');
        }
        while ($length > 0) {
            $chunk = HostIo::attempt(fn() => fread($this->handle, min(1048576, $length)))->value;
            if (!is_string($chunk) || $chunk === '') {
                throw new \RuntimeException('File body ended unexpectedly');
            }
            $offset = 0;
            while ($offset < strlen($chunk)) {
                $written = HostIo::attempt(fn() => fwrite($output, substr($chunk, $offset)))->value;
                if (!is_int($written) || $written < 1) {
                    throw new \RuntimeException('File body could not be written');
                }
                $offset += $written;
            }
            $length -= strlen($chunk);
        }
    }

    public function __destruct()
    {
        fclose($this->handle);
    }
}
