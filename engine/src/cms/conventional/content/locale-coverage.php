<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Content;

/**
 * Translation completeness as editors see it: a localized text field is missing in a
 * language when any other language already has text for it. Required-field validation
 * remains in the content service; this is an advisory projection only.
 */
final class LocaleCoverage
{
    private const TEXT_TYPES = ['localized_short_text', 'short_text', 'multiline_text', 'rich_text', 'web_address', 'vimeo_video'];

    /**
     * @param array<string, mixed> $body
     * @return array<string, list<string>> Missing field labels keyed by locale ID, in manifest order.
     */
    public static function missing(RecordTypeDefinition $type, array $body, ProfileManifest $profile): array
    {
        $missing = array_fill_keys($profile->locales, []);
        foreach ($type->fields as $field) {
            if (!$field->localized || !in_array($field->type, self::TEXT_TYPES, true)) {
                continue;
            }
            $value = is_array($body[$field->id] ?? null) ? $body[$field->id] : [];
            $filled = [];
            foreach ($profile->locales as $locale) {
                $entry = $value[$locale] ?? null;
                $filled[$locale] = $field->type === 'vimeo_video'
                    ? is_array($entry) && is_string($entry['title'] ?? null) && trim($entry['title']) !== ''
                    : ($field->type === 'web_address'
                    ? is_array($entry) && is_string($entry['url'] ?? null) && $entry['url'] !== ''
                    : self::hasText($entry, $field->type === 'rich_text'));
            }
            if (!in_array(true, $filled, true)) {
                continue;
            }
            foreach ($filled as $locale => $present) {
                if (!$present) {
                    $missing[$locale][] = $field->label;
                }
            }
        }
        return $missing;
    }

    public static function localized(RecordTypeDefinition $type): bool
    {
        foreach ($type->fields as $field) {
            if ($field->localized && in_array($field->type, self::TEXT_TYPES, true)) {
                return true;
            }
        }
        return false;
    }

    private static function hasText(mixed $value, bool $rich): bool
    {
        if (!$rich) {
            return is_string($value) && trim($value) !== '';
        }
        if (!is_array($value)) {
            return false;
        }
        foreach ($value as $key => $item) {
            if (($key === 'text' && is_string($item) && trim($item) !== '') || (is_array($item) && self::hasText($item, true))) {
                return true;
            }
        }
        return false;
    }
}
