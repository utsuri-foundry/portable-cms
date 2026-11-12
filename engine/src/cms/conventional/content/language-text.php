<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Content;

/** Whether stored documents hold text written in one language. Values are read by field, never by key name alone. */
final class LanguageText
{
    /**
     * @param list<FieldDefinition> $fields
     * @param array<string, mixed> $body
     */
    public static function record(array $fields, array $body, string $locale): bool
    {
        foreach ($fields as $field) {
            $value = $body[$field->id] ?? null;
            if ($value === null) {
                continue;
            }
            if ($field->type === 'composition') {
                foreach ($field->localized && is_array($value) ? $value : [$value] as $key => $layout) {
                    if (($field->localized && $key === $locale && self::filled($layout)) || self::captions($layout, $locale)) {
                        return true;
                    }
                }
            } elseif ($field->type === 'blocks' && is_array($value)) {
                foreach ($field->localized ? $value : [$value] as $key => $items) {
                    if (is_array($items) && (($field->localized && $key === $locale && self::filled(array_column(array_filter($items, is_array(...)), 'values'))) || self::blocks($field, $items, $locale))) {
                        return true;
                    }
                }
            } elseif ($field->localized) {
                if (is_array($value) && array_key_exists($locale, $value) && self::filled($value[$locale])) {
                    return true;
                }
            } elseif (in_array($field->type, ['group', 'repeatable_group'], true) && is_array($value)) {
                foreach ($field->type === 'group' ? [$value] : $value as $item) {
                    if (is_array($item) && self::record($field->fields, $item, $locale)) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /** A media description document: alternative text, caption or credit. */
    public static function media(mixed $document, string $locale): bool
    {
        foreach (is_array($document) && is_array($document['translations'] ?? null) ? $document['translations'] : [] as $entry) {
            if (is_array($entry) && ($entry['locale'] ?? null) === $locale
                && self::filled([$entry['alternativeText'] ?? null, $entry['caption'] ?? null, $entry['credit'] ?? null])) {
                return true;
            }
        }
        return false;
    }

    /** A site wording document or revision. */
    public static function wording(mixed $document, string $locale): bool
    {
        foreach (is_array($document) && is_array($document['values'] ?? null) ? $document['values'] : [] as $entry) {
            if (is_array($entry) && ($entry['locale'] ?? null) === $locale && self::filled($entry['text'] ?? null)) {
                return true;
            }
        }
        return false;
    }

    /** The holding page settings: a contact, or a message other than the engine's default. */
    public static function holding(mixed $document, string $locale): bool
    {
        foreach (is_array($document) && is_array($document['translations'] ?? null) ? $document['translations'] : [] as $entry) {
            if (is_array($entry) && ($entry['locale'] ?? null) === $locale
                && (self::filled($entry['contact'] ?? null) || (self::filled($entry['message'] ?? null) && $entry['message'] !== \Utsuri\PortableCms\Conventional\Publication\WebsitePublication::defaultMessage($locale)))) {
                return true;
            }
        }
        return false;
    }

    /**
     * A record body without its values in $locale: localized values lose that language, and layouts
     * lose its captions. Everything else is unchanged.
     * @param list<FieldDefinition> $fields
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public static function withoutRecord(array $fields, array $body, string $locale): array
    {
        foreach ($fields as $field) {
            $value = $body[$field->id] ?? null;
            if (!is_array($value)) {
                continue;
            }
            if ($field->type === 'composition') {
                if ($field->localized) {
                    unset($value[$locale]);
                    $value = array_map(static fn(mixed $layout): mixed => self::withoutCaptions($layout, $locale), $value);
                } else {
                    $value = self::withoutCaptions($value, $locale);
                }
            } elseif ($field->type === 'blocks') {
                if ($field->localized && array_key_exists($locale, $value)) {
                    // Every installed language keeps a list of blocks.
                    $value[$locale] = [];
                }
                $value = $field->localized
                    ? array_map(static fn(mixed $items): mixed => is_array($items) ? self::withoutBlocks($field, $items, $locale) : $items, $value)
                    : self::withoutBlocks($field, $value, $locale);
            } elseif ($field->localized) {
                unset($value[$locale]);
            } elseif (in_array($field->type, ['group', 'repeatable_group'], true)) {
                $value = $field->type === 'group' ? self::withoutRecord($field->fields, $value, $locale)
                    : array_map(static fn(mixed $item): mixed => is_array($item) ? self::withoutRecord($field->fields, $item, $locale) : $item, $value);
            }
            $body[$field->id] = $value;
        }
        return $body;
    }

    /**
     * @param array<string, mixed> $document A media description document.
     * @return array<string, mixed> The document without its translation in $locale.
     */
    public static function withoutMedia(array $document, string $locale): array
    {
        $document['translations'] = array_values(array_filter(is_array($document['translations'] ?? null) ? $document['translations'] : [], static fn(mixed $entry): bool => !is_array($entry) || ($entry['locale'] ?? null) !== $locale));
        return $document;
    }

    /**
     * @param array<string, array<string, string>> $values Site wording key to language to text.
     * @return array<string, array<string, string>>
     */
    public static function withoutWording(array $values, string $locale): array
    {
        return array_filter(array_map(static fn(array $texts): array => array_diff_key($texts, [$locale => true]), $values), static fn(array $texts): bool => $texts !== []);
    }

    /**
     * Holding page settings with the language's message back to the default and no contact; every
     * installed language keeps an entry.
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    public static function withoutHolding(array $document, string $locale): array
    {
        foreach (is_array($document['translations'] ?? null) ? $document['translations'] : [] as $index => $entry) {
            if (is_array($entry) && ($entry['locale'] ?? null) === $locale) {
                $document['translations'][$index] = ['locale' => $locale, 'message' => \Utsuri\PortableCms\Conventional\Publication\WebsitePublication::defaultMessage($locale), 'contact' => ''];
            }
        }
        return $document;
    }

    /**
     * @param array<mixed> $items
     * @return array<mixed>
     */
    private static function withoutBlocks(FieldDefinition $field, array $items, string $locale): array
    {
        foreach ($items as $position => $item) {
            $block = is_array($item) && is_string($item['typeId'] ?? null) ? ($field->blockTypes[$item['typeId']] ?? null) : null;
            if ($block !== null && is_array($item['values'] ?? null)) {
                $items[$position]['values'] = self::withoutRecord($block->fields, $item['values'], $locale);
            }
        }
        return $items;
    }

    /** @param array<mixed> $items */
    private static function blocks(FieldDefinition $field, array $items, string $locale): bool
    {
        foreach ($items as $item) {
            $block = is_array($item) && is_string($item['typeId'] ?? null) ? ($field->blockTypes[$item['typeId']] ?? null) : null;
            if ($block !== null && is_array($item['values'] ?? null) && self::record($block->fields, $item['values'], $locale)) {
                return true;
            }
        }
        return false;
    }

    private static function withoutCaptions(mixed $layout, string $locale): mixed
    {
        foreach (is_array($layout) && is_array($layout['rows'] ?? null) ? $layout['rows'] : [] as $r => $row) {
            foreach (is_array($row) && is_array($row['columns'] ?? null) ? $row['columns'] : [] as $c => $column) {
                if (is_array($column) && is_array($column['content'] ?? null) && is_array($column['content']['captions'] ?? null)) {
                    unset($layout['rows'][$r]['columns'][$c]['content']['captions'][$locale]);
                    // A layout keeps no empty caption list.
                    if ($layout['rows'][$r]['columns'][$c]['content']['captions'] === []) {
                        unset($layout['rows'][$r]['columns'][$c]['content']['captions']);
                    }
                }
            }
        }
        return $layout;
    }

    private static function captions(mixed $layout, string $locale): bool
    {
        foreach (is_array($layout) && is_array($layout['rows'] ?? null) ? $layout['rows'] : [] as $row) {
            foreach (is_array($row) && is_array($row['columns'] ?? null) ? $row['columns'] : [] as $column) {
                $captions = is_array($column) && is_array($column['content'] ?? null) ? ($column['content']['captions'] ?? null) : null;
                if (is_array($captions) && self::filled($captions[$locale] ?? null)) {
                    return true;
                }
            }
        }
        return false;
    }

    /** Any non-blank string inside a value; numbers, flags and document versions are not text. */
    private static function filled(mixed $value): bool
    {
        if (is_string($value)) {
            return trim($value) !== '';
        }
        foreach (is_array($value) ? $value : [] as $item) {
            if (self::filled($item)) {
                return true;
            }
        }
        return false;
    }
}
