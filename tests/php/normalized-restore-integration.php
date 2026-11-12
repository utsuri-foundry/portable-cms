<?php

declare(strict_types=1);

require_once __DIR__ . '/trailer-fixture.php';

use Utsuri\PortableCms\Conventional\ApplicationFactory;
use Utsuri\PortableCms\Conventional\Compiler\FrozenSnapshot;
use Utsuri\PortableCms\Conventional\Compiler\PublicUrlContext;
use Utsuri\PortableCms\Conventional\Compiler\PublishedSlugHistory;
use Utsuri\PortableCms\Conventional\Content\ContentService;
use Utsuri\PortableCms\Conventional\Content\EditorialTerminology;
use Utsuri\PortableCms\Conventional\Content\MariaDbRecordRepository;
use Utsuri\PortableCms\Conventional\Content\MariaDbTerminologyRepository;
use Utsuri\PortableCms\Conventional\Content\ProfilePackage;
use Utsuri\PortableCms\Conventional\Database\MigrationRunner;
use Utsuri\PortableCms\Conventional\Design\FilesystemFontStore;
use Utsuri\PortableCms\Conventional\Design\FontIngestion;
use Utsuri\PortableCms\Conventional\Design\MariaDbRegistryRepository;
use Utsuri\PortableCms\Conventional\Design\NativeFontSanitizer;
use Utsuri\PortableCms\Conventional\Design\RegistryDocument;
use Utsuri\PortableCms\Conventional\Export\MariaDbExportRepository;
use Utsuri\PortableCms\Conventional\Export\NormalizedExport;
use Utsuri\PortableCms\Conventional\Identity\AdministratorBootstrap;
use Utsuri\PortableCms\Conventional\Identity\SystemClock;
use Utsuri\PortableCms\Conventional\Identity\SystemRandomSource;
use Utsuri\PortableCms\Conventional\Preview\FilesystemPreviewStore;
use Utsuri\PortableCms\Conventional\Preview\MariaDbPreviewRepository;
use Utsuri\PortableCms\Conventional\Preview\PreviewService;
use Utsuri\PortableCms\Conventional\Preview\ProfilePreviewCompiler;
use Utsuri\PortableCms\Conventional\Restore\FilesystemRestoreStore;
use Utsuri\PortableCms\Conventional\Restore\MariaDbRestoreRepository;
use Utsuri\PortableCms\Conventional\Restore\RestorePlan;
use Utsuri\PortableCms\Conventional\Restore\RestoreRejected;
use Utsuri\PortableCms\Conventional\Restore\RestoreService;
use Utsuri\PortableCms\Conventional\RuntimeConfiguration;

require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/bootstrap.php';
require_once dirname(__DIR__, 2) . '/tools/development/reference-studio-seeder.php';
require_once dirname(__DIR__, 2) . '/tools/development/reference-media-seeder.php';
require_once __DIR__ . '/assertions.php';
require_once __DIR__ . '/restore-fixture.php';
require_once __DIR__ . '/published-record-integration.php';

function restoreTestExecutable(string $name): string
{
    foreach (explode(PATH_SEPARATOR, getenv('PATH') ?: '') as $directory) {
        $path = $directory . '/' . $name;
        if (is_file($path) && is_executable($path)) {
            return $path;
        }
    }
    throw new RuntimeException('Pinned test processor is missing');
}

/** @param list<string> $migrations
 * @return array{PDO, string, string}
 */
function restoreTestDatabase(string $environment, array $migrations): array
{
    $dsn = getenv($environment);
    if (!is_string($dsn) || $dsn === '') {
        throw new RuntimeException('An isolated restore database is required');
    }
    $pdo = new PDO($dsn, 'portable', 'integration-secret', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
    (new MigrationRunner($pdo))->apply($migrations);
    $actor = (new AdministratorBootstrap($pdo, new SystemClock(), new SystemRandomSource()))->create(strtolower($environment) . '@example.test', 'Test administrator', 'isolated restore test password');
    return [$pdo, $actor, $dsn];
}

/** @return array{PreviewService, string} */
function restoreTestPreview(PDO $pdo, string $actor, RuntimeConfiguration $config, ProfilePackage $package, string $schemas, bool $holding = false, bool $preparation = false): array
{
    $compiler = new ProfilePreviewCompiler($package, $config->mediaRoot, $schemas);
    $source = $holding ? new \Utsuri\PortableCms\Conventional\Publication\HoldingSnapshotSource($pdo, new \Utsuri\PortableCms\Conventional\Publication\MariaDbWebsitePublication($pdo), $package->checksum, $config->publicUrlContext ?? throw new RuntimeException('Holding restore needs a public context')) : null;
    $repository = new MariaDbPreviewRepository($pdo, $package->manifest->designRegistries, $package->checksum, $holding ? null : $compiler->preflight(...), $config->publicUrlContext, holdingSource: $source);
    $service = new PreviewService($repository, $package->manifest, new SystemClock(), new SystemRandomSource(), new FilesystemPreviewStore($config->mediaRoot . '/previews'), buildKind: $holding ? 'holding' : 'website');
    $status = $service->create($actor, false, $preparation);
    $run = ApplicationFactory::previewRunner($config)->runOne('restore-acceptance');
    assertTrueValue($run->completed, 'restored content must compile a fresh verified preview');
    assertSameValue('ready', $service->find($status->id)?->status, 'only verified bytes become ready');
    return [$service, $status->id];
}

/** @param list<string> $arguments
 * @return array<string, mixed>
 */
function restoreTestOperator(string $tool, array $arguments, int $expectedExit = 0): array
{
    $process = proc_open([PHP_BINARY, dirname(__DIR__, 2) . '/tools/' . $tool, ...$arguments], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Isolated operator process could not start');
    }
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1], 1048576);
    $error = stream_get_contents($pipes[2], 1048576);
    fclose($pipes[1]);
    fclose($pipes[2]);
    assertSameValue($expectedExit, proc_close($process), 'operator process must report the expected completion state: ' . $output . $error);
    $report = json_decode((string) ($output === '' ? $error : $output), true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($report)) {
        throw new RuntimeException('Operator did not return its bounded report');
    }
    return $report;
}

function restoreTestConfiguration(string $path, string $dsn, string $mediaRoot, ProfilePackage $package): void
{
    file_put_contents($path, json_encode(['origin' => 'http://localhost:4181', 'database' => ['dsn' => $dsn, 'username' => 'portable', 'password' => 'integration-secret'], 'throttleSecretBase64' => base64_encode(str_repeat('d', 32)), 'storage' => ['mediaRoot' => $mediaRoot], 'profileRoot' => $package->root, 'development' => ['allowInsecureLoopback' => true]], JSON_THROW_ON_ERROR));
    chmod($path, 0600);
}

