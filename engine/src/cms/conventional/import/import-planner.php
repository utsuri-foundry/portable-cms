<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Import;

use Utsuri\PortableCms\Conventional\Content\ContentService;
use Utsuri\PortableCms\Conventional\Content\ProfilePackage;
use Utsuri\PortableCms\Conventional\Identity\Clock;
use Utsuri\PortableCms\Conventional\Identity\RandomSource;
use Utsuri\PortableCms\Conventional\Media\MediaContract;
use Utsuri\PortableCms\Conventional\Restore\FilesystemRestoreStore;
use Utsuri\PortableCms\Conventional\Restore\RestoreFile;
use Utsuri\PortableCms\Conventional\Restore\RestoreInput;
use Utsuri\PortableCms\Conventional\Restore\RestoreRejected;

/** Read-only planning. All rejected mappings stay visible; nothing is guessed or truncated. */
final class ImportPlanner
{
    public function __construct(private readonly ImportRepository $repository, private readonly ProfilePackage $package, private readonly Clock $clock, private readonly RandomSource $random, private readonly bool $mediaAvailable) {}

    public static function source(string $source): string
    {
        if (preg_match('/\A[a-z0-9][a-z0-9._-]{0,79}\z/', $source) !== 1) {
            throw new ImportRejected('invalid_source_namespace');
        }
        return $source;
    }

