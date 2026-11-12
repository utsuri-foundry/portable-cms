<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Media;

use Utsuri\PortableCms\Conventional\HostIo;

/** Validates prepared containers and preserves their exact bytes as a versioned delivery derivative. */
final class PreencodedVideoProcessor implements MediaProcessor
{
    public static function available(): bool
    {
        return VideoSupport::available();
    }

    /** @return array{width: int, height: int, durationMilliseconds: int, codec: string, hasAudio: bool} */
    public static function inspectFile(string $path, string $mime, int $size): array
    {
        if (!self::available()) {
            throw new MediaProcessingFailure('preencoded_video_unavailable', false, 'Prepared video support is unavailable on this host.');
        }
        $stream = HostIo::attempt(fn() => fopen($path, 'rb'))->value;
        if (!is_resource($stream)) {
            throw new MediaProcessingFailure('video_unreadable', false, 'The prepared video is unavailable.');
        }
        try {
            return VideoMetadata::inspect($mime, $size, static function (int $offset, int $length) use ($stream): string {
                if (HostIo::attempt(fn() => fseek($stream, $offset))->value !== 0) {
                    return '';
                }
                $bytes = HostIo::attempt(fn() => fread($stream, $length))->value;
                return is_string($bytes) ? $bytes : '';
            });
        } finally {
            fclose($stream);
        }
    }

    public function process(MediaSource $source, string $sourcePath, string $workingDirectory): ProcessedMedia
    {
        if (filesize($sourcePath) !== $source->byteSize || hash_file('sha256', $sourcePath) !== $source->checksum) {
            throw new MediaProcessingFailure('source_verification_failed', false, 'The prepared video failed checksum verification.');
        }
        $facts = self::inspectFile($sourcePath, $source->declaredType, $source->byteSize);
        $extension = $source->declaredType === 'video/mp4' ? 'mp4' : 'webm';
        $filename = $source->mediaId . '-prepared-video.' . $extension;
        if (!HostIo::attempt(fn() => copy($sourcePath, $workingDirectory . '/' . $filename . '.tmp'))->value) {
            throw new MediaProcessingFailure('video_copy_failed', true, 'The prepared video could not be staged.');
        }
        $derivative = new MediaDerivative($source->mediaId, 'prepared-video', 'derivatives/' . $source->mediaId . '/' . $filename, $filename, $source->declaredType, $facts['width'], $facts['height'], $source->byteSize, $source->checksum, 'php-preencoded-video', '1', 1, ['durationMilliseconds' => $facts['durationMilliseconds'], 'codec' => $facts['codec'], 'hasAudio' => $facts['hasAudio']]);
        return new ProcessedMedia($source->checksum, $source->declaredType, $facts['width'], $facts['height'], $derivative);
    }
}

final class PreparedMediaProcessor implements MediaProcessor
{
    public function __construct(private readonly RasterProcessor $raster, private readonly MediaProcessor $video = new PreencodedVideoProcessor()) {}

    public function process(MediaSource $source, string $sourcePath, string $workingDirectory): ProcessedMedia
    {
        return (str_starts_with($source->declaredType, 'video/') ? $this->video : $this->raster)->process($source, $sourcePath, $workingDirectory);
    }
}
