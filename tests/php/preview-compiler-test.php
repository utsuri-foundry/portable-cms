<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Preview\GateZeroPreviewCompiler;
use Utsuri\PortableCms\Conventional\Preview\PreviewMedia;
use Utsuri\PortableCms\Conventional\Preview\PreviewRecord;
use Utsuri\PortableCms\Conventional\Preview\PreviewSnapshot;

function removePreviewTestTree(string $root): void
{
    if (!is_dir($root) || is_link($root)) {
        return;
    }
    foreach (scandir($root) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $root . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($path) && !is_link($path)) {
            removePreviewTestTree($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($root);
}

/** @return array{root: string, mediaRoot: string, outputRoot: string, media: PreviewMedia, snapshot: PreviewSnapshot} */
function previewCompilerFixture(): array
{
    $root = sys_get_temp_dir() . '/portable-cms-preview-' . bin2hex(random_bytes(8));
    $mediaRoot = $root . '/media-store';
    $outputParent = $root . '/output';
    $outputRoot = $outputParent . '/preview-build';
    if (!mkdir($mediaRoot, 0700, true) || !mkdir($outputParent, 0700, true)) {
        throw new RuntimeException('Could not create preview test directories');
    }
    $mediaId = str_repeat('4', 32);
    $fileName = $mediaId . '-960-webp.webp';
    $objectKey = 'derivatives/' . $mediaId . '/' . $fileName;
    $source = $mediaRoot . '/' . $objectKey;
    if (!mkdir(dirname($source), 0700, true)) {
        throw new RuntimeException('Could not create preview media directory');
    }
    $bytes = "RIFF\x0c\x00\x00\x00WEBPfixture";
    file_put_contents($source, $bytes);
    $media = new PreviewMedia(
        $mediaId,
        $objectKey,
        $fileName,
        'image/webp',
        strlen($bytes),
        hash('sha256', $bytes),
        960,
        640,
    );
    $entryId = str_repeat('1', 32);
    $articleId = str_repeat('2', 32);
    $topicId = str_repeat('3', 32);
    $snapshot = new PreviewSnapshot(
        'portable-gate-zero',
        '1.2.0',
        ['ca', 'es', 'en'],
        [
            new PreviewRecord(
                $entryId,
                'portfolio-entry',
                str_repeat('a', 32),
                2,
                ['title' => 'Entry <script>alert(1)</script>', 'summary' => "First line\nSecond line"],
                ['topics' => [$topicId]],
                [$media],
            ),
            new PreviewRecord(
                $articleId,
                'article',
                str_repeat('b', 32),
                1,
                ['title' => 'A short article', 'body' => 'Article body'],
                ['featuredEntry' => [$entryId], 'topics' => [$topicId]],
            ),
            new PreviewRecord(
                $topicId,
                'topic',
                str_repeat('c', 32),
                1,
                ['label' => ['ca' => 'Arquitectura', 'es' => 'Arquitectura', 'en' => 'Architecture']],
                [],
            ),
        ],
        new DateTimeImmutable('2026-09-30T12:00:00Z'),
        includeDrafts: true,
    );
    return compact('root', 'mediaRoot', 'outputRoot', 'media', 'snapshot');
}

$tests['preview compiler freezes both record types, relationships, and verified media'] = static function (): void {
    $fixture = previewCompilerFixture();
    try {
        $build = (new GateZeroPreviewCompiler($fixture['mediaRoot']))
            ->compile($fixture['snapshot'], $fixture['outputRoot']);
        $manifest = $build->manifest();
        assertSameValue(1, $manifest['formatVersion'], 'preview manifest version should be explicit');
        assertSameValue(7, count($manifest['artifacts']), 'preview should contain the bounded fixture artifact set');
        $paths = array_column($manifest['artifacts'], 'path');
        assertSameValue($paths, array_values(array_unique($paths)), 'artifact paths should be unique');
        assertTrueValue(in_array('index.html', $paths, true), 'preview should contain a root document');
        assertTrueValue(
            in_array('portfolio-entry/' . str_repeat('1', 32) . '/index.html', $paths, true),
            'preview should contain the portfolio record route',
        );
        assertTrueValue(
            in_array('article/' . str_repeat('2', 32) . '/index.html', $paths, true),
            'preview should contain the article route',
        );

        $entryPath = $fixture['outputRoot'] . '/portfolio-entry/' . str_repeat('1', 32) . '/index.html';
        $entry = (string) file_get_contents($entryPath);
        assertTrueValue(!str_contains($entry, '<script>'), 'stored content must not become executable markup');
        assertTrueValue(str_contains($entry, '&lt;script&gt;'), 'stored content should remain visible after escaping');
        assertTrueValue(str_contains($entry, 'Arquitectura'), 'taxonomy relationship should render its label');

        $article = (string) file_get_contents(
            $fixture['outputRoot'] . '/article/' . str_repeat('2', 32) . '/index.html',
        );
        assertTrueValue(
            str_contains($article, '../../portfolio-entry/' . str_repeat('1', 32) . '/'),
            'record relationship should become a relative preview link',
        );
        foreach ($manifest['artifacts'] as $artifact) {
            $path = $fixture['outputRoot'] . '/' . $artifact['path'];
            assertSameValue(filesize($path), $artifact['byteSize'], 'manifest size should match artifact bytes');
            assertSameValue(hash_file('sha256', $path), $artifact['checksum'], 'manifest hash should match artifact bytes');
        }
    } finally {
        removePreviewTestTree($fixture['root']);
    }
};

$tests['preview compiler rejects changed immutable media and removes partial output'] = static function (): void {
    $fixture = previewCompilerFixture();
    try {
        $source = $fixture['mediaRoot'] . '/' . $fixture['media']->objectKey;
        file_put_contents($source, 'changed after snapshot');
        try {
            (new GateZeroPreviewCompiler($fixture['mediaRoot']))
                ->compile($fixture['snapshot'], $fixture['outputRoot']);
            throw new RuntimeException('changed preview media unexpectedly compiled');
        } catch (RuntimeException $error) {
            assertTrueValue(
                str_contains($error->getMessage(), 'immutable verification'),
                'changed media should fail for a specific immutable verification reason',
            );
        }
        assertSameValue(false, file_exists($fixture['outputRoot']), 'failed preview output should be removed');
    } finally {
        removePreviewTestTree($fixture['root']);
    }
};
