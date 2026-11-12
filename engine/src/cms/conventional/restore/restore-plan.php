<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Restore;

use Utsuri\PortableCms\Conventional\Content\ContentService;
use Utsuri\PortableCms\Conventional\Content\FieldDefinition;
use Utsuri\PortableCms\Conventional\Content\ProfilePackage;
use Utsuri\PortableCms\Conventional\Design\RegistryDocument;
use Utsuri\PortableCms\Conventional\Export\ExportDataset;
use Utsuri\PortableCms\Conventional\Identity\Clock;
use Utsuri\PortableCms\Conventional\Identity\RandomSource;

final class RestoreRejected extends \RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('Restore rejected: ' . $reason);
    }
}

final readonly class RestoreFile
{
    public function __construct(public string $key, public string $sha256, public int $byteSize, public string $mime, public ?int $width = null, public ?int $height = null, public ?int $durationMilliseconds = null, public ?bool $hasAudio = null) {}
}

/** An immutable, validated intent. No storage addresses are accepted from an export. */
final readonly class RestorePlan
{
    /** @var list<RestoreFile> */
    public array $files;

    public function __construct(public ExportDataset $export, public string $sha256, public ProfilePackage $package, Clock $clock, RandomSource $random)
    {
        if (preg_match('/\A[a-f0-9]{64}\z/', $sha256) !== 1) {
            throw new RestoreRejected('invalid_export_identity');
        }
        $document = $export->metadata();
        $profile = $package->manifest;
        // Records are checked against the exported language choice: languages in preparation or off never
        // require text, and languages the installation added within the envelope are installed again.
        try {
            $languages = isset($document['siteLanguages']) && ($document['profile']['id'] ?? null) === $profile->id
                ? \Utsuri\PortableCms\Conventional\Content\SiteLanguages::from($document['siteLanguages'], $profile)
                : \Utsuri\PortableCms\Conventional\Content\SiteLanguages::defaults($profile);
        } catch (\InvalidArgumentException) {
            throw new RestoreRejected('site_languages_invalid');
        }
        $profile = $languages->installed($profile);
        if ($document['profile']['id'] !== $profile->id || $document['profile']['version'] !== $profile->version || $document['profile']['locales'] !== $profile->locales) {
            throw new RestoreRejected('profile_mismatch_requires_migration');
        }
        $design = isset($document['designRegistries']) ? RegistryDocument::fromDocument($document['designRegistries']) : $profile->designRegistries;
        if ($design !== null && ($profile->designRegistries === null || $design->profileId() !== $profile->id)) {
            throw new RestoreRejected('design_profile_mismatch');
        }
        $files = [];
        $mediaIds = [];
        $logos = \Utsuri\PortableCms\Conventional\Publication\AdministrationBranding::from($document['websitePublication']['administrationBranding'] ?? null);
        $logoIds = array_filter($logos->document());
        $logoDerivatives = [];
        foreach ($document['editorialTerminology']['labels'] ?? [] as $label) {
            if ($profile->type($label['typeId']) === null) {
                throw new RestoreRejected('terminology_uses_unknown_type');
            }
        }
        if (isset($document['editorialTerminology'])) {
            try {
                \Utsuri\PortableCms\Conventional\Content\AdministrationMenuLabels::assert($profile, $package->administrationLabels, \Utsuri\PortableCms\Conventional\Content\EditorialTerminology::fromDocument($document['editorialTerminology']));
            } catch (\InvalidArgumentException) {
                throw new RestoreRejected('terminology_label_collision');
            }
        }
        foreach ($export->rows('media') as $media) {
            if ((int) substr($media['createdAt'], 0, 4) < 1000 || (int) substr($media['updatedAt'], 0, 4) < 1000 || $media['width'] > 4_294_967_295 || $media['height'] > 4_294_967_295 || ($media['metadataRevision'] ?? 1) > 4_294_967_295) {
                throw new RestoreRejected('media_requires_storage_migration');
            }
            if (!in_array($media['status'], ['ready', 'needs_attention'], true)) {
                throw new RestoreRejected('unfinished_media_jobs_not_exported');
            }
            $mediaIds[$media['id']] = array_intersect_key($media, array_flip(['status', 'detectedType', 'width', 'height', 'original']));
            $original = $media['original'];
            if (!in_array($original['mime'], ['image/jpeg', 'image/png', 'image/webp', 'video/mp4', 'video/webm'], true)
                || !in_array($media['declaredType'], ['image/jpeg', 'image/png', 'image/webp', 'video/mp4', 'video/webm'], true)
                || ($media['status'] === 'ready' && ($media['detectedType'] !== $original['mime'] || $media['width'] === null || $media['height'] === null))) {
                throw new RestoreRejected('unsupported_or_inconsistent_media_facts');
            }
            $files[] = new RestoreFile('originals/' . $media['id'] . '/source', $original['sha256'], $original['byteSize'], $original['mime']);
            $ready = false;
            foreach ($media['derivatives'] as $derivative) {
                if (strlen($derivative['variant']) > 64 || strlen($derivative['processorId']) > 64 || $derivative['recipeVersion'] > 65535) {
                    throw new RestoreRejected('derivative_requires_storage_migration');
                }
                if ((int) substr($derivative['createdAt'], 0, 4) < 1000 || $derivative['width'] > 4_294_967_295 || $derivative['height'] > 4_294_967_295) {
                    throw new RestoreRejected('derivative_requires_storage_migration');
                }
                if ($derivative['variant'] === 'prepared-video') {
                    $extension = $media['detectedType'] === 'video/mp4' ? 'mp4' : 'webm';
                    $ready = in_array($media['detectedType'], ['video/mp4', 'video/webm'], true) && $derivative['mime'] === $media['detectedType']
                        && $derivative['fileName'] === $media['id'] . '-prepared-video.' . $extension && $derivative['sha256'] === $original['sha256']
                        && $derivative['byteSize'] === $original['byteSize'] && $derivative['width'] === $media['width'] && $derivative['height'] === $media['height'];
                }
                if ($derivative['variant'] === '960-webp') {
                    if (in_array($media['id'], $logoIds, true)) {
                        $logoDerivatives[$media['id']] = $derivative;
                    }
                    $ready = in_array($media['detectedType'], ['image/jpeg', 'image/png', 'image/webp'], true)
                        && $derivative['fileName'] === $media['id'] . '-960-webp.webp' && $derivative['mime'] === 'image/webp';
                }
                $rasterWidth = \Utsuri\PortableCms\Conventional\Media\RasterWidths::maximum($derivative['variant']);
                if ($rasterWidth !== null && $rasterWidth !== 960
                    && (!in_array($media['detectedType'], ['image/jpeg', 'image/png', 'image/webp'], true)
                        || $derivative['fileName'] !== $media['id'] . '-' . $rasterWidth . '-webp.webp' || $derivative['mime'] !== 'image/webp'
                        || $derivative['width'] !== $rasterWidth || $derivative['height'] > 20_000
                        || !is_int($media['width']) || !is_int($media['height']) || $rasterWidth > max($media['width'], $media['height']))) {
                    throw new RestoreRejected('invalid_raster_width_recipe');
                }
                $duration = $audio = null;
                if ($derivative['variant'] === 'prepared-video') {
                    $duration = $derivative['parameters']['durationMilliseconds'] ?? null;
                    $audio = $derivative['parameters']['hasAudio'] ?? null;
                    if (($duration !== null && (!is_int($duration) || $duration < 1 || $duration > 7200000)) || ($audio !== null && (!is_bool($audio) || $duration === null))) {
                        throw new RestoreRejected('invalid_video_recipe_metadata');
                    }
                }
                $files[] = new RestoreFile('derivatives/' . $media['id'] . '/' . $derivative['fileName'], $derivative['sha256'], $derivative['byteSize'], $derivative['mime'], $derivative['width'], $derivative['height'], $duration, $audio);
            }
            if ($media['status'] === 'ready' && !$ready) {
                throw new RestoreRejected('ready_media_recipe_unavailable');
            }
        }
        $fontHashes = [];
        foreach ($design?->faces() ?? [] as $font) {
            if ($font['origin'] === 'upload') {
                if (isset($fontHashes[$font['sha256']]) && $fontHashes[$font['sha256']] !== $font['byteSize']) {
                    throw new RestoreRejected('inconsistent_font_identity');
                }
                if (!isset($fontHashes[$font['sha256']])) {
                    $files[] = new RestoreFile('fonts/' . $font['sha256'] . '.woff2', $font['sha256'], $font['byteSize'], 'font/woff2');
                }
                $fontHashes[$font['sha256']] = $font['byteSize'];
            } else {
                $matches = array_filter($package->manifest->assets, static fn($asset): bool => $asset->role === 'font' && $asset->checksum === $font['sha256'] && $asset->byteSize === $font['byteSize']);
                if ($matches === []) {
                    throw new RestoreRejected('package_font_unavailable');
                }
            }
        }
        $keys = $hashes = [];
        $total = 0;
        foreach ($files as $file) {
            if (isset($keys[$file->key]) || $file->byteSize > (in_array($file->mime, ['video/mp4', 'video/webm'], true) ? \Utsuri\PortableCms\Conventional\Media\VideoSupport::MAX_BYTES : 134_217_728) || (isset($hashes[$file->sha256]) && $hashes[$file->sha256] !== $file->byteSize)) {
                throw new RestoreRejected('invalid_blob_inventory');
            }
            $keys[$file->key] = true;
            $hashes[$file->sha256] = $file->byteSize;
            $total += $file->byteSize;
        }
        if ($total > 8_589_934_592 || count($files) > 25032) {
            throw new RestoreRejected('blob_inventory_limit');
        }
        $lookup = new ExportRecordLookup($export);
        $projections = [];
        foreach ($export->rows('relationships') as $relation) {
            $projections[$relation['recordId']][$relation['revisionId']][] = self::relationshipHash($relation);
        }
        if (($iconId = $document['websitePublication']['iconMediaId'] ?? null) !== null) {
            $icon = $mediaIds[$iconId] ?? null;
            if ($icon === null || !\Utsuri\PortableCms\Conventional\Content\WebsiteIconSelection::eligible($icon['status'], $icon['detectedType'] ?? '', $icon['width'], $icon['height'])) {
                throw new RestoreRejected('holding_icon_not_ready');
            }
        }
        foreach ($logoIds as $id) {
            $media = $mediaIds[$id] ?? null;
            $face = $logoDerivatives[$id] ?? null;
            if ($media === null || $face === null || $face['mime'] !== 'image/webp'
                || !\Utsuri\PortableCms\Conventional\Publication\AdministrationBranding::eligible($media['status'], $media['detectedType'] ?? '', $face['width'], $face['height'], $face['byteSize'])) {
                throw new RestoreRejected('administration_logo_not_ready');
            }
        }
        foreach ($document['websitePublication']['identityUpgrade']['previous'] ?? [] as $previous) {
            if ($previous['iconMediaId'] !== null && !isset($mediaIds[$previous['iconMediaId']])) {
                throw new RestoreRejected('previous_website_icon_missing');
            }
        }
        if (isset($document['siteWording'])) {
            try {
                \Utsuri\PortableCms\Conventional\Content\SiteWording::from($document['siteWording'], $profile);
            } catch (\InvalidArgumentException) {
                throw new RestoreRejected('site_wording_invalid');
            }
        }
        $validator = new ContentService($lookup, $profile, $clock, $random, publicLocales: static fn(): array => $languages->public());
        $counts = [];
        foreach ($export->rows('records') as $record) {
            if ($record['currentRevisionNumber'] > 4_294_967_295 || (int) substr($record['createdAt'], 0, 4) < 1000 || (int) substr($record['updatedAt'], 0, 4) < 1000) {
                throw new RestoreRejected('record_requires_storage_migration');
            }
            $type = $profile->type($record['typeId']);
            $counts[$record['typeId']] = ($counts[$record['typeId']] ?? 0) + 1;
            if ($type === null || ($type->kind === 'singleton' && $counts[$type->id] > 1)) {
                throw new RestoreRejected('unsupported_record_type_or_multiplicity');
            }
        }
        foreach ($export->rows('revisions') as $revision) {
            if ($revision['revisionNumber'] > 4_294_967_295 || (int) substr($revision['createdAt'], 0, 4) < 1000) {
                throw new RestoreRejected('revision_requires_storage_migration');
            }
            if ($revision['profileId'] !== $profile->id || $revision['profileVersion'] !== $profile->version) {
                throw new RestoreRejected('historical_profile_requires_migration');
            }
            $record = $lookup->metadata($revision['recordId']);
            try {
                $validated = $validator->validateCanonical($record['typeId'], $revision['body'], $design);
                self::mediaReferences($profile->type($record['typeId'])->fields, $revision['body'], $mediaIds, $profile->locales);
                $type = $profile->type($record['typeId']);
                foreach (\Utsuri\PortableCms\Conventional\Content\Blocks::layouts($type, $revision['body'], $profile->locales, $design) as $layout) {
                    foreach ($layout->mediaIds() as $id) {
                        if (!isset($mediaIds[$id]) || !str_starts_with($mediaIds[$id]['detectedType'], 'image/')) {
                            throw new \InvalidArgumentException('Unavailable block composition media');
                        }
                    }
                    foreach ($layout->links() as $link) {
                        if ($lookup->find($link->typeId, $link->recordId) === null) {
                            throw new \InvalidArgumentException('Unavailable block composition link');
                        }
                    }
                }
                foreach ($type->fields as $field) {
                    if ($field->type !== 'composition' || ($revision['body'][$field->id] ?? null) === null) {
                        continue;
                    }
                    foreach ($field->localized ? $profile->locales : [''] as $locale) {
                        $raw = $field->localized ? ($revision['body'][$field->id][$locale] ?? null) : $revision['body'][$field->id];
                        $layout = \Utsuri\PortableCms\Conventional\Content\Composition::from($raw, $field, $type, $design, $profile->locales);
                        foreach ($layout->mediaIds() as $id) {
                            if (!isset($mediaIds[$id])) {
                                throw new \InvalidArgumentException('Unavailable composition media');
                            }
                        }
                    }
                }
            } catch (\Utsuri\PortableCms\Conventional\I18n\InvalidInput $error) {
                throw new RestoreRejected($error->getMessage());
            } catch (\InvalidArgumentException) {
                throw new RestoreRejected('invalid_or_lossy_revision_body');
            }
            if (in_array($revision['id'], [$record['currentRevisionId'], \Utsuri\PortableCms\Conventional\Export\NormalizedExport::publishedRevisionId($record)], true)) {
                $expected = array_map(static fn($relation): string => self::relationshipHash(['fieldId' => $relation->fieldId, 'kind' => $relation->kind, 'targetRecordId' => $relation->targetRecordId, 'position' => $relation->position]), $validated->relationships);
                $actual = $projections[$record['id']][$revision['id']] ?? [];
                sort($expected);
                sort($actual);
                if ($expected != $actual) {
                    throw new RestoreRejected('relationship_projection_mismatch');
                }
            }
        }
        $this->files = $files;
    }

    /** @param array<string, mixed> $relation */
    private static function relationshipHash(array $relation): string
    {
        return hash('sha256', json_encode([$relation['fieldId'], $relation['kind'], $relation['targetRecordId'], $relation['position']], JSON_THROW_ON_ERROR));
    }

    /** @param list<FieldDefinition> $fields
     * @param array<string, mixed> $body
     * @param array<string, array<string, mixed>> $mediaIds
     * @param list<string> $locales
     */
    private static function mediaReferences(array $fields, array $body, array $mediaIds, array $locales): void
    {
        [$fields, $body] = \Utsuri\PortableCms\Conventional\Content\Blocks::expand($fields, $body, $locales);
        \Utsuri\PortableCms\Conventional\Content\VimeoVideo::assertFields($fields, $body, $locales, static fn(string $id): ?string => ($mediaIds[$id]['status'] ?? null) === 'ready' ? ($mediaIds[$id]['detectedType'] ?? null) : null);
        \Utsuri\PortableCms\Conventional\Content\VideoReference::assertGraph($fields, $body, $locales, static fn(string $id): ?string => ($mediaIds[$id]['status'] ?? null) === 'ready' ? ($mediaIds[$id]['detectedType'] ?? null) : null, static fn(string $id): ?int => $mediaIds[$id]['original']['byteSize'] ?? null);
        foreach ($fields as $field) {
            $raw = $body[$field->id] ?? null;
            if ($field->localized && is_array($raw) && array_diff(array_keys($raw), $locales) !== []) {
                throw new \InvalidArgumentException('Unknown locale');
            }
            if ($raw === null) {
                continue;
            }
            foreach ($field->localized ? $locales : [''] as $locale) {
                $value = $field->localized ? ($raw[$locale] ?? null) : $raw;
                if ($field->type === 'media_references' && is_array($value)) {
                    foreach ($value as $id) {
                        if (!is_string($id) || !isset($mediaIds[$id])) {
                            throw new \InvalidArgumentException('Unavailable media');
                        }
                        $media = $mediaIds[$id];
                        if (($field->settings['purpose'] ?? null) === 'website-icon' && !\Utsuri\PortableCms\Conventional\Content\WebsiteIconSelection::eligible($media['status'], $media['detectedType'], $media['width'], $media['height'])) {
                            throw new \InvalidArgumentException('Invalid website icon selection');
                        }
                    }
                }
                if (in_array($field->type, ['group', 'repeatable_group'], true) && is_array($value)) {
                    foreach ($field->type === 'group' ? [$value] : $value as $item) {
                        if (is_array($item)) {
                            self::mediaReferences($field->fields, $item, $mediaIds, $locales);
                        }
                    }
                }
            }
        }
    }

    /** @return array<string, mixed> Safe operator output, never an export body. */
    public function report(): array
    {
        return ['exportSha256' => $this->sha256, 'profileId' => $this->package->manifest->id,
            'records' => $this->export->count('records'), 'revisions' => $this->export->count('revisions'),
            'media' => $this->export->count('media'), 'files' => count($this->files),
            'bytes' => array_sum(array_map(static fn(RestoreFile $file): int => $file->byteSize, $this->files)),
            'notes' => ['source_authors_are_provenance_only', 'source_publication_not_activated', 'identities_and_secrets_not_imported']];
    }
}
