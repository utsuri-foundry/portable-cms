<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Media;

use Utsuri\PortableCms\Conventional\HostIo;

final class FilesystemUploadStorage
{
    private string $root;

    /** @param (\Closure(string): float)|null $freeBytes */
    public function __construct(string $root, private readonly ?\Closure $freeBytes = null, private readonly \Utsuri\PortableCms\Conventional\Identity\Clock $clock = new \Utsuri\PortableCms\Conventional\Identity\SystemClock())
    {
        if (!str_starts_with($root, DIRECTORY_SEPARATOR)) {
            throw new \InvalidArgumentException('Media storage root must be absolute');
        }
        if (is_link($root)) {
            throw new \RuntimeException('Media storage root is unsafe');
        }
        if (!is_dir($root) && !HostIo::attempt(fn() => mkdir($root, 0700, true))->value && !is_dir($root)) {
            throw new \RuntimeException('Media storage root could not be created');
        }
        HostIo::required(fn() => chmod($root, 0700));
        $resolved = realpath($root);
        if ($resolved === false || HostIo::attempt(fn() => is_link($root))->value || !is_writable($resolved)) {
            throw new \RuntimeException('Media storage root is unsafe');
        }
        $this->root = rtrim($resolved, DIRECTORY_SEPARATOR);
    }

    public function assertHeadroom(int $expectedBytes, bool $video = false, int $existingCopies = 0): void
    {
        $free = $this->freeBytes !== null ? ($this->freeBytes)($this->root) : HostIo::attempt(fn() => disk_free_space($this->root))->value;
        if (!is_float($free)) {
            throw new \RuntimeException('Media storage headroom is unavailable');
        }
        if ($free < ($expectedBytes * max(1, ($video ? 4 : 2) - $existingCopies)) + 64 * 1024 * 1024) {
            throw new UploadFailure('storage_headroom_insufficient', 507, 'upload.error.the_upload_cannot_start_because_storage_is');
        }
    }

    /** @param resource $source */
    public function writePart(string $sessionId, int $partNumber, mixed $source, int $expectedBytes): UploadPart
    {
        if (!is_resource($source)) {
            throw new UploadFailure('upload_body_missing', 400, 'upload.error.the_upload_part_body_is_required');
        }
        $directory = $this->uploadDirectory($sessionId) . '/parts';
        $this->ensureDirectory($directory);
        $destination = $directory . '/' . $partNumber . '.part';
        $temporary = $directory . '/.' . $partNumber . '.' . bin2hex(random_bytes(8)) . '.tmp';
        $output = HostIo::attempt(fn() => fopen($temporary, 'xb'))->value;
        if (!is_resource($output)) {
            throw new \RuntimeException('Upload part could not be created');
        }
        $hash = hash_init('sha256');
        $bytes = 0;
        try {
            try {
                while (!feof($source)) {
                    $chunk = HostIo::attempt(fn() => fread($source, min(1024 * 1024, $expectedBytes + 1 - $bytes)))->value;
                    if ($chunk === false) {
                        throw new UploadFailure('upload_body_read_failed', 400, 'upload.error.the_upload_part_could_not_be_read');
                    }
                    if ($chunk === '') {
                        break;
                    }
                    $bytes += strlen($chunk);
                    if ($bytes > $expectedBytes || HostIo::attempt(fn() => fwrite($output, $chunk))->value !== strlen($chunk)) {
                        throw new UploadFailure('invalid_part_size', 422, 'upload.error.the_upload_part_has_the_wrong_size');
                    }
                    hash_update($hash, $chunk);
                }
                HostIo::required(fn() => fflush($output));
            } finally {
                HostIo::cleanup(fn() => fclose($output));
            }
        } catch (\Throwable $error) {
            HostIo::cleanup(fn() => unlink($temporary));
            throw $error;
        }
        if ($bytes !== $expectedBytes) {
            HostIo::cleanup(fn() => unlink($temporary));
            throw new UploadFailure('invalid_part_size', 422, 'upload.error.the_upload_part_has_the_wrong_size');
        }
        if (HostIo::attempt(fn() => chmod($temporary, 0600))->value !== true) {
            HostIo::cleanup(fn() => unlink($temporary));
            throw new \RuntimeException('Upload part permissions could not be secured');
        }
        if (!HostIo::attempt(fn() => rename($temporary, $destination))->value) {
            HostIo::cleanup(fn() => unlink($temporary));
            throw new \RuntimeException('Upload part could not be committed');
        }
        return new UploadPart($partNumber, $bytes, hash_final($hash));
    }

