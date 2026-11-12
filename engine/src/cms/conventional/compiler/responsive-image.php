<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Compiler;

use Utsuri\PortableCms\Conventional\Http\Html;

/** Escaped native attributes over only the widths frozen into this preview. */
final class ResponsiveImage
{
    public static function attributes(FrozenMedia $media, string $route, string $sizes): string
    {
        if (!str_starts_with($media->mime, 'image/') || $media->width === null || $media->height === null || $sizes === '' || strlen($sizes) > 500 || preg_match('/[\x00-\x1f\x7f]/', $sizes) === 1) {
            throw new \InvalidArgumentException('Responsive image attributes are invalid');
        }
        $byWidth = [];
        foreach ($media->renditions() as $item) {
            $byWidth[$item->width] = ArtifactUrl::relative('/' . FilesystemMediaSource::publicPath($item), $route) . ' ' . $item->width . 'w';
        }
        ksort($byWidth, SORT_NUMERIC);
        return 'srcset="' . Html::attribute(implode(', ', $byWidth)) . '" sizes="' . Html::attribute($sizes) . '"';
    }
}
