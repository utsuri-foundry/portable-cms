<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Content;

/** Pure expansion of schema-validated v3 authoring syntax into the existing v2 field contract. */
final class ProfileFieldGroups
{
    private const MAX_BYTES = 262_144;
    private int $emittedBytes = 0;
    /** @var array<string, \stdClass> */
    private array $expanded = [];

    /** @param array<string, \stdClass> $groups */
    private function __construct(private readonly array $groups) {}

    public static function expand(\stdClass $document): \stdClass
    {
        if (($document->formatVersion ?? null) !== 3) {
            return $document;
        }
        $expander = new self((array) ($document->fieldGroups ?? []));
        // Check unused declarations too: a later use must never reveal a hidden cycle or typo.
        foreach (array_keys($expander->groups) as $name) {
            $expander->group($name, []);
        }
        foreach (['recordTypes', 'singletons', 'taxonomyNamespaces'] as $collection) {
            foreach ($document->$collection as $type) {
                $type->fields = $expander->fields($type->fields, [], 64);
            }
        }
        unset($document->fieldGroups);
        $document->formatVersion = 2;
        if (strlen(json_encode($document, JSON_THROW_ON_ERROR)) > self::MAX_BYTES) {
            throw new \InvalidArgumentException('Expanded profile exceeds the 256 KiB manifest limit');
        }
        return $document;
    }

    /** @param list<string> $stack */
    private function group(string $name, array $stack): \stdClass
    {
        if (!isset($this->groups[$name])) {
            throw new \InvalidArgumentException('Unknown profile field group: ' . $name);
        }
        if (in_array($name, $stack, true)) {
            throw new \InvalidArgumentException('Recursive profile field group: ' . implode(' -> ', [...$stack, $name]));
        }
        if (!isset($this->expanded[$name])) {
            $value = clone $this->groups[$name];
            $value->settings = clone $value->settings;
            $value->settings->fields = $this->fields($value->settings->fields, [...$stack, $name], 32);
            $this->expanded[$name] = $value;
        }
        // Each use owns its labels/mapping; empty JSON objects remain objects during revalidation.
        $copy = json_decode(json_encode($this->expanded[$name], JSON_THROW_ON_ERROR), false, 32, JSON_THROW_ON_ERROR);
        if (!$copy instanceof \stdClass) {
            throw new \InvalidArgumentException('Profile field group is invalid');
        }
        return $copy;
    }

    /** @param list<\stdClass> $values
     * @param list<string> $stack
     * @return list<\stdClass>
     */
    private function fields(array $values, array $stack, int $maximum): array
    {
        $fields = [];
        $ids = [];
        foreach ($values as $value) {
            if (isset($value->includeFieldsFrom)) {
                $children = $this->group($value->includeFieldsFrom, $stack)->settings->fields;
                $batch = self::labels($children, (array) ($value->labels ?? []));
            } else {
                if (isset($value->fieldGroup)) {
                    $field = $this->group($value->fieldGroup, $stack);
                    $field->id = $value->id;
                    if (isset($value->label)) {
                        $field->label = $value->label;
                    }
                    if (isset($value->hint)) {
                        $field->hint = $value->hint;
                    }
                    if (isset($value->itemLabel)) {
                        $field->settings->itemLabel = $value->itemLabel;
                    }
                    $field->settings->fields = self::labels($field->settings->fields, (array) ($value->labels ?? []));
                    if (isset($value->mapping)) {
                        $mapping = $field->settings->searchSharing ?? new \stdClass();
                        foreach ($value->mapping as $key => $setting) {
                            if ($setting === null) {
                                unset($mapping->$key);
                            } else {
                                $mapping->$key = $setting;
                            }
                        }
                        $field->settings->searchSharing = $mapping;
                    }
                    $value = $field;
                } elseif (isset($value->settings->fields)) {
                    $value->settings->fields = $this->fields($value->settings->fields, $stack, 32);
                }
                foreach ($value->settings->blockTypes ?? [] as $block) {
                    $block->fields = $this->fields($block->fields, $stack, 32);
                }
                $batch = [$value];
            }
            foreach ($batch as $field) {
                if (isset($ids[$field->id])) {
                    throw new \InvalidArgumentException('Conflicting profile field after group expansion: ' . $field->id);
                }
                if (count($fields) >= $maximum) {
                    throw new \InvalidArgumentException('Expanded profile field list exceeds its field limit');
                }
                // Charge complete top-level fields before accumulating more copies of a template.
                if ($maximum === 64) {
                    $this->emittedBytes += strlen(json_encode($field, JSON_THROW_ON_ERROR));
                    if ($this->emittedBytes > self::MAX_BYTES) {
                        throw new \InvalidArgumentException('Expanded profile exceeds the 256 KiB manifest limit');
                    }
                }
                $ids[$field->id] = true;
                $fields[] = $field;
            }
        }
        return $fields;
    }

    /** @param list<\stdClass> $fields
     * @param array<string, string> $labels
     * @return list<\stdClass>
     */
    private static function labels(array $fields, array $labels): array
    {
        foreach ($fields as $field) {
            if (isset($labels[$field->id])) {
                $field->label = $labels[$field->id];
                unset($labels[$field->id]);
            }
        }
        if ($labels !== []) {
            throw new \InvalidArgumentException('Unknown field in profile group label overrides: ' . array_key_first($labels));
        }
        return $fields;
    }
}
