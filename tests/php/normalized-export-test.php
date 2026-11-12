<?php

declare(strict_types=1);

use const Utsuri\PortableCms\Conventional\Export\EXPORT_MAX_JSON_BYTES;
use const Utsuri\PortableCms\Conventional\Export\EXPORT_MAX_MEDIA;
use const Utsuri\PortableCms\Conventional\Export\EXPORT_MAX_RECORDS;
use const Utsuri\PortableCms\Conventional\Export\EXPORT_MAX_RELATIONSHIPS;
use const Utsuri\PortableCms\Conventional\Export\EXPORT_MAX_REVISIONS;

use Utsuri\PortableCms\Conventional\Export\ExportCapacity;
use Utsuri\PortableCms\Conventional\Export\NormalizedExport;
use Utsuri\PortableCms\Conventional\I18n\Translator;
use Utsuri\PortableCms\Conventional\View\ExportCapacityView;
use Utsuri\PortableCms\Conventional\View\Pages;

$tests['export limits are discoverable before any content and translated without scripts'] = static function (): void {
    $page = Pages::exportPage('csrf');
    assertTrueValue(str_contains($page, '<details id="export-capacity"><summary>Export and restore limits</summary>'), 'the ordinary empty export page exposes a native disclosure');
    foreach (['500 records', '5,000 retained versions', '16,000 current relationships', '32 MiB of JSON', '2,000 records', '20,000 retained versions', '32,000 current relationships', '512 MiB of content chunks', '20,000 derivatives', 'never truncated or pruned', 'sixteen times the JSON file size', 'uploaded fonts', 'separately verified installation backup'] as $fact) {
        assertTrueValue(str_contains($page, $fact), 'the administrator can discover ' . $fact);
    }
    foreach (['ca', 'de', 'en', 'es', 'fr'] as $locale) {
        $t = Translator::load($locale);
        $markup = ExportCapacityView::render($t);
        $document = new DOMDocument();
        assertTrueValue($document->loadHTML('<!doctype html><html><head><meta charset="UTF-8"><title>Export</title></head><body>' . $markup . '</body></html>', LIBXML_NOERROR | LIBXML_NOWARNING), 'native help is parseable in ' . $locale);
        $xpath = new DOMXPath($document);
        $summaries = $xpath->query('//details[@id="export-capacity"]/summary');
        assertTrueValue($summaries !== false && $summaries->length === 1, 'one keyboard-operable native summary');
        assertTrueValue(str_contains($markup, $t->html('export.capacity.details')), 'the summary uses the chosen interface language');
        assertTrueValue(!str_contains($markup, '<script') && !str_contains($markup, 'hidden') && !str_contains($markup, 'http'), 'disclosure has no script, hidden help or external documentation dependency');
    }
};

$tests['documented export collection bounds match PHP and the legacy schema'] = static function (): void {
    $root = dirname(__DIR__, 2);
    $schema = json_decode((string) file_get_contents($root . '/schemas/portable-cms-export-v1.schema.json'), true, 64, JSON_THROW_ON_ERROR);
    $documentation = (string) file_get_contents($root . '/docs/development/export-capacity.md');
    foreach ([
        ['records', 'Records, including drafts, archives, terms and settings', EXPORT_MAX_RECORDS],
        ['revisions', 'All retained record revisions', EXPORT_MAX_REVISIONS],
        ['relationships', 'Current relationship projections', EXPORT_MAX_RELATIONSHIPS],
        ['media', 'Media records', EXPORT_MAX_MEDIA],
    ] as [$collection, $label, $legacyLimit]) {
        assertSameValue($legacyLimit, $schema['properties'][$collection]['maxItems'], 'legacy schema agrees with PHP: ' . $collection);
        $row = '| ' . $label . ' | ' . number_format($legacyLimit) . ' | ' . number_format(ExportCapacity::limit($collection)) . ' |';
        assertTrueValue(str_contains($documentation, $row), 'the current matrix agrees with both contracts: ' . $collection);
    }
    $byteRow = '| Serialized content | ' . intdiv(EXPORT_MAX_JSON_BYTES, 1_048_576) . ' MiB envelope | ' . intdiv(ExportCapacity::PACKAGE_BYTES, 1_048_576) . ' MiB aggregate JSONL |';
    assertTrueValue(str_contains($documentation, $byteRow), 'documented JSON byte limits agree with PHP');
    assertTrueValue(str_contains((string) file_get_contents($root . '/README.md'), '(docs/development/export-capacity.md#supported-envelope)'), 'root README links directly to the authoritative matrix');
};

