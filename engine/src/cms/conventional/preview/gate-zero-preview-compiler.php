<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Preview;

use Utsuri\PortableCms\Conventional\HostIo;

final class GateZeroPreviewCompiler implements PreviewCompiler
{
    private string $mediaRoot;

    public function __construct(string $mediaRoot)
    {
        $resolved = realpath($mediaRoot);
        if ($resolved === false || !is_dir($resolved) || is_link($mediaRoot)) {
            throw new \RuntimeException('Preview media root is unavailable');
        }
        $this->mediaRoot = rtrim($resolved, DIRECTORY_SEPARATOR);
    }

    public function compile(PreviewSnapshot $snapshot, string $outputRoot): PreviewBuild
    {
        if ($snapshot->profileId !== 'portable-gate-zero' || $snapshot->profileVersion !== '1.2.0') {
            throw new \InvalidArgumentException('Preview compiler does not support this profile');
        }
        if (file_exists($outputRoot) || is_link($outputRoot)) {
            throw new \RuntimeException('Preview output root must not exist');
        }
        $parent = realpath(dirname($outputRoot));
        if ($parent === false || !is_dir($parent) || is_link(dirname($outputRoot))) {
            throw new \RuntimeException('Preview output parent is unavailable');
        }
        $root = $parent . DIRECTORY_SEPARATOR . basename($outputRoot);
        if (!HostIo::attempt(fn() => mkdir($root, 0700))->value || is_link($root)) {
            throw new \RuntimeException('Preview output root could not be created');
        }

        try {
            $artifacts = [];
            $records = $this->indexRecords($snapshot);
            $this->writeText(
                $root,
                'assets/preview.css',
                'text/css; charset=utf-8',
                $this->stylesheet(),
                $artifacts,
            );
            $this->writeText(
                $root,
                'index.html',
                'text/html; charset=utf-8',
                $this->rootDocument($snapshot, $records),
                $artifacts,
            );
            foreach (['portfolio-entry', 'article'] as $typeId) {
                $this->writeText(
                    $root,
                    $typeId . '/index.html',
                    'text/html; charset=utf-8',
                    $this->typeDocument($typeId, $records[$typeId] ?? []),
                    $artifacts,
                );
                foreach ($records[$typeId] ?? [] as $record) {
                    $this->writeText(
                        $root,
                        $typeId . '/' . $record->id . '/index.html',
                        'text/html; charset=utf-8',
                        $this->recordDocument($record, $records, $root, $artifacts),
                        $artifacts,
                    );
                }
            }
            usort(
                $artifacts,
                static fn(PreviewArtifact $left, PreviewArtifact $right): int => $left->path <=> $right->path,
            );
            return new PreviewBuild($artifacts);
        } catch (\Throwable $error) {
            $this->removeTree($root);
            throw $error;
        }
    }

    /** @return array<string, array<string, PreviewRecord>> */
    private function indexRecords(PreviewSnapshot $snapshot): array
    {
        $records = ['portfolio-entry' => [], 'article' => [], 'topic' => []];
        foreach ($snapshot->records as $record) {
            if (!$snapshot->includes($record)) {
                continue;
            }
            if (!array_key_exists($record->typeId, $records)) {
                throw new \InvalidArgumentException('Preview record type is not supported');
            }
            $records[$record->typeId][$record->id] = $record;
        }
        foreach ($records as &$items) {
            uasort(
                $items,
                fn(PreviewRecord $left, PreviewRecord $right): int => $this->title($left) <=> $this->title($right),
            );
        }
        unset($items);
        return $records;
    }

    /** @param array<string, array<string, PreviewRecord>> $records */
    private function rootDocument(PreviewSnapshot $snapshot, array $records): string
    {
        $sections = '';
        foreach (['portfolio-entry' => 'Portfolio entries', 'article' => 'Articles'] as $typeId => $label) {
            $items = '';
            foreach ($records[$typeId] ?? [] as $record) {
                $items .= '<li><a href="' . $typeId . '/' . $record->id . '/">'
                    . self::escape($this->title($record)) . '</a></li>';
            }
            $sections .= '<section><h2><a href="' . $typeId . '/">' . $label . '</a></h2><ul>'
                . $items . '</ul></section>';
        }
        return $this->document(
            'Exact preview',
            '<header><p>Frozen ' . self::escape($snapshot->createdAt->format('Y-m-d H:i:sP'))
                . '</p><h1>Exact preview</h1></header><main>' . $sections . '</main>',
            'assets/preview.css',
        );
    }

