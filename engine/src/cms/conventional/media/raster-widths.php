<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Media;

/** Finite delivery recipes; the legacy default remains an immutable compatibility boundary. */
final class RasterWidths
{
    public const WIDTHS = [480, 960, 1600, 2400];
    public const EXTRA_WIDTHS = [480, 1600, 2400];

    public static function variant(int $width): string
    {
        if (!in_array($width, self::WIDTHS, true)) {
            throw new \InvalidArgumentException('Raster delivery width is unsupported');
        }
        return $width . '-webp';
    }

    public static function maximum(string $variant): ?int
    {
        foreach (self::WIDTHS as $width) {
            if ($variant === $width . '-webp') {
                return $width;
            }
        }
        return null;
    }
}
