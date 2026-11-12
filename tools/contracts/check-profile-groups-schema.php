<?php

declare(strict_types=1);

// Profile v3 is v2's field contract plus bounded authoring references, never a second field model.
$root = dirname(__DIR__, 2);
$schema = json_decode((string) file_get_contents($root . '/schemas/portable-cms-profile-v2.schema.json'), true, 64, JSON_THROW_ON_ERROR);
$schema['$id'] = 'https://schemas.utsuri.io/portable-cms/profile-v3.schema.json';
$schema['title'] = 'Portable CMS profile manifest v3';
$schema['properties']['formatVersion']['const'] = 3;
$defs = &$schema['$defs'];
$labels = ['type' => 'object', 'maxProperties' => 32, 'propertyNames' => ['$ref' => '#/$defs/fieldId'], 'additionalProperties' => ['$ref' => '#/$defs/label']];
$mapping = $defs['groupSettings']['properties']['searchSharing'];
unset($mapping['required'], $mapping['oneOf'], $mapping['dependentRequired']);
foreach ($mapping['properties'] as &$property) {
    $property = ['anyOf' => [$property, ['type' => 'null']]];
}
unset($property);
$defs['fieldGroupReference'] = [
    'type' => 'object', 'additionalProperties' => false, 'required' => ['id', 'fieldGroup'],
    'properties' => [
        'id' => ['$ref' => '#/$defs/fieldId'], 'fieldGroup' => ['$ref' => '#/$defs/canonicalId'],
        'label' => ['$ref' => '#/$defs/label'], 'hint' => $defs['fieldBase']['properties']['hint'], 'labels' => $labels,
        'itemLabel' => ['$ref' => '#/$defs/label'], 'mapping' => $mapping,
    ],
];
$defs['fieldGroupInclude'] = [
    'type' => 'object', 'additionalProperties' => false, 'required' => ['includeFieldsFrom'],
    'properties' => ['includeFieldsFrom' => ['$ref' => '#/$defs/canonicalId'], 'labels' => $labels],
];
$base = $defs['fieldBase'];
$base['required'] = array_values(array_diff($base['required'], ['id']));
unset($base['properties']['id']);
$base['properties']['type'] = ['enum' => ['group', 'repeatable_group']];
$defs['fieldGroupDefinition'] = ['allOf' => [$base, ['$ref' => '#/$defs/fieldSettings']]];
$defs['inlineField'] = $defs['field'];
$defs['field'] = ['oneOf' => [['$ref' => '#/$defs/inlineField'], ['$ref' => '#/$defs/fieldGroupReference']]];
$defs['leafFields']['items'] = ['oneOf' => [['$ref' => '#/$defs/leafField'], ['$ref' => '#/$defs/fieldGroupInclude']]];
$schema['properties']['fieldGroups'] = [
    'type' => 'object', 'maxProperties' => 32,
    'propertyNames' => ['$ref' => '#/$defs/canonicalId'], 'additionalProperties' => ['$ref' => '#/$defs/fieldGroupDefinition'],
];
$path = $root . '/schemas/portable-cms-profile-v3.schema.json';
$bytes = json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
if (in_array('--write', $argv, true)) {
    file_put_contents($path, $bytes);
} elseif (!is_file($path) || (string) file_get_contents($path) !== $bytes) {
    throw new RuntimeException('Profile v3 schema differs; run tools/contracts/check-profile-groups-schema.php --write');
}
fwrite(STDOUT, "Profile v3 schema matches the authoritative v2 fields and bounded group references.\n");
