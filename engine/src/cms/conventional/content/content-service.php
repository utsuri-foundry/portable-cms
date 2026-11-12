<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Content;

use Utsuri\PortableCms\Conventional\I18n\InvalidInput;
use Utsuri\PortableCms\Conventional\I18n\Message;
use Utsuri\PortableCms\Conventional\Identity\Clock;
use Utsuri\PortableCms\Conventional\Identity\RandomSource;

final readonly class ValidationFailure
{
    /**
     * @param array<string, mixed> $values
     * @param array<string, \Utsuri\PortableCms\Conventional\I18n\Message> $errors
     */
    public function __construct(public array $values, public array $errors) {}
}

final readonly class ValidatedContent
{
    /**
     * @param array<string, mixed> $body
     * @param list<RelationshipProjection> $relationships
     */
    public function __construct(public array $body, public array $relationships) {}
}

final class CanonicalContentRejected extends \InvalidArgumentException
{
    /** @param list<array{field: int|string, reason: string}> $issues */
    public function __construct(public readonly array $issues, string $message)
    {
        parent::__construct($message);
    }
}

final class ContentService
{
    public function __construct(
        private readonly RecordRepository $repository,
        private readonly ProfileManifest $profile,
        private readonly Clock $clock,
        private readonly RandomSource $random,
        private readonly ?\Utsuri\PortableCms\Conventional\Media\MediaLibrary $media = null,
        private readonly ?\Utsuri\PortableCms\Conventional\Design\RegistryService $design = null,
        /** Returns the site's public languages; requirements that apply in every language apply to these. */
        private readonly ?\Closure $publicLocales = null,
        /** @var null|\Closure(): WebsiteIdentity */
        private readonly ?\Closure $identity = null,
    ) {}

    public function websiteIdentity(): WebsiteIdentity
    {
        return $this->identity === null ? WebsiteIdentity::defaults($this->profile->id) : ($this->identity)();
    }

    /** @var list<string>|null */
    private ?array $required = null;

    /** @return list<string> The languages whose text requirements are enforced: the public ones. */
    public function requiredLocales(): array
    {
        return $this->required ??= $this->publicLocales === null ? $this->profile->locales
            : array_values(array_intersect($this->profile->locales, ($this->publicLocales)()));
    }

    public function workflow(): RecordWorkflowService
    {
        return new RecordWorkflowService($this->repository, $this, $this->profile, $this->clock, $this->random, $this->media, $this->design);
    }

    /** @return list<RecordSnapshot> */
    public function list(string $typeId): array
    {
        $this->requiredType($typeId);
        return $this->repository->list($typeId, 100);
    }

    /** @return array<string, int> Totals for profile types only; empty when the store has no summary projection. */
    public function counts(): array
    {
        if (!$this->repository instanceof RecordActivity) {
            return [];
        }
        return array_intersect_key($this->repository->counts(), array_fill_keys(array_map(static fn(RecordTypeDefinition $type): string => $type->id, $this->profile->types), true));
    }

    /** @return list<RecordSnapshot> */
    public function recent(int $limit): array
    {
        if (!$this->repository instanceof RecordActivity) {
            return [];
        }
        return array_values(array_filter($this->repository->recent(max(1, min(20, $limit))), fn(RecordSnapshot $record): bool => $this->profile->type($record->typeId) !== null));
    }

    /**
     * @param list<string> $recordIds
     * @return array<string, int>
     */
    public function incomingReferences(array $recordIds): array
    {
        return $this->repository instanceof RecordActivity ? $this->repository->incomingReferences($recordIds) : [];
    }

    public function search(string $typeId, string $query, int $page, string $state = ''): RecordPage
    {
        $this->requiredType($typeId);
        if (!$this->repository instanceof RecordBrowser) {
            throw new \LogicException('The record browser is unavailable');
        }
        return $this->repository->search($typeId, mb_substr(trim($query), 0, 100), max(1, min(1000, $page)), $state);
    }

    public function pendingPublicationCount(): int
    {
        return $this->repository instanceof PublishedRecordRepository ? $this->repository->pendingPublicationCount() : 0;
    }

    /** @return array<string, int> */
    public function stateCounts(string $typeId): array
    {
        $this->requiredType($typeId);
        if ($this->repository instanceof RecordWorkflowRepository) {
            return $this->repository->stateCounts($typeId);
        }
        $states = array_fill_keys(['draft', 'published', 'archived'], 0);
        foreach ($this->list($typeId) as $record) {
            ++$states[$record->workflowState];
        }
        return $states;
    }

    public function searchReferences(FieldDefinition $field, string $query, int $page, string $filter = ''): RecordPage
    {
        if ($filter !== '' && !in_array($filter, $field->targetTypes, true)) {
            throw new \InvalidArgumentException('Reference filter is unavailable');
        }
        if (!$this->repository instanceof RecordBrowser) {
            throw new \LogicException('The record browser is unavailable');
        }
        $types = $filter === '' ? $field->targetTypes : [$filter];
        foreach ($types as $typeId) {
            $this->requiredType($typeId);
        }
        return $this->repository->search($types, mb_substr(trim($query), 0, 100), max(1, min(1000, $page)));
    }

    public function find(string $typeId, string $recordId): ?RecordSnapshot
    {
        $this->requiredType($typeId);
        if (!self::validId($recordId)) {
            return null;
        }
        return $this->repository->find($typeId, $recordId);
    }

    /** @param array<string, mixed> $input */
    public function create(string $typeId, array $input, string $actorUserId): RecordSnapshot|ValidationFailure
    {
        $type = $this->requiredType($typeId);
        $validated = $this->validate($type, $input);
        if ($validated instanceof ValidationFailure) {
            return $validated;
        }
        return $this->repository->create(
            $this->randomId(),
            $this->randomId(),
            $typeId,
            $this->profile->id,
            $this->profile->version,
            $validated->body,
            $validated->relationships,
            $actorUserId,
            $this->randomId(),
            $this->clock->now(),
        );
    }

