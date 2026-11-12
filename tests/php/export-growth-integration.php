<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Content\ProfilePackage;
use Utsuri\PortableCms\Conventional\Database\MigrationRunner;
use Utsuri\PortableCms\Conventional\Export\ChunkedPackageService;
use Utsuri\PortableCms\Conventional\Export\ExportCapacity;
use Utsuri\PortableCms\Conventional\Export\MariaDbExportRepository;
use Utsuri\PortableCms\Conventional\Export\MariaDbExportRows;
use Utsuri\PortableCms\Conventional\Export\NormalizedExport;
use Utsuri\PortableCms\Conventional\Identity\AdministratorBootstrap;
use Utsuri\PortableCms\Conventional\Identity\SystemClock;
use Utsuri\PortableCms\Conventional\Identity\SystemRandomSource;
use Utsuri\PortableCms\Conventional\Preview\MariaDbPreviewRepository;
use Utsuri\PortableCms\Conventional\Preview\ProfilePreviewCompiler;
use Utsuri\PortableCms\Conventional\Restore\FilesystemRestoreStore;
use Utsuri\PortableCms\Conventional\Restore\MariaDbRestoreRepository;
use Utsuri\PortableCms\Conventional\Restore\MariaDbRestoreWriter;
use Utsuri\PortableCms\Conventional\Restore\RestorePlan;
use Utsuri\PortableCms\Conventional\Restore\RestoreService;

require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/bootstrap.php';
require_once __DIR__ . '/assertions.php';
require_once __DIR__ . '/restore-fixture.php';

/** Measure the real transaction owner, not a guessed fraction of total runtime. */
final class GrowthMeasuredPdo extends PDO
{
    private int $began = 0;
    public float $lastTransactionMs = 0;
    public ?Closure $afterSnapshot = null;

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $result = $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs);
        if ($this->afterSnapshot !== null && str_contains($query, 'MAX(OCTET_LENGTH(body_json))')) {
            $callback = $this->afterSnapshot;
            $this->afterSnapshot = null;
            $callback();
        }
        return $result;
    }

    public function beginTransaction(): bool
    {
        $result = parent::beginTransaction();
        $this->began = hrtime(true);
        return $result;
    }

    public function commit(): bool
    {
        $result = parent::commit();
        $this->lastTransactionMs = (hrtime(true) - $this->began) / 1_000_000;
        return $result;
    }
}

/** @param list<string> $migrations
 * @return array{GrowthMeasuredPdo, string, string}
 */
