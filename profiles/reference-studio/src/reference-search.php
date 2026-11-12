<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Profile\ReferenceStudio;

use Utsuri\PortableCms\Conventional\Compiler\FrozenRecord;
use Utsuri\PortableCms\Conventional\Compiler\FrozenSnapshot;
use Utsuri\PortableCms\Conventional\Compiler\SearchDocument;
use Utsuri\PortableCms\Conventional\Compiler\SearchIndexBuilder;

final class ReferenceSearch
{
    public function __construct(
        private readonly FrozenSnapshot $snapshot,
        private readonly ReferenceContent $content,
    ) {}

    /** @return array<string, mixed> */
    public function index(string $locale): array
    {
        $index = new SearchIndexBuilder($locale);
        foreach (['project', 'article', 'person'] as $typeId) {
            foreach ($this->content->records($typeId) as $record) {
                $title = $this->content->title($record, $locale);
                $mediaIds = $this->content->imageMediaIds($record);
                $index->add(new SearchDocument($record->id, $record->typeId, $this->content->route($record, $locale), $title, $mediaIds[0] ?? null), [
                    ['text' => $title, 'weight' => 16],
                    ['text' => $this->content->summary($record, $locale), 'weight' => 8],
                    ...array_map(static fn(string $text): array => ['text' => $text, 'weight' => 4], $this->relatedText($record, $locale)),
                ]);
            }
        }
        foreach ($this->content->records('landing') as $record) {
            $index->add(new SearchDocument($record->id, $record->typeId, $this->content->path('/landing/', $locale), $this->content->title($record, $locale)), [
                ['text' => $this->content->title($record, $locale), 'weight' => 16],
                ['text' => $this->content->blockText($record, $locale), 'weight' => 4],
            ]);
        }
        return $index->build()->document;
    }

    /** @return list<string> */
    private function relatedText(FrozenRecord $record, string $locale): array
    {
        $result = [];
        if (is_int($record->body['year'] ?? null)) {
            $result[] = (string) $record->body['year'];
        }
        foreach ($record->relationships as $relationship) {
            $target = $this->snapshot->record($relationship->targetRecordId);
            if (!$target instanceof FrozenRecord || !$this->snapshot->includes($target)) {
                continue;
            }
            if (in_array($target->typeId, ['person', 'discipline', 'sector'], true)) {
                $result[] = $this->content->title($target, $locale);
            }
        }
        return $result;
    }
}
