<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Design;

use Utsuri\PortableCms\Conventional\I18n\InvalidInput;

/**
 * Versioned font declarations; file verification remains the ingestion/package boundary.
 * @phpstan-import-type FontData from RegistryDocument
 * @phpstan-type FaceData array{id: string, label: string, origin: string, sha256: string, byteSize: int, sourceSha256: string, provenance: string, license: string, style: string, weightMin: int, weightMax: int}
 * @phpstan-type FamilyData array{id: string, label: string, faces: list<FaceData>}
 * @phpstan-type ResolvedFace array{key: string, familyId: string, id: string, label: string, origin: string, sha256: string, byteSize: int, sourceSha256: string, provenance: string, license: string, style: string, weightMin: int, weightMax: int}
 */
final class FontFamilies
{
    public const MAX_FACES = 8;

    public static function validate(mixed $fonts, int $version): void
    {
        foreach (RegistryDocument::items($fonts, 32) as $family) {
            if ($version === 1) {
                self::file($family);
                continue;
            }
            RegistryDocument::keys($family, ['id', 'label', 'faces']);
            $faces = RegistryDocument::items($family['faces'], self::MAX_FACES);
            if ($faces === []) {
                throw new InvalidInput('design.error.font_faces');
            }
            $seen = [];
            foreach ($faces as $face) {
                RegistryDocument::keys($face, ['id', 'label', 'origin', 'sha256', 'byteSize', 'sourceSha256', 'provenance', 'license', 'style', 'weightMin', 'weightMax']);
                $file = $face;
                unset($file['style'], $file['weightMin'], $file['weightMax']);
                self::file($file);
                if (!in_array($face['style'], ['normal', 'italic'], true)
                    || !is_int($face['weightMin']) || !is_int($face['weightMax'])
                    || $face['weightMin'] < 100 || $face['weightMax'] > 900 || $face['weightMin'] > $face['weightMax']) {
                    throw new InvalidInput('design.error.font_faces');
                }
                foreach ($seen as $other) {
                    if ($other['style'] === $face['style'] && $other['weightMin'] <= $face['weightMax'] && $face['weightMin'] <= $other['weightMax']) {
                        throw new InvalidInput('design.error.font_overlap');
                    }
                }
                $seen[] = $face;
            }
        }
    }

    /** @param array<string, mixed> $font */
    private static function file(array $font): void
    {
        RegistryDocument::keys($font, ['id', 'label', 'origin', 'sha256', 'byteSize', 'sourceSha256', 'provenance', 'license']);
        if (!in_array($font['origin'], ['package', 'upload'], true) || !RegistryDocument::hash($font['sha256'])
            || !RegistryDocument::hash($font['sourceSha256']) || !is_int($font['byteSize']) || $font['byteSize'] < 48
            || $font['byteSize'] > 2_097_152 || !RegistryDocument::text($font['provenance'], 2000, true) || !RegistryDocument::text($font['license'], 2000, true)) {
            throw new InvalidInput('design.error.font_facts');
        }
    }

    /** @return list<ResolvedFace> */
    public static function faces(RegistryDocument $registry): array
    {
        $document = $registry->document();
        $result = [];
        foreach ($document['fonts'] as $family) {
            if (!isset($family['faces'])) {
                $result[] = ['key' => $family['id'], 'familyId' => $family['id'], ...$family, 'style' => 'normal', 'weightMin' => 100, 'weightMax' => 900];
                continue;
            }
            foreach ($family['faces'] as $face) {
                $result[] = ['key' => $family['id'] . ':' . $face['id'], 'familyId' => $family['id'], ...$face];
            }
        }
        return $result;
    }

    /** Explicit projection for adding faces; ordinary v1 saves and old snapshots remain v1. */
    public static function upgrade(RegistryDocument $registry): RegistryDocument
    {
        if ($registry->document()['formatVersion'] === 2) {
            return $registry;
        }
        $document = $registry->document();
        $document['formatVersion'] = 2;
        $document['fonts'] = [];
        foreach ($registry->faces() as $face) {
            $document['fonts'][] = ['id' => $face['familyId'], 'label' => $face['label'], 'faces' => [
                array_diff_key($face, ['key' => true, 'familyId' => true]),
            ]];
        }
        return RegistryDocument::fromDocument($document);
    }
}
