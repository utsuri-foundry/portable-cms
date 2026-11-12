<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Content;

/** A reviewed block shape; editor content never chooses executable renderers. */
final readonly class BlockTypeDefinition
{
    /** @param list<FieldDefinition> $fields */
    public function __construct(public string $id, public string $label, public ?string $itemLabel, public array $fields) {}
}

/** Safe leaf errors addressed by the native controls of the rejected blocks. */
final class InvalidBlockValues extends \InvalidArgumentException
{
    /** @param array<string, \Utsuri\PortableCms\Conventional\I18n\Message> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('Block values fail their declared type');
    }
}

/** Bounded ordered blocks and their projection onto the existing group field boundaries. */
final class Blocks
{
    /** @param array<string, mixed> $settings
     * @return array<string, BlockTypeDefinition>
     */
    public static function definitions(array $settings): array
    {
        $types = $settings['blockTypes'] ?? null;
        $minimum = $settings['minimumItems'] ?? null;
        $maximum = $settings['maximumItems'] ?? null;
        if (array_diff(array_keys($settings), ['blockTypes', 'minimumItems', 'maximumItems']) !== []
            || !is_array($types) || !array_is_list($types) || count($types) < 1 || count($types) > 16
            || !is_int($minimum) || !is_int($maximum) || $minimum < 0 || $minimum > $maximum || $maximum < 1 || $maximum > 64) {
            throw new \InvalidArgumentException('Block declarations are invalid');
        }
        $definitions = [];
        foreach ($types as $type) {
            if (!is_array($type) || array_diff(array_keys($type), ['id', 'label', 'itemLabel', 'fields']) !== []
                || !is_string($type['id'] ?? null) || !ProfileManifest::canonicalId($type['id']) || isset($definitions[$type['id']])
                || !is_string($type['label'] ?? null) || !ProfileManifest::label($type['label'])
                || (isset($type['itemLabel']) && (!is_string($type['itemLabel']) || !ProfileManifest::label($type['itemLabel'])))
                || !is_array($type['fields'] ?? null) || !array_is_list($type['fields']) || $type['fields'] === [] || count($type['fields']) > 32) {
                throw new \InvalidArgumentException('Block type declaration is invalid');
            }
            // Check before parsing, including direct PHP construction, so recursion never starts.
            foreach ($type['fields'] as $child) {
                if (!is_array($child) || in_array($child['type'] ?? null, ['blocks', 'group', 'repeatable_group', 'vimeo_video'], true)) {
                    throw new \InvalidArgumentException('Blocks cannot contain nested groups or blocks');
                }
            }
            $fields = ProfileManifest::parseFields($type['fields']);
            if (count(array_unique(array_column($fields, 'id'))) !== count($fields)) {
                throw new \InvalidArgumentException('Block field identities must be unique');
            }
            $definitions[$type['id']] = new BlockTypeDefinition($type['id'], $type['label'], $type['itemLabel'] ?? null, $fields);
        }
        return $definitions;
    }

    /** Validate the envelope without coercing, generating identities or changing canonical bytes.
     * @param list<string> $locales
     * @param list<string>|null $requiredIn
     * @return list<array{item: array{_itemId: string, typeId: string, values: array<string, mixed>}, locale: string, field: FieldDefinition}>
     */
    public static function items(FieldDefinition $field, mixed $raw, array $locales, bool $published = false, ?array $requiredIn = null, bool $partial = false): array
    {
        if ($raw !== null && (!is_array($raw) || ($field->localized && array_diff(array_keys($raw), $locales) !== []))) {
            throw new \InvalidArgumentException('Block values or languages are invalid');
        }
        $result = [];
        foreach ($field->localized ? $locales : [''] as $locale) {
            $items = $field->localized ? ($raw[$locale] ?? []) : ($raw ?? []);
            $enforced = $locale === '' || $requiredIn === null || in_array($locale, $requiredIn, true);
            $required = $published ? $field->requiredOnPublish : $field->requiredOnDraft;
            $minimum = max($field->settings['minimumItems'], $required ? 1 : 0);
            if (!is_array($items) || !array_is_list($items) || count($items) > $field->settings['maximumItems'] || (!$partial && $enforced && count($items) < $minimum)) {
                throw new \InvalidArgumentException('Block item count is invalid');
            }
            $identities = [];
            foreach ($items as $item) {
                if (!is_array($item) || count($item) !== 3 || !is_string($item['_itemId'] ?? null)
                    || preg_match('/\A[a-f0-9]{32}\z/', $item['_itemId']) !== 1 || isset($identities[$item['_itemId']])
                    || !is_string($item['typeId'] ?? null) || !isset($field->blockTypes[$item['typeId']]) || !is_array($item['values'] ?? null)) {
                    throw new \InvalidArgumentException('Block item is invalid');
                }
                $type = $field->blockTypes[$item['typeId']];
                if (array_diff(array_keys($item['values']), array_column($type->fields, 'id')) !== []) {
                    throw new \InvalidArgumentException('Block contains undeclared values');
                }
                $identities[$item['_itemId']] = true;
                $children = [];
                foreach ($type->fields as $child) {
                    $child = EditorialControls::childDefinition($child, $child->id, $locale !== '');
                    $children[] = new FieldDefinition($child->id, $child->label, $child->type, $child->localized, $enforced && ($published ? $child->requiredOnPublish : $child->requiredOnDraft), $enforced && $child->requiredOnPublish, $child->settings, [], $child->maximumLength, $child->targetType, $child->maximumItems);
                }
                $id = self::groupId($field, $locale, $item['_itemId']);
                $group = new FieldDefinition($id, $type->label, 'group', false, false, false, [], $children);
                $result[] = ['item' => $item, 'locale' => $locale, 'field' => $group];
            }
        }
        return $result;
    }

