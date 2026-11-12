<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Media;

use Utsuri\PortableCms\Conventional\HostIo;

final class FilesystemMediaStore
{
    private string $root;

    public function __construct(string $root)
    {
        $resolved = realpath($root);
        if ($resolved === false || !is_dir($resolved) || is_link($root) || !is_writable($resolved)) {
            throw new \RuntimeException('Media storage root is unavailable');
        }
        $this->root = rtrim($resolved, DIRECTORY_SEPARATOR);
    }

    public function videoFrame(MediaDerivative $video): ?VideoFrame
    {
        return (new FilesystemVideoFrames($this->root))->find($video);
    }

    public function readVideoFrame(MediaDerivative $video): ?string
    {
        return (new FilesystemVideoFrames($this->root))->read($video);
    }

    public function cacheVideoFrame(MediaDerivative $video, string $bytes): bool
    {
        return (new FilesystemVideoFrames($this->root))->store($video, $bytes);
    }

    public function sourcePath(MediaSource $source): string
    {
        if (!preg_match('#\Aoriginals/[a-f0-9]{32}/source\z#', $source->originalKey)) {
            throw new MediaProcessingFailure('invalid_source_key', false, 'The media source key is invalid.');
        }
        return $this->existingFile($source->originalKey);
    }

    public function readThumbnail(MediaDerivative $derivative): string
    {
        if (preg_match('/\A[a-f0-9]{32}\z/', $derivative->mediaId) !== 1
            || $derivative->variant !== '960-webp' || $derivative->mime !== 'image/webp'
            || $derivative->objectKey !== 'derivatives/' . $derivative->mediaId . '/' . $derivative->mediaId . '-960-webp.webp'
            || $derivative->byteSize < 1 || $derivative->byteSize > 8_388_608) {
            throw new MediaProcessingFailure('invalid_derivative_metadata', false, 'The thumbnail is unavailable.');
        }
        $path = $this->existingFile($derivative->objectKey);
        $bytes = HostIo::attempt(fn() => file_get_contents($path, false, null, 0, $derivative->byteSize + 1))->value;
        if (!is_string($bytes) || strlen($bytes) !== $derivative->byteSize || !hash_equals($derivative->checksum, hash('sha256', $bytes))) {
            throw new MediaProcessingFailure('derivative_verification_failed', false, 'The thumbnail is unavailable.');
        }
        return $bytes;
    }

    /** @return array{width: int, height: int, durationMilliseconds: int, codec: string, hasAudio: bool} */
    public function videoFacts(MediaDerivative $derivative): array
    {
        $this->readVideo($derivative);
        return PreencodedVideoProcessor::inspectFile($this->existingFile($derivative->objectKey), $derivative->mime, $derivative->byteSize);
    }

    public function readVideo(MediaDerivative $derivative): MediaDelivery
    {
        $extension = match ($derivative->mime) {
            'video/mp4' => 'mp4', 'video/webm' => 'webm', default => ''
        };
        if ($extension === '' || preg_match('/\A[a-f0-9]{32}\z/', $derivative->mediaId) !== 1 || $derivative->variant !== 'prepared-video'
            || $derivative->objectKey !== 'derivatives/' . $derivative->mediaId . '/' . $derivative->mediaId . '-prepared-video.' . $extension
            || $derivative->byteSize < 1 || $derivative->byteSize > VideoSupport::MAX_BYTES) {
            throw new MediaProcessingFailure('invalid_derivative_metadata', false, 'The prepared video is unavailable.');
        }
        $path = $this->existingFile($derivative->objectKey);
        try {
            return \Utsuri\PortableCms\Conventional\Http\FileBody::verified($path, $derivative->byteSize, $derivative->checksum);
        } catch (\RuntimeException $error) {
            throw new MediaProcessingFailure('derivative_verification_failed', false, 'The prepared video is unavailable.', $error);
        }
    }

    public function workingDirectory(MediaJob $job): string
    {
        if (!preg_match('/\A[a-f0-9]{32}\z/', $job->id)) {
            throw new \InvalidArgumentException('Job ID is invalid');
        }
        $workRoot = $this->root . '/work';
        $this->ensureDirectory($workRoot);
        $directory = $workRoot . '/' . $job->id . '-' . substr($job->leaseToken, 0, 16);
        $this->ensureDirectory($directory);
        return $directory;
    }

