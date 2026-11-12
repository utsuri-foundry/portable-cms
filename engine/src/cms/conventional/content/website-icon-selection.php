<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Content;

/** Media facts required for an editor-selected website icon, shared by save and restore. */
final class WebsiteIconSelection
{
    public static function eligible(string $status, string $mime, ?int $width, ?int $height): bool
    {
        return $status === 'ready' && in_array($mime, ['image/png', 'image/webp', 'image/jpeg'], true)
            && $width !== null && $width >= 512 && $height === $width;
    }
}
