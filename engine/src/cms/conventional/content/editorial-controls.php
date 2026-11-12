<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Content;

use Utsuri\PortableCms\Conventional\Http\Request;
use Utsuri\PortableCms\Conventional\Identity\RandomSource;

/** Native form projection and transient operations. Operations never implicitly save a record. */
final class EditorialControls
{
    public static function structured(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }
        if ($value === 'null') {
            return null;
        }
        if (!str_starts_with($value, '[') && !str_starts_with($value, '{')) {
            return $value;
        }
        try {
            return json_decode($value, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
    }

    /** @return list<string> */
    public static function selections(mixed $value, ?FieldDefinition $field = null): array
    {
        $value = self::structured($value);
        if ($field?->typedReferences()) {
            $items = $field->type === 'record_reference' ? ($value === null ? [] : [$value]) : $value;
            return is_array($items) ? array_map(static fn($item): string => RecordReference::from($item)->token(), $items) : [];
        }
        if (is_string($value)) {
            return $value === '' ? [] : [$value];
        }
        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }

    /** @return array{typeId: string, recordId: string}|list<array{typeId: string, recordId: string}>|null */
    public static function referenceInput(FieldDefinition $field, Request $request): ?array
    {
        if ($field->type === 'record_reference') {
            $value = $request->formValue($field->id);
            return $value === '' ? null : RecordReference::fromToken($value)->document();
        }
        return array_map(static fn(string $token): array => RecordReference::fromToken($token)->document(), $request->formValues($field->id));
    }

    /** @return array<string, mixed>|list<mixed> */
    public static function groupInput(Request $request, FieldDefinition $field, ProfileManifest $profile): array
    {
        $result = [];
        foreach ($field->localized ? $profile->locales : [''] as $locale) {
            $prefix = $field->id . ($locale === '' ? '' : '_' . $locale);
            if ($field->type === 'group') {
                $result[$locale] = self::children($request, $field, $profile, $prefix, $locale !== '');
                continue;
            }
            $ids = $request->formValues($prefix . '_items');
            if (count($ids) > (int) $field->maximumItems || count(array_unique($ids)) !== count($ids)) {
                throw new \InvalidArgumentException('The group item count is invalid.');
            }
            $items = [];
            foreach ($ids as $id) {
                if (preg_match('/\A[a-f0-9]{32}\z/', $id) !== 1) {
                    throw new \InvalidArgumentException('The group identity is invalid.');
                }
                $items[] = ['_itemId' => $id, ...self::children($request, $field, $profile, $prefix . '_i' . $id, $locale !== '')];
            }
            $result[$locale] = $items;
        }
        return $field->localized ? $result : $result[''];
    }

    /** @param array<string, mixed> $saved
     * @return array<string, mixed>
     */
    public static function children(Request $request, FieldDefinition $group, ProfileManifest $profile, string $prefix, bool $insideLocale, array $saved = []): array
    {
        $values = [];
        foreach ($group->fields as $child) {
            $name = self::childName($prefix, $child->id);
            if (!EditorialForm::supported(self::childDefinition($child, $name, $insideLocale))) {
                $value = $saved[$child->id] ?? null;
            } elseif ($child->type === 'composition') {
                $value = isset($request->form[$name . '__document']) ? CompositionForm::input($request, $name) : ($saved[$child->id] ?? null);
            } elseif ($child->type === 'vimeo_video') {
                $value = VimeoVideoForm::input($request, $name, $child->localized && !$insideLocale, $profile->locales);
            } elseif ($child->type === 'web_address') {
                $value = WebAddressForm::input($request, $name, $child->localized && !$insideLocale, $profile->locales);
            } elseif ($child->type === 'video_reference') {
                $value = $request->formValue('_present_' . $name) === '1'
                    ? VideoReference::input($request, $name, $child->localized && !$insideLocale, $profile->locales)
                    : ($saved[$child->id] ?? null);
            } elseif ($child->localized && !$insideLocale) {
                $value = [];
                foreach ($profile->locales as $locale) {
                    $value[$locale] = $child->type === 'rich_text'
                        ? RichTextForm::input($request, $name . '__' . $locale, $child)
                        : $request->formValue($name . '__' . $locale);
                }
            } elseif ($child->type === 'rich_text') {
                $value = RichTextForm::input($request, $name, $child);
            } elseif ($child->type === 'boolean') {
                $value = $request->formValue($name) === '1';
            } elseif ($child->typedReferences()) {
                $value = self::referenceInput(self::childDefinition($child, $name, $insideLocale), $request);
            } elseif (in_array($child->type, ['record_references', 'taxonomy_references', 'media_references'], true)) {
                $value = $request->formValues($name);
            } else {
                $value = $request->formValue($name);
            }
            // Row cells wrap long values in a text area; a short text still holds one line.
            if (in_array($child->type, ['short_text', 'localized_short_text'], true)) {
                $value = is_array($value) ? array_map(self::line(...), $value) : self::line($value);
            }
            $values[$child->id] = $value;
        }
        return $values;
    }

    private static function line(mixed $value): mixed
    {
        return is_string($value) ? (string) preg_replace('/[ \t]*\R+[ \t]*/u', ' ', $value) : $value;
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    public static function apply(Request $request, RecordTypeDefinition $type, ProfileManifest $profile, array $values, RandomSource $random): array
    {
        $action = $request->formValue('_editor_action');
        if ($action === '' || $action === 'search') {
            return $values;
        }
        $parts = explode(':', $action);
        $operation = $parts[0] ?? '';
        $id = $parts[1] ?? '';
        $field = null;
        foreach ($type->fields as $candidate) {
            if ($candidate->id === $id) {
                $field = $candidate;
            }
        }
        if (strlen($action) > 400) {
            throw new \InvalidArgumentException('The editor action is invalid.');
        }
        if ($field === null) {
            return self::nestedAction($request, $type, $profile, $values, $random, $id);
        }
        if ($field->type === 'video_reference' && in_array($operation, ['select-video', 'select-poster', 'clear-video', 'clear-poster'], true)) {
            $selection = self::structured($values[$id] ?? null);
            $selection = is_array($selection) ? $selection : ['mediaId' => '', 'posterId' => ''];
            $target = str_starts_with($operation, 'clear-') ? '' : ($parts[2] ?? '');
            if ($target !== '' && preg_match('/\A[a-f0-9]{32}\z/', $target) !== 1) {
                throw new \InvalidArgumentException('The video selection is invalid.');
            }
            $selection[str_ends_with($operation, 'video') ? 'mediaId' : 'posterId'] = $target;
            $values[$id] = ($selection['mediaId'] ?? '') === '' && ($selection['posterId'] ?? '') === '' ? null : $selection;
            return $values;
        }
        if (in_array($field->type, ['record_reference', 'record_references', 'taxonomy_references', 'media_references'], true)) {
            $items = self::selections($values[$id] ?? null, $field);
            $target = $parts[2] ?? '';
            if ($field->typedReferences()) {
                $reference = RecordReference::fromToken($target);
                if (in_array($operation, ['select', 'replace'], true) && !in_array($reference->typeId, $field->targetTypes, true)) {
                    throw new \InvalidArgumentException('The selection type is unavailable.');
                }
            } elseif (preg_match('/\A[a-f0-9]{32}\z/', $target) !== 1) {
                throw new \InvalidArgumentException('The selection is invalid.');
            }
            if ($operation === 'select') {
                if ($field->type === 'record_reference') {
                    $items = [$target];
                } elseif (!in_array($target, $items, true) && count($items) < (int) $field->maximumItems) {
                    $items[] = $target;
                }
            } elseif ($operation === 'replace') {
                $position = filter_var($request->formValue('_replace_' . $id), FILTER_VALIDATE_INT);
                if (!is_int($position) || !isset($items[$position])) {
                    throw new \InvalidArgumentException('Choose the item to replace.');
                }
                $items[$position] = $target;
                $items = array_values(array_unique($items));
            } else {
                $items = self::ordered($items, $target, $operation);
            }
            if ($field->typedReferences()) {
                $references = array_map(static fn(string $token): array => RecordReference::fromToken($token)->document(), $items);
                $values[$id] = $field->type === 'record_reference' ? ($references[0] ?? null) : $references;
                return $values;
            }
            $values[$id] = $field->type === 'media_references' ? json_encode($items, JSON_THROW_ON_ERROR)
                : ($field->type === 'record_reference' ? ($items[0] ?? '') : $items);
            return $values;
        }
        if ($field->type === 'blocks') {
            $values[$id] = json_encode(BlocksForm::apply($request, $field, $profile, self::structured($values[$id] ?? null), $random), JSON_THROW_ON_ERROR);
            return $values;
        }
        if ($field->type !== 'repeatable_group') {
            throw new \InvalidArgumentException('The editor action is unavailable.');
        }
        $locale = $parts[2] ?? '';
        if ($field->localized && !in_array($locale, $profile->locales, true)) {
            throw new \InvalidArgumentException('The group locale is invalid.');
        }
        $group = self::structured($values[$id] ?? null);
        $group = is_array($group) ? $group : [];
        $items = $field->localized ? ($group[$locale] ?? []) : $group;
        $items = is_array($items) ? array_values($items) : [];
        if ($operation === 'add' && count($items) < (int) $field->maximumItems) {
            $items[] = ['_itemId' => bin2hex($random->bytes(16))];
        } else {
            $target = $parts[3] ?? '';
            $position = null;
            foreach ($items as $index => $item) {
                if (is_array($item) && ($item['_itemId'] ?? null) === $target) {
                    $position = $index;
                }
            }
            if ($position !== null) {
                if ($operation === 'remove') {
                    array_splice($items, $position, 1);
                } elseif ($operation === 'up' && $position > 0) {
                    [$items[$position - 1], $items[$position]] = [$items[$position], $items[$position - 1]];
                } elseif ($operation === 'down' && $position + 1 < count($items)) {
                    [$items[$position + 1], $items[$position]] = [$items[$position], $items[$position + 1]];
                } elseif ($operation === 'insert' && count($items) < (int) $field->maximumItems) {
                    array_splice($items, $position + 1, 0, [['_itemId' => bin2hex($random->bytes(16))]]);
                }
            }
        }
        if ($field->localized) {
            $group[$locale] = $items;
        } else {
            $group = $items;
        }
        $values[$id] = json_encode($group, JSON_THROW_ON_ERROR);
        return $values;
    }

    public static function childName(string $prefix, string $childId): string
    {
        return 'group-' . substr(hash('sha256', $prefix . ':' . $childId), 0, 40);
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private static function nestedAction(Request $request, RecordTypeDefinition $type, ProfileManifest $profile, array $values, RandomSource $random, string $controlId): array
    {
        $block = BlocksForm::find($type, $profile, $values, $controlId);
        if ($block !== null) {
            $single = BlocksForm::type($type, [$block['field']]);
            $updated = self::apply($request, $single, $profile, [$controlId => $block['value']], $random);
            return BlocksForm::replace($values, $block, self::structured($updated[$controlId]));
        }
        foreach ($type->fields as $field) {
            if (!in_array($field->type, ['group', 'repeatable_group'], true) || !EditorialForm::supported($field)) {
                continue;
            }
            $group = self::structured($values[$field->id] ?? null);
            if (!is_array($group)) {
                continue;
            }
            foreach ($field->localized ? $profile->locales : [''] as $locale) {
                $container = $field->localized ? ($group[$locale] ?? []) : $group;
                $items = $field->type === 'group' ? [$container] : $container;
                if (!is_array($items)) {
                    continue;
                }
                foreach ($items as $position => $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $prefix = $field->id . ($locale === '' ? '' : '_' . $locale);
                    if ($field->type === 'repeatable_group') {
                        $prefix .= '_i' . (is_string($item['_itemId'] ?? null) ? $item['_itemId'] : self::legacyIdentity($prefix, (int) $position, $item));
                    }
                    foreach ($field->fields as $child) {
                        if (self::childName($prefix, $child->id) !== $controlId) {
                            continue;
                        }
                        $control = self::childDefinition($child, $controlId, $locale !== '');
                        $single = new RecordTypeDefinition($type->id, $type->label, $type->singularLabel, [$control], $type->kind, $type->archetype, $type->renderer, $type->route);
                        $updated = self::apply($request, $single, $profile, [$controlId => $item[$child->id] ?? null], $random);
                        $item[$child->id] = $child->type === 'media_references' ? self::structured($updated[$controlId]) : $updated[$controlId];
                        $items[$position] = $item;
                        $container = $field->type === 'group' ? $items[0] : array_values($items);
                        if ($field->localized) {
                            $group[$locale] = $container;
                        } else {
                            $group = $container;
                        }
                        $values[$field->id] = json_encode($group, JSON_THROW_ON_ERROR);
                        return $values;
                    }
                }
            }
        }
        throw new \InvalidArgumentException('The editor target is invalid.');
    }

    /** @param array<string, mixed> $item */
    public static function legacyIdentity(string $prefix, int $position, array $item): string
    {
        return substr(hash('sha256', $prefix . ':' . $position . ':' . json_encode($item, JSON_THROW_ON_ERROR)), 0, 32);
    }

    public static function childDefinition(FieldDefinition $child, string $id, bool $insideLocale): FieldDefinition
    {
        return new FieldDefinition(
            $id,
            $child->label,
            $insideLocale && $child->type === 'localized_short_text' ? 'short_text' : $child->type,
            !$insideLocale && $child->localized,
            $child->requiredOnDraft,
            $child->requiredOnPublish,
            $child->settings,
            [],
            $child->maximumLength,
            $child->targetType,
            $child->maximumItems,
            hint: $child->hint,
        );
    }

    /** @param array<string, mixed> $body */
    public static function nestedDefinition(RecordTypeDefinition $type, ProfileManifest $profile, array $body, string $controlId): ?FieldDefinition
    {
        return self::nestedField($type, $profile, $body, $controlId)['field'] ?? null;
    }

    /**
     * @param array<string, mixed> $body
     * @return array{field: FieldDefinition, value: mixed}|null
     */
    public static function nestedField(RecordTypeDefinition $type, ProfileManifest $profile, array $body, string $controlId): ?array
    {
        $block = BlocksForm::find($type, $profile, $body, $controlId);
        if ($block !== null) {
            return ['field' => $block['field'], 'value' => $block['value']];
        }
        $fields = array_filter($type->fields, static fn(FieldDefinition $field): bool => $field->type !== 'blocks');
        foreach ($fields as $field) {
            if (!in_array($field->type, ['group', 'repeatable_group'], true)) {
                continue;
            }
            $group = self::structured($body[$field->id] ?? null);
            if (!is_array($group)) {
                continue;
            }
            foreach ($field->localized ? $profile->locales : [''] as $locale) {
                $container = $field->localized ? ($group[$locale] ?? []) : $group;
                $items = $field->type === 'group' ? [$container] : $container;
                if (!is_array($items)) {
                    continue;
                }
                foreach ($items as $position => $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $prefix = $field->id . ($locale === '' ? '' : '_' . $locale);
                    if ($field->type === 'repeatable_group') {
                        $prefix .= '_i' . (is_string($item['_itemId'] ?? null) ? $item['_itemId'] : self::legacyIdentity($prefix, (int) $position, $item));
                    }
                    foreach ($field->fields as $child) {
                        if (self::childName($prefix, $child->id) === $controlId) {
                            return ['field' => self::childDefinition($child, $controlId, $locale !== ''), 'value' => $item[$child->id] ?? null];
                        }
                    }
                }
            }
        }
        return null;
    }

    /**
     * @param list<string> $items
     * @return list<string>
     */
    private static function ordered(array $items, string $target, string $operation): array
    {
        $position = array_search($target, $items, true);
        if ($position === false) {
            return $items;
        }
        if ($operation === 'remove') {
            array_splice($items, $position, 1);
        } elseif ($operation === 'up' && $position > 0) {
            [$items[$position - 1], $items[$position]] = [$items[$position], $items[$position - 1]];
        } elseif ($operation === 'down' && $position + 1 < count($items)) {
            [$items[$position + 1], $items[$position]] = [$items[$position], $items[$position + 1]];
        } else {
            throw new \InvalidArgumentException('The ordering action is invalid.');
        }
        return $items;
    }
}