    /** @param array<string, PreviewRecord> $records */
    private function typeDocument(string $typeId, array $records): string
    {
        $label = $typeId === 'portfolio-entry' ? 'Portfolio entries' : 'Articles';
        $items = '';
        foreach ($records as $record) {
            $items .= '<li><a href="' . $record->id . '/">' . self::escape($this->title($record)) . '</a></li>';
        }
        return $this->document(
            $label,
            '<nav><a href="../">Preview home</a></nav><main><h1>' . $label . '</h1><ul>' . $items . '</ul></main>',
            '../assets/preview.css',
        );
    }

    /**
     * @param array<string, array<string, PreviewRecord>> $records
     * @param list<PreviewArtifact> $artifacts
     */
    private function recordDocument(
        PreviewRecord $record,
        array $records,
        string $outputRoot,
        array &$artifacts,
    ): string {
        $body = '<nav><a href="../../">Preview home</a></nav><main><article><h1>'
            . self::escape($this->title($record)) . '</h1>';
        $copy = $record->typeId === 'article'
            ? $this->text($record->body['body'] ?? '')
            : $this->text($record->body['summary'] ?? '');
        if ($copy !== '') {
            $body .= '<p>' . nl2br(self::escape($copy), false) . '</p>';
        }
        $topicLabels = [];
        foreach ($record->relationships['topics'] ?? [] as $targetId) {
            $topic = $records['topic'][$targetId] ?? null;
            if ($topic instanceof PreviewRecord) {
                $topicLabels[] = self::escape($this->title($topic));
            }
        }
        if ($topicLabels !== []) {
            $body .= '<p>Topics: ' . implode(', ', $topicLabels) . '</p>';
        }
        $featuredId = $record->relationships['featuredEntry'][0] ?? null;
        $featured = is_string($featuredId) ? ($records['portfolio-entry'][$featuredId] ?? null) : null;
        if ($featured instanceof PreviewRecord) {
            $body .= '<p>Featured portfolio entry: <a href="../../portfolio-entry/' . $featured->id . '/">'
                . self::escape($this->title($featured)) . '</a></p>';
        }
        foreach ($record->media as $media) {
            $this->copyMedia($media, $outputRoot, $artifacts);
            $body .= '<figure><img src="../../media/' . self::escape($media->fileName)
                . '" width="' . $media->width . '" height="' . $media->height
                . '" alt="" loading="lazy"></figure>';
        }
        $body .= '</article></main>';
        return $this->document($this->title($record), $body, '../../assets/preview.css');
    }

    /** @param list<PreviewArtifact> $artifacts */
    private function copyMedia(PreviewMedia $media, string $outputRoot, array &$artifacts): void
    {
        $artifactPath = 'media/' . $media->fileName;
        foreach ($artifacts as $artifact) {
            if ($artifact->path === $artifactPath) {
                return;
            }
        }
        $source = $this->existingMediaFile($media->objectKey);
        $size = HostIo::attempt(fn() => filesize($source))->value;
        $checksum = HostIo::attempt(fn() => hash_file('sha256', $source))->value;
        if ($size !== $media->byteSize || !is_string($checksum) || !hash_equals($media->checksum, $checksum)) {
            throw new \RuntimeException('Preview media failed immutable verification');
        }
        $target = $this->target($outputRoot, $artifactPath);
        if (!HostIo::attempt(fn() => copy($source, $target))->value) {
            throw new \RuntimeException('Preview media could not be copied');
        }
        HostIo::required(fn() => chmod($target, 0600));
        $artifacts[] = new PreviewArtifact($artifactPath, $media->mime, $media->byteSize, $media->checksum);
    }

