<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Media;

/** Immutable prepared bytes, shared by private projections and frozen compiler input. */
final readonly class RasterImageWidth
{
    public function __construct(public int $maximumWidth, public int $width, public int $height, public int $byteSize, public string $checksum)
    {
        RasterWidths::variant($maximumWidth);
        if ($width < 1 || $width > $maximumWidth || $height < 1 || $height > 20_000 || $byteSize < 1 || $byteSize > 40 * 1024 * 1024 || preg_match('/\A[a-f0-9]{64}\z/', $checksum) !== 1) {
            throw new \InvalidArgumentException('Raster image width is invalid');
        }
    }

    /** @return array{variant: string, mime: string, width: int, height: int, byteSize: int, sha256: string} */
    public function document(): array
    {
        return ['variant' => 'display_' . $this->maximumWidth . '_webp', 'mime' => 'image/webp', 'width' => $this->width, 'height' => $this->height, 'byteSize' => $this->byteSize, 'sha256' => $this->checksum];
    }

    /** @return list<self> */
    public static function fromDocument(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > 3) {
            throw new \InvalidArgumentException('Raster image widths are invalid');
        }
        $result = [];
        $seen = [];
        foreach ($value as $item) {
            if (!is_array($item) || count($item) !== 6 || !is_string($item['variant'] ?? null) || preg_match('/\Adisplay_(480|960|1600|2400)_webp\z/', $item['variant'], $matches) !== 1
                || ($item['mime'] ?? null) !== 'image/webp' || !is_int($item['width'] ?? null) || !is_int($item['height'] ?? null) || !is_int($item['byteSize'] ?? null) || !is_string($item['sha256'] ?? null) || isset($seen[$item['variant']])) {
                throw new \InvalidArgumentException('Raster image width is invalid');
            }
            $seen[$item['variant']] = true;
            $result[] = new self((int) $matches[1], $item['width'], $item['height'], $item['byteSize'], $item['sha256']);
        }
        return $result;
    }
}
