<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\ApplicationFactory;
use Utsuri\PortableCms\Conventional\Compiler\FrozenSnapshot;
use Utsuri\PortableCms\Conventional\Compiler\PublicUrlContext;
use Utsuri\PortableCms\Conventional\Content\ContentService;
use Utsuri\PortableCms\Conventional\Content\MariaDbRecordRepository;
use Utsuri\PortableCms\Conventional\Content\ProfilePackage;
use Utsuri\PortableCms\Conventional\Database\MigrationRunner;
use Utsuri\PortableCms\Conventional\Export\MariaDbExportRepository;
use Utsuri\PortableCms\Conventional\Http\RequestBody;
use Utsuri\PortableCms\Conventional\Identity\AdministratorBootstrap;
use Utsuri\PortableCms\Conventional\Identity\SessionContext;
use Utsuri\PortableCms\Conventional\Identity\SystemClock;
use Utsuri\PortableCms\Conventional\Identity\SystemRandomSource;
use Utsuri\PortableCms\Conventional\Media\FilesystemMediaStore;
use Utsuri\PortableCms\Conventional\Media\FilesystemUploadStorage;
use Utsuri\PortableCms\Conventional\Media\MariaDbMediaLibrary;
use Utsuri\PortableCms\Conventional\Media\MariaDbUploadRepository;
use Utsuri\PortableCms\Conventional\Media\MediaLibrary;
use Utsuri\PortableCms\Conventional\Media\MediaUploadService;
use Utsuri\PortableCms\Conventional\Preview\FilesystemPreviewStore;
use Utsuri\PortableCms\Conventional\Preview\MariaDbPreviewRepository;
use Utsuri\PortableCms\Conventional\Preview\PreviewNotDeletable;
use Utsuri\PortableCms\Conventional\Preview\PreviewService;
use Utsuri\PortableCms\Conventional\Preview\ProfilePreviewCompiler;
use Utsuri\PortableCms\Conventional\Publication\FilesystemPublicationTarget;
use Utsuri\PortableCms\Conventional\Publication\MariaDbPublicationRepository;
use Utsuri\PortableCms\Conventional\Publication\PublicationConflict;
use Utsuri\PortableCms\Conventional\Publication\PublicationService;
use Utsuri\PortableCms\Conventional\RuntimeConfiguration;

require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/bootstrap.php';
require_once dirname(__DIR__, 2) . '/tools/development/reference-studio-seeder.php';
require_once dirname(__DIR__, 2) . '/tools/development/reference-media-seeder.php';
require_once __DIR__ . '/assertions.php';
require_once __DIR__ . '/publication-apache-server.php';

function removePublicationTestTree(string $path): void
{
    foreach (new DirectoryIterator($path) as $entry) {
        if ($entry->isDot()) {
            continue;
        }
        if ($entry->isDir() && !$entry->isLink()) {
            removePublicationTestTree($entry->getPathname());
        } else {
            unlink($entry->getPathname());
        }
    }
    rmdir($path);
}

function finishPublication(PublicationService $publication): void
{
    for ($index = 0; $index < 100 && $publication->runnable(); ++$index) {
        $publication->runBatch(8);
    }
    assertSameValue(null, $publication->head()->pendingId, 'bounded work must settle');
    assertSameValue(false, $publication->runnable(), 'committed feed discovery also settles');
}

/** @param list<string> $arguments */
function publicationTestTool(string $tool, array $arguments): void
{
    $process = proc_open([PHP_BINARY, $tool, ...$arguments], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Installation fixture command failed to start');
    }
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    assertSameValue(0, proc_close($process), 'installation fixture command succeeds: ' . $out . $err);
}

