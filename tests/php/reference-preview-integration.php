<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\ApplicationFactory;
use Utsuri\PortableCms\Conventional\Compiler\FrozenSnapshot;
use Utsuri\PortableCms\Conventional\Content\ContentService;
use Utsuri\PortableCms\Conventional\Content\MariaDbRecordRepository;
use Utsuri\PortableCms\Conventional\Content\ProfilePackage;
use Utsuri\PortableCms\Conventional\Identity\SystemClock;
use Utsuri\PortableCms\Conventional\Identity\SystemRandomSource;
use Utsuri\PortableCms\Conventional\Preview\FilesystemPreviewStore;
use Utsuri\PortableCms\Conventional\Preview\MariaDbPreviewRepository;
use Utsuri\PortableCms\Conventional\Preview\PreviewPreflightFailure;
use Utsuri\PortableCms\Conventional\Preview\PreviewService;
use Utsuri\PortableCms\Conventional\Preview\ProfilePreviewCompiler;
use Utsuri\PortableCms\Conventional\RuntimeConfiguration;

require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/bootstrap.php';
require_once dirname(__DIR__, 2) . '/tools/development/reference-media-seeder.php';
require_once __DIR__ . '/assertions.php';
require_once __DIR__ . '/preview-share-integration.php';
require_once __DIR__ . '/newest-preview-link-integration.php';
require_once __DIR__ . '/live-preview-integration.php';
require_once __DIR__ . '/responsive-images-integration.php';
require_once __DIR__ . '/preview-cleanup-integration.php';

