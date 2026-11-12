<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Design;

/** Native upload intent adds verified bytes, never replaces a historical face. */
final class FontUpload
{
    /** @param array<string, string> $values */
    public static function add(RegistryDocument $base, array $values, string $bytes, FontIngestion $ingestion): RegistryDocument
    {
        if (($values['font_permission'] ?? '') !== 'yes') {
            throw new FontRejected('design.font_error.confirm_permission_to_serve_and_sanitize');
        }
        // Old native clients retain their single-face request and output format.
        if (!array_key_exists('font_family', $values) && $base->document()['formatVersion'] === 1) {
            $document = $base->document();
            if (count($document['fonts']) >= 32 || in_array($values['font_id'] ?? '', array_column($document['fonts'], 'id'), true)) {
                throw new FontRejected('design.font_error.choose_a_new_font_id_a');
            }
            $document['fonts'][] = $ingestion->ingest($bytes, $values['font_id'] ?? '', $values['font_label'] ?? '', $values['font_provenance'] ?? '', $values['font_license'] ?? '');
            return RegistryDocument::fromDocument($document);
        }
        $document = FontFamilies::upgrade($base)->document();
        $familyId = trim($values['font_family'] ?? '');
        $new = $familyId === '';
        $familyId = $new ? trim($values['font_id'] ?? '') : $familyId;
        $index = array_search($familyId, array_column($document['fonts'], 'id'), true);
        if (($new && ($index !== false || count($document['fonts']) >= 32)) || (!$new && $index === false)) {
            throw new FontRejected('design.font_error.choose_a_new_font_id_a');
        }
        $faceId = trim($values['font_face_id'] ?? '');
        $faceLabel = trim($values['font_face_label'] ?? '');
        $style = $values['font_style'] ?? '';
        $min = self::weight($values['font_weight_min'] ?? '');
        $max = self::weight($values['font_weight_max'] ?? '');
        // Validate all declarations before running a processor or writing a blob.
        $face = ['id' => $faceId, 'label' => $faceLabel, 'style' => $style, 'weightMin' => $min, 'weightMax' => $max,
            'origin' => 'upload', 'sha256' => str_repeat('0', 64), 'sourceSha256' => str_repeat('0', 64), 'byteSize' => 48,
            'provenance' => $values['font_provenance'] ?? '', 'license' => $values['font_license'] ?? ''];
        if ($new) {
            $index = count($document['fonts']);
            $document['fonts'][] = ['id' => $familyId, 'label' => trim($values['font_label'] ?? ''), 'faces' => [$face]];
        } else {
            $document['fonts'][$index]['faces'][] = $face;
        }
        $validated = RegistryDocument::fromDocument($document)->document();
        $verified = $ingestion->ingest($bytes, $faceId, $faceLabel, $face['provenance'], $face['license']);
        $faces = $validated['fonts'][$index]['faces'];
        $faces[array_key_last($faces)] = [...$verified, 'style' => $style, 'weightMin' => $min, 'weightMax' => $max];
        $validated['fonts'][$index]['faces'] = $faces;
        return RegistryDocument::fromDocument($validated);
    }

    private static function weight(string $value): int
    {
        if (preg_match('/\A[1-9][0-9]{2}\z/', $value) !== 1) {
            throw new \Utsuri\PortableCms\Conventional\I18n\InvalidInput('design.error.font_faces');
        }
        return (int) $value;
    }
}
