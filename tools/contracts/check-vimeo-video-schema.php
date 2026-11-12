<?php

declare(strict_types=1);

// Canonical local embedding: field-dependent bodies are additionally checked by VimeoVideo.
$root = dirname(__DIR__, 2) . '/schemas';
$value = json_decode((string) file_get_contents($root . '/portable-cms-vimeo-video-v1.schema.json'), true, 32, JSON_THROW_ON_ERROR);
unset($value['$schema'], $value['$id'], $value['title']);
foreach (['portable-cms-snapshot-v1.schema.json', 'portable-cms-export-v1.schema.json'] as $name) {
    $path = $root . '/' . $name;
    $source = (string) file_get_contents($path);
    $schema = json_decode($source, true, 64, JSON_THROW_ON_ERROR);
    if (in_array('--write', $argv, true)) {
        $source = preg_replace('/^    "vimeoVideo": [^\r\n]*\r?\n/m', '', $source) ?? throw new RuntimeException('Vimeo embedding could not be replaced');
        $marker = '  "$defs": {' . "\n";
        $fragment = $marker . '    "vimeoVideo": ' . json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . ",\n";
        $source = str_replace($marker, $fragment, $source, $count);
        if ($count !== 1 || file_put_contents($path, $source) === false) {
            throw new RuntimeException('Vimeo embedding could not be written');
        }
    } elseif (($schema['$defs']['vimeoVideo'] ?? null) !== $value) {
        throw new RuntimeException('Vimeo value embedding differs; regenerate with --write');
    }
}
fwrite(STDOUT, "Vimeo value schemas match the authoritative contract.\n");