    /** @param array<string, mixed> $input */
    public function update(
        string $typeId,
        string $recordId,
        int $expectedRevisionNumber,
        array $input,
        string $actorUserId,
        ?int $expectedEditSequence = null,
    ): RecordSnapshot|RecordConflict|ValidationFailure {
        $type = $this->requiredType($typeId);
        if (!self::validId($recordId) || $expectedRevisionNumber < 1) {
            throw new \InvalidArgumentException('Record identity is invalid');
        }
        $current = $this->repository->find($typeId, $recordId);
        if ($current === null) {
            throw new RecordNotFound();
        }
        if ($current->revisionNumber !== $expectedRevisionNumber || ($expectedEditSequence !== null && $current->editSequence !== $expectedEditSequence)) {
            return new RecordConflict($current);
        }
        $validated = $this->validate($type, $input, $current->body);
        if ($validated instanceof ValidationFailure) {
            return $validated;
        }
        if ($expectedEditSequence !== null && $this->repository instanceof EditingRecordRepository) {
            return $this->repository->keep($typeId, $recordId, $current, new RecordEditIntent($expectedEditSequence), $this->randomId(), $this->profile->id, $this->profile->version, $validated->body, $validated->relationships, $actorUserId, $this->randomId(), $this->clock->now());
        }
        return $this->repository->update(
            $typeId,
            $recordId,
            $expectedRevisionNumber,
            $this->randomId(),
            $this->profile->id,
            $this->profile->version,
            $validated->body,
            $validated->relationships,
            $actorUserId,
            $this->randomId(),
            $this->clock->now(),
        );
    }

    /** Keep every valid field; rejected values remain solely in the editor.
     * @param array<string, mixed> $input
     */
    public function keep(string $typeId, string $recordId, array $input, string $actorId, RecordEditIntent $intent): KeptRecord|RecordConflict
    {
        if (!self::validId($recordId) || !$this->repository instanceof EditingRecordRepository) {
            throw new \InvalidArgumentException('Record keeping is unavailable');
        }
        $type = $this->requiredType($typeId);
        $current = $this->repository->find($typeId, $recordId);
        if ($intent->sessionHash !== null) {
            // Bind retries to submitted values, not a readiness-dependent validation result.
            $intent = new RecordEditIntent($intent->sequence, $intent->sessionHash, substr(hash('sha256', $intent->requestId . ':' . json_encode(self::canonicalComparison($input), JSON_THROW_ON_ERROR)), 0, 32));
        }
        $body = $relationships = $errors = [];
        $retryable = false;
        foreach ($type->fields as $original) {
            $field = EditingFields::incomplete($original);
            $value = $this->validateFields([$field], $input, $current->body ?? [], $type);
            if ($value instanceof ValidationFailure) {
                $errors += $value->errors;
                if ($this->preparingMedia($field, $input[$field->id] ?? null, $recordId)) {
                    $errors[$field->id] = new Message('validation.media_preparing', ['field' => $field->label]);
                    $retryable = true;
                }
                $fallback = self::canonicalInput($type, $current->body ?? []);
                $value = $this->validateFields([$field], $fallback, $current->body ?? [], $type, canonical: true);
                if ($value instanceof ValidationFailure) {
                    throw new \LogicException('Stored field cannot be retained');
                }
            }
            $body += $value->body;
            array_push($relationships, ...$value->relationships);
        }
        $result = $this->repository->keep($typeId, $recordId, $current, $intent, $this->randomId(), $this->profile->id, $this->profile->version, $body, $relationships, $actorId, $this->randomId(), $this->clock->now());
        if ($result instanceof RecordSnapshot && $intent->sessionHash !== null && self::canonicalComparison($result->body) !== self::canonicalComparison($body)) {
            // Recover an acknowledged partial save whose reply was lost. New readiness may
            // admit the remaining values; the fresh sequence still excludes intervening edits.
            $resume = new RecordEditIntent($result->editSequence, $intent->sessionHash, $intent->requestId, true);
            $result = $this->repository->keep($typeId, $recordId, $result, $resume, $this->randomId(), $this->profile->id, $this->profile->version, $body, $relationships, $actorId, $this->randomId(), $this->clock->now());
        }
        return $result instanceof RecordConflict ? $result : new KeptRecord($result, $errors, $retryable);
    }

    private function preparingMedia(FieldDefinition $field, mixed $input, string $recordId): bool
    {
        if (!in_array($field->type, ['media_references', 'video_reference'], true)) {
            return false;
        }
        try {
            $value = $field->type === 'media_references' ? $this->structured($field, $input)
                : (is_string($input) ? EditorialControls::structured($input) : $input);
            $ids = $field->type === 'media_references' && is_array($value) ? $value
                : ($field->type === 'video_reference' && !$field->localized ? array_values(VideoReference::from($value) ?? []) : []);
            foreach ($ids as $id) {
                $media = is_string($id) ? $this->media?->find($id) : null;
                if ($media?->ownerRecordId === $recordId && in_array($media->status, ['uploaded', 'processing'], true)) {
                    return true;
                }
            }
        } catch (\InvalidArgumentException) {
            // Malformed values remain field errors, not retryable preparation.
        }
        return false;
    }

    /**
     * Writes a new revision of a stored body changed by installation maintenance, as validation
     * normalizes it: a value left out is stored empty, as when an editor clears it. The record keeps
     * its workflow state.
     * @param array<string, mixed> $body
     */
    public function rewrite(string $typeId, string $recordId, int $expectedRevisionNumber, array $body, string $actorUserId, ?int $expectedEditSequence = null): RecordSnapshot|RecordConflict
    {
        $type = $this->requiredType($typeId);
        $validated = $this->validateFields(array_map(EditingFields::incomplete(...), $type->fields), self::canonicalInput($type, $body), $body, $type, null, true);
        if ($validated instanceof ValidationFailure) {
            throw new \InvalidArgumentException('Rewritten body fails installed profile validation: ' . implode(', ', array_keys($validated->errors)));
        }
        if ($expectedEditSequence !== null && $this->repository instanceof EditingRecordRepository) {
            $current = $this->repository->find($typeId, $recordId) ?? throw new RecordNotFound();
            if ($current->revisionNumber !== $expectedRevisionNumber || $current->editSequence !== $expectedEditSequence) {
                return new RecordConflict($current);
            }
            return $this->repository->keep($typeId, $recordId, $current, new RecordEditIntent($expectedEditSequence), $this->randomId(), $this->profile->id, $this->profile->version, $validated->body, $validated->relationships, $actorUserId, $this->randomId(), $this->clock->now());
        }
        return $this->repository->update(
            $typeId,
            $recordId,
            $expectedRevisionNumber,
            $this->randomId(),
            $this->profile->id,
            $this->profile->version,
            $validated->body,
            $validated->relationships,
            $actorUserId,
            $this->randomId(),
            $this->clock->now(),
        );
    }

