<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Import;

use Utsuri\PortableCms\Conventional\Content\RecordRepository;
use Utsuri\PortableCms\Conventional\Design\RegistryDocument;

final class ImportRejected extends \RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('Content import rejected: ' . $reason);
    }
}

interface ImportRepository
{
    public function authorize(string $actorId): void;
    public function records(): RecordRepository;
    public function languages(\Utsuri\PortableCms\Conventional\Content\ProfileManifest $profile): \Utsuri\PortableCms\Conventional\Content\SiteLanguages;
    public function design(): ?RegistryDocument;
    public function destinationIdentity(): string;
    /** @param list<string> $ids
     * @return array<string, ImportRecordCheckpoint>
     */
    public function recordMappings(string $source, array $ids): array;
    /** @param list<array{id: string, sha256: string}> $identities
     * @return array<string, ImportMediaCheckpoint> Keys are source ID and original checksum.
     */
    public function mediaMappings(string $source, array $identities): array;
    public function recordSource(string $source, string $destinationId): ?string;
    /** @return array{ownerRecordId: ?string, sha256: string, status: string, name: string, metadataRevision: int, metadataSha256: string}|null */
    public function mediaIdentity(string $id): ?array;
    /** @return array{typeId: string, revisionNumber: int}|null */
    public function identity(string $id): ?array;
    /** @template T
     * @param callable(): T $action
     * @return T
     */
    public function transaction(callable $action): mixed;
    public function checkpointRecord(string $source, ImportRecord $record, int $revision, string $actorId, \DateTimeImmutable $now): void;
    public function reserveMedia(string $source, ImportMedia $media, string $actorId, \DateTimeImmutable $now): void;
    public function completeMedia(string $source, ImportMedia $media): void;
    /** @param list<\Utsuri\PortableCms\Conventional\Content\RelationshipProjection> $relationships */
    public function project(string $id, string $revisionId, array $relationships): void;
    public function audit(string $id, string $actorId, string $source, string $digest, bool $confirmedConflicts, \DateTimeImmutable $now): void;
}

final readonly class ImportRecordCheckpoint
{
    public function __construct(public string $sourceId, public string $destinationId, public string $sourceDigest, public int $sourceRevision, public int $destinationRevision) {}
}

final readonly class ImportMediaCheckpoint
{
    public function __construct(public string $destinationId, public ?string $ownerId, public string $actorId, public string $status, public string $sourceMetadataDigest, public ?int $destinationMetadataRevision, public ?int $sourceMetadataRevision) {}
}

final readonly class ImportRecord
{
    /** @param array<string, mixed> $body
     * @param list<array{reason: string, field?: int|string, mediaId?: string, detail?: string}> $issues
     */
    public function __construct(public string $sourceId, public string $id, public string $typeId, public array $body, public string $sourceDigest, public int $sourceRevision, public ?int $expectedRevision, public string $action, public array $issues, public ?int $expectedEditSequence = null) {}
    /** @return array<string, mixed> */
    public function report(): array
    {
        return ['sourceId' => $this->sourceId, 'destinationId' => $this->id, 'typeId' => $this->typeId, 'action' => $this->action, 'expectedRevision' => $this->expectedRevision, 'expectedEditSequence' => $this->expectedEditSequence, 'sourceDigest' => $this->sourceDigest, 'issues' => $this->issues];
    }
}

final readonly class ImportMedia
{
    /** @var array{id: string, originalName: string, metadataRevision: int|null, text: array<string, mixed>, focalPoint: array{x: int, y: int}, original: array{sha256: string, byteSize: int, mime: string}} */
    public array $value;
    /** @param array<string, mixed> $value
     * @param list<array{reason: string, field?: int|string, mediaId?: string}> $issues
     */
    public function __construct(array $value, public string $id, public ?string $ownerId, public string $clientUploadId, public string $jobId, public string $auditId, public string $action, public array $issues, public ?string $processingStatus = null, public ?int $expectedMetadataRevision = null)
    {
        $original = $value['original'] ?? null;
        if (!is_string($value['id'] ?? null) || !is_string($value['originalName'] ?? null) || !is_array($original)
            || !is_string($original['sha256'] ?? null) || !is_int($original['byteSize'] ?? null) || !is_string($original['mime'] ?? null)
            || (($value['metadataRevision'] ?? null) !== null && !is_int($value['metadataRevision']))) {
            throw new ImportRejected('invalid_original_facts');
        }
        $this->value = ['id' => $value['id'], 'originalName' => $value['originalName'], 'metadataRevision' => $value['metadataRevision'] ?? null, ...self::metadata($value), 'original' => ['sha256' => $original['sha256'], 'byteSize' => $original['byteSize'], 'mime' => $original['mime']]];
    }
    /** @param array<string, mixed> $value
     * @return array{text: array<string, mixed>, focalPoint: array{x: int, y: int}}
     */
    public static function metadata(array $value): array
    {
        return ['text' => \Utsuri\PortableCms\Conventional\Media\MediaText::from($value['text'] ?? null)->document(), 'focalPoint' => \Utsuri\PortableCms\Conventional\Media\FocalPoint::from($value['focalPoint'] ?? null)->document()];
    }
    /** @param array<string, mixed> $value */
    public static function metadataDigest(array $value): string
    {
        return hash('sha256', json_encode(['originalName' => $value['originalName'], ...self::metadata($value)], JSON_THROW_ON_ERROR));
    }
    /** @return array<string, mixed> */
    public function report(): array
    {
        return ['sourceId' => $this->value['id'], 'destinationId' => $this->id, 'ownerRecordId' => $this->ownerId, 'sha256' => $this->value['original']['sha256'], 'byteSize' => $this->value['original']['byteSize'], 'sourceMetadataSha256' => self::metadataDigest($this->value), 'sourceMetadataRevision' => $this->value['metadataRevision'], 'expectedMetadataRevision' => $this->expectedMetadataRevision, 'action' => $this->action, 'processingStatus' => $this->processingStatus, 'issues' => $this->issues];
    }
}

final readonly class ImportPlan
{
    public string $sha256;
    /** @param list<ImportRecord> $records
     * @param list<ImportMedia> $media
     * @param array<string, mixed> $context
     */
    public function __construct(public array $records, public array $media, public array $context, public VerifiedImportInventory $inventory)
    {
        $this->sha256 = hash('sha256', json_encode($this->report(), JSON_THROW_ON_ERROR));
    }
    /** @return array<string, mixed> */
    public function report(): array
    {
        return $this->context + ['records' => array_map(static fn(ImportRecord $record): array => $record->report(), $this->records), 'media' => array_map(static fn(ImportMedia $media): array => $media->report(), $this->media)];
    }
}

/** Checked byte facts travel into the database transaction; filesystem/image probes stay outside it. */
final readonly class VerifiedImportInventory
{
    /** @param array<string, list<array{reason: string}>> $issues
     * @param array<string, array{mime: string, width: int, height: int}> $geometry
     */
    public function __construct(public string $exportSha256, public array $issues, public array $geometry, public ImportSource $source) {}
}