    public function commitDerivative(MediaDerivative $derivative, string $temporaryPath): void
    {
        $extension = match ($derivative->mime) {
            'image/webp' => 'webp', 'video/mp4' => 'mp4', 'video/webm' => 'webm',
            default => throw new MediaProcessingFailure('invalid_derivative_metadata', false, 'Derivative MIME is invalid.'),
        };
        $expectedFilename = $derivative->mediaId . '-' . $derivative->variant . '.' . $extension;
        $expectedKey = 'derivatives/' . $derivative->mediaId . '/' . $expectedFilename;
        if (
            !preg_match('/\A[a-f0-9]{32}\z/', $derivative->mediaId)
            || !(RasterWidths::maximum($derivative->variant) !== null || $derivative->variant === 'prepared-video')
            || (RasterWidths::maximum($derivative->variant) !== null) !== ($derivative->mime === 'image/webp')
            || $derivative->filename !== $expectedFilename
            || $derivative->objectKey !== $expectedKey
        ) {
            throw new MediaProcessingFailure('invalid_derivative_metadata', false, 'Derivative metadata is invalid.');
        }
        if (!is_file($temporaryPath) || is_link($temporaryPath)) {
            throw new MediaProcessingFailure('derivative_missing', true, 'The generated derivative is unavailable.');
        }
        $directory = $this->root . '/derivatives/' . $derivative->mediaId;
        $this->ensureDirectory($directory);
        $destination = $directory . '/' . $expectedFilename;
        if (is_file($destination) && !is_link($destination)) {
            $size = HostIo::attempt(fn() => filesize($destination))->value;
            $checksum = HostIo::attempt(fn() => hash_file('sha256', $destination))->value;
            if ($size === $derivative->byteSize && hash_equals($derivative->checksum, (string) $checksum)) {
                return;
            }
            throw new MediaProcessingFailure(
                'immutable_derivative_conflict',
                false,
                'An immutable derivative already contains different bytes.',
            );
        }
        HostIo::required(fn() => chmod($temporaryPath, 0600));
        if (!HostIo::attempt(fn() => rename($temporaryPath, $destination))->value) {
            throw new MediaProcessingFailure('derivative_commit_failed', true, 'The derivative could not be committed.');
        }
        HostIo::required(fn() => chmod($destination, 0600));
        $size = HostIo::attempt(fn() => filesize($destination))->value;
        $checksum = HostIo::attempt(fn() => hash_file('sha256', $destination))->value;
        if ($size !== $derivative->byteSize || !is_string($checksum) || !hash_equals($derivative->checksum, $checksum)) {
            HostIo::cleanup(fn() => unlink($destination));
            throw new MediaProcessingFailure('derivative_verification_failed', true, 'The committed derivative failed verification.');
        }
    }

    public function removeWorkingDirectory(string $directory): void
    {
        $prefix = $this->root . '/work/';
        if (!str_starts_with($directory, $prefix) || is_link($directory) || !is_dir($directory)) {
            return;
        }
        foreach (HostIo::attempt(fn() => scandir($directory))->value ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $directory . '/' . $item;
            if (is_file($path) && !is_link($path)) {
                HostIo::cleanup(fn() => unlink($path));
            }
        }
        HostIo::cleanup(fn() => rmdir($directory));
    }

    private function existingFile(string $key): string
    {
        $candidate = $this->root . '/' . $key;
        $segments = explode('/', $key);
        $cursor = $this->root;
        foreach ($segments as $segment) {
            $cursor .= '/' . $segment;
            if (is_link($cursor)) {
                throw new MediaProcessingFailure('unsafe_source_path', false, 'The media source path is unsafe.');
            }
        }
        $resolved = realpath($candidate);
        if (
            $resolved === false
            || !is_file($resolved)
            || !str_starts_with($resolved, $this->root . DIRECTORY_SEPARATOR)
        ) {
            throw new MediaProcessingFailure('source_missing', false, 'The media source is unavailable.');
        }
        return $resolved;
    }

    private function ensureDirectory(string $directory): void
    {
        if (!str_starts_with($directory, $this->root . DIRECTORY_SEPARATOR)) {
            throw new \RuntimeException('Media directory escaped its root');
        }
        if (is_link($directory)) {
            throw new \RuntimeException('Media directory cannot be a symbolic link');
        }
        if (!is_dir($directory) && !HostIo::attempt(fn() => mkdir($directory, 0700, true))->value && !is_dir($directory)) {
            throw new \RuntimeException('Media directory could not be created');
        }
        HostIo::required(fn() => chmod($directory, 0700));
        if (self::symbolicLink($directory)) {
            throw new \RuntimeException('Media directory became unsafe');
        }
    }

    private static function symbolicLink(string $path): bool
    {
        return is_link($path);
    }
}
