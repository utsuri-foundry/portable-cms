<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Export;

const EXPORT_MAX_RECORDS = 500;
const EXPORT_MAX_REVISIONS = 5_000;
const EXPORT_MAX_RELATIONSHIPS = 16_000;
const EXPORT_MAX_MEDIA = 5_000;
const EXPORT_MAX_DERIVATIVES = 20_000;
const EXPORT_MAX_JSON_BYTES = 32 * 1024 * 1024;

final readonly class NormalizedExport implements ExportDataset
{
    /** @param array<string, mixed> $document */
    public function __construct(public array $document)
    {
        self::validate($document);
    }

    public function json(): string
    {
        $encoded = json_encode(
            $this->document,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ) . "\n";
        if (strlen($encoded) > EXPORT_MAX_JSON_BYTES) {
            throw new ExportCapacityFailure('legacy_export_byte_capacity_exceeded');
        }
        return $encoded;
    }

    public function metadata(): array
    {
        return array_diff_key($this->document, array_flip(ExportCapacity::COLLECTIONS));
    }

    public function rows(string $collection): iterable
    {
        ExportCapacity::limit($collection);
        yield from $this->document[$collection];
    }

    public function count(string $collection): int
    {
        ExportCapacity::limit($collection);
        return count($this->document[$collection]);
    }

    public function revision(string $id): array
    {
        foreach ($this->document['revisions'] as $revision) {
            if ($revision['id'] === $id) {
                return $revision;
            }
        }
        throw new \InvalidArgumentException('Export revision unavailable');
    }

    /** Shared structural authority for one streamed row; graph checks belong to the dataset. */
    public static function entry(string $collection, mixed $entry, bool $streamed = false): void
    {
        match ($collection) {
            'records' => self::record($entry),
            'revisions' => self::revisionEntry($entry, $streamed),
            'relationships' => self::relationship($entry),
            'media' => self::media($entry),
            default => throw new \InvalidArgumentException('Unknown export collection'),
        };
    }

    /** Source provenance only. A restore must not replace destination operator configuration. */
    public function sourcePublicUrlContext(): ?\Utsuri\PortableCms\Conventional\Compiler\PublicUrlContext
    {
        return isset($this->document['sourcePublicUrlContext'])
            ? \Utsuri\PortableCms\Conventional\Compiler\PublicUrlContext::from($this->document['sourcePublicUrlContext']) : null;
    }

    /** @param array<string, mixed> $document */
    private static function validate(array $document): void
    {
        $requiredKeys = [
            'formatVersion',
            'exportId',
            'createdAt',
            'profile',
            'records',
            'revisions',
            'relationships',
            'media',
        ];
        if (array_key_exists('siteSequence', $document)) {
            $requiredKeys[] = 'siteSequence';
            \Utsuri\PortableCms\Conventional\Database\SiteSequence::value($document['siteSequence']);
        }
        if (array_key_exists('websitePublication', $document)) {
            $requiredKeys[] = 'websitePublication';
        }
        if (array_key_exists('sourcePublicUrlContext', $document)) {
            $requiredKeys[] = 'sourcePublicUrlContext';
            \Utsuri\PortableCms\Conventional\Compiler\PublicUrlContext::from($document['sourcePublicUrlContext']);
        }
        if (array_key_exists('publishedSlugHistory', $document)) {
            $requiredKeys[] = 'publishedSlugHistory';
            $history = \Utsuri\PortableCms\Conventional\Compiler\PublishedSlugHistory::from($document['publishedSlugHistory']);
            if ($history->document()['profileId'] !== ($document['profile']['id'] ?? null)) {
                throw new \InvalidArgumentException('Export publication history profile differs');
            }
        }
        if (array_key_exists('editorialTerminology', $document)) {
            $requiredKeys[] = 'editorialTerminology';
            $terminology = \Utsuri\PortableCms\Conventional\Content\EditorialTerminology::fromDocument($document['editorialTerminology']);
            if ($terminology->profileId !== ($document['profile']['id'] ?? null)) {
                throw new \InvalidArgumentException('Export terminology profile differs');
            }
        }
        if (array_key_exists('siteLanguages', $document)) {
            $requiredKeys[] = 'siteLanguages';
        }
        if (array_key_exists('siteWording', $document)) {
            $requiredKeys[] = 'siteWording';
            \Utsuri\PortableCms\Conventional\Content\SiteWording::assertShape($document['siteWording'], (string) ($document['profile']['id'] ?? ''));
        }
        if (array_key_exists('designRegistries', $document)) {
            $requiredKeys[] = 'designRegistries';
            $design = \Utsuri\PortableCms\Conventional\Design\RegistryDocument::fromDocument($document['designRegistries']);
            if ($design->profileId() !== ($document['profile']['id'] ?? null)) {
                throw new \InvalidArgumentException('Export design profile differs');
            }
        }
        self::exactKeys($document, $requiredKeys);
        if (
            ($document['formatVersion'] ?? null) !== 1
            || !is_string($document['exportId'] ?? null)
            || !self::id($document['exportId'])
            || !is_string($document['createdAt'] ?? null)
            || !self::rfc3339($document['createdAt'])
        ) {
            throw new \InvalidArgumentException('Normalized export identity is invalid');
        }
        self::profile($document['profile'] ?? null);
        if (array_key_exists('websitePublication', $document)) {
            \Utsuri\PortableCms\Conventional\Publication\WebsitePublication::from($document['websitePublication'])->assertScope($document['profile']['id'], $document['profile']['locales']);
        }
        if (array_key_exists('siteLanguages', $document)) {
            \Utsuri\PortableCms\Conventional\Content\SiteLanguages::exact($document['siteLanguages'], $document['profile']['id'], $document['profile']['locales']);
        }
        foreach ([
            'records' => EXPORT_MAX_RECORDS,
            'revisions' => EXPORT_MAX_REVISIONS,
            'relationships' => EXPORT_MAX_RELATIONSHIPS,
            'media' => EXPORT_MAX_MEDIA,
        ] as $key => $maximum) {
            if (!is_array($document[$key] ?? null) || !array_is_list($document[$key]) || count($document[$key]) > $maximum) {
                throw new \InvalidArgumentException('Normalized export collection is invalid');
            }
        }

        ExportGraph::validate($document, static fn(string $kind): iterable => $document[$kind], ['records' => EXPORT_MAX_RECORDS, 'revisions' => EXPORT_MAX_REVISIONS, 'relationships' => EXPORT_MAX_RELATIONSHIPS, 'media' => EXPORT_MAX_MEDIA]);
    }

    private static function profile(mixed $profile): void
    {
        if (!is_array($profile) || array_is_list($profile)) {
            throw new \InvalidArgumentException('Normalized export profile is invalid');
        }
        self::exactKeys($profile, ['id', 'version', 'locales']);
        if (
            !is_string($profile['id'] ?? null)
            || !self::canonicalName($profile['id'])
            || !is_string($profile['version'] ?? null)
            || strlen($profile['version']) > 64
            || preg_match('/\A[0-9]+\.[0-9]+\.[0-9]+(?:[-+][A-Za-z0-9.-]+)?\z/', $profile['version']) !== 1
            || !is_array($profile['locales'] ?? null)
            || !array_is_list($profile['locales'])
            || $profile['locales'] === []
            || count($profile['locales']) > 16
        ) {
            throw new \InvalidArgumentException('Normalized export profile is invalid');
        }
        $seen = [];
        foreach ($profile['locales'] as $locale) {
            if (
                !is_string($locale)
                || preg_match('/\A[a-z]{2,3}(?:-[A-Z]{2})?\z/', $locale) !== 1
                || isset($seen[$locale])
            ) {
                throw new \InvalidArgumentException('Normalized export locales are invalid');
            }
            $seen[$locale] = true;
        }
    }

    /** Missing pointers in legacy exports mean the then-current published revision.
     * @param array<string, mixed> $record
     */
    public static function publishedRevisionId(array $record): ?string
    {
        return $record['workflowState'] === 'published' ? ($record['publishedRevisionId'] ?? $record['currentRevisionId']) : null;
    }

    private static function record(mixed $record): void
    {
        if (!is_array($record) || array_is_list($record)) {
            throw new \InvalidArgumentException('Normalized export record is invalid');
        }
        if (array_key_exists('publishedRevisionId', $record)) {
            if (($record['workflowState'] ?? null) === 'published' ? !self::stringId($record, 'publishedRevisionId') : $record['publishedRevisionId'] !== null) {
                throw new \InvalidArgumentException('Normalized export published revision is invalid');
            }
        }
        self::exactKeys($record, [
            ...(array_key_exists('publishedRevisionId', $record) ? ['publishedRevisionId'] : []),
            'id', 'typeId', 'workflowState', 'currentRevisionId', 'currentRevisionNumber',
            'createdBy', 'createdAt', 'updatedBy', 'updatedAt',
        ]);
        if (
            !self::stringId($record, 'id')
            || !self::stringId($record, 'currentRevisionId')
            || !self::stringId($record, 'createdBy')
            || !self::stringId($record, 'updatedBy')
            || !is_string($record['typeId'] ?? null)
            || !self::canonicalName($record['typeId'])
            || !in_array($record['workflowState'] ?? null, ['draft', 'published', 'archived'], true)
            || !is_int($record['currentRevisionNumber'] ?? null)
            || $record['currentRevisionNumber'] < 1
            || !self::timestampValue($record, 'createdAt')
            || !self::timestampValue($record, 'updatedAt')
        ) {
            throw new \InvalidArgumentException('Normalized export record is invalid');
        }
    }

    private static function revisionEntry(mixed $revision, bool $streamed = false): void
    {
        if (!is_array($revision) || array_is_list($revision)) {
            throw new \InvalidArgumentException('Normalized export revision is invalid');
        }
        self::exactKeys($revision, [
            'id', 'recordId', 'revisionNumber', 'profileId', 'profileVersion', 'body', 'createdBy', 'createdAt',
            ...(array_key_exists('siteSequence', $revision) ? ['siteSequence'] : []),
        ]);
        if (array_key_exists('siteSequence', $revision) && \Utsuri\PortableCms\Conventional\Database\SiteSequence::value($revision['siteSequence']) === 0) {
            throw new \InvalidArgumentException('Revision site sequence must be positive');
        }
        if (
            !self::stringId($revision, 'id')
            || !self::stringId($revision, 'recordId')
            || !self::stringId($revision, 'createdBy')
            || !is_int($revision['revisionNumber'] ?? null)
            || $revision['revisionNumber'] < 1
            || !is_string($revision['profileId'] ?? null)
            || !self::canonicalName($revision['profileId'])
            || !is_string($revision['profileVersion'] ?? null)
            || strlen($revision['profileVersion']) > 64
            || preg_match('/\A[0-9]+\.[0-9]+\.[0-9]+(?:[-+][A-Za-z0-9.-]+)?\z/', $revision['profileVersion']) !== 1
            || !is_array($revision['body'] ?? null)
            || array_is_list($revision['body'])
            || !self::jsonValue($revision['body'], 0, $streamed ? 5000 : 1000, $streamed ? 16 : 8)
            || !self::timestampValue($revision, 'createdAt')
        ) {
            throw new \InvalidArgumentException('Normalized export revision is invalid');
        }
    }

    private static function relationship(mixed $relationship): void
    {
        if (!is_array($relationship) || array_is_list($relationship)) {
            throw new \InvalidArgumentException('Normalized export relationship is invalid');
        }
        self::exactKeys($relationship, [
            'recordId', 'revisionId', 'fieldId', 'kind', 'targetRecordId', 'position',
        ]);
        if (
            !self::stringId($relationship, 'recordId')
            || !self::stringId($relationship, 'revisionId')
            || !self::stringId($relationship, 'targetRecordId')
            || !is_string($relationship['fieldId'] ?? null)
            || preg_match('/\A[a-z][A-Za-z0-9]*(?:[-_][a-z0-9]+)*\z/', $relationship['fieldId']) !== 1
            || !in_array($relationship['kind'] ?? null, ['record', 'taxonomy'], true)
            || !is_int($relationship['position'] ?? null)
            || $relationship['position'] < 0
            || $relationship['position'] > 65_535
        ) {
            throw new \InvalidArgumentException('Normalized export relationship is invalid');
        }
    }

    private static function media(mixed $media): void
    {
        if (!is_array($media) || array_is_list($media)) {
            throw new \InvalidArgumentException('Normalized export media is invalid');
        }
        if (array_key_exists('metadataRevision', $media) && (!is_int($media['metadataRevision']) || $media['metadataRevision'] < 1)) {
            throw new \InvalidArgumentException('Normalized export media revision is invalid');
        }
        if (array_key_exists('text', $media) && \Utsuri\PortableCms\Conventional\Media\MediaText::from($media['text'])->document() != $media['text']) {
            throw new \InvalidArgumentException('Normalized export media text would require a lossy mapping');
        }
        if (array_key_exists('focalPoint', $media) && \Utsuri\PortableCms\Conventional\Media\FocalPoint::from($media['focalPoint'])->document() != $media['focalPoint']) {
            throw new \InvalidArgumentException('Normalized export focal point would require a lossy mapping');
        }
        self::exactKeys(array_diff_key($media, ['text' => true, 'metadataRevision' => true, 'focalPoint' => true]), [
            'id', 'ownerRecordId', 'status', 'originalName', 'declaredType', 'detectedType',
            'width', 'height', 'original', 'derivatives', 'createdBy', 'createdAt', 'updatedAt',
        ]);
        if (
            !self::stringId($media, 'id')
            || ($media['ownerRecordId'] !== null && !self::stringId($media, 'ownerRecordId'))
            || ($media['ownerRecordId'] === null && !in_array($media['declaredType'] ?? null, ['image/jpeg', 'image/png', 'image/webp'], true))
            || !self::stringId($media, 'createdBy')
            || !in_array($media['status'] ?? null, ['uploaded', 'processing', 'ready', 'needs_attention'], true)
            || !is_string($media['originalName'] ?? null)
            || $media['originalName'] === ''
            || mb_strlen($media['originalName']) > 180
            || preg_match('/[\x00-\x1f\x7f]/u', $media['originalName']) === 1
            || !self::mimeOrNull($media['declaredType'] ?? null, false)
            || !self::mimeOrNull($media['detectedType'] ?? null, true)
            || !self::positiveIntOrNull($media['width'] ?? null)
            || !self::positiveIntOrNull($media['height'] ?? null)
            || (($media['width'] === null) !== ($media['height'] === null))
            || !is_array($media['original'] ?? null)
            || array_is_list($media['original'])
            || !is_array($media['derivatives'] ?? null)
            || !array_is_list($media['derivatives'])
            || !self::timestampValue($media, 'createdAt')
            || !self::timestampValue($media, 'updatedAt')
        ) {
            throw new \InvalidArgumentException('Normalized export media is invalid');
        }
        self::blob($media['original']);
        if ($media['ownerRecordId'] === null && (!in_array($media['original']['mime'], ['image/jpeg', 'image/png', 'image/webp'], true)
            || ($media['detectedType'] !== null && !in_array($media['detectedType'], ['image/jpeg', 'image/png', 'image/webp'], true)))) {
            throw new \InvalidArgumentException('Website-owned media must be images');
        }
        $variants = [];
        foreach ($media['derivatives'] as $derivative) {
            if (!is_array($derivative) || array_is_list($derivative)) {
                throw new \InvalidArgumentException('Normalized export derivative is invalid');
            }
            self::exactKeys($derivative, [
                'variant', 'fileName', 'mime', 'width', 'height', 'byteSize', 'sha256',
                'processorId', 'processorVersion', 'recipeVersion', 'parameters', 'createdAt',
            ]);
            if (
                !is_string($derivative['variant'] ?? null)
                || strlen($derivative['variant']) > 160
                || preg_match('/\A[a-z0-9][a-z0-9]*(?:[-_][a-z0-9]+)*\z/', $derivative['variant']) !== 1
                || isset($variants[$derivative['variant']])
                || !is_string($derivative['fileName'] ?? null)
                || preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.-]{0,179}\z/', $derivative['fileName']) !== 1
                || !self::mimeOrNull($derivative['mime'] ?? null, false)
                || !is_int($derivative['width'] ?? null)
                || $derivative['width'] < 1
                || !is_int($derivative['height'] ?? null)
                || $derivative['height'] < 1
                || !self::blobFields($derivative)
                || !is_string($derivative['processorId'] ?? null)
                || !self::canonicalName($derivative['processorId'])
                || !is_string($derivative['processorVersion'] ?? null)
                || $derivative['processorVersion'] === ''
                || mb_strlen($derivative['processorVersion']) > 180
                || preg_match('/[\x00-\x1f\x7f]/u', $derivative['processorVersion']) === 1
                || !is_int($derivative['recipeVersion'] ?? null)
                || $derivative['recipeVersion'] < 1
                || !is_array($derivative['parameters'] ?? null)
                || array_is_list($derivative['parameters'])
                || !self::jsonValue($derivative['parameters'], 0)
                || !self::timestampValue($derivative, 'createdAt')
            ) {
                throw new \InvalidArgumentException('Normalized export derivative is invalid');
            }
            $variants[$derivative['variant']] = true;
        }
    }

    /** @param array<string, mixed> $blob */
    private static function blob(array $blob): void
    {
        self::exactKeys($blob, ['mime', 'byteSize', 'sha256']);
        if (!self::mimeOrNull($blob['mime'] ?? null, false) || !self::blobFields($blob)) {
            throw new \InvalidArgumentException('Normalized export blob identity is invalid');
        }
    }

    /** @param array<string, mixed> $value */
    private static function blobFields(array $value): bool
    {
        return is_int($value['byteSize'] ?? null)
            && $value['byteSize'] > 0
            && is_string($value['sha256'] ?? null)
            && preg_match('/\A[a-f0-9]{64}\z/', $value['sha256']) === 1;
    }

    private static function jsonValue(mixed $value, int $depth, int $maximumItems = 1000, int $maximumDepth = 8): bool
    {
        if ($depth > $maximumDepth || (is_float($value) && !is_finite($value)) || is_resource($value) || is_object($value)) {
            return false;
        }
        if ($value === null || is_string($value) || is_int($value) || is_float($value) || is_bool($value)) {
            return !is_string($value) || strlen($value) <= 100_000;
        }
        if (!is_array($value) || count($value) > $maximumItems) {
            return false;
        }
        foreach ($value as $key => $item) {
            if (!self::jsonValue($item, $depth + 1, $maximumItems, $maximumDepth)) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param array<string, mixed> $value
     * @param list<string> $expected
     */
    private static function exactKeys(array $value, array $expected): void
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new \InvalidArgumentException('Normalized export object contains unknown or missing properties');
        }
    }

    /** @param array<string, mixed> $value */
    private static function stringId(array $value, string $key): bool
    {
        return is_string($value[$key] ?? null) && self::id($value[$key]);
    }

    private static function id(string $value): bool
    {
        return preg_match('/\A[a-f0-9]{32}\z/', $value) === 1;
    }

    private static function canonicalName(string $value): bool
    {
        return strlen($value) <= 160
            && preg_match('/\A[a-z][a-z0-9]*(?:[-_][a-z0-9]+)*\z/', $value) === 1;
    }

    /** @param array<string, mixed> $value */
    private static function timestampValue(array $value, string $key): bool
    {
        return is_string($value[$key] ?? null) && self::rfc3339($value[$key]);
    }

    private static function rfc3339(string $value): bool
    {
        if (preg_match(
            '/\A(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(?:\.\d{1,6})?Z\z/',
            $value,
            $match,
        ) !== 1) {
            return false;
        }
        return checkdate((int) $match[2], (int) $match[3], (int) $match[1])
            && (int) $match[4] <= 23
            && (int) $match[5] <= 59
            && (int) $match[6] <= 59;
    }

    private static function mimeOrNull(mixed $value, bool $nullable): bool
    {
        return ($nullable && $value === null)
            || (is_string($value) && preg_match('/\A[a-z0-9.+-]+\/[a-z0-9.+-]+\z/', $value) === 1);
    }

    private static function positiveIntOrNull(mixed $value): bool
    {
        return $value === null || (is_int($value) && $value > 0);
    }
}
