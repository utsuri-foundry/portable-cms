<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Content;

use Utsuri\PortableCms\Conventional\Http\Request;
use Utsuri\PortableCms\Conventional\I18n\InvalidInput;

/** Portable identities for one prepared video and its separately prepared image poster. */
final class VideoReference
{
    /** @return array{mediaId: string, posterId: string}|null */
    public static function from(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }
        if (!is_array($value) || count($value) !== 2 || !is_string($value['mediaId'] ?? null) || !is_string($value['posterId'] ?? null)
            || preg_match('/\A[a-f0-9]{32}\z/', $value['mediaId']) !== 1 || preg_match('/\A[a-f0-9]{32}\z/', $value['posterId']) !== 1 || $value['mediaId'] === $value['posterId']) {
            throw new InvalidInput('video.selection_invalid');
        }
        return ['mediaId' => $value['mediaId'], 'posterId' => $value['posterId']];
    }

    /** @param list<string> $locales
     * @return array<string, mixed>|null
     */
    public static function input(Request $request, string $name, bool $localized, array $locales): ?array
    {
        $values = [];
        foreach ($localized ? $locales : [''] as $locale) {
            $prefix = $name . ($locale === '' ? '' : '__' . $locale);
            $videoName = $prefix . '__video';
            $video = $request->formValue(array_key_exists($videoName, $request->form) ? $videoName : '_retained_' . $videoName);
            $poster = $request->formValue($prefix . '__poster');
            $values[$locale] = $video === '' && $poster === '' ? null : ['mediaId' => $video, 'posterId' => $poster];
        }
        return $localized ? $values : $values[''];
    }

    /** Validate references in frozen and restored graphs through an explicit lookup.
     * @param list<FieldDefinition> $fields
     * @param array<string, mixed> $body
     * @param list<string> $locales
     * @param \Closure(string): ?string $mime
     * @param (\Closure(string): ?int)|null $byteSize
     * @param (\Closure(string): array<string, mixed>)|null $facts
     */
    public static function assertGraph(array $fields, array $body, array $locales, \Closure $mime, ?\Closure $byteSize = null, ?\Closure $facts = null): void
    {
        [$fields, $body] = \Utsuri\PortableCms\Conventional\Content\Blocks::expand($fields, $body, $locales);
        foreach ($fields as $field) {
            if (!in_array($field->type, ['video_reference', 'group', 'repeatable_group'], true)) {
                continue;
            }
            $raw = $body[$field->id] ?? null;
            if ($raw === null) {
                continue;
            }
            if ($field->localized && (!is_array($raw) || array_diff(array_keys($raw), $locales) !== [])) {
                throw new InvalidInput('video.selection_invalid');
            }
            foreach ($field->localized ? $locales : [''] as $locale) {
                $value = $field->localized ? ($raw[$locale] ?? null) : $raw;
                if ($field->type === 'video_reference') {
                    $reference = self::from($value);
                    if ($reference !== null && (!in_array($mime($reference['mediaId']), ['video/mp4', 'video/webm'], true)
                        || !str_starts_with($mime($reference['posterId']) ?? '', 'image/'))) {
                        throw new InvalidInput('video.selection_invalid');
                    }
                    if ($reference !== null && $byteSize !== null) {
                        $size = $byteSize($reference['mediaId']);
                        if ($size === null || $size < 1) {
                            throw new InvalidInput('video.selection_invalid');
                        }
                        // Restore defers new metadata rules until checksum-verified blob inspection.
                        if ($facts !== null) {
                            VideoLimits::assert($field, $size, VideoLimits::needsFacts($field) ? $facts($reference['mediaId']) : []);
                        } elseif ($size > \Utsuri\PortableCms\Conventional\Media\MediaContract::videoFieldMaximum([$field])) {
                            VideoLimits::assert($field, $size, []);
                        }
                    }
                } elseif (in_array($field->type, ['group', 'repeatable_group'], true) && is_array($value)) {
                    foreach ($field->type === 'group' ? [$value] : $value as $item) {
                        if (is_array($item)) {
                            self::assertGraph(array_map(static fn(FieldDefinition $child): FieldDefinition => EditorialControls::childDefinition($child, $child->id, $locale !== ''), $field->fields), $item, $locales, $mime, $byteSize, $facts);
                        }
                    }
                }
            }
        }
    }
}
