<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Import;

use Utsuri\PortableCms\Conventional\Content\FieldDefinition;

/** Remap only profile-declared identity slots; arbitrary text and rich text remain data. */
final class ImportBody
{
    /** @param list<FieldDefinition> $fields
     * @param array<string, mixed> $body
     * @param list<string> $locales
     * @param array<string, string> $records
     * @param array<string, string> $media
     * @return array<string, mixed>
     */
    public static function remap(array $fields, array $body, array $locales, array $records, array $media): array
    {
        foreach ($fields as $field) {
            if (!array_key_exists($field->id, $body) || $body[$field->id] === null) {
                continue;
            }
            $value = $body[$field->id];
            if ($field->localized && is_array($value)) {
                foreach ($locales as $locale) {
                    if (isset($value[$locale])) {
                        $value[$locale] = self::value($field, $value[$locale], $locales, $records, $media);
                    }
                }
                $body[$field->id] = $value;
            } else {
                $body[$field->id] = self::value($field, $value, $locales, $records, $media);
            }
        }
        return $body;
    }

    /** @param list<string> $locales
     * @param array<string, string> $records
     * @param array<string, string> $media
     */
    private static function value(FieldDefinition $field, mixed $value, array $locales, array $records, array $media): mixed
    {
        if ($field->type === 'blocks' && is_array($value)) {
            foreach ($value as $position => $item) {
                $type = is_array($item) && is_string($item['typeId'] ?? null) ? ($field->blockTypes[$item['typeId']] ?? null) : null;
                if ($type !== null && is_array($item['values'] ?? null)) {
                    $value[$position]['values'] = self::remap(self::children($type->fields, $field->localized), $item['values'], $locales, $records, $media);
                }
            }
            return $value;
        }
        if ($field->type === 'vimeo_video' && is_array($value) && is_string($value['poster'] ?? null)) {
            $value['poster'] = $media[$value['poster']] ?? $value['poster'];
            return $value;
        }
        if ($field->type === 'video_reference' && is_array($value)) {
            foreach (['mediaId', 'posterId'] as $key) {
                if (is_string($value[$key] ?? null)) {
                    $value[$key] = $media[$value[$key]] ?? $value[$key];
                }
            }
            return $value;
        }
        if ($field->typedReferences() && is_array($value)) {
            $items = $field->type === 'record_reference' ? [$value] : $value;
            foreach ($items as &$item) {
                if (is_array($item) && is_string($item['recordId'] ?? null)) {
                    $item['recordId'] = $records[$item['recordId']] ?? $item['recordId'];
                }
            }
            unset($item);
            return $field->type === 'record_reference' ? $items[0] : $items;
        }
        if ($field->type === 'record_reference' && is_string($value)) {
            return $records[$value] ?? $value;
        }
        if (in_array($field->type, ['record_references', 'taxonomy_references', 'media_references'], true) && is_array($value)) {
            $map = $field->type === 'media_references' ? $media : $records;
            return array_map(static fn($id) => is_string($id) ? ($map[$id] ?? $id) : $id, $value);
        }
        if (in_array($field->type, ['group', 'repeatable_group'], true) && is_array($value)) {
            if ($field->type === 'group') {
                return self::remap(self::children($field->fields, $field->localized), $value, $locales, $records, $media);
            }
            return array_map(static fn($item) => is_array($item) ? self::remap(self::children($field->fields, $field->localized), $item, $locales, $records, $media) : $item, $value);
        }
        if ($field->type === 'composition' && is_array($value) && is_array($value['rows'] ?? null)) {
            foreach ($value['rows'] as &$row) {
                if (!is_array($row) || !is_array($row['columns'] ?? null)) {
                    continue;
                }
                foreach ($row['columns'] as &$column) {
                    if (is_array($column) && ($column['content']['kind'] ?? null) === 'media' && is_string($column['content']['id'] ?? null)) {
                        $column['content']['id'] = $media[$column['content']['id']] ?? $column['content']['id'];
                        if (is_string($column['content']['link']['recordId'] ?? null)) {
                            $id = $column['content']['link']['recordId'];
                            $column['content']['link']['recordId'] = $records[$id] ?? $id;
                        }
                    }
                }
                unset($column);
            }
            unset($row);
        }
        return $value;
    }

    /** @param list<FieldDefinition> $fields
     * @param array<string, mixed> $body
     * @param list<string> $locales
     * @return list<string>
     */
    public static function mediaIds(array $fields, array $body, array $locales): array
    {
        $ids = [];
        foreach ($fields as $field) {
            $raw = $body[$field->id] ?? null;
            foreach ($field->localized && is_array($raw) ? array_values($raw) : [$raw] as $value) {
                if ($field->type === 'blocks' && is_array($value)) {
                    foreach ($value as $item) {
                        $type = is_array($item) && is_string($item['typeId'] ?? null) ? ($field->blockTypes[$item['typeId']] ?? null) : null;
                        if ($type !== null && is_array($item['values'] ?? null)) {
                            $ids = [...$ids, ...self::mediaIds(self::children($type->fields, $field->localized), $item['values'], $locales)];
                        }
                    }
                } elseif ($field->type === 'vimeo_video' && is_array($value) && is_string($value['poster'] ?? null)) {
                    $ids[] = $value['poster'];
                } elseif ($field->type === 'video_reference' && is_array($value)) {
                    foreach (['mediaId', 'posterId'] as $key) {
                        if (is_string($value[$key] ?? null)) {
                            $ids[] = $value[$key];
                        }
                    }
                } elseif ($field->type === 'media_references' && is_array($value)) {
                    $ids = [...$ids, ...array_filter($value, 'is_string')];
                } elseif ($field->type === 'composition' && is_array($value)) {
                    $rows = $value['rows'] ?? [];
                    if (!is_array($rows)) {
                        continue;
                    }
                    foreach ($rows as $row) {
                        if (!is_array($row) || !is_array($row['columns'] ?? null)) {
                            continue;
                        }
                        foreach ($row['columns'] as $column) {
                            if (is_array($column) && ($column['content']['kind'] ?? null) === 'media' && is_string($column['content']['id'] ?? null)) {
                                $ids[] = $column['content']['id'];
                            }
                        }
                    }
                } elseif (in_array($field->type, ['group', 'repeatable_group'], true) && is_array($value)) {
                    foreach ($field->type === 'group' ? [$value] : $value as $item) {
                        if (is_array($item)) {
                            $ids = [...$ids, ...self::mediaIds(self::children($field->fields, $field->localized), $item, $locales)];
                        }
                    }
                }
            }
        }
        return array_values(array_unique($ids));
    }

    /** @param list<FieldDefinition> $fields
     * @return list<FieldDefinition>
     */
    private static function children(array $fields, bool $localized): array
    {
        return array_map(static fn(FieldDefinition $child): FieldDefinition => \Utsuri\PortableCms\Conventional\Content\EditorialControls::childDefinition($child, $child->id, $localized), $fields);
    }
}
