<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Compiler;

/** Build-only effect adapter. Input is a frozen display derivative, never an original. */
final class ImagickSharingImageProcessor implements SharingImageProcessor
{
    public function process(FrozenMedia $source, string $verifiedPath): SharingImageOutput
    {
        if (!extension_loaded('imagick') || !in_array($source->mime, ['image/webp', 'image/jpeg', 'image/png'], true)
            || !str_starts_with($source->variant, 'display_')
            || $source->byteSize < 1 || $source->byteSize > 10 * 1024 * 1024
            || is_link($verifiedPath) || !is_file($verifiedPath)
            || filesize($verifiedPath) !== $source->byteSize
            || hash_file('sha256', $verifiedPath) !== $source->checksum) {
            throw new \InvalidArgumentException('Sharing source is unavailable or differs from frozen display media');
        }
        $limits = [\Imagick::RESOURCETYPE_MEMORY => 256 * 1024 * 1024,
            \Imagick::RESOURCETYPE_MAP => 512 * 1024 * 1024,
            \Imagick::RESOURCETYPE_DISK => 1024 * 1024 * 1024,
            \Imagick::RESOURCETYPE_AREA => 25_000_000,
            \Imagick::RESOURCETYPE_THREAD => 1, \Imagick::RESOURCETYPE_TIME => 45];
        $previous = [];
        $probe = new \Imagick();
        $image = new \Imagick();
        $canvas = new \Imagick();
        try {
            foreach ($limits as $kind => $limit) {
                $previous[$kind] = \Imagick::getResourceLimit($kind);
                if (!\Imagick::setResourceLimit($kind, $previous[$kind] > 0 ? min($limit, $previous[$kind]) : $limit)) {
                    throw new \RuntimeException('Sharing image resource limits could not be applied');
                }
            }
            $probe->pingImage($verifiedPath);
            $width = $probe->getImageWidth();
            $height = $probe->getImageHeight();
            if ($probe->getNumberImages() !== 1 || $width < 1 || $height < 1
                || $width > 20_000 || $height > 20_000 || $width * $height > 25_000_000
                || $source->width !== $width || $source->height !== $height
                || 'image/' . strtolower($probe->getImageFormat()) !== $source->mime) {
                throw new \InvalidArgumentException('Sharing source dimensions or raster format are invalid');
            }
            $image->readImage($verifiedPath);
            $image->transformImageColorspace(\Imagick::COLORSPACE_SRGB);
            $image->stripImage();
            $scale = min(1, 1200 / $width, 630 / $height);
            $targetWidth = max(1, (int) round($width * $scale));
            $targetHeight = max(1, (int) round($height * $scale));
            if ($scale < 1) {
                $image->resizeImage($targetWidth, $targetHeight, \Imagick::FILTER_LANCZOS, 1);
            }
            $image->setImagePage(0, 0, 0, 0);
            $canvas->newImage(1200, 630, new \ImagickPixel('white'));
            $canvas->setImageColorspace(\Imagick::COLORSPACE_SRGB);
            $canvas->compositeImage($image, \Imagick::COMPOSITE_OVER, (int) floor((1200 - $targetWidth) / 2), (int) floor((630 - $targetHeight) / 2));
            $canvas->setImageFormat('jpeg');
            $canvas->setImageCompressionQuality(82);
            $canvas->stripImage();
            $bytes = $canvas->getImageBlob();
            return new SharingImageOutput(new SharingImage($source->id, $source->checksum, hash('sha256', $bytes), strlen($bytes), substr(\Imagick::getVersion()['versionString'], 0, 180)), $bytes);
        } finally {
            $probe->clear();
            $image->clear();
            $canvas->clear();
            foreach ($previous as $kind => $limit) {
                if (!\Imagick::setResourceLimit($kind, $limit)) {
                    throw new \RuntimeException('Sharing image resource limits could not be restored');
                }
            }
        }
    }
}