/** @return array<string, mixed> */
function normalizedExportFixture(): array
{
    $recordId = str_repeat('1', 32);
    $revisionId = str_repeat('2', 32);
    $targetRecordId = str_repeat('3', 32);
    $targetRevisionId = str_repeat('4', 32);
    $actorId = str_repeat('5', 32);
    return [
        'formatVersion' => 1,
        'exportId' => str_repeat('6', 32),
        'createdAt' => '2026-10-01T09:15:30.123456Z',
        'profile' => [
            'id' => 'portable-gate-zero',
            'version' => '1.1.0',
            'locales' => ['ca', 'es', 'en'],
        ],
        'records' => [
            [
                'id' => $recordId,
                'typeId' => 'article',
                'workflowState' => 'draft',
                'currentRevisionId' => $revisionId,
                'currentRevisionNumber' => 1,
                'createdBy' => $actorId,
                'createdAt' => '2026-10-01T09:00:00.000000Z',
                'updatedBy' => $actorId,
                'updatedAt' => '2026-10-01T09:00:00.000000Z',
            ],
            [
                'id' => $targetRecordId,
                'typeId' => 'portfolio-entry',
                'workflowState' => 'draft',
                'currentRevisionId' => $targetRevisionId,
                'currentRevisionNumber' => 1,
                'createdBy' => $actorId,
                'createdAt' => '2026-10-01T08:00:00Z',
                'updatedBy' => $actorId,
                'updatedAt' => '2026-10-01T08:00:00Z',
            ],
        ],
        'revisions' => [
            [
                'id' => $revisionId,
                'recordId' => $recordId,
                'revisionNumber' => 1,
                'profileId' => 'portable-gate-zero',
                'profileVersion' => '1.1.0',
                'body' => ['title' => 'Portable article', 'featuredEntry' => $targetRecordId],
                'createdBy' => $actorId,
                'createdAt' => '2026-10-01T09:00:00.000000Z',
            ],
            [
                'id' => $targetRevisionId,
                'recordId' => $targetRecordId,
                'revisionNumber' => 1,
                'profileId' => 'portable-gate-zero',
                'profileVersion' => '1.1.0',
                'body' => ['title' => 'Portable entry', 'summary' => 'A fixture.'],
                'createdBy' => $actorId,
                'createdAt' => '2026-10-01T08:00:00Z',
            ],
        ],
        'relationships' => [
            [
                'recordId' => $recordId,
                'revisionId' => $revisionId,
                'fieldId' => 'featuredEntry',
                'kind' => 'record',
                'targetRecordId' => $targetRecordId,
                'position' => 0,
            ],
        ],
        'media' => [
            [
                'id' => str_repeat('7', 32),
                'ownerRecordId' => $targetRecordId,
                'status' => 'ready',
                'originalName' => 'courtyard.png',
                'declaredType' => 'image/png',
                'detectedType' => 'image/png',
                'width' => 1200,
                'height' => 800,
                'original' => [
                    'mime' => 'image/png',
                    'byteSize' => 4096,
                    'sha256' => str_repeat('a', 64),
                ],
                'derivatives' => [
                    [
                        'variant' => '960-webp',
                        'fileName' => 'courtyard-960-webp.webp',
                        'mime' => 'image/webp',
                        'width' => 960,
                        'height' => 640,
                        'byteSize' => 2048,
                        'sha256' => str_repeat('b', 64),
                        'processorId' => 'imagick',
                        'processorVersion' => 'ImageMagick 7.1.1',
                        'recipeVersion' => 1,
                        'parameters' => ['outputFormat' => 'webp', 'maximumWidth' => 960],
                        'createdAt' => '2026-10-01T09:05:00.000000Z',
                    ],
                ],
                'createdBy' => $actorId,
                'createdAt' => '2026-10-01T09:01:00.000000Z',
                'updatedAt' => '2026-10-01T09:05:00.000000Z',
            ],
        ],
    ];
}

/** @param callable(): mixed $operation */
function assertInvalidNormalizedExport(callable $operation, string $message): void
{
    try {
        $operation();
        throw new RuntimeException($message);
    } catch (InvalidArgumentException) {
        return;
    }
}

$tests['normalized export preserves portable history relationships and media provenance'] = static function (): void {
    $export = new NormalizedExport(normalizedExportFixture());
    $json = $export->json();
    $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    assertSameValue(1, $decoded['formatVersion'] ?? null, 'export format should be explicitly versioned');
    assertSameValue(2, count($decoded['revisions'] ?? []), 'all immutable fixture revisions should survive');
    assertSameValue('featuredEntry', $decoded['relationships'][0]['fieldId'] ?? null, 'current relationship should survive');
    assertSameValue('960-webp', $decoded['media'][0]['derivatives'][0]['variant'] ?? null, 'numeric derivative variant should be valid');
    foreach (['objectKey', 'originalKey', 'absolutePath', 'publicUrl', 'secret', 'token'] as $forbidden) {
        assertTrueValue(!str_contains($json, $forbidden), 'export must not expose provider field ' . $forbidden);
    }
};

