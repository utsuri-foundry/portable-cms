<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Compiler;

use Utsuri\PortableCms\Conventional\HostIo;

/** Build-only icon processing: decode only signature-checked prepared raster bytes. */
final class ImagickWebsiteIconProcessor implements WebsiteIconProcessor
{
    public function process(FrozenMedia $source, string $verifiedPath): WebsiteIconOutput
    {
        if (!extension_loaded('imagick') || !in_array($source->mime, ['image/png', 'image/webp', 'image/jpeg'], true)
            || !str_starts_with($source->variant, 'display_') || $source->width === null || $source->width < 512
            || $source->height !== $source->width || $source->width > 5000
            || $source->byteSize < 1 || $source->byteSize > 10 * 1024 * 1024
            || is_link($verifiedPath) || !is_file($verifiedPath) || HostIo::attempt(static fn() => filesize($verifiedPath))->value !== $source->byteSize) {
            throw new \InvalidArgumentException('Website icon requires a square prepared raster of at least 512 pixels');
        }
        $data = HostIo::attempt(static fn() => file_get_contents($verifiedPath, false, null, 0, $source->byteSize + 1))->value;
        if (!is_string($data) || strlen($data) !== $source->byteSize || hash('sha256', $data) !== $source->checksum || !match ($source->mime) {
            'image/png' => str_starts_with($data, "\x89PNG\r\n\x1a\n"),
            'image/jpeg' => str_starts_with($data, "\xff\xd8\xff"),
            'image/webp' => str_starts_with($data, 'RIFF') && substr($data, 8, 4) === 'WEBP',
        }) {
            throw new \InvalidArgumentException('Website icon source differs from its frozen raster signature');
        }
        $limits = [\Imagick::RESOURCETYPE_MEMORY => 256 * 1024 * 1024, \Imagick::RESOURCETYPE_MAP => 512 * 1024 * 1024,
            \Imagick::RESOURCETYPE_DISK => 1024 * 1024 * 1024, \Imagick::RESOURCETYPE_AREA => 25_000_000,
            \Imagick::RESOURCETYPE_THREAD => 1, \Imagick::RESOURCETYPE_TIME => 45];
        $previous = [];
        $image = new \Imagick();
        try {
            foreach ($limits as $kind => $limit) {
                $previous[$kind] = \Imagick::getResourceLimit($kind);
                if (!\Imagick::setResourceLimit($kind, $previous[$kind] > 0 ? min($limit, $previous[$kind]) : $limit)) {
                    throw new \RuntimeException('Website icon resource limit could not be applied');
                }
            }
            $image->pingImageBlob($data);
            if ($image->getNumberImages() !== 1 || $image->getImageWidth() !== $source->width || $image->getImageHeight() !== $source->height
                || 'image/' . strtolower($image->getImageFormat()) !== $source->mime) {
                throw new \InvalidArgumentException('Website icon raster format or dimensions differ from the frozen source');
            }
            $image->clear();
            $image->readImageBlob($data);
            $image->transformImageColorspace(\Imagick::COLORSPACE_SRGB);
            $image->stripImage();
            $bytes = [];
            $files = [];
            foreach ([32, 180, 512] as $size) {
                $derivative = clone $image;
                try {
                    $derivative->resizeImage($size, $size, \Imagick::FILTER_LANCZOS, 1);
                    $derivative->setImagePage(0, 0, 0, 0);
                    $derivative->setImageFormat('png');
                    $derivative->stripImage();
                    $bytes[$size] = $derivative->getImageBlob();
                    $sha256 = hash('sha256', $bytes[$size]);
                    $files[] = ['size' => $size, 'path' => 'icons/' . $sha256 . '.png', 'sha256' => $sha256, 'byteSize' => strlen($bytes[$size])];
                } finally {
                    $derivative->clear();
                }
            }
            return new WebsiteIconOutput(new WebsiteIcon($source->id, $source->checksum, substr(\Imagick::getVersion()['versionString'], 0, 180), $files), $bytes);
        } finally {
            $image->clear();
            foreach ($previous as $kind => $limit) {
                if (!\Imagick::setResourceLimit($kind, $limit)) {
                    throw new \RuntimeException('Website icon resource limit could not be restored');
                }
            }
        }
    }
}
