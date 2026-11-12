<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Content;

/** Reviewed public field allowlist. Compound fields need a future explicit child-selection contract. */
final readonly class ContentFeed
{
    public const TYPES = ['short_text', 'localized_short_text', 'multiline_text', 'rich_text', 'integer', 'boolean', 'date', 'enum', 'slug', 'record_reference', 'record_references', 'taxonomy_references', 'web_address', 'media_references'];

    /** @param array<string, list<string>> $types */
    private function __construct(public array $types, public int $pageSize) {}

    /** @param list<RecordTypeDefinition> $types */
    public static function from(mixed $value, array $types): ?self
    {
        if ($value === null) {
            return null;
        }
        if (!is_array($value) || array_diff(array_keys($value), ['formatVersion', 'recordTypes', 'pageSize']) !== []
            || ($value['formatVersion'] ?? null) !== 1 || !is_array($value['recordTypes'] ?? null) || !array_is_list($value['recordTypes'])
            || count($value['recordTypes']) < 1 || count($value['recordTypes']) > 64
            || !is_int($value['pageSize'] ?? 100) || ($value['pageSize'] ?? 100) < 1 || ($value['pageSize'] ?? 100) > 100) {
            throw new \InvalidArgumentException('Content feed declaration is invalid');
        }
        $definitions = array_column($types, null, 'id');
        $selected = [];
        foreach ($value['recordTypes'] as $entry) {
            if (!is_array($entry) || count($entry) !== 2 || !is_string($entry['typeId'] ?? null)
                || !isset($definitions[$entry['typeId']]) || isset($selected[$entry['typeId']])
                || !is_array($entry['fields'] ?? null) || !array_is_list($entry['fields']) || count($entry['fields']) < 1 || count($entry['fields']) > 32) {
                throw new \InvalidArgumentException('Content feed type is invalid');
            }
            $fields = array_column($definitions[$entry['typeId']]->fields, null, 'id');
            $names = [];
            foreach ($entry['fields'] as $name) {
                if (!is_string($name) || !isset($fields[$name]) || isset($names[$name]) || !in_array($fields[$name]->type, self::TYPES, true)) {
                    throw new \InvalidArgumentException('Content feed field is unavailable or requires child selection');
                }
                $names[$name] = true;
            }
            $selected[$entry['typeId']] = array_keys($names);
            sort($selected[$entry['typeId']], SORT_STRING);
        }
        ksort($selected, SORT_STRING);
        return new self($selected, $value['pageSize'] ?? 100);
    }

    /** @return array<string, mixed> */
    public function document(): array
    {
        $types = [];
        foreach ($this->types as $id => $fields) {
            $types[] = ['typeId' => $id, 'fields' => $fields];
        }
        return ['formatVersion' => 1, 'pageSize' => $this->pageSize, 'recordTypes' => $types];
    }
}
