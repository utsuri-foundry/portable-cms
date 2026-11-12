<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Media;

use Utsuri\PortableCms\Conventional\HostIo;

final class ImagickRasterProcessor implements RasterProcessor
{
    private const WEBP_QUALITY = 78;
    private const RECIPE_VERSION = 2;

    public function process(MediaSource $source, string $sourcePath, string $workingDirectory): ProcessedMedia
    {
        $targetWidth = $source->maximumWidth;
        $variant = RasterWidths::variant($targetWidth);
        if (!extension_loaded('imagick') || !class_exists(\Imagick::class)) {
            throw new MediaProcessingFailure(
                'imagick_unavailable',
                false,
                'The configured raster processor is unavailable.',
            );
        }
        $detectedType = self::detectedType($sourcePath);
        if ($detectedType === null || $detectedType !== $source->declaredType) {
            throw new MediaProcessingFailure(
                'source_type_mismatch',
                false,
                'The source signature does not match its declared media type.',
            );
        }
        $size = HostIo::attempt(fn() => filesize($sourcePath))->value;
        $checksum = HostIo::attempt(fn() => hash_file('sha256', $sourcePath))->value;
        if ($size !== $source->byteSize || !is_string($checksum) || !hash_equals($source->checksum, $checksum)) {
            throw new MediaProcessingFailure(
                'source_integrity_mismatch',
                false,
                'The private source no longer matches its stored metadata.',
            );
        }

        self::applyResourceLimits();
        $probe = new \Imagick();
        try {
            $probe->pingImage($sourcePath . '[0]');
            $sourceWidth = $probe->getImageWidth();
            $sourceHeight = $probe->getImageHeight();
        } catch (\Throwable $error) {
            throw new MediaProcessingFailure('source_decode_failed', false, 'The source image could not be decoded.', $error);
        } finally {
            $probe->clear();
            $probe->destroy();
        }
        if (!MediaContract::imageDimensionsAllowed($sourceWidth, $sourceHeight)) {
            throw new MediaProcessingFailure('source_geometry_rejected', false, 'The source image dimensions exceed the limit.');
        }

        $filename = $source->mediaId . '-' . $variant . '.webp';
        $temporaryPath = $workingDirectory . '/' . $filename . '.tmp';
        $image = new \Imagick();
        try {
            $image->readImage($sourcePath . '[0]');
            $image->setIteratorIndex(0);
            $autoOrient = 'autoOrient';
            $legacyAutoOrient = 'autoOrientate';
            if (is_callable([$image, $autoOrient])) {
                $image->{$autoOrient}();
            } elseif (is_callable([$image, $legacyAutoOrient])) {
                $image->{$legacyAutoOrient}();
            } else {
                throw new \RuntimeException('Imagick does not expose auto-orientation');
            }
            if ($image->getImageColorspace() !== \Imagick::COLORSPACE_SRGB) {
                $image->transformImageColorspace(\Imagick::COLORSPACE_SRGB);
            }
            $image->stripImage();
            $image->setImagePage(0, 0, 0, 0);
            if ($image->getImageWidth() > $targetWidth) {
                $targetHeight = max(1, (int) round(
                    $image->getImageHeight() * $targetWidth / $image->getImageWidth(),
                ));
                $image->resizeImage(
                    $targetWidth,
                    $targetHeight,
                    \Imagick::FILTER_LANCZOS,
                    1,
                    true,
                );
            }
            $image->setOption('webp:method', '4');
            $image->setImageFormat('webp');
            $image->setImageCompressionQuality(self::WEBP_QUALITY);
            if (!$image->writeImage($temporaryPath)) {
                throw new \RuntimeException('Imagick did not write the derivative');
            }
            $width = $image->getImageWidth();
            $height = $image->getImageHeight();
        } catch (MediaProcessingFailure $error) {
            throw $error;
        } catch (\Throwable $error) {
            if (is_file($temporaryPath) || is_link($temporaryPath)) {
                HostIo::cleanup(fn() => unlink($temporaryPath));
            }
            throw new MediaProcessingFailure('derivative_processing_failed', false, 'The image derivative could not be generated.', $error);
        } finally {
            $image->clear();
            $image->destroy();
        }
        $derivativeSize = HostIo::attempt(fn() => filesize($temporaryPath))->value;
        $derivativeChecksum = HostIo::attempt(fn() => hash_file('sha256', $temporaryPath))->value;
        if (
            !is_int($derivativeSize)
            || $derivativeSize < 1
            || !is_string($derivativeChecksum)
            || $width < 1
            || $height < 1
        ) {
            if (is_file($temporaryPath) || is_link($temporaryPath)) {
                HostIo::cleanup(fn() => unlink($temporaryPath));
            }
            throw new MediaProcessingFailure('derivative_verification_failed', false, 'The image derivative failed verification.');
        }
        $version = \Imagick::getVersion();
        $processorVersion = is_array($version) && is_string($version['versionString'] ?? null)
            ? mb_substr($version['versionString'], 0, 180)
            : 'unknown';
        return new ProcessedMedia(
            $checksum,
            $detectedType,
            $sourceWidth,
            $sourceHeight,
            new MediaDerivative(
                $source->mediaId,
                $variant,
                'derivatives/' . $source->mediaId . '/' . $filename,
                $filename,
                'image/webp',
                $width,
                $height,
                $derivativeSize,
                $derivativeChecksum,
                'imagick',
                $processorVersion,
                self::RECIPE_VERSION,
                [
                    'firstFrame' => 1,
                    'autoOrient' => 1,
                    'stripMetadata' => 1,
                    'colorspace' => 'srgb',
                    'maximumWidth' => $targetWidth,
                    'maximumSourceDimension' => MediaContract::MAX_IMAGE_DIMENSION,
                    'maximumSourcePixels' => MediaContract::MAX_IMAGE_PIXELS,
                    'quality' => self::WEBP_QUALITY,
                    'format' => 'webp',
                ],
            ),
        );
    }

    private static function detectedType(string $path): ?string
    {
        foreach (['image/jpeg', 'image/png', 'image/webp'] as $type) {
            if (MediaContract::signatureMatches($path, $type)) {
                return $type;
            }
        }
        return null;
    }

    private static function applyResourceLimits(): void
    {
        foreach ([
            \Imagick::RESOURCETYPE_MEMORY => 256 * 1024 * 1024,
            \Imagick::RESOURCETYPE_MAP => 512 * 1024 * 1024,
            \Imagick::RESOURCETYPE_DISK => 1024 * 1024 * 1024,
            \Imagick::RESOURCETYPE_AREA => MediaContract::MAX_IMAGE_PIXELS,
            \Imagick::RESOURCETYPE_THREAD => 1,
            \Imagick::RESOURCETYPE_TIME => 45,
        ] as $type => $cap) {
            $current = \Imagick::getResourceLimit($type);
            \Imagick::setResourceLimit($type, $current > 0 ? min($current, $cap) : $cap);
        }
    }
}