$root = dirname(__DIR__, 2);
$dsn = getenv('CMS_TEST_PUBLICATION_DSN');
if (!is_string($dsn) || $dsn === '') {
    throw new RuntimeException('An isolated publication database is required');
}
$pdo = new PDO($dsn, 'portable', 'integration-secret', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
$paths = glob($root . '/engine/migrations/*.sql');
sort($paths, SORT_STRING);
(new MigrationRunner($pdo))->apply($paths);
$clock = new SystemClock();
$random = new SystemRandomSource();
$actorId = (new AdministratorBootstrap($pdo, $clock, $random))->create('publisher@example.test', 'Publisher', 'test-publication-password');
$actor = new SessionContext('session', 'authenticated', $actorId, 'publisher@example.test', 'Publisher', 'administrator', 'token', 'csrf', $clock->now()->modify('+1 hour'), false);
$fixturePackage = ProfilePackage::load($root . '/profiles/reference-studio');
$snapshot = FrozenSnapshot::load($fixturePackage->root . '/fixtures/snapshot.json', $root . '/schemas/portable-cms-snapshot-v1.schema.json', $fixturePackage);
ReferenceStudioDevelopmentSeeder::load($fixturePackage->root, $root . '/schemas/portable-cms-snapshot-v1.schema.json')->seed($pdo, $actorId);
$state = sys_get_temp_dir() . '/portable-publication-' . bin2hex(random_bytes(8));
foreach ([$state, $state . '/media', $state . '/public', $state . '/cms'] as $directory) {
    mkdir($directory, 0700);
}
publicationTestTool($root . '/tools/build-conventional-cms-bundle.php', ['--output=' . $state . '/bundle', '--private-root=' . $state, '--profile-root=' . $fixturePackage->root]);
// Simulate uploading the packaged profile to its declared private host location.
rename($state . '/bundle/private/profile', $state . '/profile');
\Utsuri\PortableCms\Conventional\HostIo::createPrivateFile($state . '/database-password', 'integration-secret');
publicationTestTool($root . '/tools/conventional-create-install-config.php', [
    '--runtime-output=' . $state . '/cms.json', '--runtime-config-path=' . $state . '/cms.json',
    '--gate-output=' . $state . '/install.json', '--token-output=' . $state . '/install-token',
    '--origin=https://cms.example.test', '--dsn=' . $dsn, '--username=portable',
    '--database-password-file=' . $state . '/database-password', '--error-log=' . $state . '/errors.log',
    '--profile-root=' . $state . '/profile', '--public-base-url=https://studio.example', '--publication-root=' . $state . '/public',
]);
$configuration = RuntimeConfiguration::load($state . '/cms.json', $state . '/cms');
$configurationChecksum = hash_file('sha256', $state . '/cms.json');
$context = $configuration->publicUrlContext;
assertTrueValue($context instanceof PublicUrlContext, 'the generated configuration supplies the public identity');
$package = ProfilePackage::load($configuration->profileRoot);
$server = new PublicationApacheServer($state, $configuration->publicationRoot);

try {
    ReferenceMediaSeeder::seed($pdo, $fixturePackage, $snapshot, $actorId, $configuration->mediaRoot);
    ApplicationFactory::mediaRunner($configuration)->runBounded('publication-media', 10, 60);
    $library = new MediaLibrary(new MariaDbMediaLibrary($pdo), new FilesystemMediaStore($configuration->mediaRoot), $clock, $random);
    $videoContent = new ContentService(new MariaDbRecordRepository($pdo), $package->manifest, $clock, $random, $library);
    $videoProject = $videoContent->find('project', '10000000000000000000000000000001');
    $videoBytes = (string) file_get_contents($root . '/tests/fixtures/video/prepared.mp4');
    $uploads = new MediaUploadService(new MariaDbUploadRepository($pdo), new FilesystemUploadStorage($configuration->mediaRoot), $clock, $random, true);
    $upload = $uploads->create('project', $videoProject->id, $videoProject->revisionNumber, 'Publication film.mp4', 'video/mp4', strlen($videoBytes), 'publication-video', $actorId);
    $stream = RequestBody::fromString($videoBytes)->open();
    try {
        $uploads->uploadPart($upload->id, 1, strlen($videoBytes), $stream, $actorId);
    } finally {
        fclose($stream);
    }
    $videoId = $uploads->complete($upload->id, $actorId)['media']['id'];
    assertSameValue(true, ApplicationFactory::mediaRunner($configuration)->runOne('publication-video')->completed, 'prepared video runs through the real leased worker');
    $videoInput = $videoProject->body;
    $videoInput['trailer'] = json_encode(['mediaId' => $videoId, 'posterId' => 'd0000000000000000000000000000003'], JSON_THROW_ON_ERROR);
    $videoInput['related'] = [];
    $videoInput['layout']['rows'][] = ['id' => 'publication-video', 'gap' => ['unit' => 'rem', 'value' => 1], 'columns' => [['id' => 'publication-video-c1', 'share' => 1, 'width' => 100, 'anchor' => 'top-left', 'content' => ['kind' => 'video', 'field' => 'trailer']]]];
    foreach ($package->manifest->type('project')->fields as $field) {
        if (in_array($field->type, ['media_references', 'group', 'repeatable_group'], true) && is_array($videoInput[$field->id] ?? null)) {
            $videoInput[$field->id] = json_encode($videoInput[$field->id], JSON_THROW_ON_ERROR);
        } elseif (in_array($field->type, ['integer', 'decimal'], true) && is_numeric($videoInput[$field->id] ?? null)) {
            $videoInput[$field->id] = (string) $videoInput[$field->id];
        }
    }
    $savedVideo = $videoContent->update('project', $videoProject->id, $videoProject->revisionNumber, $videoInput, $actorId);
    assertTrueValue($savedVideo instanceof \Utsuri\PortableCms\Conventional\Content\RecordSnapshot, 'video and poster are selected through authoritative content validation');
    $fixtureActor = new \Utsuri\PortableCms\Conventional\Identity\SessionContext('publication-fixture', 'authenticated', $actorId, 'admin@portable.test', 'Administrator', 'administrator', 'token', 'csrf', $clock->now()->modify('+1 hour'), false);
    assertTrueValue($videoContent->workflow()->transition('project', $savedVideo->id, $savedVideo->revisionNumber, 'published', $fixtureActor) instanceof \Utsuri\PortableCms\Conventional\Content\RecordSnapshot, 'the prepared video is deliberately published before release approval');
    $privateDraft = $videoContent->create('article', ['title' => array_fill_keys($package->manifest->locales, 'Private scheduling draft'), 'slug' => array_fill_keys($package->manifest->locales, 'private-scheduling-draft'), 'excerpt' => array_fill_keys($package->manifest->locales, 'This draft never belongs to a public release.'), 'body' => array_fill_keys($package->manifest->locales, ['formatVersion' => 1, 'nodes' => [['type' => 'paragraph', 'children' => [['text' => 'Private fictional draft.', 'marks' => []]]]]])], $actorId);
    assertTrueValue($privateDraft instanceof \Utsuri\PortableCms\Conventional\Content\RecordSnapshot, 'a real unscheduled draft is retained in the frozen snapshot');
    $compiler = new ProfilePreviewCompiler($package, $configuration->mediaRoot, $root . '/schemas');
    $previewRepository = new MariaDbPreviewRepository($pdo, $package->manifest->designRegistries, $package->checksum, $compiler->preflight(...), $context);
    $previews = new PreviewService($previewRepository, $package->manifest, $clock, $random, new FilesystemPreviewStore($configuration->mediaRoot . '/previews'));
    $publication = ApplicationFactory::publication($configuration, $package->manifest, $previews, $pdo);
    assertTrueValue($publication !== null, 'explicit dedicated target enables publication');
    $settingsService = $publication->holding->settings;
    $settingsService->save([...$settingsService->load()->document(), 'mode' => 'live', 'revision' => 1], 0, $actor, false);
    try {
        $first = $previews->create($actorId);
    } catch (\Utsuri\PortableCms\Conventional\Preview\PreviewPreflightFailure $failure) {
        throw new RuntimeException(implode('; ', array_map(\Utsuri\PortableCms\Conventional\I18n\Translator::english()->message(...), $failure->issues)), previous: $failure);
    }
    assertSameValue(true, ApplicationFactory::previewRunner($configuration)->runOne('publication-preview')->completed, 'first exact preview builds');
    $frozenManifest = $previews->find($first->id)?->manifest;
    $frozenHome = $previews->artifact($first->id, 'en/index.html')?->contents;
    $historyRecord = $videoContent->find('project', $savedVideo->id);
    $historyRecord = $videoContent->rewrite('project', $historyRecord->id, $historyRecord->revisionNumber, $historyRecord->body, $actorId);
    assertTrueValue($historyRecord instanceof \Utsuri\PortableCms\Conventional\Content\RecordSnapshot, 'history fixture begins with a new edit');
    $historyRecord = $videoContent->workflow()->transition('project', $historyRecord->id, $historyRecord->revisionNumber, 'published', $fixtureActor);
    assertTrueValue($historyRecord instanceof \Utsuri\PortableCms\Conventional\Content\RecordSnapshot, 'new identical published head permits pruning the frozen source');
    for ($i = 0; $i < 60; $i++) {
        $historyRecord = $videoContent->rewrite('project', $historyRecord->id, $historyRecord->revisionNumber, $historyRecord->body, $actorId);
        assertTrueValue($historyRecord instanceof \Utsuri\PortableCms\Conventional\Content\RecordSnapshot, 'ordinary save prunes source history');
    }
    $historyRepository = new \Utsuri\PortableCms\Conventional\Content\MariaDbRecordHistory($pdo);
    assertTrueValue($historyRepository->missingPreviewRevisions($first->id) > 0, 'frozen preview source was pruned');
    assertSameValue($frozenManifest, $previews->find($first->id)?->manifest, 'pruning preserves the exact frozen manifest');
    assertTrueValue(is_string($frozenHome) && $frozenHome !== '', 'frozen website artifact exists');
    assertSameValue($frozenHome, $previews->artifact($first->id, 'en/index.html')?->contents, 'frozen preview bytes remain readable after pruning');
    $historyPage = \Utsuri\PortableCms\Conventional\View\Pages::previewStatus('csrf', $package->manifest, $previews->find($first->id), missingRevisions: $historyRepository->missingPreviewRevisions($first->id));
    assertTrueValue(str_contains($historyPage, 'source revisions are no longer kept'), 'preview references explain removed history');
    $approved = $publication->approve($first->id, null, $actor);
    assertSameValue(null, $publication->head()->activeId, 'approval alone never activates');
    try {
        $publication->approve($first->id, null, $actor);
        throw new RuntimeException('Concurrent approval accepted');
    } catch (PublicationConflict) {
    }
    try {
        $previews->delete($first->id, $actorId);
        throw new RuntimeException('Pinned preview deleted');
    } catch (PreviewNotDeletable) {
    }
    $publication->runBatch(1);
    assertSameValue(null, $publication->head()->activeId, 'partial asset write never activates');
    assertTrueValue(!is_file($state . '/public/feed/v1/current.json'), 'unverified publication has no public feed activation');
    // A fresh runtime, as after process death or a five-minute cron interval, resumes the journal.
    $resumed = ApplicationFactory::publicationRunner($configuration);
    assertTrueValue($resumed !== null, 'cron publication runtime available');
    for ($i = 0; $i < 200 && $resumed->head()->activeId === null; ++$i) {
        $resumed->runBatch(1);
    }
    assertSameValue($approved->id, $resumed->head()->activeId, 'target verification commits before the public feed outbox finishes');
    assertTrueValue($resumed->runnable(), 'a committed activation with incomplete feed publication remains runnable');
    $activationSequence = (int) $pdo->query('SELECT site_sequence FROM cms_releases WHERE status = \'active\'')->fetchColumn();
    finishPublication($resumed);
    assertSameValue($activationSequence, (int) $pdo->query('SELECT site_sequence FROM cms_releases WHERE status = \'active\'')->fetchColumn(), 'outbox retries consume no new site sequence');
    $firstFeed = json_decode((string) file_get_contents($state . '/public/feed/v1/current.json'), true, flags: JSON_THROW_ON_ERROR);
    assertSameValue([$approved->id, $activationSequence], [$firstFeed['releaseId'], $firstFeed['sequence']], 'static discovery names the committed verified activation');
    assertSameValue($previews->artifact($first->id, $firstFeed['fullRead'])->contents, $server->get('/' . $firstFeed['fullRead'])['body'], 'exact preview and public feed snapshot bytes match');
    assertSameValue($approved->id, $publication->head()->activeId, 'complete target verification activates');
    assertSameValue($previews->artifact($first->id, 'en/index.html')->contents, file_get_contents($state . '/public/en/index.html'), 'published HTML is exactly the approved preview');
    assertSameValue($previews->artifact($first->id, 'delivery/apache24.conf')->contents, file_get_contents($state . '/public/.htaccess'), 'delivery descriptor is applied, not merely emitted');
    $server->verify($publication->artifact($first->id));
    assertSameValue($configurationChecksum, hash_file('sha256', $state . '/cms.json'), 'exact preview and publication use the generated configuration without editing it');
    fwrite(STDOUT, "PASS generated profile, public address and publication folder drive an exact preview and verified static publication\n");
    $servedVideo = $server->get('/media/' . $videoId . '-prepared_video.mp4');
    assertSameValue(200, $servedVideo['status'], 'public playback is served by the static target');
    assertSameValue('video/mp4', $servedVideo['headers']['content-type'], 'the static target serves the qualified video MIME');
    assertSameValue($videoBytes, $servedVideo['body'], 'publication preserves the exact prepared video bytes');
    $servedProject = $server->get('/en/projects/archive-of-mist/');
    assertTrueValue(str_contains($servedProject['body'], '<video controls preload="none"') && preg_match('#<video\b[^>]*\bposter="[^"]*d0000000000000000000000000000003-display_960_webp\.webp"#', $servedProject['body']) === 1, 'published composition retains native controls and the separate image poster');
    $posterPath = 'media/d0000000000000000000000000000003-display_960_webp.webp';
    assertSameValue($previews->artifact($first->id, $posterPath)->contents, $server->get('/' . $posterPath)['body'], 'public poster bytes match the exact approved preview');

    $receiptTarget = new FilesystemPublicationTarget($state . '/public', $state . '/media/publication', hash_hmac('sha256', 'publication-target-v1', $configuration->throttleSecret, true));
    $receipt = $pdo->query('SELECT receipt_json FROM cms_releases WHERE status = \'active\'')->fetchColumn();
    $firstArtifact = $publication->artifact($first->id);
    $receiptTarget->authenticate($receipt, $approved, $firstArtifact);
    $replayed = new \Utsuri\PortableCms\Conventional\Publication\Release(bin2hex(random_bytes(16)), $approved->previewId, null, 'queued', $approved->artifactChecksum, $approved->actorId, null, $approved->targetChecksum);
    try {
        $receiptTarget->authenticate($receipt, $replayed, $firstArtifact);
        throw new RuntimeException('Target receipt replay accepted');
    } catch (\Utsuri\PortableCms\Conventional\Publication\PublicationFailure $error) {
        assertSameValue('invalid_target_receipt', $error->codeName, 'authentic receipt cannot activate a different intent');
    }
    $entryBytes = file_get_contents($state . '/public/index.html');
    file_put_contents($state . '/public/index.html', 'changed after verification');
    try {
        $receiptTarget->authenticate($receipt, $approved, $firstArtifact);
        throw new RuntimeException('Changed target activated with old receipt');
    } catch (\Utsuri\PortableCms\Conventional\Publication\PublicationFailure $error) {
        assertSameValue('target_verification_failed', $error->codeName, 'receipt authenticity never replaces current target hashing');
    }
    file_put_contents($state . '/public/index.html', $entryBytes);

    require_once __DIR__ . '/release-schedule-integration.php';
    $approved = exerciseReleaseSchedules($pdo, $configuration, $previews, $actor, $first->id, $clock->now(), $privateDraft->id);

    $content = new ContentService(new MariaDbRecordRepository($pdo), $package->manifest, $clock, $random);
    $projectId = '10000000000000000000000000000001';
    $project = $content->find('project', $projectId);
    assertTrueValue($project !== null, 'project fixture present');
    $body = $project->body;
    $oldSlug = $body['slug']['en'];
    $body['slug']['en'] = 'verified-new-slug';
    $validated = $content->validateCanonical('project', $body);
    $updated = (new MariaDbRecordRepository($pdo))->update('project', $projectId, $project->revisionNumber, bin2hex(random_bytes(16)), $package->manifest->id, $package->manifest->version, $validated->body, $validated->relationships, $actorId, bin2hex(random_bytes(16)), $clock->now());
    assertTrueValue($updated instanceof \Utsuri\PortableCms\Conventional\Content\RecordSnapshot, 'new route content is saved');
    $updated = $videoContent->workflow()->transition('project', $projectId, $updated->revisionNumber, 'published', $fixtureActor);
    assertTrueValue($updated instanceof \Utsuri\PortableCms\Conventional\Content\RecordSnapshot, 'validated localized slug revision is saved before preview');
    $focalId = 'd0000000000000000000000000000001';
    $focalAsset = $library->find($focalId);
    $focalChecksum = $focalAsset->thumbnail->checksum;
    assertSameValue(true, $library->rename($focalId, $focalAsset->metadataRevision, $focalAsset->name, $actorId, focalPoint: new \Utsuri\PortableCms\Conventional\Media\FocalPoint(85, 20)), 'published image focal point changes without reprocessing');
    assertSameValue($focalChecksum, $library->find($focalId)->thumbnail->checksum, 'focal edit retains processed bytes');
    assertSameValue(0, (int) $pdo->query("SELECT COUNT(*) FROM cms_jobs WHERE job_type = 'media_derivatives' AND status = 'queued'")->fetchColumn(), 'focal edit creates no processing job');
    $second = $previews->create($actorId);
    assertSameValue(true, ApplicationFactory::previewRunner($configuration)->runOne('next-preview')->completed, 'next preview freezes successful publication history');
    $manifest = json_decode($previews->artifact($second->id, 'artifact.json')->contents, true, 64, JSON_THROW_ON_ERROR);
    assertTrueValue(in_array(['from' => '/en/projects/' . $oldSlug . '/', 'to' => '/en/projects/verified-new-slug/', 'status' => 308], $manifest['redirects'], true), 'localized slug change produces a direct permanent redirect');
    try {
        $publication->approve($second->id, null, $actor);
        throw new RuntimeException('Stale activation accepted');
    } catch (PublicationConflict) {
    }
    $next = $publication->approve($second->id, $approved->id, $actor);
    finishPublication($publication);
    assertSameValue($next->id, $publication->head()->activeId, 'next verified target becomes active');
    $secondFeed = json_decode((string) file_get_contents($state . '/public/feed/v1/current.json'), true, flags: JSON_THROW_ON_ERROR);
    $secondActivation = json_decode((string) file_get_contents($state . '/public/' . $secondFeed['activation']), true, flags: JSON_THROW_ON_ERROR);
    assertSameValue([$firstFeed['snapshotId'], $secondFeed['snapshotId'], false], [$secondActivation['replacedSnapshotId'], $secondActivation['snapshotId'], $secondActivation['rollback']], 'a normal activation records both exact snapshot identities');
    assertSameValue(3, count($secondActivation['changes']['changed']), 'the frozen project revision changes in all three language collections');
    assertSameValue(200, $server->get('/' . $firstFeed['fullRead'])['status'], 'the replaced snapshot remains available outside the current artifact');
    assertTrueValue(str_contains($server->get('/assets/media-focal.css')['body'], '.cms-focal-85-20{object-position:85% 20%}'), 'new publication serves the focal position');
    assertTrueValue(!str_contains($previews->artifact($first->id, 'assets/media-focal.css')->contents, 'cms-focal-85-20'), 'previous approved preview keeps its earlier focal point');
    assertTrueValue(str_contains($server->get('/en/projects/verified-new-slug/')['body'], 'cms-focal-85-20'), 'published renderer selects the new point');
    $server->verify($publication->artifact($second->id));
    assertTrueValue(!is_file($state . '/public/en/projects/' . $oldSlug . '/index.html'), 'obsolete owned route is removed');
    $export = (new MariaDbExportRepository($pdo, $package->manifest->designRegistries, $context))->create(bin2hex(random_bytes(16)), bin2hex(random_bytes(16)), $actorId, $package->manifest->id, $package->manifest->version, $package->manifest->locales, $clock->now());
    assertSameValue($next->id, $export->document['publishedSlugHistory']['releaseId'], 'normalized export contains actual successful destination history');

    $rollback = $publication->rollback($approved->id, $next->id, $actor);
    finishPublication($publication);
    assertSameValue($rollback->id, $publication->head()->activeId, 'rollback is a new verified activation');
    $rollbackFeed = json_decode((string) file_get_contents($state . '/public/feed/v1/current.json'), true, flags: JSON_THROW_ON_ERROR);
    $rollbackActivation = json_decode((string) file_get_contents($state . '/public/' . $rollbackFeed['activation']), true, flags: JSON_THROW_ON_ERROR);
    assertSameValue([$secondFeed['snapshotId'], $firstFeed['snapshotId'], true], [$rollbackActivation['replacedSnapshotId'], $rollbackActivation['snapshotId'], $rollbackActivation['rollback']], 'rollback reports B to A changes with its own activation identity');
    assertSameValue(3, count($rollbackActivation['changes']['changed']), 'rollback includes the inverse project revision changes');
    $server->verify($publication->artifact($first->id));
    assertSameValue($previews->artifact($first->id, 'en/projects/' . $oldSlug . '/index.html')->contents, file_get_contents($state . '/public/en/projects/' . $oldSlug . '/index.html'), 'rollback restores exact old route bytes');
    assertTrueValue(!is_file($state . '/public/en/projects/verified-new-slug/index.html'), 'rollback removes abandoned owned route');
    assertSameValue(404, $server->get('/en/projects/verified-new-slug/')['status'], 'rollback removes the empty directory instead of leaving an Apache 403');

    $failurePreview = $previews->create($actorId);
    ApplicationFactory::previewRunner($configuration)->runOne('failure-preview');
    $failed = $publication->approve($failurePreview->id, $rollback->id, $actor);
    $once = true;
    $failingTarget = new FilesystemPublicationTarget($state . '/public', $state . '/media/publication', hash_hmac('sha256', 'publication-target-v1', $configuration->throttleSecret, true), static function (string $path) use (&$once): void {
        if ($once && str_ends_with($path, '.html')) {
            $once = false;
            throw new RuntimeException('Injected failure after an HTML replacement');
        }
    });
    $failing = new PublicationService(new MariaDbPublicationRepository($pdo), $previews, $failingTarget, $context, $package->manifest->id, file_get_contents($root . '/schemas/portable-cms-artifact-v1.schema.json'), $clock, $random);
    try {
        for ($index = 0; $index < 100; ++$index) {
            $failing->runBatch(8);
        }
        throw new RuntimeException('Failure injection not reached');
    } catch (RuntimeException $error) {
        assertSameValue('Injected failure after an HTML replacement', $error->getMessage(), 'real HTML replacement interruption exercised');
    }
    assertSameValue($rollback->id, $publication->head()->activeId, 'failed partial release never replaces active authority');
    finishPublication($resumed);
    assertSameValue('failed', (new MariaDbPublicationRepository($pdo))->find($failed->id)->status, 'recovery restores previous verified target before terminal failure');
    assertSameValue($rollback->id, $publication->head()->history->document()['releaseId'], 'failed operation never creates redirect history');
    assertSameValue($previews->artifact($first->id, 'en/projects/' . $oldSlug . '/index.html')->contents, file_get_contents($state . '/public/en/projects/' . $oldSlug . '/index.html'), 'recovery restores the old complete route set');
    $publication->forget($failed->id, $actor);
    $previews->delete($failurePreview->id, $actorId);
    assertSameValue(null, $previews->find($failurePreview->id), 'explicit retention release unpins preview cleanup');
    assertSameValue(5, (int) $pdo->query("SELECT COUNT(*) FROM cms_audit_events WHERE event_type = 'release_approved'")->fetchColumn(), 'approval actor events retained including the intervening release and failed intent');
    assertSameValue(5, (int) $pdo->query("SELECT COUNT(*) FROM cms_audit_events WHERE event_type = 'release_activated'")->fetchColumn(), 'only successful verified operations, including scheduled activation, are audited as activations');

    // A failed activation transaction must restore public bytes, not only the SQL pointer.
    $sqlPreview = $previews->create($actorId);
    ApplicationFactory::previewRunner($configuration)->runOne('sql-failure-preview');
    $sqlRelease = $publication->approve($sqlPreview->id, $rollback->id, $actor);
    $existingAudit = $pdo->query('SELECT id FROM cms_audit_events LIMIT 1')->fetchColumn();
    $duplicateAuditRandom = new class ($existingAudit) implements \Utsuri\PortableCms\Conventional\Identity\RandomSource {
        public function __construct(private readonly string $id) {}

        public function bytes(int $length): string
        {
            return hex2bin($this->id);
        }
    };
    $transactionFailure = new PublicationService(new MariaDbPublicationRepository($pdo), $previews, new FilesystemPublicationTarget($state . '/public', $state . '/media/publication', str_repeat('x', 32)), $context, $package->manifest->id, file_get_contents($root . '/schemas/portable-cms-artifact-v1.schema.json'), $clock, $duplicateAuditRandom);
    try {
        finishPublication($transactionFailure);
        throw new RuntimeException('Duplicate activation audit accepted');
    } catch (PDOException) {
    }
    assertSameValue($rollback->id, $publication->head()->activeId, 'failed SQL activation rolls back every authority change');
    finishPublication($publication);
    assertSameValue('failed', (new MariaDbPublicationRepository($pdo))->find($sqlRelease->id)->status, 'fresh worker restores preceding files after activation SQL failure');
    $server->verify($publication->artifact($first->id));

    $attentionPreview = $previews->create($actorId);
    ApplicationFactory::previewRunner($configuration)->runOne('attention-preview');
    $attention = $publication->approve($attentionPreview->id, $rollback->id, $actor);
    $alwaysFail = new FilesystemPublicationTarget($state . '/public', $state . '/media/publication', str_repeat('x', 32), static function (string $path): void {
        if (str_ends_with($path, '.html')) {
            throw new RuntimeException('Persistent target failure');
        }
    });
    $unavailable = new PublicationService(new MariaDbPublicationRepository($pdo), $previews, $alwaysFail, $context, $package->manifest->id, file_get_contents($root . '/schemas/portable-cms-artifact-v1.schema.json'), $clock, $random);
    for ($attempt = 0; $attempt < 3; ++$attempt) {
        try {
            finishPublication($unavailable);
            throw new RuntimeException('Persistent failure accepted');
        } catch (RuntimeException $error) {
            assertSameValue('Persistent target failure', $error->getMessage(), 'each bounded attempt fails at the real write boundary');
        }
    }
    assertSameValue('needs_attention', (new MariaDbPublicationRepository($pdo))->find($attention->id)->status, 'retry ceiling stops unbounded recovery');
    assertSameValue(false, $publication->runnable(), 'cron and HTTP wake do not spin on operator attention');
    try {
        $publication->retry($sqlRelease->id, $rollback->id, $actor);
        throw new RuntimeException('Stale recovery form accepted');
    } catch (PublicationConflict) {
    }
    $publication->retry($attention->id, $rollback->id, $actor);
    finishPublication($publication);
    $server->verify($publication->artifact($first->id));

    // A changed operator origin requires a new build and never adopts the old redirect authority.
    $newContext = PublicUrlContext::from(['formatVersion' => 1, 'baseUrl' => 'https://new-studio.example']);
    $newConfiguration = new RuntimeConfiguration('https://cms.example.test', $dsn, 'portable', 'integration-secret', $configuration->throttleSecret, null, $state . '/media', $configuration->mediaWakeStrategy, profileRoot: $package->root, publicUrlContext: $newContext, publicationRoot: $state . '/public');
    $newPreviews = new PreviewService(new MariaDbPreviewRepository($pdo, $package->manifest->designRegistries, $package->checksum, $compiler->preflight(...), $newContext), $package->manifest, $clock, $random, new FilesystemPreviewStore($configuration->mediaRoot . '/previews'));
    $newPublication = ApplicationFactory::publication($newConfiguration, $package->manifest, $newPreviews, $pdo);
    try {
        $newPublication->approve($first->id, $rollback->id, $actor);
        throw new RuntimeException('Old origin preview accepted');
    } catch (\Utsuri\PortableCms\Conventional\Publication\PublicationFailure) {
    }
    $newOriginPreview = $newPreviews->create($actorId);
    assertSameValue(true, ApplicationFactory::previewRunner($newConfiguration)->runOne('new-origin-preview')->completed, 'changed context rebuilds exact preview');
    $newOriginRelease = $newPublication->approve($newOriginPreview->id, $rollback->id, $actor);
    $newOriginFailure = new PublicationService(new MariaDbPublicationRepository($pdo), $newPreviews, $alwaysFail, $newContext, $package->manifest->id, file_get_contents($root . '/schemas/portable-cms-artifact-v1.schema.json'), $clock, $random);
    try {
        finishPublication($newOriginFailure);
        throw new RuntimeException('New origin failure accepted');
    } catch (RuntimeException $error) {
        assertSameValue('Persistent target failure', $error->getMessage(), 'changed-origin write interruption is exercised');
    }
    finishPublication($newPublication);
    assertSameValue('failed', (new MariaDbPublicationRepository($pdo))->find($newOriginRelease->id)->status, 'previous verified origin remains recoverable after configuration change');
    $server->verify($publication->artifact($first->id));
    assertSameValue($context->document(), $publication->head()->history->context->document(), 'failed changed-context target never adopts new history');

    $entry = $content->create('article', ['title' => array_fill_keys($package->manifest->locales, 'Disposable editorial record'), 'slug' => array_fill_keys($package->manifest->locales, 'disposable-record'), 'excerpt' => array_fill_keys($package->manifest->locales, 'Publication and removal acceptance.'), 'body' => array_fill_keys($package->manifest->locales, ['formatVersion' => 1, 'nodes' => [['type' => 'paragraph', 'children' => [['text' => 'A fictional authored article.', 'marks' => []]]]]])], $actorId);
    assertTrueValue($entry instanceof \Utsuri\PortableCms\Conventional\Content\RecordSnapshot, 'disposable collection record created');
    assertTrueValue($content->workflow()->delete('article', $entry->id, $entry->revisionNumber, $actor) instanceof \Utsuri\PortableCms\Conventional\Content\ValidationFailure, 'draft records require explicit archival first');
    $publishedEntry = $content->workflow()->transition('article', $entry->id, $entry->revisionNumber, 'published', $actor);
    assertTrueValue($publishedEntry instanceof \Utsuri\PortableCms\Conventional\Content\RecordSnapshot, 'authored article becomes eligible for the next exact preview');
    $articlePreview = $previews->create($actorId);
    assertSameValue(true, ApplicationFactory::previewRunner($configuration)->runOne('authored-preview')->completed, 'authored article preview builds');
    $articleRelease = $publication->approve($articlePreview->id, $rollback->id, $actor);
    finishPublication($publication);
    $articleFeed = json_decode((string) file_get_contents($state . '/public/feed/v1/current.json'), true, flags: JSON_THROW_ON_ERROR);
    $articleActivation = json_decode((string) file_get_contents($state . '/public/' . $articleFeed['activation']), true, flags: JSON_THROW_ON_ERROR);
    assertSameValue([$entry->id, $entry->id, $entry->id], array_column($articleActivation['changes']['added'], 'id'), 'a newly published article appears once per language in the activation additions');
    $server->verify($publication->artifact($articlePreview->id));
    foreach ($package->manifest->locales as $locale) {
        assertSameValue(200, $server->get('/' . $locale . '/articles/disposable-record/')['status'], 'authored article reaches the actual public target');
    }
    $entry = $publishedEntry;
    $archived = $content->workflow()->transition('article', $entry->id, $entry->revisionNumber, 'archived', $actor);
    assertTrueValue($archived instanceof \Utsuri\PortableCms\Conventional\Content\RecordSnapshot, 'disposable record archived');
    assertTrueValue($content->workflow()->delete('article', $entry->id, $entry->revisionNumber, $actor) instanceof \Utsuri\PortableCms\Conventional\Content\RecordConflict, 'stale delete cannot remove a newer revision');
    assertSameValue(true, $content->workflow()->delete('article', $entry->id, $archived->revisionNumber, $actor), 'unreferenced archived collection can be explicitly deleted');
    assertSameValue(null, $content->find('article', $entry->id), 'record and editorial history are removed together');
    assertSameValue(1, (int) $pdo->query("SELECT COUNT(*) FROM cms_audit_events WHERE event_type = 'archived_record_deleted'")->fetchColumn(), 'hard deletion is attributed');
    file_put_contents($state . '/public/en/articles/operator-note.txt', 'unmanaged sibling stays untouched');
    $removalPreview = $previews->create($actorId);
    assertSameValue(true, ApplicationFactory::previewRunner($configuration)->runOne('removal-preview')->completed, 'fresh snapshot excludes the deleted article');
    $removalClock = new ReleaseScheduleTestClock($clock->now());
    $removalService = scheduleTestService($pdo, $configuration, $previews, $removalClock);
    $removalAt = $removalClock->time->modify('+1 hour');
    $removalService->approve($removalPreview->id, $articleRelease->id, $actor, $removalAt);
    assertSameValue(false, $removalService->runnable(), 'unpublishing waits for the approved scheduled time');
    assertSameValue(200, $server->get('/en/articles/disposable-record/')['status'], 'scheduled removal leaves current public content intact');
    assertTrueValue(count($removalService->scheduledForRecord('article', $entry->id)) === 1, 'removal notices include membership of the preceding approved release');
    $removalClock->time = $removalAt;
    $removalService->runBatch(1);
    // Model process death after unlink but before the journal/directory cleanup advances.
    unlink($state . '/public/en/articles/disposable-record/index.html');
    finishPublication($publication);
    $server->verify($publication->artifact($removalPreview->id));
    $removalFeed = json_decode((string) file_get_contents($state . '/public/feed/v1/current.json'), true, flags: JSON_THROW_ON_ERROR);
    $removalActivation = json_decode((string) file_get_contents($state . '/public/' . $removalFeed['activation']), true, flags: JSON_THROW_ON_ERROR);
    assertSameValue([$entry->id, $entry->id, $entry->id], array_column($removalActivation['changes']['removed'], 'id'), 'removing an article reports its three localized projections');
    foreach ($package->manifest->locales as $locale) {
        assertSameValue(404, $server->get('/' . $locale . '/articles/disposable-record/')['status'], 'deleted article returns 404 rather than an empty-directory 403');
    }
    assertSameValue('unmanaged sibling stays untouched', file_get_contents($state . '/public/en/articles/operator-note.txt'), 'cleanup preserves unmanaged sibling content');
    assertTrueValue(is_dir($state . '/public'), 'cleanup never removes the public root');
    assertTrueValue(str_contains($previews->artifact($articlePreview->id, 'en/articles/disposable-record/index.html')->contents, 'Disposable editorial record'), 'deletion and cleanup never rewrite the frozen preview');

    // A language turned off leaves the next release, and its addresses lead to the primary language.
    $siteLanguages = ApplicationFactory::siteLanguages($package->manifest, $pdo);
    $siteLanguages->save(['ca' => 'public', 'es' => 'public', 'en' => 'off'], 'es', 0, $actor, true);
    $spanishProject = '/es/projects/' . $content->find('project', $projectId)->body['slug']['es'] . '/';
    $offPreview = $previews->create($actorId);
    assertSameValue(true, ApplicationFactory::previewRunner($configuration)->runOne('language-off-preview')->completed, 'a preview of the public languages builds');
    $offManifest = json_decode($previews->artifact($offPreview->id, 'artifact.json')->contents, true, 64, JSON_THROW_ON_ERROR);
    assertSameValue([], array_values(array_filter(array_column($offManifest['files'], 'path'), static fn(string $path): bool => str_starts_with($path, 'en/') || $path === 'search/en.json')), 'no page or search index is built in the language that is off');
    assertTrueValue(in_array(['from' => '/en/projects/verified-new-slug/', 'to' => $spanishProject, 'status' => 308], $offManifest['redirects'], true), 'its pages redirect to their counterpart in the primary language');
    assertTrueValue(in_array(['from' => '/en/', 'to' => '/es/', 'status' => 308], $offManifest['redirects'], true), 'its home page redirects to the primary home page');
    assertTrueValue(str_contains($previews->artifact($offPreview->id, 'index.html')->contents, '<html lang="es"'), 'the root shows the new primary language');
    $offRelease = $publication->approve($offPreview->id, $publication->head()->activeId, $actor);
    finishPublication($publication);
    $server->verify($publication->artifact($offPreview->id));
    $moved = $server->get('/en/projects/verified-new-slug/');
    assertTrueValue($moved['status'] === 308 && str_ends_with($moved['headers']['location'] ?? '', $spanishProject), 'the public address of a language that is off redirects');

    // Languages in preparation reach previews for review only, never a release.
    $siteLanguages->save(['ca' => 'public', 'es' => 'public', 'en' => 'preparation'], 'es', 1, $actor, false);
    $review = $previews->create($actorId, false, true);
    assertTrueValue($review->includePreparation, 'the review preview says it includes languages in preparation');
    assertSameValue(true, ApplicationFactory::previewRunner($configuration)->runOne('language-review-preview')->completed, 'a review preview with a language in preparation builds');
    assertTrueValue(str_contains($previews->artifact($review->id, 'en/projects/verified-new-slug/index.html')->contents, 'Archive of Mist'), 'the review preview draws the language in preparation');
    try {
        $publication->approve($review->id, $offRelease->id, $actor);
        throw new RuntimeException('A review preview with a language in preparation was released');
    } catch (\Utsuri\PortableCms\Conventional\Preview\PreviewNotReleasable) {
    }

    // Turning the language on again publishes its pages at their addresses with the text they had.
    $siteLanguages->save(['ca' => 'public', 'es' => 'public', 'en' => 'public'], 'ca', 2, $actor, false);
    $onPreview = $previews->create($actorId);
    assertSameValue(true, ApplicationFactory::previewRunner($configuration)->runOne('language-on-preview')->completed, 'a preview with the language on again builds');
    $onManifest = json_decode($previews->artifact($onPreview->id, 'artifact.json')->contents, true, 64, JSON_THROW_ON_ERROR);
    assertSameValue([], array_values(array_filter($onManifest['redirects'], static fn(array $redirect): bool => in_array($redirect['from'], ['/en/', '/en/projects/verified-new-slug/'], true))), 'returning pages are served, not redirected');
    $publication->approve($onPreview->id, $offRelease->id, $actor);
    finishPublication($publication);
    $server->verify($publication->artifact($onPreview->id));
    $returned = $server->get('/en/projects/verified-new-slug/');
    assertTrueValue($returned['status'] === 200 && str_contains($returned['body'], 'Archive of Mist'), 'the language that returns keeps its text');
    $project = $content->find('project', $projectId);
    $archivedProject = $content->workflow()->transition('project', $projectId, $project->revisionNumber, 'archived', $actor);
    assertTrueValue($archivedProject instanceof \Utsuri\PortableCms\Conventional\Content\RecordSnapshot, 'media-owning project archived');
    assertTrueValue($content->workflow()->delete('project', $projectId, $archivedProject->revisionNumber, $actor) instanceof \Utsuri\PortableCms\Conventional\Content\ValidationFailure, 'owned media and incoming references guard deletion');
    $server->verify($publication->artifact($onPreview->id));
    fwrite(STDOUT, "PASS real publication service: exact preview promotion, target/rules verification, CAS, retained rollback, slug history/export, process resume and HTML-failure recovery.\n");
    require_once __DIR__ . '/content-feed-publication-assertions.php';
    exerciseContentFeedRetention($pdo, $configuration, $previews, $actor, $approved->id, $next->id, $firstFeed, $secondFeed, $state, $server);
} finally {
    $server->close();
    removePublicationTestTree($state);
}
