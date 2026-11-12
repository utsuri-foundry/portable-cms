<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Compiler;

use Utsuri\PortableCms\Conventional\Content\FieldDefinition;
use Utsuri\PortableCms\Conventional\Content\ProfilePackage;
use Utsuri\PortableCms\Conventional\Content\RecordTypeDefinition;
use Utsuri\PortableCms\Conventional\JsonSchemaValidator;

final readonly class FrozenRelationship
{
    public function __construct(
        public string $fieldId,
        public string $kind,
        public string $targetRecordId,
        public int $position,
        public ?string $targetTypeId = null,
    ) {}
}

final readonly class FrozenRecord
{
    /**
     * @param array<string, mixed> $body
     * @param list<FrozenRelationship> $relationships
     */
    public function __construct(
        public string $id,
        public string $typeId,
        public string $workflowState,
        public string $revisionId,
        public int $revisionNumber,
        public array $body,
        public array $relationships,
    ) {}

    /** @return list<string> */
    public function targets(string $fieldId): array
    {
        $targets = [];
        foreach ($this->relationships as $relationship) {
            if ($relationship->fieldId === $fieldId) {
                $targets[$relationship->position] = $relationship->targetRecordId;
            }
        }
        ksort($targets, SORT_NUMERIC);
        return array_values($targets);
    }
}

final readonly class FrozenMedia
{
    /** @param list<\Utsuri\PortableCms\Conventional\Media\RasterImageWidth> $widths */
    public function __construct(
        public string $id,
        public ?string $ownerRecordId,
        public string $variant,
        public string $mime,
        public int $byteSize,
        public string $checksum,
        public ?int $width,
        public ?int $height,
        public ?\Utsuri\PortableCms\Conventional\Media\MediaText $text = null,
        public \Utsuri\PortableCms\Conventional\Media\FocalPoint $focalPoint = new \Utsuri\PortableCms\Conventional\Media\FocalPoint(),
        public array $widths = [],
        public ?int $durationMilliseconds = null,
        public ?bool $hasAudio = null,
    ) {
        if (($ownerRecordId === null && !str_starts_with($mime, 'image/')) || !array_is_list($widths) || count($widths) > 3 || ($widths !== [] && $mime !== 'image/webp')) {
            throw new \InvalidArgumentException('Frozen image widths are invalid');
        }
        if (($durationMilliseconds === null) !== ($hasAudio === null)
            || ($durationMilliseconds !== null && (!str_starts_with($mime, 'video/') || $durationMilliseconds < 1 || $durationMilliseconds > 7200000))) {
            throw new \InvalidArgumentException('Frozen video metadata is invalid');
        }
        $seen = [$variant => true];
        foreach ($widths as $item) {
            if (!$item instanceof \Utsuri\PortableCms\Conventional\Media\RasterImageWidth || isset($seen['display_' . $item->maximumWidth . '_webp'])) {
                throw new \InvalidArgumentException('Frozen image widths are invalid');
            }
            $seen['display_' . $item->maximumWidth . '_webp'] = true;
        }
    }

    /** @return list<self> */
    public function renditions(): array
    {
        return [$this, ...array_map(fn(\Utsuri\PortableCms\Conventional\Media\RasterImageWidth $width): self => new self($this->id, $this->ownerRecordId, 'display_' . $width->maximumWidth . '_webp', 'image/webp', $width->byteSize, $width->checksum, $width->width, $width->height, $this->text, $this->focalPoint), $this->widths)];
    }
}

