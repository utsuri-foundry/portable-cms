<?php

declare(strict_types=1);

/** Valid fast-start MP4 plus an opaque free box. Large payload is generated sparsely, never committed or buffered. */
function trailerFixture(string $path, int $size = 250 * 1024 * 1024): string
{
    $clip = (string) file_get_contents(dirname(__DIR__) . '/fixtures/video/prepared.mp4');
    $output = fopen($path, 'xb');
    if (!is_resource($output)) {
        throw new RuntimeException('Trailer fixture is unavailable');
    }
    try {
        fwrite($output, $clip . pack('N', $size - strlen($clip)) . 'free');
        fseek($output, $size - 1);
        fwrite($output, "\0");
    } finally {
        fclose($output);
    }
    return (string) hash_file('sha256', $path);
}

/** Only for small responses/ranges. Large stream proofs write to disk. */
function videoResponseBytes(\Utsuri\PortableCms\Conventional\Http\Response $response): string
{
    $output = fopen('php://temp', 'w+b');
    if (!is_resource($output)) {
        throw new RuntimeException('Response capture is unavailable');
    }
    try {
        $response->writeBodyTo($output);
        rewind($output);
        return (string) stream_get_contents($output);
    } finally {
        fclose($output);
    }
}

/** Qualified test profile with explicit 256 MiB field allowance; the committed reference profile keeps its narrower contract. */
function trailerQualifiedPackage(string $destination): \Utsuri\PortableCms\Conventional\Content\ProfilePackage
{
    $source = dirname(__DIR__, 2) . '/profiles/reference-studio';
    mkdir($destination, 0700);
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($files as $file) {
        $target = $destination . '/' . substr($file->getPathname(), strlen($source) + 1);
        if ($file->isDir()) {
            mkdir($target, 0700);
        } else {
            copy($file->getPathname(), $target);
        }
    }
    $document = json_decode((string) file_get_contents($destination . '/profile.json'), flags: JSON_THROW_ON_ERROR);
    foreach ($document->recordTypes as $type) {
        foreach ($type->fields as $field) {
            if (($field->type ?? null) === 'video_reference') {
                $field->settings->maximumBytes = \Utsuri\PortableCms\Conventional\Media\VideoSupport::MAX_BYTES;
            }
        }
    }
    file_put_contents($destination . '/profile.json', json_encode($document, JSON_THROW_ON_ERROR));
    return \Utsuri\PortableCms\Conventional\Content\ProfilePackage::load($destination);
}