    private function existingMediaFile(string $objectKey): string
    {
        if (preg_match('#\Aderivatives/[a-f0-9]{32}/[a-f0-9]{32}-960-webp\.webp\z#', $objectKey) !== 1) {
            throw new \InvalidArgumentException('Preview media object key is invalid');
        }
        $cursor = $this->mediaRoot;
        foreach (explode('/', $objectKey) as $segment) {
            $cursor .= DIRECTORY_SEPARATOR . $segment;
            if (is_link($cursor)) {
                throw new \RuntimeException('Preview media path is unsafe');
            }
        }
        $resolved = realpath($cursor);
        if (
            $resolved === false
            || !is_file($resolved)
            || !str_starts_with($resolved, $this->mediaRoot . DIRECTORY_SEPARATOR)
        ) {
            throw new \RuntimeException('Preview media is unavailable');
        }
        return $resolved;
    }

    /** @param list<PreviewArtifact> $artifacts */
    private function writeText(
        string $outputRoot,
        string $path,
        string $mime,
        string $contents,
        array &$artifacts,
    ): void {
        $target = $this->target($outputRoot, $path);
        $bytes = HostIo::attempt(fn() => file_put_contents($target, $contents, LOCK_EX))->value;
        if (!is_int($bytes) || $bytes < 1) {
            throw new \RuntimeException('Preview artifact could not be written');
        }
        HostIo::required(fn() => chmod($target, 0600));
        $artifacts[] = new PreviewArtifact($path, $mime, $bytes, hash('sha256', $contents));
    }

    private function target(string $outputRoot, string $path): string
    {
        PreviewArtifact::assertPath($path);
        $target = $outputRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
        $directory = dirname($target);
        if (!is_dir($directory) && !HostIo::attempt(fn() => mkdir($directory, 0700, true))->value && !is_dir($directory)) {
            throw new \RuntimeException('Preview artifact directory could not be created');
        }
        $cursor = $outputRoot;
        $relativeDirectory = substr($directory, strlen($outputRoot));
        foreach (array_filter(explode(DIRECTORY_SEPARATOR, $relativeDirectory)) as $segment) {
            $cursor .= DIRECTORY_SEPARATOR . $segment;
            if (is_link($cursor)) {
                throw new \RuntimeException('Preview artifact directory is unsafe');
            }
        }
        return $target;
    }

    private function document(string $title, string $body, string $stylesheet): string
    {
        return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex, nofollow">'
            . '<title>' . self::escape($title) . '</title>'
            . '<link rel="stylesheet" href="' . self::escape($stylesheet) . '"></head><body>'
            . $body . '</body></html>';
    }

    private function stylesheet(): string
    {
        return ':root{font-family:system-ui,sans-serif;color:#111;background:#fff}'
            . 'body{margin:0;padding:2rem;line-height:1.45}main,header,nav{max-width:72rem;margin-inline:auto}'
            . 'nav{margin-block-end:2rem}a{color:inherit}ul{padding-inline-start:1.2rem}'
            . 'article{max-width:56rem}figure{margin:2rem 0}img{display:block;max-width:100%;height:auto}';
    }

    private function title(PreviewRecord $record): string
    {
        if ($record->typeId === 'topic') {
            $labels = $record->body['label'] ?? [];
            if (is_array($labels)) {
                foreach ($labels as $label) {
                    if (is_string($label) && trim($label) !== '') {
                        return $label;
                    }
                }
            }
            return 'Untitled topic';
        }
        $title = $this->text($record->body['title'] ?? '');
        return trim($title) !== '' ? $title : 'Untitled';
    }

    private function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    private function removeTree(string $root): void
    {
        if (!is_dir($root) || is_link($root)) {
            return;
        }
        foreach (HostIo::attempt(fn() => scandir($root))->value ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $root . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path) && !is_link($path)) {
                $this->removeTree($path);
            } else {
                HostIo::cleanup(fn() => unlink($path));
            }
        }
        HostIo::cleanup(fn() => rmdir($root));
    }
}
