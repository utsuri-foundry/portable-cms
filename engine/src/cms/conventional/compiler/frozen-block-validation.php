<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Compiler;

use Utsuri\PortableCms\Conventional\Content\Blocks;
use Utsuri\PortableCms\Conventional\Content\ContentService;
use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Content\RecordConflict;
use Utsuri\PortableCms\Conventional\Content\RecordRepository;
use Utsuri\PortableCms\Conventional\Content\RecordSnapshot;
use Utsuri\PortableCms\Conventional\Identity\Clock;
use Utsuri\PortableCms\Conventional\Identity\RandomSource;

/** Frozen graph adapter for the authoritative content validator; no writable store or effects. */
final readonly class FrozenBlockValidation implements RecordRepository, Clock, RandomSource
{
    /** @param array<string, FrozenRecord> $records */
    public function __construct(private array $records) {}

    /** @param list<FrozenRecord> $records
     * @param array<string, FrozenMedia> $media
     */
    public static function check(array $records, array $media, ProfileManifest $profile, SnapshotLanguages $languages, ?\Utsuri\PortableCms\Conventional\Design\RegistryDocument $design, bool $includeDrafts): void
    {
        $lookup = new self(array_column($records, null, 'id'));
        $profile = $profile->carrying($languages->locales);
        $states = array_map(static fn(string $locale): array => ['locale' => $locale, 'state' => !in_array($locale, $languages->locales, true) ? 'off' : (in_array($locale, $languages->preparation, true) ? 'preparation' : 'public')], $profile->locales);
        $profile = \Utsuri\PortableCms\Conventional\Content\SiteLanguages::from(['formatVersion' => 1, 'profileId' => $profile->id, 'revision' => 0, 'primary' => $languages->primary, 'languages' => $states], $profile)->editingProfile($profile);
        $content = new ContentService($lookup, $profile, $lookup, $lookup, publicLocales: static fn(): array => array_values(array_diff($languages->locales, $languages->preparation)));
        foreach ($records as $record) {
            $type = $profile->type($record->typeId);
            $visible = $record->workflowState === 'published' || ($includeDrafts && $record->workflowState === 'draft');
            $relationships = $content->validateBlocksCanonical($type, $record->body, $visible, $design);
            foreach ($relationships as $expected) {
                $matches = array_filter($record->relationships, static fn(FrozenRelationship $actual): bool => $actual->fieldId === $expected->fieldId && $actual->kind === $expected->kind && $actual->position === $expected->position && $actual->targetRecordId === $expected->targetRecordId);
                $target = $lookup->records[$expected->targetRecordId] ?? null;
                if (count($matches) !== 1 || $target === null || ($visible && $target->workflowState !== 'published' && !($includeDrafts && $target->workflowState === 'draft'))) {
                    throw new \InvalidArgumentException('Frozen block relationships differ from content');
                }
            }
            foreach (Blocks::layouts($type, $record->body, $languages->locales, $design) as $layout) {
                foreach ($layout->mediaIds() as $id) {
                    if (!isset($media[$id]) || !str_starts_with($media[$id]->mime, 'image/')) {
                        throw new \InvalidArgumentException('Frozen block layout image is unavailable');
                    }
                }
                foreach ($layout->links() as $link) {
                    $target = $lookup->find($link->typeId, $link->recordId);
                    if ($target === null || ($visible && $target->workflowState !== 'published' && !($includeDrafts && $target->workflowState === 'draft'))) {
                        throw new \InvalidArgumentException('Frozen block layout target is unavailable');
                    }
                }
            }
            $owned = [];
            foreach ($type->fields as $field) {
                if ($field->type !== 'blocks') {
                    continue;
                }
                foreach (Blocks::items($field, $record->body[$field->id] ?? null, $languages->locales, $visible, array_values(array_diff($languages->locales, $languages->preparation))) as $entry) {
                    foreach ($entry['field']->fields as $child) {
                        $owned[\Utsuri\PortableCms\Conventional\Content\EditorialControls::childName($entry['field']->id, $child->id)] = true;
                        $raw = $entry['item']['values'][$child->id] ?? null;
                        foreach ($child->localized ? $languages->locales : [''] as $locale) {
                            $value = $child->localized ? ($raw[$locale] ?? null) : $raw;
                            if ($child->type === 'media_references') {
                                foreach ($value ?? [] as $id) {
                                    if (!isset($media[$id]) || !str_starts_with($media[$id]->mime, 'image/')) {
                                        throw new \InvalidArgumentException('Frozen block image is unavailable');
                                    }
                                }
                            }

                        }
                    }
                }
            }
            if (count(array_filter($record->relationships, static fn(FrozenRelationship $actual): bool => isset($owned[$actual->fieldId]))) !== count($relationships)) {
                throw new \InvalidArgumentException('Frozen block relationships contain unexpected entries');
            }
        }
    }

    public function find(string $typeId, string $recordId): ?RecordSnapshot
    {
        $record = $this->records[$recordId] ?? null;
        return $record?->typeId === $typeId ? new RecordSnapshot($record->id, $record->typeId, $record->workflowState, $record->revisionId, $record->revisionNumber, $record->body, new \DateTimeImmutable('1970-01-01T00:00:00Z')) : null;
    }

    public function list(string $typeId, int $limit): array
    {
        throw new \LogicException('Frozen validation does not list mutable content');
    }

    public function create(string $recordId, string $revisionId, string $typeId, string $profileId, string $profileVersion, array $body, array $relationships, string $actorUserId, string $auditId, \DateTimeImmutable $now): RecordSnapshot
    {
        throw new \LogicException('Frozen validation is read-only');
    }

    public function update(string $typeId, string $recordId, int $expectedRevisionNumber, string $revisionId, string $profileId, string $profileVersion, array $body, array $relationships, string $actorUserId, string $auditId, \DateTimeImmutable $now): RecordSnapshot|RecordConflict
    {
        throw new \LogicException('Frozen validation is read-only');
    }

    public function now(): \DateTimeImmutable
    {
        throw new \LogicException('Frozen validation cannot read a clock');
    }

    public function bytes(int $length): string
    {
        throw new \LogicException('Frozen validation cannot generate identities');
    }
}
