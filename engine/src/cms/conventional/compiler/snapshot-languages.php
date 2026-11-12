<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Compiler;

use Utsuri\PortableCms\Conventional\Content\FieldDefinition;
use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Content\RecordTypeDefinition;

/**
 * The languages one build draws: the public ones in profile order with the primary language as
 * default, and for review only, languages in preparation. Values in any other language never enter
 * the snapshot. In a language in preparation, a missing value shows the primary language's value,
 * as media text and layout captions already do, so untranslated records still draw.
 */
final readonly class SnapshotLanguages
{
    /**
     * @param list<string> $locales Languages to draw, in profile order.
     * @param list<string> $preparation Languages among them that are in preparation, never publishable.
     */
    public function __construct(public array $locales, public string $primary, public array $preparation = [])
    {
        if ($locales === [] || !array_is_list($locales) || count(array_unique($locales)) !== count($locales)
            || !in_array($primary, $locales, true) || !array_is_list($preparation) || in_array($primary, $preparation, true)
            || array_diff($preparation, $locales) !== [] || count(array_unique($preparation)) !== count($preparation)) {
            throw new \InvalidArgumentException('Snapshot languages are invalid');
        }
    }

    public static function all(ProfileManifest $profile): self
    {
        return new self($profile->locales, $profile->defaultLocale);
    }

    public function assertProfile(ProfileManifest $profile): void
    {
        if (array_values(array_intersect($profile->carrying($this->locales)->supported(), $this->locales)) !== $this->locales) {
            throw new \InvalidArgumentException('Snapshot languages are not the profile languages in order');
        }
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function record(RecordTypeDefinition $type, array $body): array
    {
        foreach ($type->fields as $field) {
            if (array_key_exists($field->id, $body)) {
                $body[$field->id] = $this->field($field, $body[$field->id]);
            }
        }
        return $body;
    }

    /**
     * @param array<string, mixed> $text A media text document.
     * @return array<string, mixed>
     */
    public function mediaText(array $text): array
    {
        if (is_array($text['translations'] ?? null)) {
            $text['translations'] = array_values(array_filter($text['translations'], fn(mixed $entry): bool => is_array($entry) && in_array($entry['locale'] ?? null, $this->locales, true)));
        }
        return $text;
    }

    /**
     * A snapshot document drawn in every profile language, projected onto these languages.
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    public function document(array $document, ProfileManifest $profile): array
    {
        $this->assertProfile($profile);
        $profile = $profile->carrying($document['profile']['locales']);
        $document['profile']['defaultLocale'] = $this->primary;
        $document['profile']['locales'] = $this->locales;
        foreach (is_array($document['records'] ?? null) ? $document['records'] : [] as $index => $record) {
            $type = is_array($record) ? $profile->type((string) ($record['typeId'] ?? '')) : null;
            if ($type !== null && is_array($record['body'] ?? null)) {
                $document['records'][$index]['body'] = $this->record($type, $record['body']);
                $document['records'][$index]['relationships'] = \Utsuri\PortableCms\Conventional\Content\Blocks::relationships($type->fields, $record['body'], $document['records'][$index]['body'], $profile->locales, $this->locales, $record['relationships'] ?? []);
            }
        }
        foreach (is_array($document['media'] ?? null) ? $document['media'] : [] as $index => $media) {
            if (is_array($media) && is_array($media['text'] ?? null)) {
                $document['media'][$index]['text'] = $this->mediaText($media['text']);
            }
        }
        if (is_array($document['wording'] ?? null)) {
            $document['wording'] = $this->wording($document['wording']);
        }
        unset($document['preparationLocales']);
        if ($this->preparation !== []) {
            $document['preparationLocales'] = $this->preparation;
        }
        return $document;
    }

    /**
     * Site wording in these languages. A language in preparation without a text shows the
     * primary language's text, as its records do.
     * @param array<mixed> $entries
     * @return list<array{key: string, locale: string, text: string}>
     */
    public function wording(array $entries): array
    {
        $texts = [];
        foreach ($entries as $entry) {
            if (is_array($entry) && is_string($entry['key'] ?? null) && is_string($entry['text'] ?? null) && in_array($entry['locale'] ?? null, $this->locales, true)) {
                $texts[$entry['key']][$entry['locale']] = $entry['text'];
            }
        }
        $kept = [];
        foreach ($texts as $key => $values) {
            foreach ($this->locales as $locale) {
                $text = $values[$locale] ?? (in_array($locale, $this->preparation, true) ? ($values[$this->primary] ?? null) : null);
                if ($text !== null) {
                    $kept[] = ['key' => (string) $key, 'locale' => $locale, 'text' => $text];
                }
            }
        }
        return $kept;
    }

    private function field(FieldDefinition $field, mixed $value): mixed
    {
        if ($field->localized) {
            if (!is_array($value) || array_is_list($value)) {
                return $value;
            }
            $kept = array_filter($value, fn(int|string $locale): bool => in_array($locale, $this->locales, true), ARRAY_FILTER_USE_KEY);
            foreach ($this->preparation as $locale) {
                if (self::missing($field, $kept[$locale] ?? null) && !self::missing($field, $kept[$this->primary] ?? null)) {
                    $kept[$locale] = $kept[$this->primary];
                }
            }
            if ($field->type === 'blocks') {
                foreach ($kept as $locale => $items) {
                    foreach (is_array($items) ? $items : [] as $position => $item) {
                        $block = is_array($item) && is_string($item['typeId'] ?? null) ? ($field->blockTypes[$item['typeId']] ?? null) : null;
                        foreach ($block->fields ?? [] as $child) {
                            if ($child->type === 'composition' && isset($item['values'][$child->id])) {
                                $kept[$locale][$position]['values'][$child->id] = $this->captions($item['values'][$child->id]);
                            }
                        }
                    }
                }
            }
            return $field->type === 'composition' ? array_map($this->captions(...), $kept) : $kept;
        }
        if ($field->type === 'composition') {
            return $this->captions($value);
        }
        if ($field->type === 'blocks' && is_array($value)) {
            foreach ($value as $position => $item) {
                if (!is_array($item) || !isset($field->blockTypes[$item['typeId'] ?? '']) || !is_array($item['values'] ?? null)) {
                    continue;
                }
                foreach ($field->blockTypes[$item['typeId']]->fields as $child) {
                    if (array_key_exists($child->id, $item['values'])) {
                        $value[$position]['values'][$child->id] = $this->field($child, $item['values'][$child->id]);
                    }
                }
            }
            return $value;
        }
        if (!in_array($field->type, ['group', 'repeatable_group'], true) || !is_array($value)) {
            return $value;
        }
        $items = $field->type === 'group' ? [$value] : $value;
        foreach ($items as $position => $item) {
            if (!is_array($item)) {
                continue;
            }
            foreach ($field->fields as $child) {
                if (array_key_exists($child->id, $item)) {
                    $item[$child->id] = $this->field($child, $item[$child->id]);
                }
            }
            $items[$position] = $item;
        }
        return $field->type === 'group' ? $items[0] : $items;
    }

    /** Layout captions keep these languages; a missing one already falls back to the primary language. */
    private function captions(mixed $value): mixed
    {
        if (!is_array($value) || !is_array($value['rows'] ?? null)) {
            return $value;
        }
        foreach ($value['rows'] as $r => $row) {
            foreach (is_array($row) && is_array($row['columns'] ?? null) ? $row['columns'] : [] as $index => $column) {
                $captions = is_array($column) && is_array($column['content'] ?? null) ? ($column['content']['captions'] ?? null) : null;
                if (is_array($captions) && $captions !== []) {
                    $value['rows'][$r]['columns'][$index]['content']['captions'] = array_filter($captions, fn(int|string $locale): bool => in_array($locale, $this->locales, true), ARRAY_FILTER_USE_KEY);
                }
            }
        }
        return $value;
    }

    private static function missing(FieldDefinition $field, mixed $value): bool
    {
        if ($value === null || $value === [] || (is_string($value) && trim($value) === '')) {
            return true;
        }
        if ($field->type === 'rich_text') {
            try {
                return !\Utsuri\PortableCms\Conventional\Content\RichText::from($value, $field)->hasText();
            } catch (\InvalidArgumentException) {
                return false;
            }
        }
        return $field->type === 'composition' && is_array($value) && ($value['rows'] ?? null) === [];
    }
}
