<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Compiler;

final class FilesystemMediaSource
{
    private string $root;

    public function __construct(string $root, private readonly bool $privateDerivatives = false)
    {
        $resolved = realpath($root);
        if ($resolved === false || !is_dir($resolved) || is_link($root)) {
            throw new \InvalidArgumentException('Compiler media source is unavailable');
        }
        $this->root = rtrim($resolved, DIRECTORY_SEPARATOR);
    }

    public function file(FrozenMedia $media): string
    {
        $relative = self::relativePath($media);
        if ($this->privateDerivatives) {
            if (!((preg_match('/\Adisplay_(480|960|1600|2400)_webp\z/', $media->variant) === 1 && $media->mime === 'image/webp')
                || ($media->variant === 'prepared_video' && in_array($media->mime, ['video/mp4', 'video/webm'], true)))) {
                throw new \InvalidArgumentException('Private compiler derivative recipe is unavailable');
            }
            $relative = 'derivatives/' . $media->id . '/' . $media->id . ($media->variant === 'prepared_video' ? '-prepared-video.' . self::extension($media->mime) : '-' . str_replace('_', '-', substr($media->variant, 8)) . '.webp');
        }
        $cursor = $this->root;
        foreach (explode('/', $relative) as $segment) {
            $cursor .= DIRECTORY_SEPARATOR . $segment;
            if (is_link($cursor)) {
                throw new \RuntimeException('Compiler media source contains a symbolic link');
            }
        }
        $resolved = realpath($cursor);
        if (
            $resolved === false
            || !is_file($resolved)
            || !str_starts_with($resolved, $this->root . DIRECTORY_SEPARATOR)
        ) {
            throw new \RuntimeException('Compiler media source file is unavailable');
        }
        $size = filesize($resolved);
        $checksum = hash_file('sha256', $resolved);
        if (
            !is_int($size)
            || !is_string($checksum)
            || $size !== $media->byteSize
            || !hash_equals($media->checksum, $checksum)
        ) {
            throw new \RuntimeException('Compiler media source failed immutable verification');
        }
        return $resolved;
    }

    public static function relativePath(FrozenMedia $media): string
    {
        return $media->id . '-' . $media->variant . '.' . self::extension($media->mime);
    }

    public static function publicPath(FrozenMedia $media): string
    {
        return 'media/' . self::relativePath($media);
    }

    private static function extension(string $mime): string
    {
        return match ($mime) {
            'image/avif' => 'avif',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'video/mp4' => 'mp4',
            'video/webm' => 'webm',
            default => throw new \InvalidArgumentException('Compiler media MIME type is unsupported'),
        };
    }
}
