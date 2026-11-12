<?php

declare(strict_types=1);

/** Bounded page values embed the authoritative leaf contracts with local references only.
 * @return array{values: array<string, mixed>, definitions: array<string, mixed>}
 */
function contentFeedPageSchema(string $root): array
{
    $object = static fn(array $properties): array => ['type' => 'object', 'additionalProperties' => false, 'required' => array_keys($properties), 'properties' => $properties];
    $rewrite = static function (mixed $value, string $prefix) use (&$rewrite): mixed {
        if (!is_array($value)) {
            return $value;
        }
        foreach ($value as $key => $item) {
            $value[$key] = is_string($item) ? str_replace('#/$defs/', '#/$defs/' . $prefix, $item) : $rewrite($item, $prefix);
        }
        return $value;
    };
    $definitions = [];
    foreach ([1, 2] as $version) {
        $source = json_decode((string) file_get_contents($root . '/portable-cms-rich-text-v' . $version . '.schema.json'), true, 64, JSON_THROW_ON_ERROR);
        foreach ($source['$defs'] as $key => $value) {
            $definitions['rich' . $version . '-' . $key] = $rewrite($value, 'rich' . $version . '-');
        }
        unset($source['$schema'], $source['$id'], $source['title'], $source['$defs']);
        $definitions['rich' . $version] = $rewrite($source, 'rich' . $version . '-');
    }
    $definitions['reference'] = $object(['typeId' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]*(?:[-_][a-z0-9]+)*$', 'maxLength' => 160], 'recordId' => ['type' => 'string', 'pattern' => '^[a-f0-9]{32}$']]);
    $dimension = ['type' => ['integer', 'null'], 'minimum' => 1, 'maximum' => 50000];
    $definitions['image'] = $object(['url' => ['type' => 'string', 'maxLength' => 4096, 'pattern' => '(?:^|/)feed/v1/media/[a-f0-9]{64}\\.webp$'], 'mime' => ['const' => 'image/webp'], 'width' => $dimension, 'height' => $dimension]);
    $definitions['address'] = json_decode((string) file_get_contents($root . '/portable-cms-web-address-v1.schema.json'), true, 64, JSON_THROW_ON_ERROR);
    unset($definitions['address']['$schema'], $definitions['address']['$id'], $definitions['address']['title']);
    $values = ['anyOf' => [
        ['type' => 'null'], ['type' => 'boolean'], ['type' => 'integer', 'minimum' => -9007199254740991, 'maximum' => 9007199254740991], ['type' => 'string', 'maxLength' => 1000000],
        ['$ref' => '#/$defs/reference'], ['$ref' => '#/$defs/address'], ['$ref' => '#/$defs/rich1'], ['$ref' => '#/$defs/rich2'],
        ['type' => 'array', 'maxItems' => 1000, 'items' => ['anyOf' => [['type' => 'string', 'pattern' => '^[a-f0-9]{32}$'], ['$ref' => '#/$defs/reference'], ['$ref' => '#/$defs/image']]]],
    ]];
    return ['values' => $values, 'definitions' => $definitions];
}