    /**
     * Validates unsaved form values exactly as Save does, without writing, for the live preview.
     *
     * @param array<string, mixed> $input
     */
    public function unsaved(string $typeId, array $input, ?RecordSnapshot $saved = null): ValidatedContent|ValidationFailure
    {
        return $this->validate($this->requiredType($typeId), $input, $saved->body ?? []);
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $saved
     */
    private function validate(RecordTypeDefinition $type, array $input, array $saved = []): ValidatedContent|ValidationFailure
    {
        return $this->validateFields($type->fields, $input, $saved, $type);
    }

    /**
     * Validate an immutable canonical body without writing or regenerating IDs.
     * Missing optional fields retain their legacy absence; supplied values cannot be
     * silently coerced, dropped or rewritten. The repository supplies graph lookups.
     * @param array<string, mixed> $body
     */
    public function validateCanonical(string $typeId, array $body, ?\Utsuri\PortableCms\Conventional\Design\RegistryDocument $design = null): ValidatedContent
    {
        return $this->validateCanonicalType($this->requiredType($typeId), $body, $design);
    }

    /** Validate only the reviewed public projection, without reinterpreting excluded private values.
     * @param array<string, mixed> $body
     */
    public function validateFeedRecord(string $typeId, array $body): ValidatedContent
    {
        $names = $this->profile->contentFeed?->types[$typeId] ?? throw new \InvalidArgumentException('Type is not declared for public content feeds');
        $type = $this->requiredType($typeId);
        $fields = array_values(array_filter($type->fields, static fn(FieldDefinition $field): bool => in_array($field->id, $names, true)));
        $projection = new RecordTypeDefinition($type->id, $type->label, $type->singularLabel, $fields, $type->kind, $type->archetype, $type->renderer, $type->route);
        return $this->validateCanonicalType($projection, array_intersect_key($body, array_fill_keys($names, true)));
    }

    /** @param array<string, mixed> $body */
    private function validateCanonicalType(RecordTypeDefinition $type, array $body, ?\Utsuri\PortableCms\Conventional\Design\RegistryDocument $design = null): ValidatedContent
    {
        $unsupported = array_diff(array_keys($body), array_map(static fn(FieldDefinition $field): string => $field->id, $type->fields));
        $issues = array_values(array_map(static fn($field): array => ['field' => $field, 'reason' => 'unsupported_field'], $unsupported));
        $input = self::canonicalInput($type, $body);
        $validated = $this->validateFields(array_map(EditingFields::incomplete(...), $type->fields), $input, $body, $type, $design, true);
        $errors = $validated instanceof ValidationFailure ? $validated->errors : [];
        $normalized = $validated instanceof ValidationFailure ? $validated->values : $validated->body;
        if ($validated instanceof ValidationFailure) {
            $issues = [...$issues, ...array_map(static fn(string $field): array => ['field' => $field, 'reason' => 'invalid_field_value'], array_keys($errors))];
        }
        foreach ($body as $key => $value) {
            if (in_array($key, $unsupported, true) || isset($errors[$key])) {
                continue;
            }
            $field = array_values(array_filter($type->fields, static fn(FieldDefinition $field): bool => $field->id === $key))[0];
            $expected = $field->type === 'rich_text' && !$field->required ? $input[$key] : $value;
            if (!array_key_exists($key, $normalized) || self::canonicalComparison($expected) !== self::canonicalComparison(self::unwritten($normalized[$key], $expected, $this->profile->addedLocales))) {
                $issues[] = ['field' => $key, 'reason' => 'lossy_field_mapping'];
            }
        }
        if ($validated instanceof ValidationFailure || $issues !== []) {
            throw new CanonicalContentRejected($issues, 'Canonical body is invalid or would require a lossy mapping');
        }
        return new ValidatedContent($body, $validated->relationships);
    }

    /**
     * A revision saved before a language was added has no value in it, which validation reads as an
     * empty one: such empty values are left out where the saved body has none.
     * @param list<string> $added
     */
    private static function unwritten(mixed $validated, mixed $saved, array $added): mixed
    {
        if ($added === [] || !is_array($validated) || !is_array($saved)) {
            return $validated;
        }
        // Group items pair by position and localized values by language.
        foreach ($validated as $key => $value) {
            if (!array_key_exists($key, $saved)) {
                if (in_array($key, $added, true) && !self::written($value)) {
                    unset($validated[$key]);
                }
                continue;
            }
            $validated[$key] = self::unwritten($value, $saved[$key], $added);
        }
        return $validated;
    }

    private static function written(mixed $value): bool
    {
        if (is_string($value)) {
            return trim($value) !== '';
        }
        foreach (is_array($value) ? $value : [] as $item) {
            if (self::written($item)) {
                return true;
            }
        }
        return false;
    }

    /**
     * A stored body as the form values validation reads.
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private static function canonicalInput(RecordTypeDefinition $type, array $body): array
    {
        $input = $body;
        foreach ($type->fields as $field) {
            $value = $body[$field->id] ?? null;
            if ($field->type === 'rich_text' && !$field->required) {
                if (!$field->localized && $value === null) {
                    $input[$field->id] = ['formatVersion' => 1, 'nodes' => []];
                } elseif ($field->localized && is_array($value)) {
                    $input[$field->id] = array_map(static fn($document) => $document ?? ['formatVersion' => 1, 'nodes' => []], $value);
                }
            }
            if (in_array($field->type, ['integer', 'decimal'], true)) {
                $input[$field->id] = $value ?? '';
            } elseif ($field->type === 'media_references' || ($field->type === 'composition' && $field->localized) || in_array($field->type, ['video_reference', 'web_address', 'vimeo_video'], true)) {
                $input[$field->id] = $value === null ? '' : json_encode($value, JSON_THROW_ON_ERROR);
            }
        }
        return $input;
    }

    /** Operator imports retain canonical values and use the ordinary revision/audit repository.
     * A null expected revision creates a new draft with the supplied stable identity.
     * @param array<string, mixed> $body
     */
    public function saveCanonical(string $typeId, string $recordId, ?int $expectedRevision, array $body, string $actorId, ?\Utsuri\PortableCms\Conventional\Design\RegistryDocument $design = null): RecordSnapshot|RecordConflict
    {
        if (!self::validId($recordId) || ($expectedRevision !== null && $expectedRevision < 1)) {
            throw new \InvalidArgumentException('Record identity is invalid');
        }
        $value = $this->validateCanonical($typeId, $body, $design);
        return $expectedRevision === null
            ? $this->repository->create($recordId, $this->randomId(), $typeId, $this->profile->id, $this->profile->version, $value->body, $value->relationships, $actorId, $this->randomId(), $this->clock->now())
            : $this->repository->update($typeId, $recordId, $expectedRevision, $this->randomId(), $this->profile->id, $this->profile->version, $value->body, $value->relationships, $actorId, $this->randomId(), $this->clock->now());
    }

    private static function canonicalComparison(mixed $value): mixed
    {
        // JSON does not distinguish 2 from 2.0; booleans and numeric strings stay distinct.
        if (is_float($value) && $value >= PHP_INT_MIN && $value < PHP_INT_MAX && floor($value) === $value) {
            return (int) $value;
        }
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        return array_map(self::canonicalComparison(...), $value);
    }

    /**
     * @param list<FieldDefinition> $fields
     * @param array<string, mixed> $input
     * @param array<string, mixed> $saved
     */
    private function validateFields(array $fields, array $input, array $saved = [], ?RecordTypeDefinition $type = null, ?\Utsuri\PortableCms\Conventional\Design\RegistryDocument $canonicalDesign = null, bool $canonical = false): ValidatedContent|ValidationFailure
    {
        $values = [];
        $errors = [];
        $relationships = [];
        foreach ($fields as $field) {
            if (in_array($field->type, ['short_text', 'multiline_text'], true) && !$field->localized) {
                $raw = $input[$field->id] ?? '';
                $value = is_string($raw) ? str_replace("\r\n", "\n", $raw) : '';
                $values[$field->id] = $value;
                $this->validateText($field, $value, $errors);
                continue;
            }
            if (
                $field->type === 'localized_short_text'
                || (in_array($field->type, ['short_text', 'multiline_text'], true) && $field->localized)
            ) {
                $raw = is_array($input[$field->id] ?? null) ? $input[$field->id] : [];
                $localized = [];
                foreach ($this->profile->locales as $locale) {
                    $value = is_string($raw[$locale] ?? null) ? (string) $raw[$locale] : '';
                    $localized[$locale] = str_replace("\r\n", "\n", $value);
                }
                $values[$field->id] = $localized;
                $missing = array_filter($localized, fn(string $value, string $locale): bool => trim($value) === '' && $this->enforced($locale), ARRAY_FILTER_USE_BOTH);
                $tooLong = array_filter(
                    $localized,
                    static fn(string $value): bool => mb_strlen($value) > (int) $field->maximumLength,
                );
                if ($field->required && $missing !== []) {
                    $errors[$field->id] = new Message('validation.required_every_language', ['field' => $field->label]);
                } elseif ($tooLong !== []) {
                    $errors[$field->id] = new Message('validation.too_long_each_language', ['field' => $field->label, 'maximum' => (string) $field->maximumLength]);
                }
                continue;
            }
            if (in_array($field->type, ['integer', 'decimal'], true)) {
                $raw = $input[$field->id] ?? '';
                $native = $canonical && (is_int($raw) || is_float($raw)) ? $raw : null;
                $text = is_string($raw) ? trim($raw) : ($native === null ? '' : (string) $native);
                $number = $this->number($field, $text, $native);
                $values[$field->id] = $number;
                if ($text === '' && $field->required) {
                    $errors[$field->id] = new Message('validation.required', ['field' => $field->label]);
                } elseif ($text !== '' && $number === null) {
                    $errors[$field->id] = new Message('validation.number', ['field' => $field->label]);
                }
                continue;
            }
            if ($field->type === 'boolean') {
                $values[$field->id] = ($input[$field->id] ?? false) === true;
                continue;
            }
            if ($field->type === 'date') {
                $raw = $input[$field->id] ?? '';
                $value = is_string($raw) ? trim($raw) : '';
                $values[$field->id] = $value;
                if ($value === '' && $field->required) {
                    $errors[$field->id] = new Message('validation.required', ['field' => $field->label]);
                } elseif ($value !== '' && !$this->date($value)) {
                    $errors[$field->id] = new Message('validation.date', ['field' => $field->label]);
                }
                continue;
            }
            if ($field->type === 'enum') {
                $raw = $input[$field->id] ?? '';
                $value = is_string($raw) ? $raw : '';
                $values[$field->id] = $value;
                $allowed = [];
                foreach ($field->settings['options'] ?? [] as $option) {
                    if (is_array($option) && is_string($option['value'] ?? null)) {
                        $allowed[] = $option['value'];
                    }
                }
                if ($value === '' && $field->required) {
                    $errors[$field->id] = new Message('validation.required', ['field' => $field->label]);
                } elseif ($value !== '' && !in_array($value, $allowed, true)) {
                    $errors[$field->id] = new Message('validation.selection_invalid', ['field' => $field->label]);
                }
                continue;
            }
            if ($field->typedReferences()) {
                $raw = $input[$field->id] ?? ($field->type === 'record_reference' ? null : []);
                $values[$field->id] = $raw;
                try {
                    $items = RecordReference::values($field, $raw);
                    foreach ($items as $position => $ref) {
                        if ($this->repository->find($ref->typeId, $ref->recordId) === null) {
                            throw new \InvalidArgumentException('Reference target is unavailable');
                        }
                        $relationships[] = new RelationshipProjection($field->id, 'record', $ref->recordId, $position);
                    }
                } catch (\InvalidArgumentException) {
                    $errors[$field->id] = new Message('validation.selection_contains_invalid', ['field' => $field->label]);
                }
                continue;
            }
            if ($field->type === 'record_reference') {
                $raw = $input[$field->id] ?? '';
                $value = is_string($raw) ? $raw : '';
                $values[$field->id] = $value;
                if ($value === '') {
                    if ($field->required) {
                        $errors[$field->id] = new Message('validation.required', ['field' => $field->label]);
                    }
                    continue;
                }
                if (!$this->validTarget($field, $value, $saved[$field->id] ?? null)) {
                    $errors[$field->id] = new Message('validation.selection_invalid', ['field' => $field->label]);
                    continue;
                }
                $relationships[] = new RelationshipProjection($field->id, 'record', $value, 0);
                continue;
            }
            if (in_array($field->type, ['record_references', 'taxonomy_references'], true)) {
                $raw = $input[$field->id] ?? [];
                $items = is_array($raw) ? array_values($raw) : [];
                $unique = [];
                foreach ($items as $item) {
                    if (is_string($item) && !isset($unique[$item])) {
                        $unique[$item] = true;
                    }
                }
                $values[$field->id] = array_keys($unique);
                if ($field->required && $unique === []) {
                    $errors[$field->id] = new Message('validation.selection_required', ['field' => $field->label]);
                    continue;
                }
                if (count($unique) > (int) $field->maximumItems) {
                    $errors[$field->id] = new Message('validation.selection_maximum', ['field' => $field->label, 'maximum' => (string) $field->maximumItems]);
                    continue;
                }
                foreach (array_keys($unique) as $position => $targetId) {
                    if (!$this->validTarget($field, $targetId, $saved[$field->id] ?? null)) {
                        $errors[$field->id] = new Message('validation.selection_contains_invalid', ['field' => $field->label]);
                        break;
                    }
                    $relationships[] = new RelationshipProjection(
                        $field->id,
                        $field->type === 'taxonomy_references' ? 'taxonomy' : 'record',
                        $targetId,
                        $position,
                    );
                }
                continue;
            }
            if ($field->type === 'blocks') {
                $raw = EditorialControls::structured($input[$field->id] ?? null);
                try {
                    if ($raw === null && ($input[$field->id] ?? '') !== '' && ($input[$field->id] ?? null) !== null) {
                        throw new \InvalidArgumentException('Block document is invalid');
                    }
                    $values[$field->id] = $this->validateBlocks($field, $raw, $relationships, $saved[$field->id] ?? null, $canonical, $type, $canonicalDesign);
                } catch (\InvalidArgumentException $error) {
                    $values[$field->id] = $raw;
                    $errors[$field->id] = new Message('validation.values_invalid', ['field' => $field->label]);
                    if ($error instanceof InvalidBlockValues) {
                        $errors += $error->errors;
                    }
                }
                continue;
            }
            if (in_array($field->type, ['group', 'repeatable_group'], true) && EditorialForm::supported($field)) {
                $group = EditorialControls::structured($input[$field->id] ?? null);
                $validated = $this->validateGroup($field, $group, $relationships, $saved[$field->id] ?? null, $canonical);
                $values[$field->id] = $validated;
                if ($validated === null && ($group !== null || $field->required)) {
                    $errors[$field->id] = new Message('validation.values_invalid', ['field' => $field->label]);
                }
                continue;
            }
            if ($field->type === 'composition' && !$field->localized && $type !== null) {
                $raw = EditorialControls::structured($input[$field->id] ?? null);
                $values[$field->id] = $raw;
                try {
                    $registry = $canonicalDesign ?? $this->design?->load() ?? $this->profile->designRegistries;
                    $composition = Composition::from($raw, $field, $type, $registry, $this->profile->locales);
                    try {
                        $existingIds = Composition::from($saved[$field->id] ?? null, $field, $type, $registry, $this->profile->locales)->mediaIds();
                    } catch (\InvalidArgumentException) {
                        // A removed design token must not prevent correcting an older layout.
                        $existingIds = [];
                    }
                    foreach ($composition->mediaIds() as $id) {
                        if (!in_array($id, $existingIds, true) && $this->media?->find($id)?->status !== 'ready') {
                            throw new InvalidInput('validation.layout.image_not_ready');
                        }
                    }
                    foreach ($composition->links() as $link) {
                        if ($this->find($link->typeId, $link->recordId) === null) {
                            throw new InvalidInput('composition.link_unavailable');
                        }
                    }
                    if ($field->required && $composition->document()['rows'] === []) {
                        throw new InvalidInput('validation.layout.row_required');
                    }
                    $values[$field->id] = $raw !== null && $raw === ($saved[$field->id] ?? null) ? $raw : $composition->compact();
                } catch (\InvalidArgumentException $error) {
                    $errors[$field->id] = new Message('validation.detail', ['field' => $field->label, 'detail' => Message::fromFailure($error)]);
                }
                continue;
            }
            if ($field->type === 'rich_text') {
                $raw = $input[$field->id] ?? null;
                if ($raw === null || $raw === '') {
                    $raw = $field->localized ? [] : ['formatVersion' => 1, 'nodes' => []];
                }
                $values[$field->id] = $raw;
                $context = null;
                try {
                    $localized = [];
                    if ($field->localized && (!is_array($raw) || array_diff(array_keys($raw), $this->profile->locales) !== [])) {
                        throw new InvalidInput('validation.rich.locales');
                    }
                    foreach ($field->localized ? $this->profile->locales : [''] as $locale) {
                        $context = $locale === '' ? null : $locale;
                        $document = $field->localized ? ($raw[$locale] ?? ['formatVersion' => 1, 'nodes' => []]) : $raw;
                        $rich = RichText::from($document, $field);
                        if ($field->required && !$rich->hasText() && $this->enforced($locale)) {
                            throw new InvalidInput('validation.rich.required');
                        }
                        $localized[$locale] = $rich->document();
                    }
                    $values[$field->id] = $field->localized ? $localized : $localized[''];
                } catch (\InvalidArgumentException $error) {
                    $errors[$field->id] = $context === null
                        ? new Message('validation.detail', ['field' => $field->label, 'detail' => Message::fromFailure($error)])
                        : new Message('validation.detail_locale', ['field' => $field->label, 'locale' => $context, 'detail' => Message::fromFailure($error)]);
                }
                continue;
            }
            if ($field->type === 'web_address') {
                $raw = $input[$field->id] ?? '';
                $value = $raw === '' ? null : EditorialControls::structured($raw);
                $values[$field->id] = $value;
                try {
                    if ($raw !== '' && $raw !== 'null' && $value === null) {
                        throw new InvalidInput('address.invalid', ['maximum' => $field->maximumLength ?? WebAddress::MAXIMUM_LENGTH]);
                    }
                    $values[$field->id] = WebAddress::field($value, $field, $this->profile->locales, $field->required, $this->requiredLocales());
                } catch (\InvalidArgumentException $error) {
                    $errors[$field->id] = new Message('validation.detail', ['field' => $field->label, 'detail' => Message::fromFailure($error)]);
                }
                continue;
            }
            if ($field->type === 'vimeo_video') {
                $raw = $input[$field->id] ?? '';
                $value = $raw === '' ? null : EditorialControls::structured($raw);
                $values[$field->id] = $value;
                try {
                    if ($raw !== '' && $raw !== 'null' && $value === null) {
                        throw new InvalidInput('vimeo.value_invalid');
                    }
                    $values[$field->id] = VimeoVideo::field($value, $field, $this->profile->locales, $field->required, $this->requiredLocales(), !$canonical);
                    foreach ($field->localized && is_array($values[$field->id]) ? $values[$field->id] : [$values[$field->id]] as $entry) {
                        if ($entry === null || $canonical) {
                            continue;
                        }
                        $poster = $this->media?->find(VimeoVideo::from($entry)->poster);
                        if ($poster?->status !== 'ready' || $poster->thumbnail?->mime !== 'image/webp' || !str_starts_with($poster->mime, 'image/')) {
                            throw new InvalidInput('vimeo.poster_invalid');
                        }
                    }
                } catch (\InvalidArgumentException $error) {
                    $errors[$field->id] = new Message('validation.detail', ['field' => $field->label, 'detail' => Message::fromFailure($error)]);
                }
                continue;
            }
            if ($field->type === 'video_reference') {
                $raw = $input[$field->id] ?? '';
                $value = $raw === '' ? null : EditorialControls::structured($raw);
                $values[$field->id] = $value;
                try {
                    if ($raw !== '' && $raw !== 'null' && $value === null) {
                        throw new InvalidInput('video.selection_invalid');
                    }
                    if ($field->localized && $value !== null && (!is_array($value) || array_diff(array_keys($value), $this->profile->locales) !== [])) {
                        throw new InvalidInput('video.selection_invalid');
                    }
                    foreach ($field->localized ? $this->profile->locales : [''] as $locale) {
                        $reference = VideoReference::from($field->localized && is_array($value) ? ($value[$locale] ?? null) : $value);
                        if ($field->required && $reference === null && $this->enforced($locale)) {
                            throw new InvalidInput('video.selection_invalid');
                        }
                        if ($reference !== null && !$canonical) {
                            if (!\Utsuri\PortableCms\Conventional\Media\MediaContract::profileVideoEnabled($this->profile)) {
                                throw new InvalidInput('video.unavailable');
                            }
                            $video = $this->media?->find($reference['mediaId']);
                            $poster = $this->media?->find($reference['posterId']);
                            if ($video?->status !== 'ready' || $video->thumbnail?->variant !== 'prepared-video' || !in_array($video->mime, ['video/mp4', 'video/webm'], true)
                                || $poster?->status !== 'ready' || $poster->thumbnail?->mime !== 'image/webp' || !str_starts_with($poster->mime, 'image/')) {
                                throw new InvalidInput('video.selection_invalid');
                            }
                            VideoLimits::assert($field, $video->byteSize, $this->media->videoFacts($video));
                        }
                    }
                } catch (\InvalidArgumentException $error) {
                    $errors[$field->id] = new Message('validation.detail', ['field' => $field->label, 'detail' => Message::fromFailure($error)]);
                }
                continue;
            }
            if (in_array($field->type, [
                'media_references',
                'video_reference',
                'group',
                'repeatable_group',
                'composition',
            ], true)) {
                $raw = $input[$field->id] ?? '';
                $structured = $this->structured($field, $raw);
                if ($field->type === 'media_references' && is_array($structured)) {
                    if (count(array_filter($structured, 'is_string')) !== count($structured)
                        || count(array_unique($structured)) !== count($structured)) {
                        $structured = null;
                    } else {
                        foreach ($structured as $mediaId) {
                            $existing = is_array($saved[$field->id] ?? null) && in_array($mediaId, $saved[$field->id], true);
                            $media = $this->media?->find($mediaId);
                            $icon = ($field->settings['purpose'] ?? null) === 'website-icon';
                            if (!self::validId($mediaId) || (!$existing && $media?->status !== 'ready')
                                || ($media !== null && !str_starts_with($media->mime, 'image/'))
                                || ($icon && !$canonical && ($media === null || !WebsiteIconSelection::eligible($media->status, $media->mime, $media->width, $media->height)))) {
                                $structured = null;
                                break;
                            }
                        }
                    }
                }
                $values[$field->id] = $structured;
                if (($raw === '' || $structured === []) && $field->required) {
                    $errors[$field->id] = new Message('validation.required', ['field' => $field->label]);
                } elseif ($raw !== '' && $structured === null) {
                    $errors[$field->id] = new Message('validation.structured_invalid', ['field' => $field->label]);
                }
            }
        }
        return $errors === []
            ? new ValidatedContent($values, $relationships)
            : new ValidationFailure($values, $errors);
    }

    /** @param array<string, Message> $errors */
    private function validateText(FieldDefinition $field, string $value, array &$errors): void
    {
        if (!mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value) === 1) {
            $errors[$field->id] = new Message('validation.plain_text', ['field' => $field->label]);
        } elseif ($field->required && trim($value) === '') {
            $errors[$field->id] = new Message('validation.required', ['field' => $field->label]);
        } elseif (mb_strlen($value) > (int) $field->maximumLength) {
            $errors[$field->id] = new Message('validation.too_long', ['field' => $field->label, 'maximum' => (string) $field->maximumLength]);
        }
    }