    public function plan(RestoreInput $input, FilesystemRestoreStore $inventory, string $source, string $actorId, ?VerifiedImportInventory $verifiedInventory = null): ImportPlan
    {
        self::source($source);
        $this->repository->authorize($actorId);
        $deadline = $this->clock->now()->modify('+180 seconds');
        $projection = $verifiedInventory->source ?? ImportSource::from($input->export);
        $document = $projection->document;
        $languages = $this->repository->languages($this->package->manifest);
        $profile = $languages->installed($this->package->manifest);
        if ($document['profile']['id'] !== $profile->id || $document['profile']['version'] !== $profile->version || $document['profile']['locales'] !== $profile->supported()) {
            throw new ImportRejected('installed_profile_version_and_languages_required');
        }
        if ($verifiedInventory === null && !hash_equals($input->sha256, $inventory->exportChecksum())) {
            throw new ImportRejected('export_changed');
        }
        $verifiedInventory ??= $this->verifyInventory($input, $inventory, $projection);
        if (!hash_equals($input->sha256, $verifiedInventory->exportSha256)) {
            throw new ImportRejected('inventory_identity_mismatch');
        }
        $mappings = $this->repository->recordMappings($source, array_column($document['records'], 'id'));
        $mediaMappings = $this->repository->mediaMappings($source, array_map(static fn(array $media): array => ['id' => $media['id'], 'sha256' => $media['original']['sha256']], $document['media']));
        $revisions = array_column($document['revisions'], null, 'id');
        $recordIds = $sourceCounts = $destinationCounts = [];
        foreach ($document['records'] as $record) {
            $this->budget($deadline);
            $type = $profile->type($record['typeId']);
            $existing = $type?->kind === 'singleton' ? $this->repository->records()->list($type->id, 2) : [];
            $recordIds[$record['id']] = $mappings[$record['id']]->destinationId ?? ($existing[0]->id ?? $record['id']);
            $destinationCounts[$record['typeId']] = count($existing);
            $sourceCounts[$record['typeId']] = ($sourceCounts[$record['typeId']] ?? 0) + 1;
        }
        $mediaIds = $mediaItems = [];
        $totalBytes = 0;
        foreach ($document['media'] as $value) {
            $this->budget($deadline);
            $owner = $value['ownerRecordId'] === null ? null : $recordIds[$value['ownerRecordId']];
            $seed = $this->repository->destinationIdentity() . "\0" . $source . "\0" . $value['id'] . "\0" . ($owner ?? 'website') . "\0" . $value['original']['sha256'];
            $id = self::id('media', $seed);
            $mediaIds[$value['id']] = $id;
            $issues = $verifiedInventory->issues[$value['id']];
            $totalBytes += $value['original']['byteSize'];
            $mapping = $mediaMappings[$value['id'] . ':' . $value['original']['sha256']] ?? null;
            if (!$this->mediaAvailable && ($mapping === null || $mapping->status !== 'completed')) {
                $issues[] = ['reason' => 'qualified_media_processor_required'];
            }
            $identity = $this->repository->mediaIdentity($id);
            if ($identity !== null && ($mapping === null || $identity['sha256'] !== $value['original']['sha256'] || $identity['ownerRecordId'] !== $owner)) {
                $issues[] = ['reason' => 'unowned_or_changed_destination_media'];
            } elseif ($mapping !== null && $mapping->status === 'completed' && $identity === null) {
                $issues[] = ['reason' => 'mapped_destination_media_missing'];
            }
            if ($mapping !== null && ($mapping->destinationId !== $id || $mapping->ownerId !== $owner)) {
                $issues[] = ['reason' => 'source_media_owner_changed'];
            }
            if ($mapping !== null && $mapping->status !== 'completed' && $mapping->actorId !== $actorId) {
                $issues[] = ['reason' => 'resume_with_original_administrator'];
            }
            $action = $mapping === null ? 'create' : ($mapping->status === 'completed' ? 'unchanged' : 'resume');
            $sourceRevision = $value['metadataRevision'] ?? null;
            if ($sourceRevision !== null && $sourceRevision > 4_294_967_295) {
                $issues[] = ['reason' => 'source_media_revision_outside_checkpoint_bounds'];
            }
            if ($mapping !== null && $mapping->sourceMetadataDigest !== ImportMedia::metadataDigest($value)) {
                if ($mapping->status !== 'completed') {
                    $issues[] = ['reason' => 'pending_media_intent_changed'];
                } elseif ($identity !== null) {
                    $stale = $mapping->sourceMetadataRevision !== null && ($sourceRevision === null || $sourceRevision <= $mapping->sourceMetadataRevision);
                    $action = !$stale && ($identity['metadataSha256'] === ImportMedia::metadataDigest($value) || $identity['metadataRevision'] === $mapping->destinationMetadataRevision) ? 'update' : 'conflict';
                    if ($action === 'conflict') {
                        $issues[] = ['reason' => $stale ? 'source_media_metadata_not_newer' : 'destination_media_metadata_changed'];
                    }
                }
            } elseif ($mapping !== null && $mapping->status === 'completed' && $sourceRevision !== null && ($mapping->sourceMetadataRevision === null || $sourceRevision > $mapping->sourceMetadataRevision)) {
                $action = 'update';
            }
            $blocked = array_filter($issues, static fn(array $issue): bool => !in_array($issue['reason'], ['destination_media_metadata_changed', 'source_media_metadata_not_newer'], true));
            $mediaItems[] = new ImportMedia($value, $id, $owner, self::id('upload', $seed), self::id('job', $seed), self::id('audit', $seed), $blocked !== [] ? 'blocked' : $action, $issues, $identity['status'] ?? null, $identity['metadataRevision'] ?? null);
        }
        if ($totalBytes > 8_589_934_592) {
            throw new ImportRejected('original_inventory_limit');
        }
        $design = $this->repository->design();
        $records = [];
        $sourceMedia = array_column($document['media'], null, 'id');
        $videoFacts = [];
        $sourceLookup = new \Utsuri\PortableCms\Conventional\Restore\ExportRecordLookup($projection);
        $sourceValidator = new ContentService($sourceLookup, $profile, $this->clock, $this->random, publicLocales: static fn(): array => $languages->public());
        $availableMedia = array_fill_keys(array_map(static fn(ImportMedia $media): string => $media->value['id'], array_filter($mediaItems, static fn(ImportMedia $media): bool => $media->action !== 'blocked')), true);
        foreach ($document['records'] as $record) {
            $this->budget($deadline);
            $revision = $revisions[$record['currentRevisionId']];
            $type = $profile->type($record['typeId']);
            $id = $recordIds[$record['id']];
            $current = $type === null ? null : $this->repository->records()->find($type->id, $id);
            $identity = $this->repository->identity($id);
            $mapping = $mappings[$record['id']] ?? null;
            $issues = [];
            $body = $revision['body'];
            if ($record['currentRevisionNumber'] > 4_294_967_295) {
                $issues[] = ['reason' => 'source_revision_outside_checkpoint_bounds'];
            }
            if ($type === null || ($type->kind === 'singleton' && $sourceCounts[$type->id] > 1)) {
                $issues[] = ['reason' => 'unsupported_type_or_singleton_multiplicity'];
            } else {
                if ($revision['profileId'] !== $profile->id || $revision['profileVersion'] !== $profile->version) {
                    $issues[] = ['reason' => 'current_revision_profile_mismatch'];
                }
                try {
                    \Utsuri\PortableCms\Conventional\Content\VimeoVideo::assertFields($type->fields, $body, $profile->locales, static fn(string $id): ?string => $sourceMedia[$id]['original']['mime'] ?? null);
                    \Utsuri\PortableCms\Conventional\Content\VideoReference::assertGraph(
                        $type->fields,
                        $body,
                        $profile->locales,
                        static fn(string $id): ?string => $sourceMedia[$id]['original']['mime'] ?? null,
                        static fn(string $id): ?int => $sourceMedia[$id]['original']['byteSize'] ?? null,
                        function (string $id) use ($sourceMedia, $inventory, &$videoFacts): array {
                            $original = $sourceMedia[$id]['original'];
                            return $videoFacts[$id] ??= $inventory->videoFacts(new RestoreFile('source', $original['sha256'], $original['byteSize'], $original['mime']));
                        },
                    );
                    $validated = $sourceValidator->validateCanonical($type->id, $body, $design);
                    $expected = array_map(static fn($relation): array => ['fieldId' => $relation->fieldId, 'kind' => $relation->kind, 'targetRecordId' => $relation->targetRecordId, 'position' => $relation->position], $validated->relationships);
                    $actual = [];
                    foreach ($document['relationships'] as $relation) {
                        if ($relation['recordId'] === $record['id'] && $relation['revisionId'] === $record['currentRevisionId']) {
                            $actual[] = array_intersect_key($relation, array_flip(['fieldId', 'kind', 'targetRecordId', 'position']));
                        }
                    }
                    sort($expected);
                    sort($actual);
                    if ($expected != $actual) {
                        $issues[] = ['reason' => 'relationship_projection_mismatch'];
                    }
                } catch (\Utsuri\PortableCms\Conventional\I18n\InvalidInput $error) {
                    $issues[] = ['reason' => $error->text()->key, 'detail' => $error->getMessage()];
                } catch (RestoreRejected $error) {
                    $issues[] = ['reason' => $error->reason];
                } catch (\Utsuri\PortableCms\Conventional\Content\CanonicalContentRejected $error) {
                    $issues = [...$issues, ...$error->issues, ['reason' => 'invalid_or_lossy_body']];
                } catch (\InvalidArgumentException) {
                    $issues[] = ['reason' => 'invalid_or_lossy_body'];
                }
                foreach (ImportBody::mediaIds($type->fields, $body, $profile->locales) as $mediaId) {
                    if (!isset($availableMedia[$mediaId])) {
                        $issues[] = ['reason' => 'unavailable_media', 'mediaId' => $mediaId];
                    }
                }
                $body = ImportBody::remap($type->fields, $body, $profile->supported(), $recordIds, $mediaIds);
                $body = \Utsuri\PortableCms\Conventional\Content\SiteLanguages::keep($type, $body, $current?->body, array_values(array_diff($profile->supported(), $languages->editing())));
            }
            if ($destinationCounts[$record['typeId']] > 1) {
                $issues[] = ['reason' => 'ambiguous_destination_singleton'];
            }
            if ($identity !== null && $identity['typeId'] !== $record['typeId']) {
                $issues[] = ['reason' => 'destination_identity_type_conflict'];
            }
            if ($mapping !== null && $current === null) {
                $issues[] = ['reason' => 'mapped_destination_missing'];
            }
            $ownedSource = $this->repository->recordSource($source, $id);
            if ($ownedSource !== null && $ownedSource !== $record['id']) {
                $issues[] = ['reason' => 'destination_has_another_source_identity'];
            }
            $digest = hash('sha256', json_encode([$record['currentRevisionId'], $record['currentRevisionNumber'], $body], JSON_THROW_ON_ERROR));
            $action = $current === null ? 'create' : 'update';
            if ($issues !== []) {
                $action = 'blocked';
            } elseif (($mapping?->sourceDigest) === $digest || ($current !== null && self::json($current->body) === self::json($body))) {
                $action = 'unchanged';
            } elseif ($current !== null && ($mapping !== null
                ? ($current->revisionNumber !== $mapping->destinationRevision || $record['currentRevisionNumber'] <= $mapping->sourceRevision)
                : ($current->updatedAt > new \DateTimeImmutable($record['updatedAt']) || $current->revisionNumber > $record['currentRevisionNumber'] || $id !== $record['id']))) {
                $action = 'conflict';
                $issues[] = ['reason' => 'destination_changed_or_source_not_newer'];
            }
            $records[] = new ImportRecord($record['id'], $id, $record['typeId'], $body, $digest, $record['currentRevisionNumber'], $current?->revisionNumber, $action, $issues, $current?->editSequence);
        }
        // Propagate unavailable targets to a fixed point before any graph mutation.
        do {
            $changed = false;
            $graph = new ImportGraphRepository($this->repository->records(), $records);
            $validator = new ContentService($graph, $profile, $this->clock, $this->random, publicLocales: static fn(): array => $languages->public());
            foreach ($records as $index => $record) {
                $this->budget($deadline);
                if ($record->action === 'blocked') {
                    continue;
                }
                try {
                    $validator->validateCanonical($record->typeId, $record->body, $design);
                } catch (\InvalidArgumentException) {
                    $records[$index] = new ImportRecord($record->sourceId, $record->id, $record->typeId, $record->body, $record->sourceDigest, $record->sourceRevision, $record->expectedRevision, 'blocked', [...$record->issues, ['reason' => 'unavailable_destination_graph_or_lossy_mapping']], $record->expectedEditSequence);
                    $changed = true;
                }
            }
        } while ($changed);
        $owners = array_column($records, null, 'id');
        foreach ($mediaItems as $index => $media) {
            if ($media->ownerId !== null && in_array($owners[$media->ownerId]->action, ['blocked', 'conflict'], true) && $owners[$media->ownerId]->expectedRevision === null) {
                $mediaItems[$index] = new ImportMedia($media->value, $media->id, $media->ownerId, $media->clientUploadId, $media->jobId, $media->auditId, 'blocked', [...$media->issues, ['reason' => 'owner_not_importable']], $media->processingStatus);
            }
        }
        return new ImportPlan($records, $mediaItems, ['source' => $source, 'exportSha256' => $input->sha256, 'profileSha256' => $this->package->checksum, 'destinationSha256' => $this->repository->destinationIdentity(), 'administratorId' => $actorId, 'designSha256' => $design?->checksum(), 'destinationSiteLanguages' => $languages->document(),
            'notes' => ['current_content_only', 'new_records_are_drafts', 'destination_workflow_preserved', 'derivatives_rebuilt_by_normal_jobs', 'identities_secrets_and_publication_not_imported', 'destination_design_and_terminology_retained', 'destination_settings_and_off_language_text_retained'],
            'excluded' => ['historicalRevisions' => $projection->historicalRevisions, 'sourceWorkflowStates' => count($document['records']), 'sourceDerivatives' => $projection->derivatives, 'designRegistries' => isset($document['designRegistries']), 'editorialTerminology' => isset($document['editorialTerminology']), 'publicationHistory' => isset($document['publishedSlugHistory']), 'siteLanguages' => isset($document['siteLanguages']), 'siteWording' => isset($document['siteWording']), 'websitePublication' => isset($document['websitePublication']), 'siteSequence' => isset($document['siteSequence'])]], $verifiedInventory);
    }

