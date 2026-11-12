<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Compiler;

/** Frozen render selection, including drafts only when the snapshot explicitly permits them. */
final readonly class PublishedRecords
{
    /** @var array<string, list<FrozenRecord>> */
    private array $byType;

    public function __construct(public FrozenSnapshot $snapshot, ?string $orderField = 'sortOrder')
    {
        $byType = [];
        foreach ($snapshot->records as $record) {
            if ($snapshot->includes($record)) {
                $byType[$record->typeId][] = $record;
            }
        }
        foreach ($byType as &$records) {
            usort($records, static function (FrozenRecord $left, FrozenRecord $right) use ($orderField): int {
                $a = $orderField === null ? null : ($left->body[$orderField] ?? null);
                $b = $orderField === null ? null : ($right->body[$orderField] ?? null);
                return ((is_int($a) ? $a : 100_000) <=> (is_int($b) ? $b : 100_000)) ?: ($left->id <=> $right->id);
            });
        }
        unset($records);
        $this->byType = $byType;
    }

    /** @return list<FrozenRecord> */
    public function records(string $typeId): array
    {
        return $this->byType[$typeId] ?? [];
    }

    public function record(string $id): ?FrozenRecord
    {
        $record = $this->snapshot->record($id);
        return $record !== null && $this->snapshot->includes($record) ? $record : null;
    }

    public function singleton(string $typeId, bool $required = true): ?FrozenRecord
    {
        $records = $this->records($typeId);
        if (count($records) > 1 || ($required && $records === [])) {
            throw new ProfileBuildFailure(ProfileBuildReason::MissingSetting, typeId: $typeId);
        }
        return $records[0] ?? null;
    }
}
