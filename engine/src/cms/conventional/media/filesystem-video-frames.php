<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Media;

use Utsuri\PortableCms\Conventional\HostIo;
use Utsuri\PortableCms\Conventional\I18n\InvalidInput;

/** Small private browser-generated hints, outside derivatives, snapshots, export and restore. */
final readonly class FilesystemVideoFrames
{
    private string $root;

    public function __construct(string $root)
    {
        $resolved = realpath($root);
        if ($resolved === false || !is_dir($resolved) || is_link($root)) {
            throw new MediaProcessingFailure('video_frame_unavailable', false, 'Video frame cache is unavailable.');
        }
        $this->root = rtrim($resolved, DIRECTORY_SEPARATOR);
    }

    public function find(MediaDerivative $video): ?VideoFrame
    {
        $bytes = $this->read($video);
        return $bytes === null ? null : self::inspect($video, $bytes);
    }

    public function read(MediaDerivative $video): ?string
    {
        $path = $this->path($video);
        if (!is_file($path) || is_link($path)) {
            return null;
        }
        $size = HostIo::attempt(fn() => filesize($path))->value;
        if (!is_int($size) || $size < 12 || $size > VideoFrame::MAX_BYTES) {
            return null;
        }
        $bytes = HostIo::attempt(fn() => file_get_contents($path, false, null, 0, VideoFrame::MAX_BYTES + 1))->value;
        if (!is_string($bytes)) {
            return null;
        }
        try {
            self::inspect($video, $bytes);
            return $bytes;
        } catch (InvalidInput) {
            return null;
        }
    }

    public function store(MediaDerivative $video, string $bytes): bool
    {
        self::inspect($video, $bytes);
        $path = $this->path($video);
        $directory = dirname($path);
        if (!is_dir($directory) && !HostIo::attempt(fn() => mkdir($directory, 0700))->value && !is_dir($directory)) {
            throw new MediaProcessingFailure('video_frame_unavailable', true, 'Video frame cache is unavailable.');
        }
        if (is_link($directory) || is_link($path) || is_link($path . '.lock') || is_link($path . '.tmp')) {
            throw new MediaProcessingFailure('video_frame_unavailable', false, 'Video frame cache is unavailable.');
        }
        $lock = HostIo::attempt(fn() => fopen($path . '.lock', 'c+b'))->value;
        if (!is_resource($lock)) {
            throw new MediaProcessingFailure('video_frame_unavailable', true, 'Video frame cache is unavailable.');
        }
        try {
            HostIo::required(fn() => chmod($path . '.lock', 0600));
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                return false;
            }
            if ($this->read($video) !== null) {
                return true;
            }
            $written = HostIo::attempt(fn() => file_put_contents($path . '.tmp', $bytes))->value;
            if ($written !== strlen($bytes)) {
                throw new MediaProcessingFailure('video_frame_unavailable', true, 'Video frame cache is unavailable.');
            }
            HostIo::required(fn() => chmod($path . '.tmp', 0600));
            if (!HostIo::attempt(fn() => rename($path . '.tmp', $path))->value) {
                throw new MediaProcessingFailure('video_frame_unavailable', true, 'Video frame cache is unavailable.');
            }
            return true;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function path(MediaDerivative $video): string
    {
        if ($video->variant !== 'prepared-video' || !in_array($video->mime, ['video/mp4', 'video/webm'], true)
            || preg_match('/\A[a-f0-9]{32}\z/', $video->mediaId) !== 1 || preg_match('/\A[a-f0-9]{64}\z/', $video->checksum) !== 1
            || is_link($this->root . '/video-frames')) {
            throw new MediaProcessingFailure('video_frame_unavailable', false, 'Video frame cache is unavailable.');
        }
        return $this->root . '/video-frames/' . $video->mediaId . '-' . $video->checksum . '.webp';
    }

    private static function inspect(MediaDerivative $video, string $bytes): VideoFrame
    {
        if (!function_exists('getimagesizefromstring')) {
            throw new MediaProcessingFailure('video_frame_unavailable', false, 'Video frame cache is unavailable.');
        }
        $size = strlen($bytes);
        if ($size < 12 || $size > VideoFrame::MAX_BYTES || substr($bytes, 0, 4) !== 'RIFF' || substr($bytes, 8, 4) !== 'WEBP'
            || (unpack('Vsize', substr($bytes, 4, 4))['size'] ?? -1) + 8 !== $size) {
            throw new InvalidInput('video.frame_invalid');
        }
        $facts = HostIo::attempt(fn() => getimagesizefromstring($bytes))->value;
        [$width, $height] = VideoFrame::dimensions($video->width, $video->height);
        if (!is_array($facts) || ($facts[2] ?? null) !== IMAGETYPE_WEBP || $facts[0] !== $width || $facts[1] !== $height) {
            throw new InvalidInput('video.frame_invalid');
        }
        return new VideoFrame($width, $height, $size, hash('sha256', $bytes));
    }
}
