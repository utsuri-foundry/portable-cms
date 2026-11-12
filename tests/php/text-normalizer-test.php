<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\TextNormalizer;

$tests['PHP search and slug normalization match the shared browser fixtures'] = static function (): void {
    $path = dirname(__DIR__) . '/fixtures/text-normalization-v1.json';
    $document = json_decode((string) file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
    assertSameValue(1, $document['formatVersion'] ?? null, 'normalization fixtures should be versioned');
    $cases = $document['cases'] ?? null;
    assertTrueValue(is_array($cases) && array_is_list($cases), 'normalization cases should be a list');
    foreach ($cases as $case) {
        assertTrueValue(is_array($case), 'normalization case should be an object');
        $input = $case['input'] ?? null;
        assertTrueValue(is_string($input), 'normalization input should be text');
        assertSameValue($case['search'] ?? null, TextNormalizer::search($input), 'PHP search normalization should match');
        assertSameValue($case['slug'] ?? null, TextNormalizer::slug($input), 'PHP slug normalization should match');
    }
};
