<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Content;

/** Portable target identity. Native tokens are an HTTP projection, never stored content. */
final readonly class RecordReference
{
    public function __construct(public string $typeId, public string $recordId)
    {
        if (!ProfileManifest::canonicalId($typeId) || preg_match('/\A[a-f0-9]{32}\z/', $recordId) !== 1) {
            throw new \InvalidArgumentException('Record reference identity is invalid');
        }
    }

    public static function from(mixed $value): self
    {
        if (!is_array($value) || count($value) !== 2 || !is_string($value['typeId'] ?? null) || !is_string($value['recordId'] ?? null)) {
            throw new \InvalidArgumentException('Record reference requires a type and record identity');
        }
        return new self($value['typeId'], $value['recordId']);
    }

    public static function fromToken(string $token): self
    {
        $parts = explode('@', $token);
        if (count($parts) !== 2) {
            throw new \InvalidArgumentException('Record selection is invalid');
        }
        return new self($parts[0], $parts[1]);
    }

    public function token(): string
    {
        return $this->typeId . '@' . $this->recordId;
    }

    /** @return array{typeId: string, recordId: string} */
    public function document(): array
    {
        return ['typeId' => $this->typeId, 'recordId' => $this->recordId];
    }

    /** @return list<self> */
    public static function values(FieldDefinition $field, mixed $raw, bool $published = false): array
    {
        $items = $field->type === 'record_reference' ? ($raw === null ? [] : [$raw]) : $raw;
        if (!is_array($items) || !array_is_list($items) || count($items) > ($field->maximumItems ?? 1)
            || (($published ? $field->requiredOnPublish : $field->requiredOnDraft) && $items === [])) {
            throw new \InvalidArgumentException('Record reference count is invalid');
        }
        $references = [];
        foreach ($items as $item) {
            $reference = self::from($item);
            if (!in_array($reference->typeId, $field->targetTypes, true) || isset($references[$reference->token()])) {
                throw new \InvalidArgumentException('Record reference type or ordering is invalid');
            }
            $references[$reference->token()] = $reference;
        }
        return array_values($references);
    }

    /**
     * @param list<FieldDefinition> $fields
     * @param array<string, mixed> $body
     * @param list<string> $locales
     * @return array<string, list<self>>
     */
    public static function fields(array $fields, array $body, array $locales, bool $published): array
    {
        [$fields, $body] = \Utsuri\PortableCms\Conventional\Content\Blocks::expand($fields, $body, $locales, $published);
        $references = [];
        foreach ($fields as $field) {
            $raw = $body[$field->id] ?? ($field->type === 'record_reference' ? null : []);
            if ($field->typedReferences()) {
                $references[$field->id] = self::values($field, $raw, $published);
            } elseif (in_array($field->type, ['group', 'repeatable_group'], true) && is_array($raw)) {
                foreach ($field->localized ? $locales : [''] as $locale) {
                    $container = $field->localized ? ($raw[$locale] ?? []) : $raw;
                    if (!is_array($container)) {
                        throw new \InvalidArgumentException('Record reference group is invalid');
                    }
                    $prefix = $field->id . ($locale === '' ? '' : '_' . $locale);
                    foreach ($field->type === 'group' ? [$container] : $container as $position => $item) {
                        if (!is_array($item)) {
                            throw new \InvalidArgumentException('Record reference group item is invalid');
                        }
                        $itemPrefix = $field->type === 'group' ? $prefix : $prefix . '_i' . ($item['_itemId'] ?? EditorialControls::legacyIdentity($prefix, (int) $position, $item));
                        foreach (self::fields($field->fields, $item, $locales, $published) as $id => $values) {
                            $references[EditorialControls::childName($itemPrefix, $id)] = $values;
                        }
                    }
                }
            }
        }
        return $references;
    }

}
