<?php

declare(strict_types=1);

// Embedded local schemas derive from one canonical input; never add remote resolution.
$root = dirname(__DIR__, 2);
$write = in_array('--write', $argv, true);
$v1 = json_decode((string) file_get_contents($root . '/schemas/portable-cms-design-registries-v1.schema.json'), true, 32, JSON_THROW_ON_ERROR);
// V2 changes only the bounded font collection. V1 stays an immutable contract.
$v2 = $v1;
$v2['$id'] = 'https://schemas.utsuri.io/portable-cms/design-registries-v2.schema.json';
$v2['title'] = 'Website design registries v2';
$v2['properties']['formatVersion']['const'] = 2;
$face = $v1['properties']['fonts']['items'];
array_push($face['required'], 'style', 'weightMin', 'weightMax');
$face['properties']['style'] = ['enum' => ['normal', 'italic']];
$face['properties']['weightMin'] = $face['properties']['weightMax'] = ['type' => 'integer', 'minimum' => 100, 'maximum' => 900];
$v2['properties']['fonts']['items'] = ['type' => 'object', 'additionalProperties' => false, 'required' => ['id', 'label', 'faces'], 'properties' => [
    'id' => $face['properties']['id'], 'label' => $face['properties']['label'],
    'faces' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 8, 'items' => $face],
]];
$v2Path = $root . '/schemas/portable-cms-design-registries-v2.schema.json';
if ($write && file_put_contents($v2Path, preg_replace_callback('/^ +/m', static fn(array $m): string => str_repeat(' ', intdiv(strlen($m[0]), 2)), json_encode($v2, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) . "\n") === false) {
    throw new RuntimeException('Design v2 schema could not be written');
}
if (json_decode((string) file_get_contents($v2Path), true, 32, JSON_THROW_ON_ERROR) !== $v2) {
    throw new RuntimeException('Design v2 schema differs from its derivation');
}
foreach (['$schema', '$id', 'title'] as $key) {
    unset($v1[$key], $v2[$key]);
}
$canonical = ['oneOf' => [$v1, $v2]];
foreach (['profile-v1', 'profile-v2', 'snapshot-v1', 'export-v1'] as $name) {
    $path = $root . '/schemas/portable-cms-' . $name . '.schema.json';
    $source = (string) file_get_contents($path);
    if ($write) {
        $start = strpos($source, '    "designRegistries": ');
        $open = $start === false ? false : strpos($source, '{', $start);
        if ($start === false || $open === false) {
            throw new RuntimeException('Local design embedding marker is unavailable: ' . $name);
        }
        $depth = 1;
        $quoted = false;
        $escaped = false;
        $end = $open + 1;
        for (; $depth > 0 && $end < strlen($source); $end++) {
            $char = $source[$end];
            if ($quoted) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === '"') {
                    $quoted = false;
                }
            } elseif ($char === '"') {
                $quoted = true;
            } elseif ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;
            }
        }
        if ($depth !== 0) {
            throw new RuntimeException('Local design embedding is truncated: ' . $name);
        }
        $json = json_encode($canonical, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $json = preg_replace_callback('/^ +/m', static fn(array $match): string => str_repeat(' ', intdiv(strlen($match[0]), 2)), $json) ?? throw new RuntimeException('Embedding formatting failed');
        $lines = explode("\n", $json);
        $fragment = '    "designRegistries": ' . array_shift($lines) . "\n" . implode("\n", array_map(static fn(string $line): string => '    ' . $line, $lines));
        $source = substr_replace($source, $fragment, $start, $end - $start);
        if (file_put_contents($path, $source) === false) {
            throw new RuntimeException('Local design embedding could not be written: ' . $name);
        }
    }
    $schema = json_decode($source, true, 32, JSON_THROW_ON_ERROR);
    if (($schema['properties']['designRegistries'] ?? null) !== $canonical) {
        throw new RuntimeException('Embedded design schema differs from its canonical contract: ' . $name);
    }
}
fwrite(STDOUT, "Design schema embeddings match their canonical contract.\n");
