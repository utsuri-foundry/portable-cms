<?php

declare(strict_types=1);

/** Bounded container metadata probes; altered clips are for inspection, never playback. */
function videoLimitsClip(string $variant): string
{
    $bytes = (string) file_get_contents(dirname(__DIR__) . '/fixtures/video/prepared.mp4');
    if ($variant === 'fitting') {
        return $bytes;
    }
    $movie = strpos($bytes, 'moov');
    if ($movie === false) {
        throw new RuntimeException('Fixture movie is missing');
    }
    $start = $movie - 4;
    $size = unpack('Nsize', substr($bytes, $start, 4))['size'];
    $body = substr($bytes, $movie + 4, $size - 8);
    if ($variant === 'sound') {
        $track = strpos($body, 'trak');
        $length = unpack('Nsize', substr($body, $track - 4, 4))['size'];
        $audio = str_replace(['vide', 'avc1'], ['soun', 'mp4a'], substr($body, $track - 4, $length));
        $body .= $audio;
    } elseif ($variant === 'duration') {
        $header = strpos($body, 'mvhd') + 4;
        $scale = unpack('Nscale', substr($body, $header + 12, 4))['scale'];
        $body = substr_replace($body, pack('N', 16000 * $scale / 1000), $header + 16, 4);
    } elseif ($variant === 'width' || $variant === 'height') {
        $sample = strpos($body, 'avc1');
        $body = substr_replace($body, pack('n', $variant === 'width' ? 2000 : 1200), $sample + ($variant === 'width' ? 28 : 30), 2);
    } else {
        throw new InvalidArgumentException('Unknown video metadata probe');
    }
    return substr($bytes, 0, $start) . pack('N', strlen($body) + 8) . 'moov' . $body . substr($bytes, $start + $size);
}

/** Minimal EBML metadata probes distinguish Opus, Vorbis and absence of audio. */
function videoLimitsWebm(?string $audio): string
{
    $element = static fn(string $id, string $value): string => hex2bin($id) . (strlen($value) < 127 ? chr(128 + strlen($value)) : pack('n', 16384 + strlen($value))) . $value;
    $video = $element('AE', $element('83', "\1") . $element('86', 'V_VP9') . $element('E0', $element('B0', "\xa0") . $element('BA', "\x5a")));
    $sound = $audio === null ? '' : $element('AE', $element('83', "\2") . $element('86', $audio));
    $info = $element('1549A966', $element('4489', pack('E', 1000.0)));
    return $element('1A45DFA3', $element('4282', 'webm')) . $element('18538067', $info . $element('1654AE6B', $video . $sound) . $element('1F43B675', "\0"));
}

/** @param array<string, mixed> $settings */
function videoLimitsPackage(string $root, array $settings): \Utsuri\PortableCms\Conventional\Content\ProfilePackage
{
    trailerQualifiedPackage($root);
    $path = $root . '/profile.json';
    $document = json_decode((string) file_get_contents($path), flags: JSON_THROW_ON_ERROR);
    foreach ($document->recordTypes as &$type) {
        foreach ($type->fields as &$field) {
            if (($field->type ?? null) === 'video_reference') {
                $field->settings = (object) ['posterRequired' => true, 'maximumBytes' => 10485760, ...$settings];
            }
        }
        unset($field);
    }
    unset($type);
    file_put_contents($path, json_encode($document, JSON_THROW_ON_ERROR));
    // Reuse the already loaded fictional renderer classes in this PHP test process.
    $compiler = dirname(__DIR__, 2) . '/profiles/reference-studio/src/compiler.php';
    file_put_contents($root . '/src/compiler.php', '<?php return require ' . var_export($compiler, true) . ';');
    return \Utsuri\PortableCms\Conventional\Content\ProfilePackage::load($root);
}