    private function validTarget(FieldDefinition $field, string $recordId, mixed $saved): bool
    {
        $target = self::validId($recordId) && $field->targetType !== null
            ? $this->repository->find($field->targetType, $recordId) : null;
        return $target !== null && (($target->body['deprecated'] ?? false) !== true
            || $saved === $recordId || (is_array($saved) && in_array($recordId, $saved, true)));
    }

    /** Revalidate frozen block values with the same child validation as an authored save.
     * @param array<string, mixed> $body
     * @return list<RelationshipProjection>
     */
    public function validateBlocksCanonical(RecordTypeDefinition $type, array $body, bool $published, ?\Utsuri\PortableCms\Conventional\Design\RegistryDocument $design = null): array
    {
        $relationships = [];
        foreach ($type->fields as $field) {
            if ($field->type !== 'blocks') {
                continue;
            }
            $raw = $body[$field->id] ?? null;
            $value = $this->validateBlocks($field, $raw, $relationships, $raw, true, $type, $design, $published);
            if (self::canonicalComparison($raw) !== self::canonicalComparison($value)) {
                throw new \InvalidArgumentException('Frozen blocks require a lossy mapping');
            }
        }
        return $relationships;
    }

    /** @param list<RelationshipProjection> $relationships */
    private function validateBlocks(FieldDefinition $field, mixed $raw, array &$relationships, mixed $saved, bool $canonical, ?RecordTypeDefinition $type, ?\Utsuri\PortableCms\Conventional\Design\RegistryDocument $design, bool $published = false): mixed
    {
        $result = $field->localized ? array_fill_keys($this->profile->locales, []) : [];
        $issues = [];
        $failed = false;
        foreach (Blocks::items($field, $raw, $this->profile->locales, $published, $this->requiredLocales()) as $entry) {
            $item = $entry['item'];
            $savedValues = [];
            $container = $field->localized && is_array($saved) ? ($saved[$entry['locale']] ?? []) : $saved;
            foreach (is_array($container) ? $container : [] as $candidate) {
                if (is_array($candidate) && ($candidate['_itemId'] ?? null) === $item['_itemId'] && ($candidate['typeId'] ?? null) === $item['typeId']) {
                    $savedValues = $candidate['values'];
                }
            }
            $blockType = $type === null ? null : new RecordTypeDefinition($type->id, $type->label, $type->singularLabel, $entry['field']->fields, $type->kind, $type->archetype, $type->renderer, $type->route);
            $values = $this->validateGroup($entry['field'], $item['values'], $relationships, $savedValues, $canonical, $blockType, $design, $issues);
            if ($values === null) {
                $failed = true;
                continue;
            }
            $item['values'] = $values;
            if ($field->localized) {
                $result[$entry['locale']][] = $item;
            } else {
                $result[] = $item;
            }
        }
        if ($failed) {
            throw new InvalidBlockValues($issues);
        }
        return $raw === null ? null : $result;
    }

