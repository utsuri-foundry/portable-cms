<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Content;

/** A page-specific session, not an authentication credential. Sequence detects stale tabs. */
final readonly class RecordEditIntent
{
    public function __construct(public int $sequence, public ?string $sessionHash = null, public ?string $requestId = null, public bool $resume = false)
    {
        \Utsuri\PortableCms\Conventional\Database\SiteSequence::value($sequence);
        if (($sessionHash !== null && preg_match('/\A[a-f0-9]{64}\z/', $sessionHash) !== 1)
            || ($requestId !== null && preg_match('/\A[a-f0-9]{32}\z/', $requestId) !== 1)
            || (($sessionHash === null) !== ($requestId === null))) {
            throw new \InvalidArgumentException('Invalid record editing intent');
        }
    }
}

interface EditingRecordRepository
{
    /** @param array<string, mixed> $body
     * @param list<RelationshipProjection> $relationships
     */
    public function keep(string $typeId, string $recordId, ?RecordSnapshot $record, RecordEditIntent $intent, string $revisionId, string $profileId, string $profileVersion, array $body, array $relationships, string $actorUserId, string $auditId, \DateTimeImmutable $now): RecordSnapshot|RecordConflict;
}

final readonly class KeptRecord
{
    /** @param array<string, \Utsuri\PortableCms\Conventional\I18n\Message> $errors */
    public function __construct(public RecordSnapshot $record, public array $errors, public bool $retryable = false) {}
}

/** Validation is shared with explicit saves; only completeness waits for publication. */
final class EditingFields
{
    public static function incomplete(FieldDefinition $field): FieldDefinition
    {
        $settings = $field->settings;
        if ($field->type === 'blocks') {
            $settings['minimumItems'] = 0;
            foreach ($settings['blockTypes'] as &$type) {
                foreach ($type['fields'] as &$child) {
                    $child['requiredOnDraft'] = false;
                }
                unset($child);
            }
            unset($type);
        }
        return new FieldDefinition($field->id, $field->label, $field->type, $field->localized, false, $field->requiredOnPublish, $settings, array_map(self::incomplete(...), $field->fields), $field->maximumLength, $field->targetType, $field->maximumItems, $field->editorialSection, $field->hint);
    }
}
