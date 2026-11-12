<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Media\ImagickRasterProcessor;
use Utsuri\PortableCms\Conventional\Media\MediaSource;

require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/bootstrap.php';

// Fictional committed studies are the source. Generated widths and pins have one input.
$profile = dirname(__DIR__, 2) . '/profiles/reference-studio';
$path = $profile . '/fixtures/snapshot.json';
$source = (string) file_get_contents($path);
$document = json_decode($source, true, 64, JSON_THROW_ON_ERROR);
$work = sys_get_temp_dir() . '/reference-image-widths-' . bin2hex(random_bytes(8));
mkdir($work, 0700);
try {
    foreach ($document['media'] as $media) {
        $widths = [];
        $original = $profile . '/fixtures/media/' . $media['id'] . '-' . $media['variant'] . '.webp';
        foreach ([480, 960] as $width) {
            if ($width > $media['width']) {
                continue;
            }
            $input = new MediaSource($media['id'], $media['ownerRecordId'], str_repeat('0', 32), $media['mime'], $media['byteSize'], $media['sha256'], '', $width);
            $processed = (new ImagickRasterProcessor())->process($input, $original, $work)->derivative;
            $name = $media['id'] . '-display_' . $width . '_webp.webp';
            if (!rename($work . '/' . $processed->filename . '.tmp', $profile . '/fixtures/media/' . $name)) {
                throw new RuntimeException('Fictional image width could not be stored');
            }
            $widths[] = (new \Utsuri\PortableCms\Conventional\Media\RasterImageWidth($width, $processed->width, $processed->height, $processed->byteSize, $processed->checksum))->document();
        }
        $pattern = '/(^      "id": "' . preg_quote($media['id'], '/') . '"[\s\S]*?"height": [0-9]+,\n)(?:      "widths": [^\n]*\n)?/m';
        $source = preg_replace_callback($pattern, static fn(array $match): string => $match[1] . '      "widths": ' . json_encode($widths, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . ",\n", $source, 1, $count);
        if (!is_string($source) || $count !== 1) {
            throw new RuntimeException('Fictional snapshot image pin could not be updated');
        }
    }
    if (file_put_contents($path, $source) === false) {
        throw new RuntimeException('Fictional snapshot could not be written');
    }
} finally {
    foreach (glob($work . '/*') ?: [] as $file) {
        unlink($file);
    }
    rmdir($work);
}