$tests['normalized export retains finite decimal content and recipes but rejects non JSON numbers'] = static function (): void {
    $document = normalizedExportFixture();
    $document['revisions'][0]['body']['measurement'] = 37.5;
    $document['media'][0]['derivatives'][0]['parameters']['quality'] = 83.5;
    $json = (new NormalizedExport($document))->json();
    $decoded = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
    assertSameValue(37.5, $decoded['revisions'][0]['body']['measurement'], 'declared decimal content is portable JSON');
    assertSameValue(83.5, $decoded['media'][0]['derivatives'][0]['parameters']['quality'], 'recipe facts retain finite decimals');
    foreach ([INF, NAN] as $invalid) {
        $document['revisions'][0]['body']['measurement'] = $invalid;
        assertInvalidNormalizedExport(static fn() => new NormalizedExport($document), 'Non-JSON numeric value was accepted');
    }
};

$tests['normalized export rejects broken revision and relationship graphs'] = static function (): void {
    $missingRevision = normalizedExportFixture();
    $missingRevision['revisions'] = [];
    assertInvalidNormalizedExport(
        static fn() => new NormalizedExport($missingRevision),
        'record without its current revision unexpectedly passed',
    );

    $staleRelationship = normalizedExportFixture();
    $staleRelationship['relationships'][0]['revisionId'] = $staleRelationship['records'][1]['currentRevisionId'];
    assertInvalidNormalizedExport(
        static fn() => new NormalizedExport($staleRelationship),
        'relationship attributed to another current revision unexpectedly passed',
    );
};

$tests['normalized export rejects unknown storage fields and invalid timestamps'] = static function (): void {
    $providerField = normalizedExportFixture();
    $providerField['media'][0]['original']['objectKey'] = 'originals/provider-key.png';
    assertInvalidNormalizedExport(
        static fn() => new NormalizedExport($providerField),
        'provider object key unexpectedly passed',
    );

    $invalidTimestamp = normalizedExportFixture();
    $invalidTimestamp['createdAt'] = '2026-02-31T25:61:61Z';
    assertInvalidNormalizedExport(
        static fn() => new NormalizedExport($invalidTimestamp),
        'invalid calendar timestamp unexpectedly passed',
    );
};

$tests['normalized export retains publication provenance and optional SEO values without adopting a destination URL'] = static function (): void {
    $document = normalizedExportFixture();
    $source = \Utsuri\PortableCms\Conventional\Compiler\PublicUrlContext::from(['formatVersion' => 1, 'baseUrl' => 'https://source.example/portfolio']);
    $destination = \Utsuri\PortableCms\Conventional\Compiler\PublicUrlContext::from(['formatVersion' => 1, 'baseUrl' => 'https://destination.example']);
    $document['sourcePublicUrlContext'] = $source->document();
    $document['publishedSlugHistory'] = \Utsuri\PortableCms\Conventional\Compiler\PublishedSlugHistory::afterVerifiedActivation(
        new \Utsuri\PortableCms\Conventional\Compiler\RouteDiscovery($source, [new \Utsuri\PortableCms\Conventional\Compiler\DiscoveryRoute('/en/old-article/', 'en', 'record:1')]),
        $document['profile']['id'],
        publicationReceiptFixture(),
    )->document();
    $metadata = ['searchTitle' => 'Localized title', 'description' => 'Page-specific copy', 'excludeFromSearch' => true, 'sharingImage' => [$document['media'][0]['id']]];
    $document['revisions'][0]['body']['searchSharing'] = ['en' => $metadata];
    $export = new NormalizedExport($document);
    \Utsuri\PortableCms\Conventional\JsonSchemaValidator::validateJson($export->json(), (string) file_get_contents(dirname(__DIR__, 2) . '/schemas/portable-cms-export-v1.schema.json'));
    $rehydrated = new NormalizedExport(json_decode($export->json(), true, flags: JSON_THROW_ON_ERROR));
    assertSameValue($source->checksum(), $rehydrated->sourcePublicUrlContext()?->checksum(), 'source publication identity survives normalized serialization');
    assertSameValue($metadata, $rehydrated->document['revisions'][0]['body']['searchSharing']['en'], 'portable metadata and media references survive unchanged');
    assertSameValue($document['publishedSlugHistory'], $rehydrated->document['publishedSlugHistory'], 'verified route history is retained as provenance without destination activation');
    assertSameValue('https://destination.example/en/', $destination->route('/en/'), 'reading source provenance has no configuration side effects');
    assertSameValue(null, (new NormalizedExport(normalizedExportFixture()))->sourcePublicUrlContext(), 'older exports without source URLs remain accepted');
    foreach ([null, ['formatVersion' => 1, 'baseUrl' => 'http://unsafe.example'], ['formatVersion' => 1, 'baseUrl' => 'https://studio.example', 'secret' => 'forbidden']] as $bad) {
        $document['sourcePublicUrlContext'] = $bad;
        assertInvalidNormalizedExport(static fn() => new NormalizedExport($document), 'Unsafe source context accepted');
    }
};
