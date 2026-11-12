<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Content\ContentService;
use Utsuri\PortableCms\Conventional\Content\MariaDbRecordRepository;
use Utsuri\PortableCms\Conventional\Content\ProfilePackage;
use Utsuri\PortableCms\Conventional\Database\MigrationRunner;
use Utsuri\PortableCms\Conventional\Export\ExportService;
use Utsuri\PortableCms\Conventional\Export\MariaDbExportRepository;
use Utsuri\PortableCms\Conventional\Export\NormalizedExport;
use Utsuri\PortableCms\Conventional\Identity\AdministratorBootstrap;
use Utsuri\PortableCms\Conventional\Identity\Clock;
use Utsuri\PortableCms\Conventional\Identity\SystemRandomSource;
use Utsuri\PortableCms\Conventional\Import\ContentImportService;
use Utsuri\PortableCms\Conventional\Import\ImportMediaTransfer;
use Utsuri\PortableCms\Conventional\Import\ImportRejected;
use Utsuri\PortableCms\Conventional\Import\MariaDbImportRepository;
use Utsuri\PortableCms\Conventional\Media\FilesystemMediaStore;
use Utsuri\PortableCms\Conventional\Media\ImagickRasterProcessor;
use Utsuri\PortableCms\Conventional\Media\MariaDbMediaJobRepository;
use Utsuri\PortableCms\Conventional\Media\MariaDbMediaLibrary;
use Utsuri\PortableCms\Conventional\Media\MariaDbUploadRepository;
use Utsuri\PortableCms\Conventional\Media\MediaJobRunner;
use Utsuri\PortableCms\Conventional\Media\MediaLibrary;
use Utsuri\PortableCms\Conventional\Restore\FilesystemRestoreStore;

require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/bootstrap.php';
require_once __DIR__ . '/assertions.php';
require_once __DIR__ . '/restore-fixture.php';
require_once __DIR__ . '/content-import-current-contracts.php';
require_once __DIR__ . '/single-line-rich-text-fixture.php';
require_once __DIR__ . '/vimeo-fixture.php';

final class ImportIntegrationClock implements Clock
{
    public function __construct(public DateTimeImmutable $current) {}
    public function now(): DateTimeImmutable
    {
        return $this->current;
    }
}

/** @param array<string, mixed> $document */
function importTestWrite(string $path, array $document): void
{
    file_put_contents($path, (new NormalizedExport($document))->json());
}

/** @return array<string, int> */
function importTestCounts(PDO $pdo): array
{
    $counts = [];
    foreach (['cms_records', 'cms_record_revisions', 'cms_record_relationship_index', 'cms_media', 'cms_jobs', 'cms_audit_events', 'cms_content_import_records', 'cms_content_import_media'] as $table) {
        $counts[$table] = (int) $pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
    }
    return $counts;
}

/** @param list<string> $arguments
 * @return array<string, mixed>
 */
function importTestOperator(array $arguments, int $expectedExit = 0): array
{
    $process = proc_open([PHP_BINARY, dirname(__DIR__, 2) . '/tools/conventional-import-content.php', ...$arguments], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Import operator failed to start');
    }
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    assertSameValue($expectedExit, proc_close($process), 'real operator exit: ' . $error);
    return json_decode((string) ($output ?: $error), true, 64, JSON_THROW_ON_ERROR);
}