$dsn = getenv('CMS_TEST_SANDBOX_DSN');
if (!is_string($dsn) || $dsn === '') {
    throw new RuntimeException('The isolated sandbox database is required');
}
$root = dirname(__DIR__, 2);
$package = ProfilePackage::load($root . '/profiles/reference-studio');
$pdo = new PDO($dsn, 'portable', 'integration-secret', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
$actor = $pdo->query('SELECT id FROM cms_users ORDER BY id LIMIT 1')->fetchColumn();
if (!is_string($actor)) {
    throw new RuntimeException('The sandbox seed must run first');
}
$state = sys_get_temp_dir() . '/portable-reference-preview-' . bin2hex(random_bytes(8));
mkdir($state, 0700);
mkdir($state . '/media', 0700);

function removeReferencePreviewIntegrationTree(string $path): void
{
    foreach (new DirectoryIterator($path) as $entry) {
        if ($entry->isDot()) {
            continue;
        }
        if ($entry->isDir() && !$entry->isLink()) {
            removeReferencePreviewIntegrationTree($entry->getPathname());
        } elseif (!unlink($entry->getPathname())) {
            throw new RuntimeException('Preview test cleanup failed');
        }
    }
    if (!rmdir($path)) {
        throw new RuntimeException('Preview test cleanup failed');
    }
}

try {
    $snapshot = FrozenSnapshot::load($package->root . '/fixtures/snapshot.json', $root . '/schemas/portable-cms-snapshot-v1.schema.json', $package);
    ReferenceMediaSeeder::seed($pdo, $package, $snapshot, $actor, $state . '/media');
    $publicContext = \Utsuri\PortableCms\Conventional\Compiler\PublicUrlContext::from(['formatVersion' => 1, 'baseUrl' => 'https://studio.example/site']);
    $configuration = new RuntimeConfiguration('http://localhost:4181', $dsn, 'portable', 'integration-secret', str_repeat('s', 32), null, $state . '/media', 'cron_only', allowInsecureLoopback: true, profileRoot: $package->root, publicUrlContext: $publicContext);
    $clock = new SystemClock();
    $random = new SystemRandomSource();
    $media = ApplicationFactory::mediaRunner($configuration)->runBounded('reference-integration', 10, 60);
    assertSameValue(6, count(array_filter($media, static fn($run): bool => $run->completed)), 'fictional media and available extra widths must enter through the actual derivative queue');
    verifyRasterWidthRecovery($pdo, $configuration, $actor, $package);
    verifyLivePreview($pdo, $configuration, $package, 'sandbox@example.test', 'sandbox integration password');
    $content = new ContentService(new MariaDbRecordRepository($pdo), $package->manifest, $clock, $random);
    $publicationActor = new \Utsuri\PortableCms\Conventional\Identity\SessionContext('preview-fixture', 'authenticated', $actor, 'sandbox@example.test', 'Administrator', 'administrator', 'token', 'csrf', $clock->now()->modify('+1 hour'), false);
    $savedTerm = $content->find('discipline', '80000000000000000000000000000001');
    assertTrueValue($savedTerm !== null, 'saved sandbox terminology exists');
    $content->workflow()->transition('discipline', $savedTerm->id, $savedTerm->revisionNumber, 'published', $publicationActor);
    $compiler = new ProfilePreviewCompiler($package, $configuration->mediaRoot, $root . '/schemas');
    $history = \Utsuri\PortableCms\Conventional\Compiler\PublishedSlugHistory::afterVerifiedActivation(
        new \Utsuri\PortableCms\Conventional\Compiler\RouteDiscovery($publicContext, [new \Utsuri\PortableCms\Conventional\Compiler\DiscoveryRoute('/en/previous-project/', 'en', 'record:10000000000000000000000000000001')]),
        $package->manifest->id,
        ['releaseId' => str_repeat('a', 32), 'artifactSha256' => str_repeat('b', 64), 'activatedAt' => '2026-10-04T12:00:00Z', 'verificationId' => str_repeat('c', 32)],
    );
    $repository = new MariaDbPreviewRepository($pdo, $package->manifest->designRegistries, $package->checksum, $compiler->preflight(...), $publicContext, $history);
    $service = new PreviewService($repository, $package->manifest, $clock, $random, new FilesystemPreviewStore($configuration->mediaRoot . '/previews'));
    $queued = $service->create($actor);
    $library = new \Utsuri\PortableCms\Conventional\Media\MediaLibrary(new \Utsuri\PortableCms\Conventional\Media\MariaDbMediaLibrary($pdo), new \Utsuri\PortableCms\Conventional\Media\FilesystemMediaStore($configuration->mediaRoot), $clock, $random);
    $assetId = 'd0000000000000000000000000000001';
    $asset = $library->find($assetId);
    $editedText = \Utsuri\PortableCms\Conventional\Media\MediaText::from(['formatVersion' => 1, 'decorative' => false, 'translations' => [
        ['locale' => 'ca', 'alternativeText' => 'Changed after media freezing', 'caption' => 'Changed caption', 'credit' => 'Changed credit'],
    ]]);
    assertSameValue(true, $library->rename($assetId, $asset->metadataRevision, $asset->name, $actor, $editedText, new \Utsuri\PortableCms\Conventional\Media\FocalPoint(85, 20)), 'live media can advance after snapshot capture');
    $content = new ContentService(new MariaDbRecordRepository($pdo), $package->manifest, $clock, $random);
    $termId = '80000000000000000000000000000001';
    $term = $content->find('discipline', $termId);
    if ($term === null) {
        throw new RuntimeException('Frozen taxonomy fixture missing');
    }
    $changedTerm = $content->update('discipline', $termId, $term->revisionNumber, ['label' => array_fill_keys($package->manifest->locales, 'Changed after freezing')], $actor);
    assertTrueValue($changedTerm instanceof \Utsuri\PortableCms\Conventional\Content\RecordSnapshot, 'new taxonomy words are saved');
    $content->workflow()->transition('discipline', $termId, $changedTerm->revisionNumber, 'published', $publicationActor);
    $changedConfiguration = new RuntimeConfiguration('http://localhost:4181', $dsn, 'portable', 'integration-secret', str_repeat('s', 32), null, $state . '/media', 'cron_only', allowInsecureLoopback: true, profileRoot: $package->root, publicUrlContext: \Utsuri\PortableCms\Conventional\Compiler\PublicUrlContext::from(['formatVersion' => 1, 'baseUrl' => 'https://changed.example']));
    $result = ApplicationFactory::previewRunner($changedConfiguration)->runOne('reference-preview');
    assertSameValue(true, $result->completed, 'configured profile must compile through the conventional worker');
    $preview = $service->find($queued->id);
    assertSameValue('ready', $preview?->status, 'verified preview becomes ready');
    $home = $service->artifact($queued->id, 'en/disciplines/index.html');
    assertTrueValue($home !== null && str_contains($home->contents, 'Local architecture'), 'the frozen revision must survive a later live edit');
    assertTrueValue(!str_contains($home->contents, 'Changed after freezing'), 'a worker must not read live record content');
    $imagePage = $service->artifact($queued->id, 'en/projects/archive-of-mist/index.html');
    assertTrueValue(str_contains($imagePage->contents, 'cms-focal-50-50') && !str_contains($imagePage->contents, 'cms-focal-85-20'), 'queued preview retains its frozen focal point');
    assertTrueValue($imagePage !== null && str_contains($imagePage->contents, 'Abstract study with a gradient') && str_contains($imagePage->contents, 'Fictional colour study') && !str_contains($imagePage->contents, 'Changed after media freezing'), 'queued preview retains authored media text despite later library edits');
    assertTrueValue(str_contains($home->contents, 'https://studio.example/site/en/disciplines/') && !str_contains($home->contents, 'https://changed.example'), 'durably stored URL context survives a worker configuration change');
    $manifest = $service->artifact($queued->id, 'artifact.json');
    assertTrueValue($manifest !== null && str_contains($manifest->contents, $package->checksum), 'artifact provenance must pin the verified package');
    $manifestDocument = json_decode($manifest->contents, true, flags: JSON_THROW_ON_ERROR);
    assertSameValue([['from' => '/en/previous-project/', 'to' => '/en/projects/archive-of-mist/', 'status' => 308]], $manifestDocument['redirects'], 'frozen verified history survives durable queue storage');
    $delivery = $service->artifact($queued->id, 'delivery/apache24.conf');
    assertTrueValue($delivery !== null && str_contains($delivery->contents, 'https://studio.example/site/en/projects/archive-of-mist/'), 'redirect target retains the frozen origin rather than live worker configuration');
    $next = $service->create($actor);
    $abandoned = $repository->claim('interrupted-worker', bin2hex($random->bytes(32)), $clock->now(), $clock->now()->modify('+15 minutes'));
    assertTrueValue($abandoned !== null, 'a queued build should acquire a lease');
    $expire = $pdo->prepare('UPDATE cms_jobs SET lease_expires_at = :expired WHERE id = :id');
    $expire->execute(['expired' => $clock->now()->modify('-1 second')->format('Y-m-d H:i:s.u'), 'id' => $next->jobId]);
    assertSameValue(true, ApplicationFactory::previewRunner($configuration)->runOne('cron-recovery')->completed, 'cron must recover an interrupted expired lease');
    $exhausted = $service->create($actor);
    for ($attempt = 0; $attempt < 3; ++$attempt) {
        assertTrueValue($repository->claim('interrupted-final-worker', bin2hex($random->bytes(32)), $clock->now(), $clock->now()->modify('+15 minutes')) !== null, 'each available attempt can acquire the job');
        $expire->execute(['expired' => $clock->now()->modify('-1 second')->format('Y-m-d H:i:s.u'), 'id' => $exhausted->jobId]);
    }
    assertSameValue(null, $repository->claim('recovery', bin2hex($random->bytes(32)), $clock->now(), $clock->now()->modify('+15 minutes')), 'attempt exhaustion must not restart the job');
    assertSameValue('needs_attention', $service->find($exhausted->id)?->status, 'a dead final attempt must not leave the preview stuck forever');
    $service->delete($exhausted->id, $actor);
    $before = (int) $pdo->query('SELECT COUNT(*) FROM cms_previews')->fetchColumn();
    $preflight = new MariaDbPreviewRepository($pdo, $package->manifest->designRegistries, $package->checksum, static function (): void {
        throw new PreviewPreflightFailure([new \Utsuri\PortableCms\Conventional\I18n\Message('preflight.issue.field_locale', ['field' => 'Title', 'locale' => 'en', 'reason' => new \Utsuri\PortableCms\Conventional\I18n\Message('preflight.reason.missing_translation')])]);
    });
    try {
        (new PreviewService($preflight, $package->manifest, $clock, $random))->create($actor);
        throw new RuntimeException('Failed preflight unexpectedly queued');
    } catch (PreviewPreflightFailure) {
    }
    assertSameValue($before, (int) $pdo->query('SELECT COUNT(*) FROM cms_previews')->fetchColumn(), 'failed preflight must roll back without an orphan build');
    $failed = $service->create($actor);
    $statement = $pdo->prepare('SELECT snapshot_json FROM cms_previews WHERE id = :id');
    $statement->execute(['id' => $failed->id]);
    $poisoned = json_decode((string) $statement->fetchColumn(), true, flags: JSON_THROW_ON_ERROR);
    foreach ($poisoned['records'] as &$record) {
        if ($record['typeId'] === 'home') {
            $record['relationships']['featuredContent'] = [str_repeat('f', 32)];
        }
    }
    unset($record);
    $pdo->prepare('UPDATE cms_previews SET snapshot_json = :snapshot WHERE id = :id')->execute(['snapshot' => json_encode($poisoned, JSON_THROW_ON_ERROR), 'id' => $failed->id]);
    $diagnosticConfiguration = new RuntimeConfiguration('http://localhost:4181', $dsn, 'portable', 'integration-secret', str_repeat('s', 32), $state . '/preview-errors.log', $state . '/media', 'cron_only', allowInsecureLoopback: true, profileRoot: $package->root, publicUrlContext: $publicContext);
    $failureRun = ApplicationFactory::previewRunner($diagnosticConfiguration)->runOne('diagnostic-worker');
    assertSameValue(false, $failureRun->completed, 'a worker reports content that became invalid after enqueue');
    $failedStatus = $service->find($failed->id);
    assertSameValue('needs_attention', $failedStatus->status, 'known content failures do not waste retries');
    $diagnostics = $failedStatus->diagnostics;
    assertTrueValue($diagnostics !== null && $diagnostics->reference !== null, 'safe causes and reference survive the job transaction');
    $text = implode(' ', array_map(\Utsuri\PortableCms\Conventional\I18n\Translator::english()->message(...), $diagnostics->issues));
    assertTrueValue(str_contains($text, 'Home') && str_contains($text, 'Featured'), 'durable failure names the owner and broken relationship');
    $failurePage = \Utsuri\PortableCms\Conventional\View\Pages::previewStatus('csrf', $package->manifest, $failedStatus);
    assertTrueValue(str_contains($failurePage, $diagnostics->reference) && str_contains($failurePage, 'available record'), 'background failure page shows the stored causes and log reference');
    $entries = array_map(static fn(string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), explode("\n", trim((string) file_get_contents($state . '/preview-errors.log'))));
    assertSameValue(1, count(array_filter($entries, static fn(array $entry): bool => ($entry['reference'] ?? null) === $diagnostics->reference)), 'the editor reference resolves to one private technical event');
    $listed = array_values(array_filter($service->list(), static fn($status): bool => $status->id === $failed->id));
    assertSameValue($diagnostics->reference, $listed[0]->diagnostics->reference, 'list and detail hydration retain the same diagnostic');
    $service->delete($failed->id, $actor);
    $following = $service->create($actor);
    assertSameValue(true, ApplicationFactory::previewRunner($configuration)->runOne('newest-link-worker')->completed, 'newest link fixture builds a real newer preview');
    verifyNewestPreviewLinks($pdo, $service, $next->id, $following->id, $actor, $package->manifest, $random, $diagnosticConfiguration);
    verifyPreviewSharing($pdo, $service, $next->id, $actor, $package->manifest, $random, $diagnosticConfiguration);
    $service->delete($queued->id, $actor);
    assertSameValue(null, $service->find($queued->id), 'guarded deletion removes the completed snapshot');
    assertTrueValue(!is_dir($configuration->mediaRoot . '/previews/artifacts/' . $queued->id), 'guarded cleanup removes only that artifact');
    verifyPreviewCleanup($pdo, $dsn, $state, $actor, $package->manifest);
    fwrite(STDOUT, "PASS configured Reference Studio preview: frozen MariaDB revisions, compiler, media, provenance, lease recovery, preflight rollback and deletion\n");
} finally {
    removeReferencePreviewIntegrationTree($state);
}
