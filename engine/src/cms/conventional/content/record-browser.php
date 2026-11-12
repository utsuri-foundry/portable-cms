<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Content;

final readonly class RecordPage
{
    /** @param list<RecordSnapshot> $records */
    public function __construct(public array $records, public bool $hasNext, public int $page, public string $query) {}
}

interface RecordBrowser
{
    /** @param string|list<string> $typeId */
    public function search(string|array $typeId, string $query, int $page, string $state = ''): RecordPage;
}

/** Bounded read projections for administration summaries. */
interface RecordActivity
{
    /** @return array<string, int> Current record totals by type ID. */
    public function counts(): array;

    /** @return list<RecordSnapshot> Most recently updated records of any type. */
    public function recent(int $limit): array;

    /**
     * @param list<string> $recordIds
     * @return array<string, int> Current records that reference each target.
     */
    public function incomingReferences(array $recordIds): array;
}

final class RecordLabels
{
    public static function label(RecordSnapshot $record, ProfileManifest $profile): string
    {
        foreach (['title', 'name', 'label'] as $key) {
            $value = $record->body[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
            if (is_array($value)) {
                foreach (array_unique([$profile->defaultLocale, ...$profile->locales]) as $locale) {
                    if (is_string($value[$locale] ?? null) && trim($value[$locale]) !== '') {
                        return trim($value[$locale]);
                    }
                }
            }
        }
        return 'Untitled';
    }
}