    /** @param list<RelationshipProjection> $relationships
     * @param array<string, Message> $issues
     */
    private function validateGroup(FieldDefinition $field, mixed $group, array &$relationships, mixed $saved, bool $canonical = false, ?RecordTypeDefinition $type = null, ?\Utsuri\PortableCms\Conventional\Design\RegistryDocument $design = null, array &$issues = []): mixed
    {
        if ($group === null || $group === '') {
            return null;
        }
        if (!is_array($group)) {
            return null;
        }
        if ($field->localized && array_diff(array_keys($group), $this->profile->locales) !== []) {
            return null;
        }
        $result = [];
        foreach ($field->localized ? $this->profile->locales : [''] as $locale) {
            $container = $field->localized ? ($group[$locale] ?? []) : $group;
            if (!is_array($container)) {
                return null;
            }
            $items = $field->type === 'group' ? [$container] : $container;
            if (!array_is_list($items) || count($items) > ($field->maximumItems ?? 1)) {
                return null;
            }
            if ($field->required && $items === [] && $this->enforced($locale)) {
                return null;
            }
            $validatedItems = [];
            $identities = [];
            foreach ($items as $position => $item) {
                if (!is_array($item) || array_diff(array_keys($item), ['_itemId', ...array_map(static fn(FieldDefinition $child): string => $child->id, $field->fields)]) !== []) {
                    return null;
                }
                $identity = $item['_itemId'] ?? null;
                $legacy = $identity === null && $group === $saved;
                if ($field->type === 'repeatable_group' && !$legacy && (!is_string($identity) || !self::validId($identity) || isset($identities[$identity]))) {
                    return null;
                }
                if (is_string($identity)) {
                    $identities[$identity] = true;
                }
                $children = [];
                foreach ($field->fields as $child) {
                    $children[] = new FieldDefinition(
                        $child->id,
                        $child->label,
                        $locale !== '' && $child->type === 'localized_short_text' ? 'short_text' : $child->type,
                        $locale === '' && $child->localized,
                        $child->requiredOnDraft && $this->enforced($locale),
                        $child->requiredOnPublish && $this->enforced($locale),
                        $child->settings,
                        [],
                        $child->maximumLength,
                        $child->targetType,
                        $child->maximumItems,
                    );
                    if (!$canonical && in_array($child->type, ['integer', 'decimal'], true) && is_numeric($item[$child->id] ?? null)) {
                        $item[$child->id] = (string) $item[$child->id];
                    }
                    if (in_array($child->type, ['media_references', 'video_reference', 'web_address', 'vimeo_video'], true) && is_array($item[$child->id] ?? null)) {
                        $item[$child->id] = json_encode($item[$child->id], JSON_THROW_ON_ERROR);
                    }
                }
                $savedContainer = is_array($saved) ? ($field->localized ? ($saved[$locale] ?? []) : $saved) : [];
                $savedItem = $field->type === 'group' ? $savedContainer : [];
                if ($field->type === 'repeatable_group' && is_array($savedContainer)) {
                    foreach ($savedContainer as $savedPosition => $candidate) {
                        $savedIdentity = is_array($candidate) ? ($candidate['_itemId'] ?? EditorialControls::legacyIdentity($field->id . ($locale === '' ? '' : '_' . $locale), (int) $savedPosition, $candidate)) : null;
                        if (is_array($candidate) && (($identity !== null && $savedIdentity === $identity) || ($legacy && $savedPosition === $position))) {
                            $savedItem = $candidate;
                        }
                    }
                }
                $validated = $this->validateFields($children, $item, is_array($savedItem) ? $savedItem : [], $type, $design, $canonical);
                if ($validated instanceof ValidationFailure) {
                    foreach ($validated->errors as $childId => $message) {
                        $issues[EditorialControls::childName($field->id, $childId)] = $message;
                    }
                    return null;
                }
                $prefix = $field->id . ($locale === '' ? '' : '_' . $locale);
                if ($field->type === 'repeatable_group') {
                    $prefix .= '_i' . (is_string($identity) ? $identity : EditorialControls::legacyIdentity($prefix, (int) $position, $item));
                }
                foreach ($validated->relationships as $relationship) {
                    $relationships[] = new RelationshipProjection(EditorialControls::childName($prefix, $relationship->fieldId), $relationship->kind, $relationship->targetRecordId, $relationship->position);
                }
                $validatedItems[] = ($identity === null ? [] : ['_itemId' => $identity]) + $validated->body;
            }
            $result[$locale] = $field->type === 'group' ? ($validatedItems[0] ?? []) : $validatedItems;
        }
        return $field->localized ? $result : $result[''];
    }

