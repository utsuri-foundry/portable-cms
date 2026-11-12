<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Content;

use Utsuri\PortableCms\Conventional\Http\Request;
use Utsuri\PortableCms\Conventional\Identity\RandomSource;

/** Native block transport and transient actions. Saving still uses the strict blocks contract. */
final class BlocksForm
{
    public static function prefix(FieldDefinition $field, string $locale): string
    {
        return $field->id . ($locale === '' ? '' : '_' . $locale);
    }

    /** @return array<mixed> */
    public static function input(Request $request, FieldDefinition $field, ProfileManifest $profile, mixed $saved): array
    {
        if ($request->formValue('_editorial_end') !== '1') {
            throw new \InvalidArgumentException('Incomplete editorial form');
        }
        $body = [];
        foreach ($field->localized ? $profile->locales : [''] as $locale) {
            $prefix = self::prefix($field, $locale);
            $ids = $request->formValues($prefix . '_items');
            if (count($ids) > $field->settings['maximumItems'] || count(array_unique($ids)) !== count($ids)) {
                throw new \InvalidArgumentException('Block item count is invalid');
            }
            $previous = $field->localized ? ($saved[$locale] ?? []) : ($saved ?? []);
            $previous = array_column($previous, null, '_itemId');
            $items = [];
            foreach ($ids as $id) {
                $typeId = $request->formValue($prefix . '_i' . $id . '__type');
                if (preg_match('/\A[a-f0-9]{32}\z/', $id) !== 1 || !isset($field->blockTypes[$typeId])
                    || (isset($previous[$id]) && $previous[$id]['typeId'] !== $typeId)) {
                    throw new \InvalidArgumentException('Block identity or type is invalid');
                }
                $block = $field->blockTypes[$typeId];
                $group = new FieldDefinition('block', $block->label, 'group', false, false, false, [], fields: $block->fields);
                $items[] = ['_itemId' => $id, 'typeId' => $typeId, 'values' => EditorialControls::children($request, $group, $profile, Blocks::groupId($field, $locale, $id), $locale !== '', $previous[$id]['values'] ?? [])];
            }
            $body[$locale] = $items;
        }
        $body = $field->localized ? $body : $body[''];
        Blocks::items($field, $body, $profile->locales, partial: true);
        return $body;
    }

    /** @return array<mixed> */
    public static function apply(Request $request, FieldDefinition $field, ProfileManifest $profile, mixed $raw, RandomSource $random): array
    {
        Blocks::items($field, $raw, $profile->supported(), partial: true);
        $parts = explode(':', $request->formValue('_editor_action'));
        $operation = $parts[0];
        $locale = $parts[2] ?? '';
        $target = $parts[3] ?? '';
        if (($field->localized && !in_array($locale, $profile->locales, true)) || (!$field->localized && $locale !== '') || count($parts) > 4 || ($operation === 'add' && $target !== '')) {
            throw new \InvalidArgumentException('Block action language is invalid');
        }
        $body = $raw ?? [];
        $items = $field->localized ? ($body[$locale] ?? []) : $body;
        $position = array_search($target, array_column($items, '_itemId'), true);
        if ($operation !== 'add' && $position === false) {
            throw new \InvalidArgumentException('Block action target is invalid');
        }
        if (in_array($operation, ['add', 'insert', 'duplicate'], true)) {
            if (count($items) >= $field->settings['maximumItems']) {
                throw new \InvalidArgumentException('Block item limit reached');
            }
            $typeId = $operation === 'duplicate' ? $items[$position]['typeId'] : $request->formValue(self::prefix($field, $locale) . '__add_' . ($target === '' ? 'end' : $target));
            if (!isset($field->blockTypes[$typeId])) {
                throw new \InvalidArgumentException('Block type is unavailable');
            }
            $id = bin2hex($random->bytes(16));
            if (in_array($id, array_column($items, '_itemId'), true)) {
                throw new \InvalidArgumentException('Block identity collision');
            }
            $item = ['_itemId' => $id, 'typeId' => $typeId, 'values' => $operation === 'duplicate' ? $items[$position]['values'] : []];
            array_splice($items, $operation === 'add' ? count($items) : $position + 1, 0, [$item]);
        } elseif ($operation === 'remove') {
            $minimum = max($field->settings['minimumItems'], $field->required ? 1 : 0);
            if ($locale !== '' && in_array($locale, array_map(static fn(LocaleDefinition $definition): string => $definition->id, array_filter($profile->localeDefinitions, static fn(LocaleDefinition $definition): bool => $definition->preparation)), true)) {
                $minimum = 0;
            }
            if (count($items) <= $minimum) {
                throw new \InvalidArgumentException('Block minimum reached');
            }
            array_splice($items, $position, 1);
        } elseif ($operation === 'up' && $position > 0) {
            [$items[$position - 1], $items[$position]] = [$items[$position], $items[$position - 1]];
        } elseif ($operation === 'down' && $position + 1 < count($items)) {
            [$items[$position + 1], $items[$position]] = [$items[$position], $items[$position + 1]];
        } else {
            throw new \InvalidArgumentException('Block action is unavailable');
        }
        if ($field->localized) {
            $body[$locale] = $items;
        } else {
            $body = $items;
        }
        return $body;
    }

    /** @param list<FieldDefinition> $fields */
    public static function type(RecordTypeDefinition $parent, array $fields): RecordTypeDefinition
    {
        return new RecordTypeDefinition($parent->id, $parent->label, $parent->singularLabel, $fields, $parent->kind, $parent->archetype, $parent->renderer, $parent->route);
    }

    /** @param array<string, mixed> $values
     * @return array{parent: FieldDefinition, locale: string, position: int, child: string, field: FieldDefinition, value: mixed, type: RecordTypeDefinition, values: array<string, mixed>}|null
     */
    public static function find(RecordTypeDefinition $type, ProfileManifest $profile, array $values, string $id): ?array
    {
        foreach ($type->fields as $field) {
            if ($field->type !== 'blocks') {
                continue;
            }
            $positions = [];
            foreach (Blocks::items($field, EditorialControls::structured($values[$field->id] ?? null), $profile->supported(), partial: true) as $entry) {
                $locale = $entry['locale'];
                if ($locale !== '' && !in_array($locale, $profile->locales, true)) {
                    continue;
                }
                $position = $positions[$locale] ?? 0;
                $positions[$locale] = $position + 1;
                foreach ($entry['field']->fields as $child) {
                    if (EditorialControls::childName($entry['field']->id, $child->id) === $id) {
                        return ['parent' => $field, 'locale' => $locale, 'position' => $position, 'child' => $child->id,
                            'field' => EditorialControls::childDefinition($child, $id, false), 'value' => $entry['item']['values'][$child->id] ?? null,
                            'type' => self::type($type, $entry['field']->fields), 'values' => $entry['item']['values']];
                    }
                }
            }
        }
        return null;
    }

    /** @param array<string, mixed> $values
     * @param array{parent: FieldDefinition, locale: string, position: int, child: string} $context
     * @return array<string, mixed>
     */
    public static function replace(array $values, array $context, mixed $value): array
    {
        $field = $context['parent'];
        $body = EditorialControls::structured($values[$field->id]);
        if ($field->localized) {
            $body[$context['locale']][$context['position']]['values'][$context['child']] = $value;
        } else {
            $body[$context['position']]['values'][$context['child']] = $value;
        }
        $values[$field->id] = json_encode($body, JSON_THROW_ON_ERROR);
        return $values;
    }
}
