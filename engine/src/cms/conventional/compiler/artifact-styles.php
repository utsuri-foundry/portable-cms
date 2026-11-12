<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Compiler;

use Utsuri\PortableCms\Conventional\Content\ProfilePackage;

/** Generated stylesheet inputs shared by the static compiler and the live preview, so both produce the same bytes. */
final class ArtifactStyles
{
    /**
     * Artifact paths of every frozen design font. Package fonts are ordinary profile assets;
     * uploaded fonts are listed so the caller can deliver their verified private files.
     *
     * @return array{paths: array<string, string>, uploads: list<array{path: string, sha256: string, byteSize: int}>}
     */
    public static function fonts(ProfilePackage $package, FrozenSnapshot $snapshot): array
    {
        $paths = [];
        $uploads = [];
        foreach ($snapshot->designRegistries?->faces() ?? [] as $font) {
            if ($font['origin'] === 'package') {
                $matches = array_values(array_filter($package->manifest->assets, static fn(\Utsuri\PortableCms\Conventional\Content\ProfileAsset $asset): bool => $asset->mime === 'font/woff2' && $asset->checksum === $font['sha256'] && $asset->byteSize === $font['byteSize']));
                if (count($matches) !== 1) {
                    throw new \InvalidArgumentException('Frozen design font is outside the verified package');
                }
                $paths[$font['key']] = $matches[0]->path;
                continue;
            }
            $path = 'fonts/' . $font['sha256'] . '.woff2';
            $paths[$font['key']] = $path;
            if (isset($uploads[$path]) && $uploads[$path]['byteSize'] !== $font['byteSize']) {
                throw new \InvalidArgumentException('Frozen design font has inconsistent file facts');
            }
            $uploads[$path] = ['path' => $path, 'sha256' => $font['sha256'], 'byteSize' => $font['byteSize']];
        }
        return ['paths' => $paths, 'uploads' => array_values($uploads)];
    }

    /** @param array<string, string> $fontPaths */
    public static function design(FrozenSnapshot $snapshot, array $fontPaths): ?string
    {
        return $snapshot->designRegistries === null ? null : \Utsuri\PortableCms\Conventional\Design\DesignCss::compile($snapshot->designRegistries, $fontPaths, artifactRelative: true);
    }

    /** Scoped rules for every included record's shared composition, or an empty string. */
    public static function composition(ProfilePackage $package, FrozenSnapshot $snapshot): string
    {
        $css = '';
        foreach ($snapshot->records as $record) {
            if (!$snapshot->includes($record)) {
                continue;
            }
            $type = $package->manifest->type($record->typeId);
            foreach ($type->fields as $field) {
                if ($field->type !== 'composition' || $field->localized || ($record->body[$field->id] ?? null) === null) {
                    continue;
                }
                $layout = \Utsuri\PortableCms\Conventional\Content\Composition::from($record->body[$field->id], $field, $type, $snapshot->designRegistries);
                $css .= \Utsuri\PortableCms\Conventional\View\CompositionView::render($layout, $snapshot->designRegistries, '/records/' . $type->id . '/' . $record->id . ':' . $field->id, static fn(array $content): string => '')->css;
            }
        }
        return $css;
    }
}
