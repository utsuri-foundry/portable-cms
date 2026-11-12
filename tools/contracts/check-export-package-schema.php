<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/bootstrap.php';

use Utsuri\PortableCms\Conventional\Export\ExportCapacity;

$root = dirname(__DIR__, 2) . '/schemas';
$source = json_decode((string) file_get_contents($root . '/portable-cms-export-v1.schema.json'), true, 64, JSON_THROW_ON_ERROR);
$header = array_intersect_key($source, array_flip(['type', 'additionalProperties', 'required', 'properties']));
$header['required'] = array_values(array_diff($header['required'], ExportCapacity::COLLECTIONS));
$header['properties'] = array_diff_key($header['properties'], array_flip(ExportCapacity::COLLECTIONS));
$chunk = ['type' => 'object', 'additionalProperties' => false, 'required' => ['sha256', 'byteSize', 'rowCount'], 'properties' => [
    'sha256' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$'],
    'byteSize' => ['type' => 'integer', 'minimum' => 3, 'maximum' => ExportCapacity::CHUNK_BYTES],
    'rowCount' => ['type' => 'integer', 'minimum' => 1, 'maximum' => ExportCapacity::CHUNK_ROWS],
]];
$collections = [];
foreach (ExportCapacity::COLLECTIONS as $kind) {
    $collections[$kind] = ['type' => 'array', 'maxItems' => min(ExportCapacity::MAX_CHUNKS, ExportCapacity::limit($kind)), 'items' => ['$ref' => '#/$defs/chunk']];
}
$manifest = ['$schema' => $source['$schema'], '$id' => 'https://utsuri.io/schemas/portable-cms-export-v2.schema.json', 'title' => 'Portable CMS chunked export v2',
    'type' => 'object', 'additionalProperties' => false, 'required' => ['formatVersion', 'metadata', 'collections'],
    'properties' => ['formatVersion' => ['const' => 2], 'metadata' => ['$ref' => '#/$defs/metadata'], 'collections' => ['type' => 'object', 'additionalProperties' => false, 'required' => ExportCapacity::COLLECTIONS, 'properties' => $collections]],
    '$defs' => $source['$defs'] + ['metadata' => $header, 'chunk' => $chunk]];
$expected = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
$path = $root . '/portable-cms-export-v2.schema.json';
if (in_array('--write', $argv, true)) {
    if (file_put_contents($path, $expected) !== strlen($expected)) {
        throw new RuntimeException('Could not write generated chunked export schema');
    }
}
if (!is_file($path) || file_get_contents($path) !== $expected) {
    throw new RuntimeException('Chunked export schema is stale; run its contract generator with --write');
}
fwrite(STDOUT, "Chunked export schema matches its shared metadata and capacity contracts.\n");