final readonly class FrozenSnapshot
{
    /** @var array<string, FrozenRecord> */
    private array $recordIndex;

    /** @var array<string, FrozenMedia> */
    private array $mediaIndex;

    /**
     * @param list<string> $locales
     * @param list<string> $preparationLocales Languages drawn for review only.
     * @param list<FrozenRecord> $records
     * @param list<FrozenMedia> $media
     * @param array<string, FrozenRecord> $recordIndex
     * @param array<string, FrozenMedia> $mediaIndex
     */
    private function __construct(
        public string $id,
        public \DateTimeImmutable $createdAt,
        public string $profileId,
        public string $profileVersion,
        public string $profileChecksum,
        public string $defaultLocale,
        public array $locales,
        public array $records,
        public array $media,
        array $recordIndex,
        array $mediaIndex,
        public ?\Utsuri\PortableCms\Conventional\Design\RegistryDocument $designRegistries,
        public ?PublicUrlContext $publicUrlContext,
        public ?PublishedSlugHistory $publishedSlugHistory,
        public bool $includeDrafts,
        /** The fixed texts of the profile's pages in the snapshot's languages. */
        public SnapshotWording $wording,
        public ?string $liveRecordId = null,
        public array $preparationLocales = [],
        public ?\Utsuri\PortableCms\Conventional\Content\WebsiteIdentity $websiteIdentity = null,
    ) {
        $this->recordIndex = $recordIndex;
        $this->mediaIndex = $mediaIndex;
    }

    public static function load(string $path, string $schemaPath, ProfilePackage $package): self
    {
        $size = filesize($path);
        if (!is_int($size) || $size > \Utsuri\PortableCms\Conventional\Export\ExportCapacity::SNAPSHOT_BYTES) {
            throw new \InvalidArgumentException('Frozen snapshot exceeds its current-content byte budget');
        }
        $contents = file_get_contents($path);
        if (!is_string($contents)) {
            throw new \RuntimeException('Frozen snapshot could not be read');
        }
        JsonSchemaValidator::validateSnapshotJson($contents, (string) file_get_contents($schemaPath));
        $document = json_decode($contents, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($document)) {
            throw new \InvalidArgumentException('Frozen snapshot root is invalid');
        }
        return self::fromDocument($document, $package);
    }

    /** @param array<string, mixed> $document */
    public static function fromDocument(array $document, ProfilePackage $package): self
    {
        $includeDrafts = array_key_exists('includeDrafts', $document) ? $document['includeDrafts'] : false;
        if (!is_bool($includeDrafts)) {
            throw new \InvalidArgumentException('Frozen preview mode is invalid');
        }
        $profile = self::map($document['profile'] ?? null);
        $locales = self::strings($profile['locales'] ?? null);
        try {
            $languages = new SnapshotLanguages($locales, (string) ($profile['defaultLocale'] ?? ''), self::strings($document['preparationLocales'] ?? []));
            $languages->assertProfile($package->manifest);
        } catch (\InvalidArgumentException $error) {
            throw new \InvalidArgumentException('Frozen snapshot languages do not match the verified package', previous: $error);
        }
        if (
            ($profile['id'] ?? null) !== $package->manifest->id
            || ($profile['version'] ?? null) !== $package->manifest->version
            || ($profile['packageSha256'] ?? null) !== $package->checksum
        ) {
            throw new \InvalidArgumentException('Frozen snapshot profile does not match the verified package');
        }

        $records = [];
        $recordIndex = [];
        foreach (self::maps($document['records'] ?? null) as $value) {
            try {
                $record = self::hydrateRecord($value, $package, $languages);
            } catch (\InvalidArgumentException $error) {
                throw new ProfileBuildFailure(ProfileBuildReason::InvalidContent, (string) ($value['id'] ?? ''), (string) ($value['typeId'] ?? ''), previous: $error);
            }
            if (isset($recordIndex[$record->id])) {
                throw new \InvalidArgumentException('Frozen snapshot contains duplicate record IDs');
            }
            $records[] = $record;
            $recordIndex[$record->id] = $record;
        }
        self::relationships($records, $recordIndex, $package, $includeDrafts, $locales);

        $media = [];
        $mediaIndex = [];
        foreach (self::maps($document['media'] ?? null) as $value) {
            $item = self::hydrateMedia($value, $locales);
            if (isset($mediaIndex[$item->id]) || ($item->ownerRecordId !== null && !isset($recordIndex[$item->ownerRecordId]))) {
                throw new \InvalidArgumentException('Frozen snapshot media graph is invalid');
            }
            $media[] = $item;
            $mediaIndex[$item->id] = $item;
        }

        $design = isset($document['designRegistries']) ? \Utsuri\PortableCms\Conventional\Design\RegistryDocument::fromDocument($document['designRegistries']) : $package->manifest->designRegistries;
        if ($design !== null && ($package->manifest->designRegistries === null || $design->profileId() !== $package->manifest->id)) {
            throw new \InvalidArgumentException('Frozen design profile is unavailable or differs');
        }
        FrozenBlockValidation::check($records, $mediaIndex, $package->manifest, $languages, $design, $includeDrafts);
        foreach ($records as $record) {
            $type = $package->manifest->type($record->typeId);
            \Utsuri\PortableCms\Conventional\Content\VimeoVideo::assertFields($type->fields, $record->body, $locales, static fn(string $id): ?string => $mediaIndex[$id]->mime ?? null);
            \Utsuri\PortableCms\Conventional\Content\VideoReference::assertGraph($type->fields, $record->body, $locales, static fn(string $id): ?string => $mediaIndex[$id]->mime ?? null, static fn(string $id): ?int => $mediaIndex[$id]->byteSize ?? null, static fn(string $id): array => isset($mediaIndex[$id]) ? ['width' => $mediaIndex[$id]->width, 'height' => $mediaIndex[$id]->height, 'durationMilliseconds' => $mediaIndex[$id]->durationMilliseconds, 'hasAudio' => $mediaIndex[$id]->hasAudio] : []);
            foreach ($type->fields as $field) {
                if ($field->type !== 'composition' || ($record->body[$field->id] ?? null) === null) {
                    continue;
                }
                if ($field->localized && (!is_array($record->body[$field->id]) || array_diff(array_keys($record->body[$field->id]), $locales) !== [])) {
                    throw new \InvalidArgumentException('Frozen composition locales are invalid');
                }
                foreach ($field->localized ? $locales : [''] as $locale) {
                    $raw = $field->localized ? ($record->body[$field->id][$locale] ?? null) : $record->body[$field->id];
                    $layout = \Utsuri\PortableCms\Conventional\Content\Composition::from($raw, $field, $type, $design, $locales);
                    $required = $record->workflowState === 'published' ? $field->requiredOnPublish : $field->requiredOnDraft;
                    if ($required && $layout->document()['rows'] === []) {
                        throw new \InvalidArgumentException('Frozen composition rows are required');
                    }
                    foreach ($layout->mediaIds() as $id) {
                        if (!isset($mediaIndex[$id]) || !str_starts_with($mediaIndex[$id]->mime, 'image/')) {
                            throw new \InvalidArgumentException('Frozen composition references unavailable image media');
                        }
                    }
                    foreach ($layout->links() as $link) {
                        $target = $recordIndex[$link->recordId] ?? null;
                        if ($target === null || $target->typeId !== $link->typeId
                            || (($record->workflowState === 'published' || ($includeDrafts && $record->workflowState === 'draft'))
                                && $target->workflowState !== 'published' && !($includeDrafts && $target->workflowState === 'draft'))) {
                            throw new \InvalidArgumentException('Frozen composition link target is missing or unpublished');
                        }
                    }
                }
            }
        }
        $createdAt = new \DateTimeImmutable((string) ($document['createdAt'] ?? ''));
        $context = isset($document['publicUrlContext']) ? PublicUrlContext::from($document['publicUrlContext']) : null;
        $history = isset($document['publishedSlugHistory']) ? PublishedSlugHistory::from($document['publishedSlugHistory']) : null;
        $history?->assertScope($context, $package->manifest->id);
        $identity = isset($document['websiteIdentity']) ? \Utsuri\PortableCms\Conventional\Content\WebsiteIdentity::from($document['websiteIdentity']) : null;
        if ($identity?->iconMediaId !== null) {
            $icon = $mediaIndex[$identity->iconMediaId] ?? null;
            if ($icon === null || !\Utsuri\PortableCms\Conventional\Content\WebsiteIconSelection::eligible('ready', $icon->mime, $icon->width ?? 0, $icon->height ?? 0)) {
                throw new \InvalidArgumentException('Frozen website icon is unavailable or ineligible');
            }
        }
        return new self(
            (string) ($document['snapshotId'] ?? ''),
            $createdAt,
            (string) $profile['id'],
            (string) $profile['version'],
            (string) $profile['packageSha256'],
            (string) $profile['defaultLocale'],
            $locales,
            $records,
            $media,
            $recordIndex,
            $mediaIndex,
            $design,
            $context,
            $history,
            $includeDrafts,
            SnapshotWording::from($document['wording'] ?? null, $package->manifest->carrying($locales), $locales),
            null,
            $languages->preparation,
            $identity,
        );
    }

    public function record(string $id): ?FrozenRecord
    {
        return $this->recordIndex[$id] ?? null;
    }

    public function media(string $id): ?FrozenMedia
    {
        return $this->mediaIndex[$id] ?? null;
    }

    /** @return list<FrozenRecord> */
    public function recordsOfType(string $typeId, bool $publishedOnly = true): array
    {
        return array_values(array_filter(
            $this->records,
            fn(FrozenRecord $record): bool => $record->typeId === $typeId
                && (!$publishedOnly || $this->includes($record)),
        ));
    }

    /**
     * The same snapshot drawn for the live preview of one record, whose pages may carry field
     * marks. Documents never record it, so publication and exact previews cannot carry it.
     */
    public function live(string $recordId): self
    {
        if (!isset($this->recordIndex[$recordId])) {
            throw new \InvalidArgumentException('Live preview record is not in the snapshot');
        }
        return new self($this->id, $this->createdAt, $this->profileId, $this->profileVersion, $this->profileChecksum, $this->defaultLocale, $this->locales, $this->records, $this->media, $this->recordIndex, $this->mediaIndex, $this->designRegistries, $this->publicUrlContext, $this->publishedSlugHistory, $this->includeDrafts, $this->wording, $recordId, $this->preparationLocales, $this->websiteIdentity);
    }

    /** One render-selection policy, used by profiles without rewriting saved workflow states. */
    public function includes(FrozenRecord $record): bool
    {
        return $record->workflowState === 'published' || ($this->includeDrafts && $record->workflowState === 'draft');
    }

    /** @param array<string, mixed> $value */
    private static function hydrateRecord(array $value, ProfilePackage $package, SnapshotLanguages $languages): FrozenRecord
    {
        $typeId = (string) ($value['typeId'] ?? '');
        $type = $package->manifest->type($typeId);
        if ($type === null) {
            throw new \InvalidArgumentException('Frozen snapshot record type is not installed');
        }
        $body = self::map($value['body'] ?? null);
        if ($languages->record($type, $body) !== $body) {
            throw new \InvalidArgumentException('Frozen record carries values in languages the snapshot does not draw');
        }
        $locales = $languages->locales;
        \Utsuri\PortableCms\Conventional\Content\WebAddress::assertFields($type->fields, $body, $locales, ($value['workflowState'] ?? '') === 'published');
        foreach ($type->fields as $field) {
            if ($field->type !== 'rich_text') {
                continue;
            }
            $required = ($value['workflowState'] ?? '') === 'published' ? $field->requiredOnPublish : $field->requiredOnDraft;
            $raw = $body[$field->id] ?? null;
            if ($raw === null) {
                if ($required) {
                    throw new \InvalidArgumentException('Frozen rich text is required');
                }
                continue;
            }
            if ($field->localized && (!is_array($raw) || array_diff(array_keys($raw), $locales) !== [])) {
                throw new \InvalidArgumentException('Frozen rich text locales are invalid');
            }
            foreach ($field->localized ? $locales : [''] as $locale) {
                $document = $field->localized ? ($raw[$locale] ?? null) : $raw;
                if ($document === null && !$required) {
                    continue;
                }
                $rich = \Utsuri\PortableCms\Conventional\Content\RichText::from($document, $field);
                if ($required && !$rich->hasText()) {
                    throw new \InvalidArgumentException('Frozen rich text is required');
                }
            }
        }
        $relationships = [];
        foreach (self::maps($value['relationships'] ?? null) as $item) {
            $relationships[] = new FrozenRelationship(
                (string) ($item['fieldId'] ?? ''),
                (string) ($item['kind'] ?? ''),
                (string) ($item['targetRecordId'] ?? ''),
                (int) ($item['position'] ?? -1),
                isset($item['targetTypeId']) ? (string) $item['targetTypeId'] : null,
            );
        }
        return new FrozenRecord(
            (string) ($value['id'] ?? ''),
            $typeId,
            (string) ($value['workflowState'] ?? ''),
            (string) ($value['revisionId'] ?? ''),
            (int) ($value['revisionNumber'] ?? 0),
            self::map($value['body'] ?? null),
            $relationships,
        );
    }

    /**
     * @param list<FrozenRecord> $records
     * @param array<string, FrozenRecord> $recordIndex
     * @param list<string> $locales
     */
    private static function relationships(array $records, array $recordIndex, ProfilePackage $package, bool $includeDrafts, array $locales): void
    {
        $failures = [];
        foreach ($records as $record) {
            $sourceType = $package->manifest->type($record->typeId);
            if (!$sourceType instanceof RecordTypeDefinition) {
                throw new \InvalidArgumentException('Frozen snapshot source type is unavailable');
            }
            $positions = [];
            $typed = \Utsuri\PortableCms\Conventional\Content\RecordReference::fields($sourceType->fields, $record->body, $locales, $record->workflowState === 'published');
            foreach ($record->relationships as $relationship) {
                $field = self::field($sourceType, $relationship->fieldId)
                    ?? \Utsuri\PortableCms\Conventional\Content\EditorialControls::nestedDefinition($sourceType, $package->manifest->carrying($locales), $record->body, $relationship->fieldId);
                $target = $recordIndex[$relationship->targetRecordId] ?? null;
                $expectedKind = $field?->type === 'taxonomy_references' ? 'taxonomy' : 'record';
                if (
                    !$field instanceof FieldDefinition
                    || !in_array($field->type, ['record_reference', 'record_references', 'taxonomy_references'], true)
                    || !$target instanceof FrozenRecord
                    || !in_array($target->typeId, $field->targetTypes, true)
                    || ($relationship->targetTypeId !== null && $relationship->targetTypeId !== $target->typeId)
                    || ($field->typedReferences() && ($relationship->targetTypeId !== $target->typeId
                        || ($typed[$relationship->fieldId][$relationship->position] ?? null)?->recordId !== $target->id
                        || $typed[$relationship->fieldId][$relationship->position]->typeId !== $target->typeId
                        || ($record->workflowState === 'published' && $target->workflowState !== 'published' && !($includeDrafts && $target->workflowState === 'draft'))))
                    || $relationship->kind !== $expectedKind
                    || isset($positions[$relationship->fieldId][$relationship->position])
                ) {
                    $failures[] = new ProfileBuildFailure(ProfileBuildReason::Relationship, $record->id, $record->typeId, fieldId: $relationship->fieldId);
                    continue;
                }
                $positions[$relationship->fieldId][$relationship->position] = true;
            }
            foreach ($typed as $id => $references) {
                if (count($references) !== count($positions[$id] ?? [])) {
                    $failures[] = new ProfileBuildFailure(ProfileBuildReason::Relationship, $record->id, $record->typeId, fieldId: $id);
                }
            }
            foreach ($positions as $fieldPositions) {
                $actual = array_keys($fieldPositions);
                sort($actual, SORT_NUMERIC);
                if ($actual !== range(0, count($actual) - 1)) {
                    $failures[] = new ProfileBuildFailure(ProfileBuildReason::Relationship, $record->id, $record->typeId);
                }
            }
        }
        if ($failures !== []) {
            throw new ProfileBuildFailures(array_slice($failures, 0, 100));
        }
    }

    private static function field(RecordTypeDefinition $type, string $fieldId): ?FieldDefinition
    {
        foreach ($type->fields as $field) {
            if ($field->id === $fieldId) {
                return $field;
            }
        }
        return null;
    }

    /** @param array<string, mixed> $value
     * @param list<string> $locales
     */
    private static function hydrateMedia(array $value, array $locales): FrozenMedia
    {
        if ((array_key_exists('durationMilliseconds', $value) && !is_int($value['durationMilliseconds']))
            || (array_key_exists('hasAudio', $value) && !is_bool($value['hasAudio']))) {
            throw new \InvalidArgumentException('Frozen video metadata is invalid');
        }
        return new FrozenMedia(
            (string) ($value['id'] ?? ''),
            array_key_exists('ownerRecordId', $value) && $value['ownerRecordId'] === null ? null : (string) ($value['ownerRecordId'] ?? ''),
            (string) ($value['variant'] ?? ''),
            (string) ($value['mime'] ?? ''),
            (int) ($value['byteSize'] ?? 0),
            (string) ($value['sha256'] ?? ''),
            is_int($value['width'] ?? null) ? $value['width'] : null,
            is_int($value['height'] ?? null) ? $value['height'] : null,
            \Utsuri\PortableCms\Conventional\Media\MediaText::from($value['text'] ?? null, $locales),
            \Utsuri\PortableCms\Conventional\Media\FocalPoint::from($value['focalPoint'] ?? null),
            \Utsuri\PortableCms\Conventional\Media\RasterImageWidth::fromDocument($value['widths'] ?? []),
            $value['durationMilliseconds'] ?? null,
            $value['hasAudio'] ?? null,
        );
    }

    /** @return array<string, mixed> */
    private static function map(mixed $value): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new \InvalidArgumentException('Frozen snapshot object is invalid');
        }
        return $value;
    }

    /** @return list<array<string, mixed>> */
    private static function maps(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new \InvalidArgumentException('Frozen snapshot collection is invalid');
        }
        $maps = [];
        foreach ($value as $item) {
            $maps[] = self::map($item);
        }
        return $maps;
    }

    /** @return list<string> */
    private static function strings(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new \InvalidArgumentException('Frozen snapshot string collection is invalid');
        }
        foreach ($value as $item) {
            if (!is_string($item)) {
                throw new \InvalidArgumentException('Frozen snapshot string collection is invalid');
            }
        }
        return $value;
    }
}