    public static function groupId(FieldDefinition $field, string $locale, string $identity): string
    {
        return 'block-' . substr(hash('sha256', $field->id . ':' . $locale . ':' . $identity), 0, 40);
    }

    /** Adapt blocks to ordinary groups for graph validation without changing stored values.
     * @param list<FieldDefinition> $fields
     * @param array<string, mixed> $body
     * @param list<string> $locales
     * @param list<string>|null $requiredIn
     * @return array{list<FieldDefinition>, array<string, mixed>}
     */
    public static function expand(array $fields, array $body, array $locales, bool $published = false, ?array $requiredIn = null): array
    {
        $expanded = [];
        foreach ($fields as $field) {
            if ($field->type !== 'blocks') {
                $expanded[] = $field;
                continue;
            }
            foreach (self::items($field, $body[$field->id] ?? null, $locales, $published, $requiredIn) as $entry) {
                $expanded[] = $entry['field'];
                $body[$entry['field']->id] = $entry['item']['values'];
            }
        }
        return [$expanded, $body];
    }

    /** Layout sources inside a block refer to that block's sibling fields.
     * @param array<string, mixed> $body
     * @param list<string> $locales
     * @return list<Composition>
     */
    public static function layouts(RecordTypeDefinition $type, array $body, array $locales, ?\Utsuri\PortableCms\Conventional\Design\RegistryDocument $design): array
    {
        $layouts = [];
        foreach ($type->fields as $field) {
            if ($field->type !== 'blocks') {
                continue;
            }
            foreach (self::items($field, $body[$field->id] ?? null, $locales, requiredIn: []) as $entry) {
                $children = $entry['field']->fields;
                $block = new RecordTypeDefinition($type->id, $type->label, $type->singularLabel, $children, $type->kind, $type->archetype, $type->renderer, $type->route);
                foreach ($children as $child) {
                    if ($child->type !== 'composition') {
                        continue;
                    }
                    $raw = $entry['item']['values'][$child->id] ?? null;
                    foreach ($child->localized ? $locales : [''] as $locale) {
                        $layouts[] = Composition::from($child->localized ? ($raw[$locale] ?? null) : $raw, $child, $block, $design, $locales);
                    }
                }
            }
        }
        return $layouts;
    }

    /** Project only block relationship indexes when a build omits or falls back a language.
     * @param list<FieldDefinition> $fields
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @param list<string> $allLocales
     * @param list<string> $drawnLocales
     * @param list<array<string, mixed>> $relationships
     * @return list<array<string, mixed>>
     */
    public static function relationships(array $fields, array $before, array $after, array $allLocales, array $drawnLocales, array $relationships): array
    {
        $owned = [];
        $projected = [];
        foreach ($fields as $field) {
            if ($field->type !== 'blocks') {
                continue;
            }
            foreach (self::items($field, $before[$field->id] ?? null, $allLocales, requiredIn: []) as $entry) {
                foreach ($entry['field']->fields as $child) {
                    $owned[EditorialControls::childName($entry['field']->id, $child->id)] = true;
                }
            }
            foreach (self::items($field, $after[$field->id] ?? null, $drawnLocales, requiredIn: []) as $entry) {
                foreach ($entry['field']->fields as $child) {
                    if (!in_array($child->type, ['record_reference', 'record_references', 'taxonomy_references'], true)) {
                        continue;
                    }
                    $raw = $entry['item']['values'][$child->id] ?? null;
                    if ($child->typedReferences()) {
                        $targets = array_map(static fn(RecordReference $reference): array => ['recordId' => $reference->recordId, 'typeId' => $reference->typeId], RecordReference::values($child, $raw));
                    } else {
                        $ids = $child->type === 'record_reference' ? ($raw === null || $raw === '' ? [] : [$raw]) : ($raw ?? []);
                        $targets = array_map(static fn(string $id): array => ['recordId' => $id], $ids);
                    }
                    foreach ($targets as $position => $target) {
                        $projected[] = ['fieldId' => EditorialControls::childName($entry['field']->id, $child->id), 'kind' => $child->type === 'taxonomy_references' ? 'taxonomy' : 'record', 'targetRecordId' => $target['recordId'], 'position' => $position, ...(isset($target['typeId']) ? ['targetTypeId' => $target['typeId']] : [])];
                    }
                }
            }
        }
        return [...array_values(array_filter($relationships, static fn(array $relationship): bool => !isset($owned[$relationship['fieldId']]))), ...$projected];
    }

    /** Profile-owned search weights can include text without indexing item/type identities.
     * @param list<string> $locales
     */
    public static function text(FieldDefinition $field, mixed $raw, array $locales, string $locale): string
    {
        $text = [];
        foreach (self::items($field, $raw, $locales) as $entry) {
            if ($entry['locale'] !== '' && $entry['locale'] !== $locale) {
                continue;
            }
            foreach ($entry['field']->fields as $child) {
                $value = $entry['item']['values'][$child->id] ?? null;
                $value = $child->localized && is_array($value) ? ($value[$locale] ?? null) : $value;
                if (in_array($child->type, ['short_text', 'localized_short_text', 'multiline_text'], true) && is_string($value)) {
                    $text[] = $value;
                } elseif ($child->type === 'rich_text' && is_array($value)) {
                    $text[] = RichText::from($value, $child)->plainText();
                } elseif ($child->type === 'web_address' && $value !== null) {
                    $text[] = WebAddress::from($value, $child->maximumLength ?? WebAddress::MAXIMUM_LENGTH)->linkText;
                }
            }
        }
        return implode(' ', $text);
    }
}