    /** Text requirements apply to untranslated values and to public languages; languages in preparation and off never block. */
    private function enforced(string $locale): bool
    {
        return $locale === '' || in_array($locale, $this->requiredLocales(), true);
    }

    private function number(FieldDefinition $field, string $value, int|float|null $canonical = null): int|float|null
    {
        if ($value === '') {
            return null;
        }
        $number = null;
        if ($canonical !== null) {
            $number = $field->type === 'integer' ? (is_int($canonical) ? $canonical : null) : (float) $canonical;
            if (is_float($number) && !is_finite($number)) {
                $number = null;
            }
        } elseif ($field->type === 'integer' && preg_match('/\A-?[0-9]+\z/', $value) === 1) {
            $number = filter_var($value, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE);
        } elseif (
            $field->type === 'decimal'
            && preg_match('/\A-?(?:[0-9]+(?:\.[0-9]+)?|\.[0-9]+)\z/', $value) === 1
        ) {
            $number = (float) $value;
            if (!is_finite($number)) {
                $number = null;
            }
        }
        if (!is_int($number) && !is_float($number)) {
            return null;
        }
        $minimum = $field->settings['minimum'] ?? null;
        $maximum = $field->settings['maximum'] ?? null;
        if ($field->type === 'decimal') {
            $minimum = is_string($minimum) ? (float) $minimum : $minimum;
            $maximum = is_string($maximum) ? (float) $maximum : $maximum;
            $scale = $field->settings['scale'] ?? null;
            if (is_int($scale) && round((float) $number, $scale) !== (float) $number) {
                return null;
            }
        }
        if (
            ((is_int($minimum) || is_float($minimum)) && $number < $minimum)
            || ((is_int($maximum) || is_float($maximum)) && $number > $maximum)
        ) {
            return null;
        }
        return $number;
    }

