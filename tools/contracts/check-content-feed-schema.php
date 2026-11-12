<?php

declare(strict_types=1);

require_once __DIR__ . '/content-feed-page-schema.php';

$root = dirname(__DIR__, 2) . '/schemas';
$schema = json_decode((string) file_get_contents($root . '/portable-cms-feed-snapshot-v1.schema.json'), true, 64, JSON_THROW_ON_ERROR);
unset($schema['$schema'], $schema['$id']);
$path = $root . '/portable-cms-artifact-v1.schema.json';
$artifact = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
if (in_array('--write', $argv, true)) {
    $source = (string) file_get_contents($path);
    $fragment = '    "contentFeed": ' . json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . ",\n";
    $source = preg_replace_callback('/^    "contentFeed": [^\r\n]*\r?\n/m', static fn(): string => $fragment, $source, 1, $count);
    if ($count !== 1 || file_put_contents($path, $source) === false) {
        throw new RuntimeException('Content feed embedding could not be written');
    }
} elseif (($artifact['properties']['contentFeed'] ?? null) !== $schema) {
    throw new RuntimeException('Content feed artifact schema embedding differs');
}
$pagePath = $root . '/portable-cms-feed-page-v1.schema.json';
$page = json_decode((string) file_get_contents($pagePath), true, 64, JSON_THROW_ON_ERROR);
$embedded = contentFeedPageSchema($root);
if (in_array('--write', $argv, true)) {
    $page['$defs'] = $embedded['definitions'];
    $page['properties']['records']['items']['properties']['fields']['additionalProperties'] = $embedded['values'];
    file_put_contents($pagePath, json_encode($page, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
} elseif ($page['$defs'] !== $embedded['definitions'] || $page['properties']['records']['items']['properties']['fields']['additionalProperties'] !== $embedded['values']) {
    throw new RuntimeException('Feed leaf schema embedding differs');
}
$profile1 = json_decode((string) file_get_contents($root . '/portable-cms-profile-v1.schema.json'), true, 64, JSON_THROW_ON_ERROR);
$profile2 = json_decode((string) file_get_contents($root . '/portable-cms-profile-v2.schema.json'), true, 64, JSON_THROW_ON_ERROR);
if ($profile1['properties']['contentFeed'] !== $profile2['properties']['contentFeed']) {
    throw new RuntimeException('Profile feed declarations differ between supported formats');
}
fwrite(STDOUT, "Content feed schema embedding matches.\n");