$root = dirname(__DIR__, 2);
$dsn = getenv('CMS_TEST_IMPORT_DSN');
if (!is_string($dsn) || $dsn === '') {
    throw new RuntimeException('An isolated import database is required');
}
$pdo = new PDO($dsn, 'portable', 'integration-secret', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
$paths = glob($root . '/engine/migrations/*.sql');
if (!is_array($paths)) {
    throw new RuntimeException('Import test migrations missing');
}
sort($paths, SORT_STRING);
(new MigrationRunner($pdo))->apply($paths);
$clock = new ImportIntegrationClock(new DateTimeImmutable('2026-10-06T12:00:00Z'));
$random = new SystemRandomSource();
$actor = (new AdministratorBootstrap($pdo, $clock, $random))->create('import-admin@example.test', 'Destination import administrator', 'fictional import test password');
$package = ProfilePackage::load($root . '/profiles/reference-studio');
$state = sys_get_temp_dir() . '/portable-import-integration-' . bin2hex(random_bytes(8));
mkdir($state, 0700);
foreach (['package', 'package/blobs', 'media', 'public'] as $directory) {
    mkdir($state . '/' . $directory, 0700);
}
try {
    $document = RestoreTestFixture::document($package);
    $document['media'][0]['ownerRecordId'] = null;
    foreach ($document['media'] as &$sourceMedia) {
        $sourceMedia['text'] = ['formatVersion' => 1, 'decorative' => false, 'translations' => array_map(static fn(string $locale): array => ['locale' => $locale, 'alternativeText' => 'An imported photograph', 'caption' => 'Source caption', 'credit' => 'Source credit'], $package->manifest->locales)];
        $sourceMedia['focalPoint'] = ['x' => 25, 'y' => 75];
    }
    unset($sourceMedia);
    foreach ($document['records'] as $sourceRecord) {
        if ($sourceRecord['workflowState'] === 'draft') {
            foreach ($document['revisions'] as &$sourceRevision) {
                if ($sourceRevision['recordId'] === $sourceRecord['id'] && is_array($sourceRevision['body']['slug'] ?? null) && ($sourceRevision['body']['slug']['en'] ?? '') === '') {
                    $sourceRevision['body']['slug']['en'] = 'import-proof-' . substr($sourceRecord['id'], -8);
                }
            }
            unset($sourceRevision);
        }
    }
    $settings = array_values(array_filter($document['records'], static fn(array $record): bool => $record['typeId'] === 'site-settings'))[0];
    $settingsBody = array_column($document['revisions'], 'body', 'recordId')[$settings['id']];
    $sourceOrganizationName = $settingsBody['organizationName'] ?? null;
    $settings['id'] = str_repeat('9', 32);
    unset($settingsBody['defaultSharingImage']);
    $settingsBody['organizationName'] = 'Destination name';
    $content = new ContentService(new MariaDbRecordRepository($pdo), $package->manifest, $clock, $random);
    $content->saveCanonical('site-settings', $settings['id'], null, $settingsBody, $actor);
    $pdo->prepare("UPDATE cms_records SET workflow_state = 'published', published_revision_id = current_revision_id WHERE id = :id")->execute(['id' => $settings['id']]);
    // Malicious-looking plain text remains literal data, not a program or trusted HTML.
    $document['revisions'][0]['body']['title']['en'] = '<script>never executed</script> <?php die(); ?>';
    foreach ($document['media'] as $media) {
        copy($package->root . '/fixtures/media/' . $media['id'] . '-display_1600_webp.webp', $state . '/package/blobs/' . $media['original']['sha256']);
    }
    importTestWrite($state . '/package/export.json', $document);
    $inventory = new FilesystemRestoreStore($state . '/package', $clock);
    $repository = new MariaDbImportRepository($pdo, hash('sha256', $state . '/media'), $package->manifest->designRegistries, $paths);
    $service = new ContentImportService($repository, $package, new ImportMediaTransfer(new MariaDbUploadRepository($pdo), $state . '/media', $clock, $random, new MariaDbMediaLibrary($pdo)), $clock, $random, true);
    $input = $inventory->load($root . '/schemas/portable-cms-export-v1.schema.json');
    $before = importTestCounts($pdo);
    $singlePackage = singleLineRichTextPackage($state . '/single-line-profile');
    $singleService = new ContentImportService($repository, $singlePackage, new ImportMediaTransfer(new MariaDbUploadRepository($pdo), $state . '/media', $clock, $random, new MariaDbMediaLibrary($pdo)), $clock, $random, true);
    $invalidSingle = $document;
    $invalidSingle['revisions'][0]['body']['salesAgent'] = ['formatVersion' => 1, 'nodes' => array_fill(0, 2, ['type' => 'paragraph', 'children' => [['text' => 'Extra agent', 'marks' => []]]])];
    importTestWrite($state . '/package/export.json', $invalidSingle);
    $singleInput = $inventory->load($root . '/schemas/portable-cms-export-v1.schema.json');
    $singlePlan = $singleService->dryRun($singleInput, $inventory, 'single-line-study', $actor);
    $rejectedAgent = array_values(array_filter($singlePlan->records, static fn($record): bool => $record->sourceId === $document['revisions'][0]['recordId']))[0];
    assertSameValue('blocked', $rejectedAgent->action, 'import refuses extra blocks in a single-line field');
    assertTrueValue(in_array(['field' => 'salesAgent', 'reason' => 'invalid_field_value'], $rejectedAgent->issues, true), 'import identifies the constrained field');
    assertSameValue($before, importTestCounts($pdo), 'rejected single-line import changes no authoritative or journal rows');
    $vimeoPackage = vimeoPackage($state . '/vimeo-profile');
    $vimeoService = new ContentImportService($repository, $vimeoPackage, new ImportMediaTransfer(new MariaDbUploadRepository($pdo), $state . '/media', $clock, $random, new MariaDbMediaLibrary($pdo)), $clock, $random, true);
    foreach ([vimeoValue(), [...vimeoValue(), 'privacyHash' => 'malformed'], [...vimeoValue(), 'title' => ''], [...vimeoValue(), 'poster' => str_repeat('f', 32)]] as $position => $video) {
        $vimeoDocument = $document;
        $vimeoDocument['revisions'][0]['body']['film'] = $video;
        importTestWrite($state . '/package/export.json', $vimeoDocument);
        $vimeoInput = $inventory->load($root . '/schemas/portable-cms-export-v1.schema.json');
        $vimeoPlan = $vimeoService->dryRun($vimeoInput, $inventory, 'vimeo-study', $actor);
        $plannedFilm = array_values(array_filter($vimeoPlan->records, static fn($record): bool => $record->sourceId === $document['revisions'][0]['recordId']))[0];
        assertSameValue($position === 0, $plannedFilm->action !== 'blocked', 'Vimeo import accepts only a valid reference and owned poster');
        assertSameValue($before, importTestCounts($pdo), 'Vimeo validation and planning make no authoritative writes');
    }
    importTestWrite($state . '/package/export.json', $document);
    $plan = $service->dryRun($input, $inventory, 'fictional-study', $actor);
    assertSameValue($before, importTestCounts($pdo), 'dry run changes no authoritative or journal rows');
    assertSameValue([], iterator_to_array(new FilesystemIterator($state . '/media')), 'dry run does not initialize storage or locks');
    assertSameValue(count($document['records']), count($plan->records), 'every record including taxonomies is reported');
    assertSameValue(count($document['media']), count($plan->media), 'every original is reported');
    assertSameValue(['conflict'], array_values(array_unique(array_map(static fn($record): string => $record->action, array_filter($plan->records, static fn($record): bool => $record->typeId === 'site-settings')))), 'newer destination settings conflict');
    assertSameValue([], array_values(array_filter($plan->records, static fn($record): bool => $record->action === 'blocked')), 'same-profile fictional content is lossless');
    $mappedSettings = array_values(array_filter($plan->records, static fn($record): bool => $record->typeId === 'site-settings'))[0];
    assertSameValue($settings['id'], $mappedSettings->id, 'existing singleton keeps its destination identity');
    assertTrueValue($mappedSettings->sourceId !== $mappedSettings->id, 'singleton identity remapping is visible');
    $withoutProcessor = new ContentImportService($repository, $package, new ImportMediaTransfer(new MariaDbUploadRepository($pdo), $state . '/media', $clock, $random, new MariaDbMediaLibrary($pdo)), $clock, $random, false);
    $unqualified = $withoutProcessor->dryRun($input, $inventory, 'unqualified-study', $actor);
    assertSameValue(count($document['media']), count(array_filter($unqualified->media, static fn($media): bool => $media->action === 'blocked')), 'missing processor cannot queue unsupported media');
    assertSameValue($before, importTestCounts($pdo), 'disabled-capability dry run remains read only');
    try {
        $service->apply($input, $inventory, 'fictional-study', $actor, str_repeat('f', 64));
        throw new RuntimeException('Unreviewed plan applied');
    } catch (ImportRejected $error) {
        assertSameValue('reviewed_plan_changed', $error->reason, 'confirmation binds exact source and destination');
    }
    // Failure during the checkpoint must roll back content, revisions, projections and audit.
    $pdo->exec("CREATE TRIGGER import_fail BEFORE INSERT ON cms_content_import_records FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Injected import interruption'");
    try {
        $service->apply($input, $inventory, 'fictional-study', $actor, $plan->sha256);
        throw new RuntimeException('Injected SQL failure did not interrupt import');
    } catch (PDOException) {
        assertSameValue($before, importTestCounts($pdo), 'failed transaction leaves the installation unchanged');
    } finally {
        $pdo->exec('DROP TRIGGER import_fail');
    }
    $result = $service->apply($input, $inventory, 'fictional-study', $actor, $plan->sha256, maximumMedia: 1);
    assertSameValue('preparing', $result['status'], 'bounded media transfer reports incomplete work');
    assertSameValue(1, (int) $pdo->query('SELECT COUNT(*) FROM cms_media')->fetchColumn(), 'one normal upload committed');
    assertSameValue('Destination name', $content->find('site-settings', $settings['id'])->body['organizationName'], 'unconfirmed conflict skipped');
    assertSameValue(1, (int) $pdo->query("SELECT COUNT(*) FROM cms_records WHERE workflow_state <> 'draft'")->fetchColumn(), 'imports never adopt published source states and preserve existing workflow');
    assertSameValue(0, (int) $pdo->query('SELECT COUNT(*) FROM cms_record_revisions WHERE created_by <> ' . $pdo->quote($actor))->fetchColumn(), 'all revisions attributed to destination administrator');
    $record = $content->find('project', $document['records'][0]['id']);
    assertSameValue($document['revisions'][0]['body']['title']['en'], $record->body['title']['en'], 'hostile plain text remains unchanged');
    // Cyclic project references commit together and have real foreign-key targets.
    assertTrueValue((int) $pdo->query('SELECT COUNT(*) FROM cms_record_relationship_index')->fetchColumn() > 0, 'ordinary relationship projection commits');
    $revisionCount = (int) $pdo->query('SELECT COUNT(*) FROM cms_record_revisions')->fetchColumn();
    $pdo->exec("CREATE TRIGGER import_media_fail BEFORE UPDATE ON cms_content_import_media FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Injected media checkpoint interruption'");
    try {
        $interrupted = $service->dryRun($input, $inventory, 'fictional-study', $actor);
        $service->apply($input, $inventory, 'fictional-study', $actor, $interrupted->sha256, maximumMedia: 1);
        throw new RuntimeException('Media checkpoint interruption did not occur');
    } catch (PDOException) {
        assertSameValue(2, (int) $pdo->query('SELECT COUNT(*) FROM cms_media')->fetchColumn(), 'normal upload committed before interrupted import checkpoint');
    } finally {
        $pdo->exec('DROP TRIGGER import_media_fail');
    }
    $recovered = $service->dryRun($input, $inventory, 'fictional-study', $actor);
    $result = $service->apply($input, $inventory, 'fictional-study', $actor, $recovered->sha256, maximumMedia: 1);
    assertSameValue(2, (int) $pdo->query('SELECT COUNT(*) FROM cms_jobs')->fetchColumn(), 'recovering completed upload does not queue duplicate work');
    $third = $recovered->media[2];
    $owner = $repository->identity($third->ownerId);
    $uploadService = new \Utsuri\PortableCms\Conventional\Media\MediaUploadService(new MariaDbUploadRepository($pdo), new \Utsuri\PortableCms\Conventional\Media\FilesystemUploadStorage($state . '/media'), $clock, $random);
    $partial = $uploadService->create($owner['typeId'], $third->ownerId, $owner['revisionNumber'], $third->value['originalName'], $third->value['original']['mime'], $third->value['original']['byteSize'], 'content-import:' . $third->clientUploadId, $actor);
    $stream = fopen($state . '/package/blobs/' . $third->value['original']['sha256'], 'rb');
    if (!is_resource($stream)) {
        throw new RuntimeException('Partial import fixture stream unavailable');
    }
    try {
        $uploadService->uploadPart($partial->id, 1, $third->value['original']['byteSize'], $stream, $actor);
    } finally {
        fclose($stream);
    }
    $clock->current = $clock->current->modify('+2 days');
    $inventory = new FilesystemRestoreStore($state . '/package', $clock);
    while ($result['status'] === 'preparing') {
        $resume = $service->dryRun($input, $inventory, 'fictional-study', $actor);
        $result = $service->apply($input, $inventory, 'fictional-study', $actor, $resume->sha256, maximumMedia: 1);
    }
    assertSameValue('applied_with_skips', $result['status'], 'the settings conflict stays visible');
    assertSameValue($revisionCount, (int) $pdo->query('SELECT COUNT(*) FROM cms_record_revisions')->fetchColumn(), 'resuming media does not replay content revisions');
    assertSameValue(count($document['media']), (int) $pdo->query('SELECT COUNT(*) FROM cms_jobs')->fetchColumn(), 'one ordinary processing job per source original');
    $firstImported = (new MariaDbMediaLibrary($pdo))->find($plan->media[0]->id);
    assertSameValue(null, $firstImported->ownerRecordId, 'existing-installation import carries website-owned images without a record mapping');
    assertSameValue($document['media'][0]['text'], $firstImported->text?->document(), 'original upload imports authored alternatives captions and credits');
    assertSameValue($document['media'][0]['focalPoint'], $firstImported->focalPoint->document(), 'original upload imports its focal point');
    assertSameValue('expired', (string) $pdo->query('SELECT status FROM cms_media_upload_sessions WHERE id = ' . $pdo->quote($partial->id))->fetchColumn(), 'expired partial transfer follows normal session recreation');
    $runner = new MediaJobRunner(new MariaDbMediaJobRepository($pdo), new FilesystemMediaStore($state . '/media'), new ImagickRasterProcessor(), $clock, $random);
    for ($index = 0; $index < count($document['media']); $index++) {
        assertTrueValue($runner->runOne('import-proof')->completed, 'normal raster processing succeeds');
    }
    $confirmed = $service->dryRun($input, $inventory, 'fictional-study', $actor);
    $service->apply($input, $inventory, 'fictional-study', $actor, $confirmed->sha256, $confirmed->sha256);
    assertSameValue($sourceOrganizationName, $content->find('site-settings', $settings['id'])->body['organizationName'] ?? null, 'explicit conflict confirmation updates settings');
    assertTrueValue(str_contains((string) $pdo->query("SELECT metadata_json FROM cms_audit_events WHERE event_type = 'content_import_applied' ORDER BY occurred_at DESC, id DESC")->fetchColumn(), 'planSha256'), 'operator application has review identity audit');
    $after = importTestCounts($pdo);
    $repeat = $service->dryRun($input, $inventory, 'fictional-study', $actor);
    $service->apply($input, $inventory, 'fictional-study', $actor, $repeat->sha256);
    assertSameValue($after, importTestCounts($pdo), 'identical imports replay no records jobs or audit events');
    $export = (new ExportService(new MariaDbExportRepository($pdo, $package->manifest->designRegistries), $package->manifest, $clock, $random))->create($actor);
    assertSameValue(count($document['records']), count($export->document['records']), 'normalized export includes imported taxonomy and content');
    assertSameValue(count($document['media']), count($export->document['media']), 'normalized export contains newly processed originals');
    $current = array_column($export->document['records'], 'currentRevisionId', 'id');
    $bodies = array_column($export->document['revisions'], 'body', 'id');
    assertSameValue($record->body, $bodies[$current[$record->id]], 'export round trip preserves the remapped canonical body');
    // Metadata follows the normal name service; source and destination revisions have separate baselines.
    $mediaRepository = new MariaDbMediaLibrary($pdo);
    $library = new MediaLibrary($mediaRepository, new FilesystemMediaStore($state . '/media'), $clock, $random);
    $mediaId = $repeat->media[0]->id;
    $document['media'][0]['originalName'] = 'Source renamed image.png';
    importTestWrite($state . '/package/export.json', $document);
    $input = $inventory->load($root . '/schemas/portable-cms-export-v1.schema.json');
    $rename = $withoutProcessor->dryRun($input, $inventory, 'fictional-study', $actor);
    assertSameValue('update', $rename->media[0]->action, 'same original with changed source name reports a native metadata update');
    $jobsBeforeRename = (int) $pdo->query('SELECT COUNT(*) FROM cms_jobs')->fetchColumn();
    $pdo->exec("CREATE TRIGGER import_checkpoint_failure BEFORE UPDATE ON cms_content_import_media FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'injected metadata checkpoint interruption'");
    try {
        $withoutProcessor->apply($input, $inventory, 'fictional-study', $actor, $rename->sha256);
        throw new RuntimeException('Metadata checkpoint interruption did not fire');
    } catch (PDOException) {
        assertSameValue($document['media'][0]['originalName'], $mediaRepository->find($mediaId)->name, 'native name commit survives interrupted import checkpoint');
    } finally {
        $pdo->exec('DROP TRIGGER import_checkpoint_failure');
    }
    $metadataRevision = $mediaRepository->find($mediaId)->metadataRevision;
    $recovery = $withoutProcessor->dryRun($input, $inventory, 'fictional-study', $actor);
    $renamed = $withoutProcessor->apply($input, $inventory, 'fictional-study', $actor, $recovery->sha256);
    assertSameValue(1, $renamed['updatedMedia'], 'interrupted metadata update resumes without upload');
    assertSameValue($metadataRevision, $mediaRepository->find($mediaId)->metadataRevision, 'checkpoint recovery does not repeat the native rename');
    assertSameValue($jobsBeforeRename, (int) $pdo->query('SELECT COUNT(*) FROM cms_jobs')->fetchColumn(), 'name updates queue no duplicate raster work');
    assertTrueValue($library->rename($mediaId, $metadataRevision, 'Local name.png', $actor), 'local native media rename succeeds');
    $sameName = $service->dryRun($input, $inventory, 'fictional-study', $actor);
    assertSameValue('unchanged', $sameName->media[0]->action, 'identical source preserves a later local media name');
    $document['media'][0]['originalName'] = 'Another source name.png';
    importTestWrite($state . '/package/export.json', $document);
    $input = $inventory->load($root . '/schemas/portable-cms-export-v1.schema.json');
    $nameConflict = $service->dryRun($input, $inventory, 'fictional-study', $actor);
    assertSameValue('conflict', $nameConflict->media[0]->action, 'changed source name conflicts with a later local rename');
    $skippedName = $service->apply($input, $inventory, 'fictional-study', $actor, $nameConflict->sha256);
    assertSameValue('applied_with_skips', $skippedName['status'], 'unconfirmed metadata conflicts are skipped');
    assertSameValue('Local name.png', $mediaRepository->find($mediaId)->name, 'unconfirmed source rename preserves local metadata');
    assertTrueValue($library->rename($mediaId, $mediaRepository->find($mediaId)->metadataRevision, 'A newer local name.png', $actor), 'concurrent native rename succeeds');
    try {
        $service->apply($input, $inventory, 'fictional-study', $actor, $nameConflict->sha256, $nameConflict->sha256);
        throw new RuntimeException('Stale metadata confirmation was accepted');
    } catch (ImportRejected $error) {
        assertSameValue('reviewed_plan_changed', $error->reason, 'metadata revisions bind the reviewed import plan');
    }
    $confirmedName = $service->dryRun($input, $inventory, 'fictional-study', $actor);
    $service->apply($input, $inventory, 'fictional-study', $actor, $confirmedName->sha256, $confirmedName->sha256);
    assertSameValue($document['media'][0]['originalName'], $mediaRepository->find($mediaId)->name, 'explicit conflict confirmation applies native metadata update');
    $next = $document;
    $next['records'][0]['currentRevisionNumber'] = 2;
    $next['records'][0]['currentRevisionId'] = str_repeat('c', 32);
    $next['records'][0]['updatedAt'] = '2026-10-05T12:00:00Z';
    $next['revisions'][0]['id'] = str_repeat('c', 32);
    $next['revisions'][0]['revisionNumber'] = 2;
    $next['revisions'][0]['body']['title']['en'] = 'Clean source advance';
    foreach ($next['relationships'] as &$relation) {
        if ($relation['recordId'] === $record->id) {
            $relation['revisionId'] = str_repeat('c', 32);
        }
    }
    unset($relation);
    importTestWrite($state . '/package/export.json', $next);
    $input = $inventory->load($root . '/schemas/portable-cms-export-v1.schema.json');
    $cleanAdvance = $service->dryRun($input, $inventory, 'fictional-study', $actor);
    assertSameValue('update', $cleanAdvance->records[0]->action, 'unmodified imported destination accepts newer source lineage despite a later import timestamp');
    $service->apply($input, $inventory, 'fictional-study', $actor, $cleanAdvance->sha256);
    $document = $next;
    // A destination edit after import survives an identical source; advancing source conflicts.
    $edited = $content->find('project', $record->id);
    $editBody = $edited->body;
    $editBody['title']['en'] = 'A newer destination edit';
    $content->saveCanonical('project', $record->id, $edited->revisionNumber, $editBody, $actor);
    $same = $service->dryRun($input, $inventory, 'fictional-study', $actor);
    assertSameValue('unchanged', $same->records[0]->action, 'same source does not overwrite subsequent destination edits');
    $advanced = $document;
    $advanced['records'][0]['currentRevisionNumber'] = 3;
    $advanced['records'][0]['currentRevisionId'] = str_repeat('e', 32);
    $advanced['records'][0]['updatedAt'] = $clock->now()->modify('+1 day')->format('Y-m-d\TH:i:s\Z');
    $advanced['revisions'][0]['id'] = str_repeat('e', 32);
    $advanced['revisions'][0]['revisionNumber'] = 3;
    $advanced['revisions'][0]['body']['title']['en'] = 'A changed source';
    foreach ($advanced['relationships'] as &$relation) {
        if ($relation['recordId'] === $record->id) {
            $relation['revisionId'] = str_repeat('e', 32);
        }
    }
    unset($relation);
    importTestWrite($state . '/package/export.json', $advanced);
    $advancedInput = $inventory->load($root . '/schemas/portable-cms-export-v1.schema.json');
    $conflict = $service->dryRun($advancedInput, $inventory, 'fictional-study', $actor);
    assertSameValue('conflict', $conflict->records[0]->action, 'local edits conflict even with a future-dated source');
    $service->apply($advancedInput, $inventory, 'fictional-study', $actor, $conflict->sha256);
    assertSameValue('A newer destination edit', $content->find('project', $record->id)->body['title']['en'], 'conflicts skipped by default');
    $content->saveCanonical('project', $record->id, $content->find('project', $record->id)->revisionNumber, $editBody, $actor);
    try {
        $service->apply($advancedInput, $inventory, 'fictional-study', $actor, $conflict->sha256, $conflict->sha256);
        throw new RuntimeException('Stale conflict approval accepted');
    } catch (ImportRejected $error) {
        assertSameValue('reviewed_plan_changed', $error->reason, 'late destination edit invalidates confirmation');
    }
    // Every missing blob and unsupported field is reported without suppressing unrelated records.
    $bad = $document;
    $bad['revisions'][0]['body']['unsupportedField'] = '<?php never run ?>';
    $bad['revisions'][1]['body']['year'] = '2025';
    $bad['revisions'][1]['body']['sortOrder'] = '10';
    $bad['revisions'][1]['body']['anotherUnknownField'] = 'literal data';
    $bad['media'][0]['originalName'] = '../../hostile.php';
    importTestWrite($state . '/package/export.json', $bad);
    $badInput = $inventory->load($root . '/schemas/portable-cms-export-v1.schema.json');
    $badPlan = $service->dryRun($badInput, $inventory, 'another-study', $actor);
    assertTrueValue(in_array('unsupported_field', array_column($badPlan->records[0]->issues, 'reason'), true), 'unsupported field named in report');
    assertTrueValue(in_array('invalid_or_lossy_body', array_column($badPlan->records[1]->issues, 'reason'), true), 'numeric-string mapping refused');
    $lossyFields = array_column(array_filter($badPlan->records[1]->issues, static fn(array $issue): bool => $issue['reason'] === 'lossy_field_mapping'), 'field');
    sort($lossyFields, SORT_STRING);
    assertSameValue(['sortOrder', 'year'], $lossyFields, 'every lossy canonical field is identified alongside an unknown field');
    assertTrueValue(in_array('unsupported_or_lossy_original_name', array_column($badPlan->media[0]->issues, 'reason'), true), 'a source filename cannot silently become a destination path or metadata mapping');
    $withWebsiteSettings = $document;
    $withWebsiteSettings['websitePublication'] = \Utsuri\PortableCms\Conventional\Publication\WebsitePublication::defaults($package->manifest->id, $package->manifest->locales)->document();
    $withWebsiteSettings['websitePublication']['websiteName'] = 'Source-only website name';
    importTestWrite($state . '/package/export.json', $withWebsiteSettings);
    $settingsPlan = $service->dryRun($inventory->load($root . '/schemas/portable-cms-export-v1.schema.json'), $inventory, 'fictional-study', $actor);
    assertSameValue(true, $settingsPlan->context['excluded']['websitePublication'], 'website identity and publication settings are explicitly excluded');
    importTestWrite($state . '/package/export.json', $document);
    $input = $inventory->load($root . '/schemas/portable-cms-export-v1.schema.json');
    $firstBlob = $state . '/package/blobs/' . $document['media'][0]['original']['sha256'];
    unlink($firstBlob);
    $missing = $service->dryRun($input, $inventory, 'missing-study', $actor);
    assertSameValue('blocked', $missing->media[0]->action, 'missing original blocks its media item');
    assertTrueValue(in_array('unavailable_media', array_column($missing->records[0]->issues, 'reason'), true), 'affected record identifies missing media');
    symlink($package->root . '/fixtures/media/' . $document['media'][0]['id'] . '-display_1600_webp.webp', $firstBlob);
    assertTrueValue(in_array('unsafe_blob_path', array_column($service->dryRun($input, $inventory, 'missing-study', $actor)->media[0]->issues, 'reason'), true), 'checksum-correct symlink refused');
    unlink($firstBlob);
    copy($package->root . '/fixtures/media/' . $document['media'][0]['id'] . '-display_1600_webp.webp', $firstBlob);
    $config = $state . '/cms.json';
    file_put_contents($config, json_encode(['origin' => 'http://localhost:4181', 'database' => ['dsn' => $dsn, 'username' => 'portable', 'password' => 'integration-secret'], 'throttleSecretBase64' => base64_encode(str_repeat('k', 32)), 'storage' => ['mediaRoot' => $state . '/media'], 'profileRoot' => $package->root, 'development' => ['allowInsecureLoopback' => true]], JSON_THROW_ON_ERROR));
    chmod($config, 0600);
    $arguments = ['--config=' . $config, '--public-root=' . $state . '/public', '--package=' . $state . '/package', '--source=fictional-study', '--administrator=import-admin@example.test'];
    $beforeCli = importTestCounts($pdo);
    $cliPlan = importTestOperator($arguments);
    assertSameValue('planned', $cliPlan['status'], 'installed PHP operator defaults to dry run');
    assertSameValue($beforeCli, importTestCounts($pdo), 'real CLI dry run is read only');
    $cliApply = importTestOperator([...$arguments, '--apply', '--confirm=' . $cliPlan['planSha256']]);
    assertSameValue('applied', $cliApply['status'], 'real CLI supports idempotent repeats');
    assertSameValue($beforeCli, importTestCounts($pdo), 'real CLI repeat creates no revisions');
    $reject = importTestOperator([...$arguments, '--apply', '--confirm=' . str_repeat('0', 64)], 1);
    assertSameValue('reviewed_plan_changed', $reject['reason'], 'CLI safe failure omits paths and source text');
    importTestCurrentContracts($pdo, $package, $service, $repository, $document, $state, $actor, $clock, $random, $arguments);
    fwrite(STDOUT, "PASS existing-installation content import: read-only plan, attributed cyclic graph, bounded uploads and normal jobs, idempotency, conflicts, hostile paths, export and interruption.\n");
} finally {
    RestoreTestFixture::remove($state);
}