function growthDatabase(string $name, array $migrations): array
{
    $dsn = getenv($name);
    if (!is_string($dsn) || $dsn === '') {
        throw new RuntimeException('Isolated growth database required');
    }
    $pdo = new GrowthMeasuredPdo($dsn, 'portable', 'integration-secret', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
    (new MigrationRunner($pdo))->apply($migrations);
    $actor = (new AdministratorBootstrap($pdo, new SystemClock(), new SystemRandomSource()))->create(strtolower($name) . '@example.test', 'Growth acceptance', 'isolated growth acceptance password');
    return [$pdo, $actor, $dsn];
}

/** @return array{float, int} */
function growthMeasure(string $phase, Closure $operation): array
{
    gc_collect_cycles();
    memory_reset_peak_usage();
    $start = hrtime(true);
    $operation();
    $milliseconds = (hrtime(true) - $start) / 1_000_000;
    $peak = memory_get_peak_usage(true);
    fwrite(STDOUT, json_encode(['measurement' => $phase, 'milliseconds' => round($milliseconds, 2), 'peakPhpBytes' => $peak], JSON_THROW_ON_ERROR) . "\n");
    assertTrueValue($peak <= 256 * 1024 * 1024, 'the declared 256 MiB host budget bounds ' . $phase);
    assertTrueValue($milliseconds < 120_000, 'each measured processing invocation remains below 120 seconds: ' . $phase);
    return [$milliseconds, $peak];
}

/** @param iterable<array<string, mixed>> $rows */
function growthDigest(iterable $rows): string
{
    $hash = hash_init('sha256');
    foreach ($rows as $row) {
        hash_update($hash, json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
    }
    return hash_final($hash);
}

$root = dirname(__DIR__, 2);
$migrations = glob($root . '/engine/migrations/*.sql') ?: throw new RuntimeException('Migrations missing');
sort($migrations, SORT_STRING);
[$source, $sourceActor, $sourceDsn] = growthDatabase('CMS_TEST_GROWTH_SOURCE_DSN', $migrations);
[$destination, $destinationActor] = growthDatabase('CMS_TEST_GROWTH_DESTINATION_DSN', $migrations);
$profile = ProfilePackage::load($root . '/profiles/reference-studio');
$clock = new SystemClock();
$random = new SystemRandomSource();
$state = sys_get_temp_dir() . '/portable-growth-acceptance-' . bin2hex(random_bytes(8));
mkdir($state, 0700);
foreach (['source', 'destination', 'package'] as $name) {
    mkdir($state . '/' . $name, 0700);
}

try {
    $document = RestoreTestFixture::document($profile);
    // This scale fixture must also build draft-inclusive previews. The small legacy fixture
    // deliberately keeps this translation missing and separately proves incomplete-content restore.
    $recordTypes = array_column($document['records'], 'typeId', 'id');
    foreach ($document['revisions'] as &$revision) {
        if ($recordTypes[$revision['recordId']] === 'project' && $revision['body']['slug']['en'] === '') {
            $revision['body']['slug']['en'] = 'light-workshop';
        }
    }
    unset($revision);
    $snapshot = json_decode((string) file_get_contents($profile->root . '/fixtures/snapshot.json'), true, 64, JSON_THROW_ON_ERROR);
    $mediaText = array_column($snapshot['media'], 'text', 'id');
    $mediaVariants = array_column($snapshot['media'], 'variant', 'id');
    foreach ($document['media'] as &$media) {
        $media['text'] = $mediaText[$media['id']];
        $media['metadataRevision'] = 3;
        $media['focalPoint'] = ['x' => 20, 'y' => 75];
    }
    unset($media);
    $legacy = new NormalizedExport($document);
    $initial = new RestorePlan($legacy, hash('sha256', $legacy->json()), $profile, $clock, $random);
    $source->beginTransaction();
    (new MariaDbRestoreWriter($source))->write($initial, $sourceActor, $clock->now());
    $source->commit();
    foreach ($initial->files as $file) {
        $id = explode('/', $file->key)[1];
        $from = $profile->root . '/fixtures/media/' . $id . '-' . $mediaVariants[$id] . '.webp';
        assertSameValue($file->sha256, hash_file('sha256', $from), 'fictional byte identity');
        $to = $state . '/source/' . $file->key;
        if (!is_dir(dirname($to))) {
            mkdir(dirname($to), 0700, true);
        }
        copy($from, $to);
    }
    $template = array_values(array_filter($document['revisions'], static fn(array $row): bool => isset($row['body']['title'], $row['body']['slug']) && $row['body']['slug']['en'] === 'notes-on-patience'))[0] ?? null;
    if ($template === null) {
        foreach ($document['records'] as $record) {
            if ($record['typeId'] === 'article') {
                $template = $legacy->revision($record['currentRevisionId']);
                break;
            }
        }
    }
    if (!is_array($template)) {
        throw new RuntimeException('Multilingual article fixture required');
    }
    $remainingRecords = ExportCapacity::LIMITS['records'] - count($document['records']);
    $remainingRevisions = ExportCapacity::LIMITS['revisions'] - count($document['revisions']);
    $recordInsert = $source->prepare('INSERT INTO cms_records (id, type_id, workflow_state, published_revision_id, current_revision_id, current_revision_number, created_by, created_at, updated_by, updated_at) VALUES (:id, \'article\', :state, :published, :revision, :number, :actor, :created, :editor, :updated)');
    $revisionInsert = $source->prepare('INSERT INTO cms_record_revisions (id, record_id, revision_number, profile_id, profile_version, body_json, created_by, created_at, site_sequence) VALUES (:id, :record, :number, :profile, :version, :body, :actor, :created, :sequence)');
    $relationInsert = $source->prepare('INSERT INTO cms_record_relationship_index (record_id, revision_id, field_id, relation_kind, target_record_id, position) VALUES (:record, :revision, :field, :kind, :target, :position)');
    $sequence = count($document['revisions']);
    $source->beginTransaction();
    for ($index = 0; $index < $remainingRecords; $index++) {
        $id = hash('md5', 'growth-record:' . $index);
        $versions = intdiv($remainingRevisions, $remainingRecords) + ($index < $remainingRevisions % $remainingRecords ? 1 : 0);
        $current = hash('md5', $id . ':' . $versions);
        $body = $template['body'];
        foreach ($profile->manifest->locales as $locale) {
            $body['title'][$locale] = 'Growth ' . $index;
            $body['slug'][$locale] = 'growth-' . $index;
            $body['body'][$locale] = ['formatVersion' => 1, 'nodes' => [['type' => 'paragraph', 'children' => [['text' => 'Current saved paragraph.', 'marks' => []]]]]];
        }
        $recordInsert->execute(['id' => $id, 'state' => ['published', 'draft', 'archived'][$index % 3], 'published' => $index % 3 === 0 ? $current : null, 'revision' => $current, 'number' => $versions, 'actor' => $sourceActor, 'created' => '2026-10-19 10:00:00.000000', 'editor' => $sourceActor, 'updated' => '2026-10-19 10:00:00.000000']);
        for ($version = 1; $version <= $versions; $version++) {
            $versionBody = $body;
            if ($version !== $versions) {
                foreach ($profile->manifest->locales as $locale) {
                    $versionBody['body'][$locale] = ['formatVersion' => 1, 'nodes' => [
                        ['type' => 'paragraph', 'children' => [['text' => str_repeat(['ca' => 'Arquitectura, color i memòria. ', 'es' => 'Arquitectura, color y memoria. ', 'en' => 'Architecture, colour and memory. '][$locale], 240), 'marks' => ['italic']]]],
                        ['type' => 'paragraph', 'children' => [['text' => 'Retained version ' . $version . ' in ' . $locale . ' 🌿', 'marks' => ['bold']]]],
                    ]];
                }
            }
            $revisionInsert->execute(['id' => hash('md5', $id . ':' . $version), 'record' => $id, 'number' => $version, 'profile' => $profile->manifest->id, 'version' => $profile->manifest->version, 'body' => json_encode($versionBody, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'actor' => $sourceActor, 'created' => '2026-10-19 10:00:00.000000', 'sequence' => ++$sequence]);
        }
        foreach ($document['relationships'] as $relation) {
            if ($relation['recordId'] === $template['recordId']) {
                $relationInsert->execute(['record' => $id, 'revision' => $current, 'field' => $relation['fieldId'], 'kind' => $relation['kind'], 'target' => $relation['targetRecordId'], 'position' => $relation['position']]);
            }
        }
    }
    $source->prepare('UPDATE cms_site_sequence SET current_sequence = :sequence WHERE singleton_id = 1')->execute(['sequence' => $sequence]);
    $source->commit();
    unset($versionBody, $body, $snapshot, $initial);
    $capacity = (new MariaDbExportRepository($source))->capacity();
    assertSameValue(ExportCapacity::LIMITS['records'], $capacity->counts['records'], 'exercise the exact record boundary');
    assertSameValue(ExportCapacity::LIMITS['revisions'], $capacity->counts['revisions'], 'exercise the exact retained-history boundary');
    assertTrueValue($capacity->revisionBytes > 32 * 1024 * 1024, 'full bodies exceed the old JSON envelope, not just count constants');
    fwrite(STDOUT, json_encode(['fixture' => $capacity->counts, 'retainedBodyBytes' => $capacity->revisionBytes, 'currentBodyBytes' => $capacity->currentBytes], JSON_THROW_ON_ERROR) . "\n");
    $packageStore = new FilesystemRestoreStore($state . '/package');
    $sourceContext = \Utsuri\PortableCms\Conventional\Compiler\PublicUrlContext::from(['formatVersion' => 1, 'baseUrl' => 'https://source.example/growth']);
    $producer = new ChunkedPackageService($source, $profile, new FilesystemRestoreStore($state . '/source'), $packageStore, $clock, $random, $sourceContext);
    $concurrentId = null;
    $source->afterSnapshot = static function () use ($sourceDsn, $profile, $clock, $random, $template, $sourceActor, &$concurrentId): void {
        $other = new PDO($sourceDsn, 'portable', 'integration-secret', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
        $body = $template['body'];
        foreach ($profile->manifest->locales as $locale) {
            $body['title'][$locale] = 'Concurrent new entry';
            $body['slug'][$locale] = 'concurrent-new-entry';
        }
        $result = (new \Utsuri\PortableCms\Conventional\Content\ContentService(new \Utsuri\PortableCms\Conventional\Content\MariaDbRecordRepository($other), $profile->manifest, $clock, $random))->create('article', $body, $sourceActor);
        if (!$result instanceof \Utsuri\PortableCms\Conventional\Content\RecordSnapshot) {
            throw new RuntimeException('Concurrent ordinary editor save must succeed');
        }
        $concurrentId = $result->id;
    };
    growthMeasure('capture_and_first_file_batch', static function () use ($producer, $sourceActor, &$report): void {
        $report = $producer->run($sourceActor, 1);
    });
    fwrite(STDOUT, json_encode(['measurement' => 'source_snapshot_transaction', 'milliseconds' => round($source->lastTransactionMs, 2)], JSON_THROW_ON_ERROR) . "\n");
    assertSameValue('packaging', $report['status'], 'a bounded partial package is not successful completion');
    assertSameValue(ExportCapacity::LIMITS['records'], $report['records'], 'capture retains the consistent view from before the concurrent save');
    assertSameValue(ExportCapacity::LIMITS['records'] + 1, (int) $source->query('SELECT COUNT(*) FROM cms_records')->fetchColumn(), 'capture never prunes or blocks the concurrent ordinary save');
    assertTrueValue(is_string($concurrentId), 'the concurrent snapshot seam was exercised');
    try {
        (new MariaDbPreviewRepository($source))->freezeSnapshot($profile->manifest->id, $profile->manifest->version, $profile->manifest->locales, $clock->now(), false);
        throw new RuntimeException('The preview count bound was ignored');
    } catch (\Utsuri\PortableCms\Conventional\Preview\PreviewPreflightFailure) {
    }
    assertTrueValue(!is_file($state . '/package/export.json'), 'no completion marker before all blobs');
    growthMeasure('resume_byte_packaging', static function () use ($producer, $sourceActor, &$report): void {
        $report = $producer->run($sourceActor, 64);
    });
    assertSameValue('packaged', $report['status'], 'the same frozen capture resumes without recapturing');
    $input = $packageStore->load($root . '/schemas/portable-cms-export-v1.schema.json');
    assertSameValue($sequence, $input->export->metadata()['siteSequence'], 'capture metadata shares the pre-edit read view');
    assertSameValue($sourceContext->document(), $input->export->metadata()['sourcePublicUrlContext'], 'configured public URL context remains portable provenance');
    assertSameValue($sequence + 1, (int) $source->query('SELECT current_sequence FROM cms_site_sequence WHERE singleton_id = 1')->fetchColumn(), 'the later ordinary save remains committed at the source');
    growthMeasure('complete_semantic_validation', static function () use ($input, $profile, $clock, $random, &$plan): void {
        $plan = new RestorePlan($input->export, $input->sha256, $profile, $clock, $random);
    });
    $restore = new RestoreService(new MariaDbRestoreRepository($destination, $migrations), new FilesystemRestoreStore($state . '/destination'), $clock);
    assertSameValue('compatible', $restore->dryRun($plan, $packageStore, $destinationActor)['status'], 'isolated dry run validates the complete package');
    $partial = $restore->apply($plan, $packageStore, $destinationActor, $input->sha256, 1);
    assertSameValue('preparing', $partial['status'], 'bounded file preparation remains incomplete');
    assertSameValue(0, (int) $destination->query('SELECT COUNT(*) FROM cms_records')->fetchColumn(), 'no partially authoritative content');
    $destination->exec("CREATE TRIGGER cms_growth_failure BEFORE INSERT ON cms_record_revisions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'injected restore failure'");
    try {
        $restore->apply($plan, $packageStore, $destinationActor, $input->sha256, 64);
        throw new RuntimeException('Injected SQL failure did not roll back');
    } catch (PDOException) {
        assertSameValue(0, (int) $destination->query('SELECT COUNT(*) FROM cms_records')->fetchColumn(), 'SQL failure rolls back every imported record');
    }
    $destination->exec('DROP TRIGGER cms_growth_failure');
    growthMeasure('atomic_content_commit', static function () use ($restore, $plan, $packageStore, $destinationActor, $input, &$restored): void {
        $restored = $restore->apply($plan, $packageStore, $destinationActor, $input->sha256, 64);
    });
    assertSameValue('restored', $restored['status'], 'only the complete commit reports restoration');
    fwrite(STDOUT, json_encode(['measurement' => 'destination_commit_transaction', 'milliseconds' => round($destination->lastTransactionMs, 2)], JSON_THROW_ON_ERROR) . "\n");
    assertSameValue('already_restored', $restore->apply($plan, $packageStore, $destinationActor, $input->sha256, 64)['status'], 'replay is idempotent');
    foreach (ExportCapacity::COLLECTIONS as $kind) {
        $sourceRows = $input->export->rows($kind);
        $destinationRows = (new MariaDbExportRows($destination))->rows($kind, ExportCapacity::limit($kind));
        assertSameValue(growthDigest($sourceRows), growthDigest($destinationRows), 'every retained normalized row remains equivalent: ' . $kind);
    }
    growthMeasure('fresh_verified_preview', static function () use ($destination, $profile, $state, $root, $clock): void {
        $context = \Utsuri\PortableCms\Conventional\Compiler\PublicUrlContext::from(['formatVersion' => 1, 'baseUrl' => 'https://destination.example/growth']);
        $projection = (new MariaDbPreviewRepository($destination, $profile->manifest->designRegistries, $profile->checksum, publicUrlContext: $context))->freezeSnapshot($profile->manifest->id, $profile->manifest->version, $profile->manifest->locales, $clock->now(), false);
        $built = (new ProfilePreviewCompiler($profile, $state . '/destination', $root . '/schemas'))->compile($projection, $state . '/preview');
        assertTrueValue(count($built->artifacts) > 1500, 'fresh CMS preview includes the large published selection, not a sampled page');
        new \Utsuri\PortableCms\Conventional\Publication\ReleaseArtifact((string) file_get_contents($state . '/preview/artifact.json'), $built, $context, $profile->manifest->id, (string) file_get_contents($root . '/schemas/portable-cms-artifact-v1.schema.json'));
        $html = (string) file_get_contents($state . '/preview/en/index.html');
        assertTrueValue(str_contains($html, 'https://destination.example/growth') && !str_contains($html, 'https://source.example/growth'), 'new verified pages use destination authority, not imported source configuration');
    });
    growthMeasure('fresh_draft_preview', static function () use ($destination, $profile, $state, $root, $clock): void {
        $projection = (new MariaDbPreviewRepository($destination, $profile->manifest->designRegistries, $profile->checksum))->freezeSnapshot($profile->manifest->id, $profile->manifest->version, $profile->manifest->locales, $clock->now(), true);
        $built = (new ProfilePreviewCompiler($profile, $state . '/destination', $root . '/schemas'))->compile($projection, $state . '/draft-preview');
        assertTrueValue(count($built->artifacts) > 3000, 'draft-inclusive CMS previews exceed the retired inventory cap');
    });
    $destination->beginTransaction();
    try {
        $destination->prepare('UPDATE cms_media SET text_json = :text')->execute(['text' => json_encode(str_repeat('x', ExportCapacity::SNAPSHOT_BYTES + 1), JSON_THROW_ON_ERROR)]);
        try {
            (new MariaDbPreviewRepository($destination))->freezeSnapshot($profile->manifest->id, $profile->manifest->version, $profile->manifest->locales, $clock->now(), false);
            throw new RuntimeException('Oversized media metadata reached allocation');
        } catch (\Utsuri\PortableCms\Conventional\Preview\PreviewPreflightFailure) {
        }
    } finally {
        $destination->rollBack();
    }
    fwrite(STDOUT, "PASS growing export: full count/byte boundary, bounded capture/package, all-history equivalence, interrupted staging, atomic rollback/recovery and verified preview.\n");
} finally {
    RestoreTestFixture::remove($state);
}