    private function verifyInventory(RestoreInput $input, FilesystemRestoreStore $inventory, ImportSource $projection): VerifiedImportInventory
    {
        $issues = $facts = [];
        foreach ($projection->document['media'] as $media) {
            $original = $media['original'];
            $issues[$media['id']] = [];
            if (str_starts_with($original['mime'], 'video/')) {
                $issues[$media['id']][] = ['reason' => 'prepared_video_import_not_supported'];
                continue;
            }
            if (!in_array($original['mime'], ['image/jpeg', 'image/png', 'image/webp'], true) || $original['byteSize'] > MediaContract::MAX_BYTES) {
                $issues[$media['id']][] = ['reason' => 'original_outside_normal_upload_contract'];
                continue;
            }
            if ($media['declaredType'] !== $original['mime']) {
                $issues[$media['id']][] = ['reason' => 'source_declared_type_mismatch'];
            }
            $intent = MediaContract::uploadIntent($media['originalName'], $original['mime'], $original['byteSize']);
            if ($intent['filename'] !== $media['originalName']) {
                $issues[$media['id']][] = ['reason' => 'unsupported_or_lossy_original_name'];
            }
            try {
                $geometry = $inventory->imageGeometry('blobs/' . $original['sha256'], new RestoreFile('source', $original['sha256'], $original['byteSize'], $original['mime']));
                if (!MediaContract::imageDimensionsAllowed($geometry['width'], $geometry['height'])) {
                    $issues[$media['id']][] = ['reason' => 'image_outside_normal_processor_bounds'];
                } else {
                    $facts[$media['id']] = ['mime' => $original['mime'], 'width' => $geometry['width'], 'height' => $geometry['height']];
                }
            } catch (RestoreRejected $error) {
                $issues[$media['id']][] = ['reason' => $error->reason];
            }
        }
        return new VerifiedImportInventory($input->sha256, $issues, $facts, $projection);
    }

    private static function id(string $kind, string $seed): string
    {
        return substr(hash('sha256', 'portable-content-import:' . $kind . "\0" . $seed), 0, 32);
    }

    private function budget(\DateTimeImmutable $deadline): void
    {
        if ($this->clock->now() >= $deadline) {
            throw new ImportRejected('content_planning_time_budget_exhausted');
        }
    }
    private static function json(mixed $value): string
    {
        if (is_array($value)) {
            if (!array_is_list($value)) {
                ksort($value, SORT_STRING);
            }
            $value = array_map(static fn($child) => json_decode(self::json($child), true, 64, JSON_THROW_ON_ERROR), $value);
        }
        return json_encode($value, JSON_THROW_ON_ERROR);
    }
}
