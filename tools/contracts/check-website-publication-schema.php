<?php

declare(strict_types=1);

// Canonical local embedding; schemas never resolve remote references at runtime.
$root = dirname(__DIR__, 2) . '/schemas';
$schema = json_decode((string) file_get_contents($root . '/portable-cms-website-publication-v1.schema.json'), true, 32, JSON_THROW_ON_ERROR);
unset($schema['$schema'], $schema['$id'], $schema['title']);
$path = $root . '/portable-cms-export-v1.schema.json';
$export = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
if (in_array('--write', $argv, true)) {
    $source = (string) file_get_contents($path);
    $source = preg_replace('/^    "websitePublication": [^\r\n]*\r?\n/m', '', $source) ?? throw new RuntimeException('Embedding replacement failed');
    if (isset(json_decode($source, true, 64, JSON_THROW_ON_ERROR)['properties']['websitePublication'])) {
        throw new RuntimeException('Unexpected website publication embedding format');
    }
    $marker = '    "formatVersion": { "const": 1 },';
    $fragment = '    "websitePublication": ' . json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . ",\n" . $marker;
    $source = preg_replace_callback('/^    "formatVersion": \{ "const": 1 \},$/m', static fn(): string => $fragment, $source, 1, $count);
    if ($count !== 1 || file_put_contents($path, $source) === false) {
        throw new RuntimeException('Website publication embedding could not be written');
    }
} elseif (($export['properties']['websitePublication'] ?? null) !== $schema) {
    throw new RuntimeException('Website publication export schema embedding differs');
}
fwrite(STDOUT, "Website publication schema embedding matches.\n");