$root = dirname(__DIR__, 2);
$migrations = glob($root . '/engine/migrations/*.sql');
if ($migrations === false) {
    throw new RuntimeException('Test migrations are missing');
}
sort($migrations, SORT_STRING);
[$source, $sourceActor, $sourceDsn] = restoreTestDatabase('CMS_TEST_RESTORE_SOURCE_DSN', $migrations);
[$destination, $destinationActor, $destinationDsn] = restoreTestDatabase('CMS_TEST_RESTORE_DESTINATION_DSN', $migrations);
$package = ProfilePackage::load($root . '/profiles/reference-studio');
$clock = new SystemClock();
$random = new SystemRandomSource();
$state = sys_get_temp_dir() . '/portable-restore-integration-' . bin2hex(random_bytes(8));
mkdir($state, 0700);
foreach (['source', 'destination', 'package', 'input', 'scratch', 'public'] as $directory) {
    mkdir($state . '/' . $directory, 0700);
}

try {
    $snapshot = FrozenSnapshot::load($package->root . '/fixtures/snapshot.json', $root . '/schemas/portable-cms-snapshot-v1.schema.json', $package);
    (new ReferenceStudioDevelopmentSeeder($package, $snapshot))->seed($source, $sourceActor);
    // Complete the intentionally unfinished draft title before testing a lossless restore.
    foreach (RestoreTestFixture::document($package)['revisions'] as $revision) {
        $update = $source->prepare('UPDATE cms_record_revisions SET body_json = :body WHERE id = :id');
        $update->execute(['body' => json_encode($revision['body'], JSON_THROW_ON_ERROR), 'id' => $revision['id']]);
    }
    $recordId = '10000000000000000000000000000001';
    $revision = $source->query("SELECT body_json FROM cms_record_revisions WHERE record_id = '10000000000000000000000000000001'")->fetchColumn();
    $body = json_decode((string) $revision, true, 64, JSON_THROW_ON_ERROR);
    foreach ($package->manifest->locales as $locale) {
        $body['searchSharing'][$locale] = ['searchTitle' => 'Search ' . $locale, 'description' => 'Description ' . $locale, 'sharingTitle' => 'Sharing ' . $locale, 'sharingDescription' => 'Card ' . $locale, 'excludeFromSearch' => $locale === 'es', 'sharingImage' => ['d0000000000000000000000000000001']];
    }
    $update = $source->prepare('UPDATE cms_record_revisions SET body_json = :body WHERE record_id = :record');
    $update->execute(['body' => json_encode($body, JSON_THROW_ON_ERROR), 'record' => $recordId]);
    $content = new ContentService(new MariaDbRecordRepository($source), $package->manifest, $clock, $random);
    $term = $content->find('discipline', '80000000000000000000000000000001');
    assertTrueValue($term !== null, 'seeded taxonomy exists');
    $content->update('discipline', $term->id, $term->revisionNumber, ['label' => ['ca' => 'Arquitectura 🌿', 'es' => 'Arquitectura 🌿', 'en' => 'Architecture 🌿']], $sourceActor);
    ReferenceMediaSeeder::seed($source, $package, $snapshot, $sourceActor, $state . '/source');
    $context = PublicUrlContext::from(['formatVersion' => 1, 'baseUrl' => 'https://source.example/studio']);
    $sourceConfig = new RuntimeConfiguration('http://localhost:4181', $sourceDsn, 'portable', 'integration-secret', str_repeat('s', 32), null, $state . '/source', 'cron_only', allowInsecureLoopback: true, profileRoot: $package->root, publicUrlContext: $context);
    $settings = $content->list('site-settings')[0];
    $iconImage = new Imagick();
    try {
        $iconImage->newImage(640, 640, new ImagickPixel('#314151'));
        $iconImage->setImageFormat('png');
        $iconBytes = $iconImage->getImageBlob();
    } finally {
        $iconImage->clear();
    }
    $uploads = new \Utsuri\PortableCms\Conventional\Media\MediaUploadService(new \Utsuri\PortableCms\Conventional\Media\MariaDbUploadRepository($source), new \Utsuri\PortableCms\Conventional\Media\FilesystemUploadStorage($state . '/source'), $clock, $random, true);
    $upload = $uploads->createWebsite('website-icon.png', 'image/png', strlen($iconBytes), bin2hex($random->bytes(16)), $sourceActor);
    $stream = fopen('php://temp', 'w+b');
    fwrite($stream, $iconBytes);
    rewind($stream);
    try {
        $uploads->uploadPart($upload->id, 1, strlen($iconBytes), $stream, $sourceActor);
    } finally {
        fclose($stream);
    }
    $iconId = $uploads->complete($upload->id, $sourceActor)['media']['id'];
    $videoIds = [];
    foreach (['mp4', 'webm'] as $extension) {
        $videoBytes = (string) file_get_contents($root . '/tests/fixtures/video/prepared.' . $extension);
        $upload = $uploads->create('project', $recordId, 1, 'Prepared film.' . $extension, 'video/' . $extension, strlen($videoBytes), 'restore-video-' . $extension, $sourceActor);
        $stream = \Utsuri\PortableCms\Conventional\Http\RequestBody::fromString($videoBytes)->open();
        try {
            $uploads->uploadPart($upload->id, 1, strlen($videoBytes), $stream, $sourceActor);
        } finally {
            fclose($stream);
        }
        $videoIds[$extension] = $uploads->complete($upload->id, $sourceActor)['media']['id'];
        assertSameValue($videoIds[$extension], $uploads->complete($upload->id, $sourceActor)['media']['id'], 'video completion is idempotent in MariaDB');
    }
    $mediaRuns = ApplicationFactory::mediaRunner($sourceConfig)->runBounded('restore-media', 10, 60);
    assertSameValue(10, count(array_filter($mediaRuns, static fn($run): bool => $run->completed)), 'all source media and eligible image widths are prepared through the real queue');
    $library = new \Utsuri\PortableCms\Conventional\Media\MediaLibrary(new \Utsuri\PortableCms\Conventional\Media\MariaDbMediaLibrary($source), new \Utsuri\PortableCms\Conventional\Media\FilesystemMediaStore($state . '/source'), $clock, $random);
    $focalId = 'd0000000000000000000000000000001';
    $focalAsset = $library->find($focalId);
    assertSameValue(true, $library->rename($focalId, $focalAsset->metadataRevision, $focalAsset->name, $sourceActor, focalPoint: new \Utsuri\PortableCms\Conventional\Media\FocalPoint(85, 20)), 'source focal metadata is authored');
    assertSameValue(true, $library->keepForWebsite($focalId, $library->find($focalId)->metadataRevision, $sourceActor), 'a content image can be retained for the website without changing its uses');
    $videoContent = new ContentService(new MariaDbRecordRepository($source), $package->manifest, $clock, $random, $library);
    $project = $videoContent->find('project', $recordId);
    $videoInput = $project->body;
    $videoInput['trailer'] = json_encode(['mediaId' => $videoIds['mp4'], 'posterId' => 'd0000000000000000000000000000003'], JSON_THROW_ON_ERROR);
    $videoInput['layout']['rows'][] = ['id' => 'prepared-video', 'gap' => ['unit' => 'rem', 'value' => 1], 'columns' => [['id' => 'prepared-video-c1', 'share' => 1, 'width' => 100, 'anchor' => 'top-left', 'content' => ['kind' => 'video', 'field' => 'trailer']]]];
    foreach ($package->manifest->type('project')->fields as $field) {
        if (in_array($field->type, ['media_references', 'group', 'repeatable_group'], true) && is_array($videoInput[$field->id] ?? null)) {
            $videoInput[$field->id] = json_encode($videoInput[$field->id], JSON_THROW_ON_ERROR);
        } elseif (in_array($field->type, ['integer', 'decimal'], true) && is_numeric($videoInput[$field->id] ?? null)) {
            $videoInput[$field->id] = (string) $videoInput[$field->id];
        }
    }
    $savedVideo = $videoContent->update('project', $recordId, $project->revisionNumber, $videoInput, $sourceActor);
    assertTrueValue($savedVideo instanceof \Utsuri\PortableCms\Conventional\Content\RecordSnapshot, 'video and separate poster save through authoritative content validation: ' . ($savedVideo instanceof \Utsuri\PortableCms\Conventional\Content\ValidationFailure ? implode(', ', array_keys($savedVideo->errors)) : 'conflict'));
    assertSameValue('prepared-video', $library->find($videoIds['webm'])?->thumbnail?->variant, 'WebM is prepared through the real leased worker');
    $settingsService = new ContentService(new MariaDbRecordRepository($source), $package->manifest, $clock, $random, $library);
    $savedSettings = $settingsService->update('site-settings', $settings->id, $settings->revisionNumber, [
        'defaultSearchDescription' => ['ca' => 'Descripció del lloc.', 'es' => 'Descripción del sitio.', 'en' => 'Website description after restoration.'],
        'projectsMetadata' => json_encode(['en' => ['description' => 'Work index after restoration.', 'sharingTitle' => 'Shared work index']], JSON_THROW_ON_ERROR)], $sourceActor);
    assertTrueValue($savedSettings instanceof \Utsuri\PortableCms\Conventional\Content\RecordSnapshot, 'validated profile discovery defaults save without another name or icon');
    $publicationSettings = ApplicationFactory::websitePublication($package->manifest, $source);
    $publicationActor = new \Utsuri\PortableCms\Conventional\Identity\SessionContext('session', 'authenticated', $sourceActor, 'admin@example.test', 'Admin', 'administrator', 'token', 'csrf', $clock->now()->modify('+1 hour'), false);
    $publicationSettings->save([...$publicationSettings->load()->document(), 'revision' => 1, 'websiteName' => 'Portable holding website', 'iconMediaId' => $iconId,
        'administrationBranding' => ['logoMediaId' => $iconId, 'darkLogoMediaId' => $iconId]], 0, $publicationActor, false);
    foreach ([$videoIds['mp4'], str_repeat('f', 32)] as $invalidLogo) {
        try {
            $publicationSettings->save([...$publicationSettings->load()->document(), 'revision' => 2,
                'administrationBranding' => ['logoMediaId' => $invalidLogo, 'darkLogoMediaId' => null]], 1, $publicationActor, false);
            throw new RuntimeException('Non-raster or missing logo saved');
        } catch (\Utsuri\PortableCms\Conventional\Publication\PublicationFailure $error) {
            assertSameValue('administration_logo_not_ready', $error->codeName, 'MariaDB save revalidates prepared media inside its transaction');
            assertSameValue(1, $publicationSettings->load()->revision(), 'failed logo save leaves settings and audit atomic');
        }
    }
    // The staged upgrade preserves a different former profile identity before removing its fields.
    $source->beginTransaction();
    $legacyBody = [...$savedSettings->body, 'siteName' => 'Former reference identity', 'websiteIcon' => [$iconId]];
    $source->prepare('UPDATE cms_record_revisions SET body_json = :body WHERE id = :id')->execute(['id' => $savedSettings->revisionId, 'body' => json_encode($legacyBody, JSON_THROW_ON_ERROR)]);
    \Utsuri\PortableCms\Conventional\Upgrade\WebsiteIdentityUpgrade::migrate($source, $package->manifest, [['typeId' => 'site-settings', 'nameField' => 'siteName', 'iconField' => 'websiteIcon']]);
    $identityMapping = require $package->root . '/src/upgrade-website-identity.php';
    $source->prepare('UPDATE cms_record_revisions SET body_json = :body WHERE id = :id')->execute(['id' => $savedSettings->revisionId, 'body' => json_encode($identityMapping('site-settings', $legacyBody), JSON_THROW_ON_ERROR)]);
    $source->commit();
    assertSameValue('Portable holding website', $publicationSettings->load()->identity()->name, 'a differing authored engine identity is kept');
    assertSameValue('Former reference identity', $publicationSettings->load()->identityUpgrade?->previous[0]['name'], 'the former profile identity remains portable');
    // Spanish in preparation: a term without Spanish text saves, exports and restores.
    $languageSettings = ApplicationFactory::siteLanguages($package->manifest, $source);
    $languageSettings->save(['ca' => 'public', 'es' => 'preparation', 'en' => 'public'], 'ca', 0, $publicationActor, false);
    $languageContent = new ContentService(new MariaDbRecordRepository($source), $package->manifest, $clock, $random, publicLocales: static fn(): array => $languageSettings->load()->public());
    $preparationTerm = $languageContent->create('discipline', ['label' => ['ca' => 'Animació', 'es' => '', 'en' => 'Animation'], 'aliases' => [], 'deprecated' => false], $sourceActor);
    assertTrueValue($preparationTerm instanceof \Utsuri\PortableCms\Conventional\Content\RecordSnapshot, 'a language in preparation never requires text');
    // Italian, added within the envelope, keeps its text through export and restore.
    $languageSettings->add('it', 1, $publicationActor);
    $installed = $languageSettings->load()->installed($package->manifest);
    $italianTerm = (new ContentService(new MariaDbRecordRepository($source), $installed, $clock, $random, publicLocales: static fn(): array => $languageSettings->load()->public()))->create('discipline', ['label' => ['ca' => 'Il·lustració', 'es' => 'Ilustración', 'en' => 'Illustration', 'it' => 'Illustrazione'], 'aliases' => [], 'deprecated' => false], $sourceActor);
    assertTrueValue($italianTerm instanceof \Utsuri\PortableCms\Conventional\Content\RecordSnapshot, 'text in an added language saves');
    // Site wording, including a text in a language that is not shown, saves, exports and restores.
    $wordingSettings = ApplicationFactory::siteWording($package->manifest, $source);
    (new \Utsuri\PortableCms\Conventional\Content\MariaDbSiteWording($source))->save(\Utsuri\PortableCms\Conventional\Content\SiteWording::of($package->manifest, 1, ['page.projects' => ['en' => 'Selected work']]), 0, ['page.projects'], $sourceActor, bin2hex($random->bytes(16)), $clock->now());
    $savedWording = $wordingSettings->save(1, ['page.projects' => ['ca' => 'Obres'], 'address.projects' => ['es' => 'obras']], [], $publicationActor);
    assertSameValue(['page.projects' => ['ca' => 'Obres', 'en' => 'Selected work'], 'address.projects' => ['es' => 'obras']], $savedWording instanceof \Utsuri\PortableCms\Conventional\Content\SiteWording ? $savedWording->values : null, 'site wording saves through the MariaDB repository');
    assertSameValue(2, (int) $source->query('SELECT COUNT(*) FROM cms_site_wording_revisions')->fetchColumn(), 'every saved wording is kept as a revision');
    $wordingAudit = array_map(static fn(string $json): array => json_decode($json, true, 8, JSON_THROW_ON_ERROR), $source->query("SELECT metadata_json FROM cms_audit_events WHERE event_type = 'site_wording_saved'")->fetchAll(PDO::FETCH_COLUMN));
    usort($wordingAudit, static fn(array $a, array $b): int => $a['revision'] <=> $b['revision']);
    assertSameValue([['page.projects'], ['page.projects', 'address.projects']], array_column($wordingAudit, 'keys'), 'every save is audited with the keys it changed');
    try {
        $wordingSettings->save(1, ['page.home' => ['ca' => 'Portada']], [], $publicationActor);
        throw new RuntimeException('A stale wording revision was saved');
    } catch (\Utsuri\PortableCms\Conventional\Content\SiteWordingConflict) {
        // The stored revision moved on.
    }
    $home = $settingsService->find('home', '50000000000000000000000000000001');
    $mixedReferences = array_reverse($home->body['featuredContent']);
    $savedHome = $settingsService->update('home', $home->id, $home->revisionNumber, [...$home->body, 'featuredContent' => $mixedReferences], $sourceActor);
    assertTrueValue($savedHome instanceof \Utsuri\PortableCms\Conventional\Content\RecordSnapshot, 'one native service persists a mixed ordered reference list');
    $mixedPage = $settingsService->searchReferences($package->manifest->type('home')->fields[1], '', 1);
    assertTrueValue(in_array('project', array_column($mixedPage->records, 'typeId'), true) && in_array('article', array_column($mixedPage->records, 'typeId'), true), 'MariaDB searches both declared types in one bounded page');
    $articlePage = $settingsService->searchReferences($package->manifest->type('home')->fields[1], '', 1, 'article');
    assertSameValue(['article'], array_values(array_unique(array_column($articlePage->records, 'typeId'))), 'type filter scopes the parameterized SQL query');
    $fontIngestion = new FontIngestion(new NativeFontSanitizer(restoreTestExecutable('ots-sanitize'), restoreTestExecutable('woff2_compress'), $state . '/scratch', $random), new FilesystemFontStore($state . '/source/fonts'));
    $font = $fontIngestion->ingest((string) file_get_contents($package->root . '/assets/studio-serif-latin.woff2'), 'uploaded-serif', 'Uploaded Serif', 'Source Serif 4 test copy', 'SIL Open Font License 1.1');
    $italic = $fontIngestion->ingest((string) file_get_contents($package->root . '/assets/studio-serif-italic-latin.woff2'), 'italic', 'Uploaded Italic', 'Studio Serif Italic, renamed Source Serif 4 test copy', 'SIL Open Font License 1.1');
    $design = $package->manifest->designRegistries->document();
    $design['fonts'][] = ['id' => $font['id'], 'label' => $font['label'], 'faces' => [
        [...$font, 'style' => 'normal', 'weightMin' => 400, 'weightMax' => 400],
        [...$italic, 'style' => 'italic', 'weightMin' => 400, 'weightMax' => 400],
    ]];
    $design['typography'][0]['fontId'] = $font['id'];
    (new MariaDbRegistryRepository($source))->save(RegistryDocument::fromDocument($design), $sourceActor, bin2hex($random->bytes(16)), $clock->now());
    (new MariaDbTerminologyRepository($source))->save(new EditorialTerminology($package->manifest->id, 0, [['typeId' => 'project', 'singular' => 'Work', 'plural' => 'Works', 'languages' => ['ca' => ['singular' => 'Obra', 'plural' => 'Obres']]]]), $sourceActor, bin2hex($random->bytes(16)), $clock->now());
    $history = PublishedSlugHistory::afterVerifiedActivation(
        new \Utsuri\PortableCms\Conventional\Compiler\RouteDiscovery($context, [new \Utsuri\PortableCms\Conventional\Compiler\DiscoveryRoute('/en/old-project/', 'en', 'record:' . $recordId)]),
        $package->manifest->id,
        ['releaseId' => str_repeat('1', 32), 'artifactSha256' => str_repeat('2', 64), 'activatedAt' => '2026-10-04T09:00:00Z', 'verificationId' => str_repeat('3', 32)],
    );
    $archived = $content->create('article', ['title' => array_fill_keys($package->manifest->locales, 'Archived editorial history')], $sourceActor);
    assertTrueValue($archived instanceof \Utsuri\PortableCms\Conventional\Content\RecordSnapshot, 'saved archive fixture enters through the content service');
    $actorSession = new \Utsuri\PortableCms\Conventional\Identity\SessionContext('test-session', 'authenticated', $sourceActor, 'admin@example.test', 'Administrator', 'administrator', 'test-token', 'test-csrf', $clock->now()->modify('+1 hour'), false);
    $archived = $content->workflow()->transition('article', $archived->id, $archived->revisionNumber, 'archived', $actorSession);
    assertTrueValue($archived instanceof \Utsuri\PortableCms\Conventional\Content\RecordSnapshot, 'archive state is an ordinary attributed transition');
    publishedRecordMigration($sourceDsn, $migrations, $clock, $random);
    $settingsToPublish = $settingsService->find('site-settings', $savedSettings->id);
    assertTrueValue($settingsToPublish instanceof \Utsuri\PortableCms\Conventional\Content\RecordSnapshot, 'saved metadata settings exist');
    $publishedSettings = $settingsService->workflow()->transition('site-settings', $settingsToPublish->id, $settingsToPublish->revisionNumber, 'published', $actorSession);
    assertTrueValue($publishedSettings instanceof \Utsuri\PortableCms\Conventional\Content\RecordSnapshot, 'metadata overrides are deliberately published before the export proof');
    publishedRecordRoundTrip($source, $videoContent, $package, $sourceActor, $clock, $random);
    // Pruning leaves genuine gaps; the ordinary exporter, planner and destination must preserve them.
    for ($i = 0; $i < 60; $i++) {
        $archived = $content->rewrite('article', $archived->id, $archived->revisionNumber, $archived->body, $sourceActor);
        assertTrueValue($archived instanceof \Utsuri\PortableCms\Conventional\Content\RecordSnapshot, 'archived revision fixture uses ordinary writes');
    }
    $historyNumbers = $source->prepare('SELECT revision_number FROM cms_record_revisions WHERE record_id = :id ORDER BY revision_number');
    $historyNumbers->execute(['id' => $archived->id]);
    assertSameValue(range($archived->revisionNumber - 50, $archived->revisionNumber), array_map('intval', $historyNumbers->fetchAll(PDO::FETCH_COLUMN)), 'export source has only retained history and a gap before its oldest revision');
    $export = (new MariaDbExportRepository($source, $package->manifest->designRegistries, $context, $history))->create(bin2hex($random->bytes(16)), bin2hex($random->bytes(16)), $sourceActor, $package->manifest->id, $package->manifest->version, $installed->locales, $clock->now());
    $forgedNames = $export->document;
    $forgedNames['editorialTerminology'] = (new EditorialTerminology($package->manifest->id, 0, [['typeId' => 'project', 'singular' => 'Image', 'plural' => 'Media']]))->document();
    $forgedNames = new NormalizedExport($forgedNames);
    try {
        new RestorePlan($forgedNames, hash('sha256', $forgedNames->json()), $package, $clock, $random);
        throw new RuntimeException('Restored names collided with engine navigation');
    } catch (RestoreRejected $error) {
        assertSameValue('terminology_label_collision', $error->reason, 'restore cannot bypass the native naming rule');
    }
    $forgedVideo = $export->document;
    foreach ($forgedVideo['media'] as &$media) {
        if ($media['id'] === $videoIds['mp4']) {
            $media['derivatives'][0]['variant'] = '960-webp';
            $media['derivatives'][0]['fileName'] = $media['id'] . '-960-webp.webp';
            $media['derivatives'][0]['mime'] = 'image/webp';
        }
    }
    unset($media);
    $forgedEnvelope = new NormalizedExport($forgedVideo);
    try {
        new RestorePlan($forgedEnvelope, hash('sha256', $forgedEnvelope->json()), $package, $clock, $random);
        throw new RuntimeException('Video with an image-only delivery recipe was accepted');
    } catch (RestoreRejected $failure) {
        assertSameValue('ready_media_recipe_unavailable', $failure->reason, 'restore rejects a forged video delivery recipe before staging');
    }
    file_put_contents($state . '/input/export.json', $export->json());
    $input = new FilesystemRestoreStore($state . '/input');
    $sourcePlan = new RestorePlan($input->export($root . '/schemas/portable-cms-export-v1.schema.json'), $input->exportChecksum(), $package, $clock, $random);
    $inventory = new FilesystemRestoreStore($state . '/package');
    restoreTestConfiguration($state . '/source.json', $sourceDsn, $state . '/source', $package);
    $packaged = restoreTestOperator('conventional-export-package.php', ['--config=' . $state . '/source.json', '--public-root=' . $state . '/public', '--export-json=' . $state . '/input/export.json', '--output=' . $state . '/package']);
    assertSameValue('packaged', $packaged['status'], 'private PHP entrypoint packages source media without shell execution');
    $plan = new RestorePlan($inventory->export($root . '/schemas/portable-cms-export-v1.schema.json'), $inventory->exportChecksum(), $package, $clock, $random);
    $service = new RestoreService(new MariaDbRestoreRepository($destination, $migrations), new FilesystemRestoreStore($state . '/destination'), $clock);
    $beforeAudit = (int) $destination->query('SELECT COUNT(*) FROM cms_audit_events')->fetchColumn();
    assertSameValue('compatible', $service->dryRun($plan, $inventory, $destinationActor)['status'], 'dry run proves schema graph profile and exact media compatibility');
    assertSameValue($beforeAudit, (int) $destination->query('SELECT COUNT(*) FROM cms_audit_events')->fetchColumn(), 'dry run never appends an audit event');
    assertSameValue(0, (int) $destination->query('SELECT COUNT(*) FROM cms_restore_state')->fetchColumn(), 'dry run never reserves a restore');
    assertSameValue([], iterator_to_array(new FilesystemIterator($state . '/destination')), 'dry run never stages files');
    restoreTestConfiguration($state . '/destination.json', $destinationDsn, $state . '/destination', $package);
    $restoreArguments = ['--config=' . $state . '/destination.json', '--public-root=' . $state . '/public', '--package=' . $state . '/package', '--administrator=cms_test_restore_destination_dsn@example.test'];
    $cliDryRun = restoreTestOperator('conventional-restore.php', $restoreArguments);
    assertSameValue('compatible', $cliDryRun['status'], 'the actual operator defaults to read-only qualification');
    assertSameValue($plan->sha256, $cliDryRun['exportSha256'], 'operator confirmation identifies exact envelope bytes');
    $blob = $state . '/package/blobs/' . $plan->files[0]->sha256;
    rename($blob, $state . '/scratch/blocked-blob');
    try {
        $blocked = restoreTestOperator('conventional-restore.php', $restoreArguments, 1);
        assertSameValue('blocked', $blocked['status'], 'missing inventory fails the actual dry-run process');
        assertTrueValue($blocked['issues'] !== [] && count($blocked['issues']) <= 32, 'blocked JSON keeps its bounded inventory diagnostics');
    } finally {
        rename($state . '/scratch/blocked-blob', $blob);
    }
    $rejected = restoreTestOperator('conventional-restore.php', [...$restoreArguments, '--apply', '--confirm=' . str_repeat('0', 64)], 1);
    assertSameValue('rejected', $rejected['status'], 'unconfirmed CLI apply is a failure distinct from preparation');
    assertSameValue('confirmation_mismatch', $rejected['reason'], 'checksum-confirmed apply protocol stays intact');
    try {
        $service->apply($plan, $inventory, $sourceActor, $plan->sha256);
        throw new RuntimeException('Source identity was treated as destination authority');
    } catch (RestoreRejected $error) {
        assertSameValue('administrator_required', $error->reason, 'source identities never grant destination permissions');
    }
    assertSameValue([], iterator_to_array(new FilesystemIterator($state . '/destination')), 'authorization failure does not even create a lock file');
    try {
        $service->apply($plan, $inventory, $destinationActor, str_repeat('0', 64));
        throw new RuntimeException('Unconfirmed export was applied');
    } catch (RestoreRejected $error) {
        assertSameValue('confirmation_mismatch', $error->reason, 'explicit checksum binds operator intent');
    }
    $applyArguments = [...$restoreArguments, '--apply', '--confirm=' . $plan->sha256, '--maximum-files=1'];
    $first = restoreTestOperator('conventional-restore.php', $applyArguments, 2);
    assertSameValue('preparing', $first['status'], 'bounded staging never claims content success');
    assertSameValue(0, (int) $destination->query('SELECT COUNT(*) FROM cms_records')->fetchColumn(), 'incomplete staging exposes no imported records');
    $second = restoreTestOperator('conventional-restore.php', $applyArguments, 2);
    assertSameValue('preparing', $second['status'], 'a new CLI process resumes the identical incomplete intent');
    assertSameValue($first['preparedFiles'] + 1, $second['preparedFiles'], 'resume makes bounded progress without replaying completed files');
    assertSameValue(0, (int) $destination->query('SELECT COUNT(*) FROM cms_records')->fetchColumn(), 'another incomplete CLI process still exposes no content');
    $other = $export->document;
    $other['exportId'] = bin2hex($random->bytes(16));
    $otherExport = new NormalizedExport($other);
    try {
        $service->dryRun(new RestorePlan($otherExport, hash('sha256', $otherExport->json()), $package, $clock, $random), $inventory, $destinationActor);
        throw new RuntimeException('A competing restore replaced the durable intent');
    } catch (RestoreRejected $error) {
        assertSameValue('different_restore_already_reserved', $error->reason, 'only the exact reserved intent can resume');
    }
    $destination->exec("CREATE TRIGGER restore_test_fault BEFORE INSERT ON cms_record_revisions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'injected isolated test failure'");
    try {
        $service->apply($plan, $inventory, $destinationActor, $plan->sha256, 64);
        throw new RuntimeException('Injected database failure was ignored');
    } catch (PDOException) {
    }
    assertSameValue(0, (int) $destination->query('SELECT COUNT(*) FROM cms_records')->fetchColumn(), 'partial SQL writes roll back');
    assertSameValue(0, (int) $destination->query('SELECT COUNT(*) FROM cms_restore_authorship')->fetchColumn(), 'partial provenance rolls back too');
    assertSameValue(0, (new \Utsuri\PortableCms\Conventional\Database\MariaDbSiteSequence($destination))->current(), 'a failed restore also rolls back the imported counter');
    assertSameValue('preparing', $destination->query('SELECT status FROM cms_restore_state')->fetchColumn(), 'journal is recoverable after transaction failure');
    $destination->exec('DROP TRIGGER restore_test_fault');
    // Resume the same bounded CLI intent in fresh processes after the injected failure.
    assertSameValue('restored', restoreTestOperator('conventional-restore.php', $applyArguments)['status'], 'identical CLI intent completes after SQL rollback without replacing prepared files');
    assertSameValue('already_restored', restoreTestOperator('conventional-restore.php', $applyArguments)['status'], 'completed CLI restore is idempotent and exits successfully');
    assertSameValue('already_restored', restoreTestOperator('conventional-restore.php', $restoreArguments)['status'], 'completed CLI dry run also exits successfully');
    assertSameValue(1, (int) $destination->query('SELECT COUNT(*) FROM cms_users')->fetchColumn(), 'no source account or password is imported');
    assertSameValue(0, (int) $destination->query('SELECT COUNT(*) FROM cms_sessions')->fetchColumn(), 'no source sessions are imported');
    assertSameValue(0, (int) $destination->query('SELECT COUNT(*) FROM cms_jobs')->fetchColumn(), 'source leases jobs and previews are not imported');
    assertSameValue($destinationActor, $destination->query('SELECT created_by FROM cms_records LIMIT 1')->fetchColumn(), 'permission FKs resolve only to destination authority');
    $roundTrip = (new MariaDbExportRepository($destination, $package->manifest->designRegistries))->create(bin2hex($random->bytes(16)), bin2hex($random->bytes(16)), $destinationActor, $package->manifest->id, $package->manifest->version, $installed->locales, $clock->now());
    assertSameValue(array_column($export->document['records'], 'publishedRevisionId', 'id'), array_column($roundTrip->document['records'], 'publishedRevisionId', 'id'), 'restore preserves every exact published head');
    $restoredHistory = $destination->prepare('SELECT revision_number FROM cms_record_revisions WHERE record_id = :id ORDER BY revision_number');
    $restoredHistory->execute(['id' => $archived->id]);
    assertSameValue(range($archived->revisionNumber - 50, $archived->revisionNumber), array_map('intval', $restoredHistory->fetchAll(PDO::FETCH_COLUMN)), 'real restore preserves revision gaps without recreating removed history');
    $sourceBlocks = array_values(array_filter($export->document['revisions'], static fn(array $revision): bool => $revision['recordId'] === 'c9000000000000000000000000000001'));
    $restoredBlocks = array_values(array_filter($roundTrip->document['revisions'], static fn(array $revision): bool => $revision['recordId'] === 'c9000000000000000000000000000001'));
    assertTrueValue($sourceBlocks !== [], 'the export includes the three-type landing page');
    assertSameValue($sourceBlocks, $restoredBlocks, 'normalized restore retains typed blocks and revision bytes exactly');
    $states = array_values(array_unique(array_column($roundTrip->document['records'], 'workflowState')));
    sort($states);
    assertSameValue(['archived', 'draft', 'published'], $states, 'every editorial status survives normalized export and restore');
    assertSameValue('archived', (new MariaDbRecordRepository($destination))->find('article', $archived->id)->workflowState, 'archived history never becomes published during restoration');
    $restoredProject = array_values(array_filter($roundTrip->document['revisions'], static fn($revision): bool => $revision['recordId'] === $recordId))[0];
    assertSameValue(2, $restoredProject['body']['body']['en']['formatVersion'], 'normalized restoration preserves rich text v2');
    assertSameValue(['text' => 'RIBA ', 'marks' => ['smallCaps']], $restoredProject['body']['body']['en']['nodes'][0]['children'][0], 'original letters and small capitals survive export and restore');
    foreach (['siteSequence', 'records', 'revisions', 'relationships', 'media', 'designRegistries', 'editorialTerminology', 'sourcePublicUrlContext', 'publishedSlugHistory', 'websitePublication', 'siteLanguages', 'siteWording'] as $key) {
        assertSameValue($export->document[$key], $roundTrip->document[$key], 'normalized round trip preserves ' . $key);
    }
    assertSameValue(0, (int) $destination->query('SELECT COUNT(*) FROM cms_site_changes')->fetchColumn(), 'normalized restore excludes change history');
    assertSameValue($export->document['siteSequence'], (int) $destination->query('SELECT pruned_through FROM cms_site_sequence WHERE singleton_id = 1')->fetchColumn(), 'restored cursors require a full read through the imported high-water mark');
    assertSameValue(['x' => 85, 'y' => 20], (new \Utsuri\PortableCms\Conventional\Media\MariaDbMediaLibrary($destination))->find($focalId)->focalPoint->document(), 'restore hydrates the authored focal point');
    assertSameValue([[2], 'obras'], [array_map('intval', $destination->query('SELECT revision FROM cms_site_wording_revisions')->fetchAll(PDO::FETCH_COLUMN)), ApplicationFactory::siteWording($package->manifest, $destination)->load()->text($package->manifest, 'address.projects', 'es')], 'restore installs the wording with its restored revision');
    assertSameValue([['ca', 'es', 'en', 'it'], 'preparation', 'Illustrazione'], [ApplicationFactory::siteLanguages($package->manifest, $destination)->load()->installed($package->manifest)->locales, ApplicationFactory::siteLanguages($package->manifest, $destination)->load()->states['it'], (new MariaDbRecordRepository($destination))->find('discipline', $italianTerm->id)->body['label']['it'] ?? null], 'restore installs the added language with its text');
    $restoredPerson = (new MariaDbRecordRepository($destination))->find('person', '40000000000000000000000000000001');
    assertSameValue('http://example.test/nora?language=en&section=work', $restoredPerson->body['website']['en']['url'], 'HTTP addresses survive database export and restore exactly');
    assertSameValue('Web de la Nora', $restoredPerson->body['website']['ca']['linkText'], 'localized address labels survive database export and restore exactly');
    $restoredHome = (new MariaDbRecordRepository($destination))->find('home', $home->id);
    assertSameValue((new MariaDbRecordRepository($source))->find('home', $home->id)->body['featuredContent'], $restoredHome->body['featuredContent'], 'normalized export and restore preserve current mixed target types and exact order');
    assertSameValue($mixedReferences, (new MariaDbRecordRepository($destination))->published($restoredHome)->body['featuredContent'], 'normalized restore independently preserves the published mixed references');
    $destinationConfig = new RuntimeConfiguration('http://localhost:4181', $destinationDsn, 'portable', 'integration-secret', str_repeat('d', 32), null, $state . '/destination', 'cron_only', allowInsecureLoopback: true, profileRoot: $package->root, publicUrlContext: $context);
    [$sourcePreview, $sourcePreviewId] = restoreTestPreview($source, $sourceActor, $sourceConfig, $package, $root . '/schemas');
    [$destinationPreview, $destinationPreviewId] = restoreTestPreview($destination, $destinationActor, $destinationConfig, $package, $root . '/schemas');
    $sourceManifest = json_decode($sourcePreview->artifact($sourcePreviewId, 'artifact.json')->contents, true, 64, JSON_THROW_ON_ERROR);
    $destinationManifest = json_decode($destinationPreview->artifact($destinationPreviewId, 'artifact.json')->contents, true, 64, JSON_THROW_ON_ERROR);
    assertSameValue($sourceManifest['files'], $destinationManifest['files'], 'same-context restoration produces byte-equivalent verified public files');
    assertSameValue(null, $destinationPreview->artifact($destinationPreviewId, 'es/index.html'), 'the restored language choice keeps Spanish in preparation out of the exact preview');
    assertSameValue($sourceManifest['websiteIcon'], $destinationManifest['websiteIcon'], 'restoration regenerates byte-equivalent verified icon assets');
    $restoredIdentity = ApplicationFactory::websitePublication($package->manifest, $destination)->load();
    assertSameValue($publicationSettings->load()->document(), $restoredIdentity->document(), 'restoration retains the canonical identity and its former conflicting values');
    assertSameValue(['logoMediaId' => $iconId, 'darkLogoMediaId' => $iconId], $restoredIdentity->administrationBranding->document(), 'both administration logo selectors survive real export and restore');
    $restoredLogos = new \Utsuri\PortableCms\Conventional\Media\AdministrationLogoDelivery(new \Utsuri\PortableCms\Conventional\Media\MediaLibrary(new \Utsuri\PortableCms\Conventional\Media\MariaDbMediaLibrary($destination), new \Utsuri\PortableCms\Conventional\Media\FilesystemMediaStore($state . '/destination'), $clock, $random));
    $pair = $restoredLogos->resolve($restoredIdentity);
    assertSameValue(null, (new \Utsuri\PortableCms\Conventional\Media\MariaDbMediaLibrary($destination))->find($iconId)?->ownerRecordId, 'website-owned icon and branding survive real byte-package restore without an owner');
    assertSameValue(null, (new \Utsuri\PortableCms\Conventional\Media\MariaDbMediaLibrary($destination))->find($focalId)?->ownerRecordId, 'restored content references still select the website-owned image');
    assertTrueValue($pair !== null && !$pair->icon, 'restored selection resolves through destination media');
    assertSameValue(200, $restoredLogos->serve($restoredIdentity, $pair->light->sha256)->status, 'restored prepared logo bytes are verified and served from destination storage');
    $restoredPage = $destinationPreview->artifact($destinationPreviewId, 'en/index.html')->contents;
    assertTrueValue(str_contains($restoredPage, 'Portable holding website') && !str_contains($restoredPage, 'Former reference identity'), 'only the canonical name enters restored public pages');
    [$sourceHolding, $sourceHoldingId] = restoreTestPreview($source, $sourceActor, $sourceConfig, $package, $root . '/schemas', true);
    [$destinationHolding, $destinationHoldingId] = restoreTestPreview($destination, $destinationActor, $destinationConfig, $package, $root . '/schemas', true);
    $holdingManifest = json_decode($destinationHolding->artifact($destinationHoldingId, 'artifact.json')->contents, true, 64, JSON_THROW_ON_ERROR);
    $originalHolding = json_decode($sourceHolding->artifact($sourceHoldingId, 'artifact.json')->contents, true, 64, JSON_THROW_ON_ERROR);
    assertSameValue($originalHolding['websiteIcon'], $holdingManifest['websiteIcon'], 'holding builds regenerate the frozen verified icon after restore');
    assertSameValue([32, 180, 512], array_column($holdingManifest['websiteIcon']['files'], 'size'), 'holding icon assets use the bounded canonical recipe');
    assertTrueValue(str_contains($destinationHolding->artifact($destinationHoldingId, 'index.html')->contents, 'apple-touch-icon'), 'restored holding inspection serves the selected icon');
    $settingsPage = $destinationPreview->artifact($destinationPreviewId, 'en/projects/index.html')->contents;
    assertTrueValue(str_contains($settingsPage, 'Work index after restoration.') && str_contains($settingsPage, 'Shared work index'), 'recordless metadata overrides survive normalized export and restoration');
    $utilityPage = $destinationPreview->artifact($destinationPreviewId, 'en/search/index.html')->contents;
    assertTrueValue(str_contains($utilityPage, 'Website description after restoration.') && str_contains($utilityPage, 'apple-touch-icon'), 'default description and selected icon survive on utility routes');
    $differentContext = PublicUrlContext::from(['formatVersion' => 1, 'baseUrl' => 'https://destination.example/new']);
    $differentConfig = new RuntimeConfiguration('http://localhost:4181', $destinationDsn, 'portable', 'integration-secret', str_repeat('d', 32), null, $state . '/destination', 'cron_only', allowInsecureLoopback: true, profileRoot: $package->root, publicUrlContext: $differentContext);
    // Reviewed with Spanish in preparation, so its restored per-language choices can be read too.
    [$differentPreview, $differentId] = restoreTestPreview($destination, $destinationActor, $differentConfig, $package, $root . '/schemas', preparation: true);
    $page = $differentPreview->artifact($differentId, 'en/projects/archive-of-mist/index.html')->contents;
    assertTrueValue(str_contains($page, 'https://destination.example/new/en/projects/archive-of-mist/') && !str_contains($page, 'https://source.example'), 'fresh metadata uses destination configuration, not source provenance');
    assertTrueValue(str_contains($page, 'Search en') && str_contains($page, 'Sharing en') && str_contains($page, 'Card en'), 'localized search and sharing overrides survive restoration');
    assertTrueValue(str_contains($page, 'Abstract study with a gradient') && str_contains($page, 'Fictional colour study') && str_contains($page, 'cms-media-credit'), 'restored public layout uses authored alternatives captions and credits');
    assertTrueValue(str_contains($page, '<video controls preload="none"') && str_contains($page, '-prepared_video.mp4') && str_contains($page, 'poster="'), 'restored public compositions retain the native video and separate poster');
    $deliveredVideo = $differentPreview->artifact($differentId, 'media/' . $videoIds['mp4'] . '-prepared_video.mp4');
    assertSameValue((string) file_get_contents($root . '/tests/fixtures/video/prepared.mp4'), videoResponseBytes($deliveredVideo->response()), 'restored publication carries exact prepared video bytes');
    // The restored site wording names the Spanish projects address.
    $spanish = $differentPreview->artifact($differentId, 'es/obras/archivo-de-la-niebla/index.html')->contents;
    assertTrueValue(str_contains($spanish, 'noindex'), 'locale-specific indexing choice survives');
    assertTrueValue(str_contains($differentPreview->artifact($differentId, 'ca/projects/index.html')->contents, '>Obres</h1>'), 'restored site wording reaches the restored website');
    $artifact = json_decode($differentPreview->artifact($differentId, 'artifact.json')->contents, true, 64, JSON_THROW_ON_ERROR);
    assertSameValue([], $artifact['redirects'], 'source published history never activates redirects at destination');
    $restoredContent = new ContentService(new MariaDbRecordRepository($destination), $package->manifest, $clock, $random);
    $restoredTerm = $restoredContent->find('discipline', $term->id);
    $restoredContent->update('discipline', $term->id, $restoredTerm->revisionNumber, ['label' => array_fill_keys($package->manifest->locales, 'Destination edit')], $destinationActor);
    $edited = (new MariaDbExportRepository($destination, $package->manifest->designRegistries))->create(bin2hex($random->bytes(16)), bin2hex($random->bytes(16)), $destinationActor, $package->manifest->id, $package->manifest->version, $installed->locales, $clock->now());
    assertSameValue($export->document['siteSequence'] + 1, $edited->document['siteSequence'], 'the first restored-site revision follows the source high-water mark');
    $editedRecord = array_values(array_filter($edited->document['records'], static fn($record): bool => $record['id'] === $term->id))[0];
    assertSameValue($sourceActor, $editedRecord['createdBy'], 'original source authorship remains provenance');
    assertSameValue($destinationActor, $editedRecord['updatedBy'], 'a new destination revision attributes its real editor');
    fwrite(STDOUT, "PASS normalized export/restore: isolated MariaDB round trip, media/fonts/design/SEO, dry run, staged interruption, SQL rollback, provenance, verified previews and destination URL context\n");
} finally {
    RestoreTestFixture::remove($state);
}