    private function date(string $value): bool
    {
        if (preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2})\z/', $value, $match) !== 1) {
            return false;
        }
        return checkdate((int) $match[2], (int) $match[3], (int) $match[1]);
    }

    private function structured(FieldDefinition $field, mixed $value): mixed
    {
        if ($value === '' || $value === null) {
            return null;
        }
        if (!is_string($value) || strlen($value) > 1_048_576) {
            return null;
        }
        try {
            $decoded = json_decode($value, true, 16, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }
        $valid = match ($field->type) {
            'group', 'composition' => is_array($decoded) && !array_is_list($decoded),
            'media_references' => is_array($decoded) && array_is_list($decoded),
            'repeatable_group' => is_array($decoded)
                && ($field->localized ? !array_is_list($decoded) : array_is_list($decoded)),
            'video_reference' => is_array($decoded) && !array_is_list($decoded),
            default => false,
        };
        if (!$valid) {
            return null;
        }
        $maximumItems = $field->maximumItems;
        if ($maximumItems !== null && is_array($decoded) && count($decoded) > $maximumItems) {
            return null;
        }
        return $decoded;
    }

    private function requiredType(string $typeId): RecordTypeDefinition
    {
        $type = $this->profile->type($typeId);
        if ($type === null) {
            throw new \InvalidArgumentException('Record type is not installed');
        }
        return $type;
    }

    private function randomId(): string
    {
        return bin2hex($this->random->bytes(16));
    }

    private static function validId(string $id): bool
    {
        return preg_match('/\A[a-f0-9]{32}\z/', $id) === 1;
    }
}