    public function assemble(UploadSession $session, int $budgetSeconds = 30): StoredOriginal
    {
        $deadline = $this->clock->now()->modify('+' . $budgetSeconds . ' seconds');
        $directory = $this->root . '/originals/' . $session->id;
        $this->ensureDirectory($directory);
        $destination = $directory . '/source';
        if (is_file($destination) && !is_link($destination)) {
            $size = HostIo::attempt(fn() => filesize($destination))->value;
            $checksum = HostIo::attempt(fn() => hash_file('sha256', $destination))->value;
            if ($size === $session->byteSize && is_string($checksum)) {
                return new StoredOriginal('originals/' . $session->id . '/source', $size, $checksum);
            }
            HostIo::cleanup(fn() => unlink($destination));
        }
        $temporary = $directory . '/.source.' . bin2hex(random_bytes(8)) . '.tmp';
        $output = HostIo::attempt(fn() => fopen($temporary, 'xb'))->value;
        if (!is_resource($output)) {
            throw new \RuntimeException('Original assembly could not start');
        }
        $hash = hash_init('sha256');
        $total = 0;
        try {
            try {
                for ($number = 1; $number <= $session->partCount; $number++) {
                    $path = $this->uploadDirectory($session->id) . '/parts/' . $number . '.part';
                    if (!is_file($path) || is_link($path)) {
                        throw new UploadFailure('upload_incomplete', 409, 'upload.error.the_upload_does_not_contain_every_required');
                    }
                    $input = HostIo::attempt(fn() => fopen($path, 'rb'))->value;
                    if (!is_resource($input)) {
                        throw new \RuntimeException('Upload part could not be opened');
                    }
                    try {
                        while (!feof($input)) {
                            if ($this->clock->now() >= $deadline) {
                                throw new UploadFailure('upload_completion_timeout', 503, 'video.completion_timeout');
                            }
                            $chunk = HostIo::attempt(fn() => fread($input, 1024 * 1024))->value;
                            if ($chunk === false) {
                                throw new \RuntimeException('Upload part could not be read');
                            }
                            if ($chunk === '') {
                                break;
                            }
                            $total += strlen($chunk);
                            hash_update($hash, $chunk);
                            if (HostIo::attempt(fn() => fwrite($output, $chunk))->value !== strlen($chunk)) {
                                throw new \RuntimeException('Original assembly could not be written');
                            }
                        }
                    } finally {
                        HostIo::cleanup(fn() => fclose($input));
                    }
                }
                HostIo::required(fn() => fflush($output));
            } finally {
                HostIo::cleanup(fn() => fclose($output));
            }
        } catch (\Throwable $error) {
            HostIo::cleanup(fn() => unlink($temporary));
            throw $error;
        }
        if ($total !== $session->byteSize) {
            HostIo::cleanup(fn() => unlink($temporary));
            throw new UploadFailure('assembled_size_mismatch', 422, 'upload.error.the_completed_upload_has_the_wrong_size');
        }
        if (HostIo::attempt(fn() => chmod($temporary, 0600))->value !== true) {
            HostIo::cleanup(fn() => unlink($temporary));
            throw new \RuntimeException('Original permissions could not be secured');
        }
        if (!HostIo::attempt(fn() => rename($temporary, $destination))->value) {
            HostIo::cleanup(fn() => unlink($temporary));
            throw new \RuntimeException('Original assembly could not be committed');
        }
        return new StoredOriginal('originals/' . $session->id . '/source', $total, hash_final($hash));
    }

    /** @return array{width: int, height: int, durationMilliseconds: int, codec: string, hasAudio: bool} */
    public function videoFacts(UploadSession $session, string $checksum): array
    {
        $directory = $this->root . '/originals/' . $session->id;
        $path = $directory . '/source';
        if (is_link($directory) || is_link($path) || !is_file($path) || filesize($path) !== $session->byteSize || !hash_equals($checksum, (string) hash_file('sha256', $path))) {
            throw new UploadFailure('source_integrity_mismatch', 422, 'upload.error.the_file_contents_do_not_match_the');
        }
        return PreencodedVideoProcessor::inspectFile($path, $session->declaredType, $session->byteSize);
    }

    public function originalPath(StoredOriginal $original): string
    {
        return $this->root . '/' . $original->key;
    }

    public function abort(string $sessionId, bool $removeOriginal = true): void
    {
        $this->removeTree($this->uploadDirectory($sessionId));
        if ($removeOriginal) {
            $this->removeTree($this->root . '/originals/' . $sessionId);
        }
    }

    public function discardParts(string $sessionId): void
    {
        $this->removeTree($this->uploadDirectory($sessionId));
    }

    private function uploadDirectory(string $sessionId): string
    {
        if (preg_match('/\A[a-f0-9]{32}\z/', $sessionId) !== 1) {
            throw new \InvalidArgumentException('Upload session ID is invalid');
        }
        return $this->root . '/uploads/' . $sessionId;
    }

    private function ensureDirectory(string $directory): void
    {
        if (!str_starts_with($directory, $this->root . DIRECTORY_SEPARATOR)) {
            throw new \RuntimeException('Private media directory escaped its root');
        }
        $relative = substr($directory, strlen($this->root) + 1);
        $current = $this->root;
        foreach (explode(DIRECTORY_SEPARATOR, $relative) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new \RuntimeException('Private media directory is invalid');
            }
            $current .= DIRECTORY_SEPARATOR . $segment;
            if (is_link($current)) {
                throw new \RuntimeException('Private media directory cannot contain a symlink');
            }
            if (!is_dir($current) && !HostIo::attempt(fn() => mkdir($current, 0700))->value && !is_dir($current)) {
                throw new \RuntimeException('Private media directory could not be created');
            }
            HostIo::required(fn() => chmod($current, 0700));
            $resolved = realpath($current);
            if ($resolved === false || !$this->withinRoot($resolved)) {
                throw new \RuntimeException('Private media directory escaped its root');
            }
        }
    }

    private function removeTree(string $directory): void
    {
        if (!file_exists($directory) || is_link($directory)) {
            if (is_link($directory)) {
                HostIo::cleanup(fn() => unlink($directory));
            }
            return;
        }
        $resolved = realpath($directory);
        if ($resolved === false || !$this->withinRoot($resolved)) {
            throw new \RuntimeException('Private media cleanup escaped its root');
        }
        $items = HostIo::attempt(fn() => scandir($directory))->value;
        if (!is_array($items)) {
            HostIo::recordFailure('cleanup_failed');
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $directory . '/' . $item;
            if (is_dir($path) && !is_link($path)) {
                $this->removeTree($path);
            } else {
                HostIo::cleanup(fn() => unlink($path));
            }
        }
        HostIo::cleanup(fn() => rmdir($directory));
    }

    private function withinRoot(string $path): bool
    {
        return $path === $this->root || str_starts_with($path, $this->root . DIRECTORY_SEPARATOR);
    }
}
