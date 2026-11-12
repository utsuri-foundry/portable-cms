<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Media;

/** Rebuildable administration hint. It is never a poster selection or canonical media. */
final readonly class VideoFrame
{
    public const MAX_BYTES = 65536;
    public const MAX_EDGE = 320;
    public const REQUEST_BYTES = 131072;

    public function __construct(public int $width, public int $height, public int $byteSize, public string $checksum) {}

    /** @return array{int, int} */
    public static function dimensions(int $width, int $height): array
    {
        $scale = min(1, self::MAX_EDGE / max(1, $width, $height));
        return [max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale))];
    }
}
