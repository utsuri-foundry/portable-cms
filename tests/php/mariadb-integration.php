<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Content\ContentService;
use Utsuri\PortableCms\Conventional\Content\MariaDbRecordRepository;
use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Content\RecordConflict;
use Utsuri\PortableCms\Conventional\Database\MigrationRunner;
use Utsuri\PortableCms\Conventional\Export\ExportService;
use Utsuri\PortableCms\Conventional\Export\MariaDbExportRepository;
use Utsuri\PortableCms\Conventional\Identity\AdministratorBootstrap;
use Utsuri\PortableCms\Conventional\Identity\IdentityService;
use Utsuri\PortableCms\Conventional\Identity\MariaDbIdentityRepository;
use Utsuri\PortableCms\Conventional\Installation\InstallationGate;
use Utsuri\PortableCms\Conventional\Installation\InstallationService;
use Utsuri\PortableCms\Conventional\Media\FilesystemMediaStore;
use Utsuri\PortableCms\Conventional\Media\FilesystemUploadStorage;
use Utsuri\PortableCms\Conventional\Media\ImagickRasterProcessor;
use Utsuri\PortableCms\Conventional\Media\MariaDbMediaJobRepository;
use Utsuri\PortableCms\Conventional\Media\MariaDbUploadRepository;
use Utsuri\PortableCms\Conventional\Media\MediaJobRunner;
use Utsuri\PortableCms\Conventional\Media\MediaUploadService;
use Utsuri\PortableCms\Conventional\Preview\FilesystemPreviewStore;
use Utsuri\PortableCms\Conventional\Preview\GateZeroPreviewCompiler;
use Utsuri\PortableCms\Conventional\Preview\MariaDbPreviewRepository;
use Utsuri\PortableCms\Conventional\Preview\PreviewJobRunner;
use Utsuri\PortableCms\Conventional\Preview\PreviewService;
use Utsuri\PortableCms\Conventional\RuntimeConfiguration;

require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/bootstrap.php';
require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/database/migration-runner.php';
require_once __DIR__ . '/assertions.php';
require_once __DIR__ . '/identity-test.php';

$dsn = getenv('CMS_TEST_DB_DSN');
if (!is_string($dsn) || $dsn === '') {
    fwrite(STDERR, "CMS_TEST_DB_DSN is required.\n");
    exit(64);
}
$pdo = new PDO($dsn, 'portable', 'integration-secret', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$migrationDirectory = dirname(__DIR__, 2) . '/engine/migrations';
$migrations = glob($migrationDirectory . '/*.sql');
if ($migrations === false) {
    throw new RuntimeException('Migration fixtures could not be read');
}
sort($migrations, SORT_STRING);
$migration = $migrationDirectory . '/0001-identity.sql';
$runner = new MigrationRunner($pdo);
$identityOnly = $runner->apply([$migration]);
$first = $runner->apply($migrations);
$second = $runner->apply($migrations);
assertSameValue(1, $identityOnly->applied, 'identity migration should establish the existing-host baseline');
assertSameValue(count($migrations) - 1, $first->applied, 'content migration should upgrade an identity-only host');
assertSameValue(1, $first->skipped, 'existing identity migration should remain complete during upgrade');
assertSameValue(count($migrations), $second->skipped, 'completed migrations should be skipped');
$changedDirectory = sys_get_temp_dir() . '/portable-cms-migration-' . bin2hex(random_bytes(8));
mkdir($changedDirectory, 0700);
$changedMigration = $changedDirectory . '/0001-identity.sql';
file_put_contents($changedMigration, (string) file_get_contents($migration) . "\n-- changed\n");
try {
    try {
        $runner->apply([$changedMigration]);
        throw new RuntimeException('changed completed migration unexpectedly ran');
    } catch (RuntimeException $error) {
        assertTrueValue(str_contains($error->getMessage(), 'checksum'), 'changed migration should fail by checksum');
    }
} finally {
    @unlink($changedMigration);
    @rmdir($changedDirectory);
}

$clock = new IdentityTestClock(new DateTimeImmutable('2026-09-30T15:00:00Z'));
$random = new IdentityTestRandom();
$bootstrap = new AdministratorBootstrap($pdo, $clock, $random);
$userId = $bootstrap->create(
    'ADMIN@Example.Test',
    'MariaDB Administrator',
    'a sufficiently long password',
);
assertSameValue(32, strlen($userId), 'bootstrap should return an opaque user ID');
$user = $pdo->query('SELECT login_name, password_hash FROM cms_users')->fetch(PDO::FETCH_ASSOC);
assertSameValue('admin@example.test', $user['login_name'] ?? null, 'login should be normalized before storage');
assertTrueValue(
    is_string($user['password_hash'] ?? null)
        && password_verify('a sufficiently long password', $user['password_hash']),
    'bootstrap should store a current password hash',
);
assertSameValue(
    'closed',
    $pdo->query('SELECT status FROM cms_bootstrap_state WHERE singleton_id = 1')->fetchColumn(),
    'bootstrap must close transactionally',
);
try {
    $bootstrap->create('second@example.test', 'Second', 'another sufficiently long password');
    throw new RuntimeException('second bootstrap unexpectedly succeeded');
} catch (RuntimeException $error) {
    assertTrueValue(str_contains($error->getMessage(), 'closed'), 'second bootstrap should fail closed');
}

$repository = new MariaDbIdentityRepository($pdo);
$identity = new IdentityService($repository, $clock, $random, str_repeat('m', 32));
$anonymous = $identity->openSession(null, null);
$authenticated = $identity->login(
    $anonymous,
    'admin@example.test',
    'a sufficiently long password',
    '192.0.2.20',
);
assertSameValue(true, $authenticated->authenticated, 'real repository login should authenticate');
assertSameValue(
    'owner',
    $authenticated->session->role,
    'real repository session should retain the role',
);
$reopened = $identity->openSession(
    $authenticated->session->sessionToken,
    $authenticated->session->csrfToken,
);
assertSameValue(true, $reopened->administrator(), 'stored binary token hashes should reopen the session');

$failedSession = $identity->openSession(null, null);
for ($attempt = 0; $attempt < 5; $attempt++) {
    $failed = $identity->login($failedSession, 'missing@example.test', 'incorrect', '192.0.2.21');
    assertSameValue(401, $failed->failureStatus, 'attempt inside the limit should fail generically');
}
$blocked = $identity->login($failedSession, 'missing@example.test', 'incorrect', '192.0.2.21');
assertSameValue(429, $blocked->failureStatus, 'MariaDB throttle should block after the limit');

$auditCount = (int) $pdo->query('SELECT COUNT(*) FROM cms_audit_events')->fetchColumn();
assertSameValue(7, $auditCount, 'bootstrap and login outcomes should be attributed in the audit log');

$content = new ContentService(
    new MariaDbRecordRepository($pdo),
    ProfileManifest::gateZero(),
    $clock,
    $random,
);
$created = $content->create('portfolio-entry', [
    'title' => 'Real generic record',
    'summary' => 'Stored as immutable JSON.',
], $userId);
assertTrueValue(
    $created instanceof \Utsuri\PortableCms\Conventional\Content\RecordSnapshot,
    'valid content should create a record',
);
assertSameValue(1, $created->revisionNumber, 'new records should begin at revision one');
$clock->current = $clock->current->modify('+1 second');
$updated = $content->update('portfolio-entry', $created->id, 1, [
    'title' => 'Revised generic record',
    'summary' => 'Second immutable revision.',
], $userId);
assertTrueValue(
    $updated instanceof \Utsuri\PortableCms\Conventional\Content\RecordSnapshot,
    'current content should revise successfully',
);
assertSameValue(2, $updated->revisionNumber, 'successful updates should advance the revision');
$stale = $content->update('portfolio-entry', $created->id, 1, [
    'title' => 'Stale overwrite',
    'summary' => '',
], $userId);
assertTrueValue($stale instanceof RecordConflict, 'stale updates should become explicit conflicts');
assertSameValue('Revised generic record', $stale->current->body['title'], 'conflict should return current content');
assertSameValue(
    2,
    (int) $pdo->query('SELECT COUNT(*) FROM cms_record_revisions')->fetchColumn(),
    'a stale update must not create a third immutable revision',
);
$topic = $content->create('topic', [
    'label' => ['ca' => 'Habitatge', 'es' => 'Vivienda', 'en' => 'Housing'],
], $userId);
assertTrueValue(
    $topic instanceof \Utsuri\PortableCms\Conventional\Content\RecordSnapshot,
    'localized taxonomy term should use the generic repository',
);
$article = $content->create('article', [
    'title' => 'Related article',
    'body' => 'Generic relationship projection.',
    'featuredEntry' => $updated->id,
    'topics' => [$topic->id],
], $userId);
assertTrueValue(
    $article instanceof \Utsuri\PortableCms\Conventional\Content\RecordSnapshot,
    'article with typed relationships should save',
);
$projected = $pdo->query(<<<'SQL'
SELECT field_id, relation_kind, target_record_id, revision_id
FROM cms_record_relationship_index
ORDER BY field_id
SQL)->fetchAll(PDO::FETCH_ASSOC);
assertSameValue(2, count($projected), 'article should project record and taxonomy relationships');
assertSameValue('featuredEntry', $projected[0]['field_id'] ?? null, 'typed field should be indexed');
assertSameValue('record', $projected[0]['relation_kind'] ?? null, 'typed field kind should be explicit');
assertSameValue($updated->id, $projected[0]['target_record_id'] ?? null, 'typed target should be exact');
assertSameValue('topics', $projected[1]['field_id'] ?? null, 'taxonomy field should be indexed');
assertSameValue('taxonomy', $projected[1]['relation_kind'] ?? null, 'taxonomy kind should be explicit');
assertSameValue($article->revisionId, $projected[0]['revision_id'] ?? null, 'index should identify its source revision');

$articleRevision = $content->update('article', $article->id, 1, [
    'title' => 'Related article revised',
    'body' => 'Topic removed.',
    'featuredEntry' => $updated->id,
    'topics' => [],
], $userId);
assertTrueValue(
    $articleRevision instanceof \Utsuri\PortableCms\Conventional\Content\RecordSnapshot,
    'relationship revision should save',
);
$remainingRelationship = $pdo->query(<<<'SQL'
SELECT field_id, revision_id
FROM cms_record_relationship_index
SQL)->fetch(PDO::FETCH_ASSOC);
assertSameValue(
    1,
    (int) $pdo->query('SELECT COUNT(*) FROM cms_record_relationship_index')->fetchColumn(),
    'new authoritative revision should replace the rebuildable relationship index',
);
assertSameValue('featuredEntry', $remainingRelationship['field_id'] ?? null, 'removed topic should leave no stale index row');
assertSameValue(
    $articleRevision->revisionId,
    $remainingRelationship['revision_id'] ?? null,
    'remaining relationship should point at the current revision',
);
assertSameValue(
    12,
    (int) $pdo->query('SELECT COUNT(*) FROM cms_audit_events')->fetchColumn(),
    'records, taxonomy terms and relationship revisions should append attributed audit events',
);

$mediaRoot = sys_get_temp_dir() . '/portable-cms-mariadb-media-' . bin2hex(random_bytes(8));
$mediaStorage = new FilesystemUploadStorage($mediaRoot);
$mediaUploads = new MediaUploadService(
    new MariaDbUploadRepository($pdo),
    $mediaStorage,
    $clock,
    $random,
);
$fixtureImage = new Imagick();
$fixtureImage->newImage(1200, 800, new ImagickPixel('#6f806a'));
$fixtureImage->setImageFormat('png');
$imageBytes = $fixtureImage->getImagesBlob();
$fixtureImage->clear();
$fixtureImage->destroy();
try {
    $upload = $mediaUploads->create(
        'portfolio-entry',
        $updated->id,
        $updated->revisionNumber,
        'mariadb.png',
        'image/png',
        strlen($imageBytes),
        'mariadb-interrupted-upload',
        $userId,
    );
    $stream = fopen('php://temp', 'w+b');
    fwrite($stream, $imageBytes);
    rewind($stream);
    try {
        $mediaUploads->uploadPart($upload->id, 1, strlen($imageBytes), $stream, $userId);
    } finally {
        fclose($stream);
    }
    $resumedService = new MediaUploadService(
        new MariaDbUploadRepository($pdo),
        new FilesystemUploadStorage($mediaRoot),
        $clock,
        $random,
    );
    $resumed = $resumedService->create(
        'portfolio-entry',
        $updated->id,
        $updated->revisionNumber,
        'mariadb.png',
        'image/png',
        strlen($imageBytes),
        'mariadb-interrupted-upload',
        $userId,
    );
    assertSameValue($upload->id, $resumed->id, 'MariaDB upload should resume by client ID');
    assertSameValue(1, count($resumed->parts), 'MariaDB progress should survive a new service instance');
    $completedUpload = $resumedService->complete($upload->id, $userId);
    assertSameValue('completed', $completedUpload['session']->status, 'MariaDB upload should complete');
    assertSameValue('queued', $completedUpload['job']['status'], 'completion should transactionally queue derivatives');
    assertSameValue(
        hash('sha256', $imageBytes),
        $completedUpload['media']['checksum'],
        'MariaDB media checksum should describe the assembled private original',
    );
    $retriedCompletion = $resumedService->complete($upload->id, $userId);
    assertSameValue(
        $completedUpload['media']['id'],
        $retriedCompletion['media']['id'],
        'MariaDB completion should be idempotent after a lost response',
    );
    assertSameValue(1, (int) $pdo->query('SELECT COUNT(*) FROM cms_media')->fetchColumn(), 'one media row should exist');
    assertSameValue(1, (int) $pdo->query('SELECT COUNT(*) FROM cms_jobs')->fetchColumn(), 'one derivative job should exist');
    assertSameValue(
        13,
        (int) $pdo->query('SELECT COUNT(*) FROM cms_audit_events')->fetchColumn(),
        'upload completion should append one attributed audit event',
    );
    $mediaJobs = new MariaDbMediaJobRepository($pdo);
    $abandoned = $mediaJobs->claim(
        'crashed-worker',
        str_repeat('a', 64),
        $clock->current,
        $clock->current->modify('+15 minutes'),
    );
    assertSameValue(1, $abandoned?->attempts, 'first worker should hold attempt one');
    $overlapPdo = new PDO($dsn, 'portable', 'integration-secret', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $overlapJobs = new MariaDbMediaJobRepository($overlapPdo);
    $overlap = $overlapJobs->claim(
        'overlapping-worker',
        str_repeat('b', 64),
        $clock->current,
        $clock->current->modify('+15 minutes'),
    );
    assertSameValue(null, $overlap, 'a second database connection must not steal a live lease');
    assertSameValue(
        1,
        (int) $pdo->query('SELECT attempts FROM cms_jobs')->fetchColumn(),
        'rejected overlap must not consume an attempt',
    );
    $clock->current = $clock->current->modify('+16 minutes');
    $mediaRunner = new MediaJobRunner(
        $overlapJobs,
        new FilesystemMediaStore($mediaRoot),
        new ImagickRasterProcessor(),
        $clock,
        $random,
    );
    $processed = $mediaRunner->runOne('mariadb-integration');
    assertSameValue(true, $processed->completed, 'leased MariaDB derivative job should complete');
    assertSameValue(
        2,
        (int) $pdo->query('SELECT attempts FROM cms_jobs')->fetchColumn(),
        'expired work should be reclaimed as a new bounded attempt',
    );
    assertSameValue(
        'completed',
        $pdo->query('SELECT status FROM cms_jobs')->fetchColumn(),
        'completed derivative should close the durable job',
    );
    assertSameValue(
        'ready',
        $pdo->query('SELECT status FROM cms_media')->fetchColumn(),
        'verified derivative should make media ready',
    );
    $derivative = $pdo->query(<<<'SQL'
SELECT variant, object_key, mime, width, height, byte_size, checksum,
       processor_id, recipe_version, parameters_json
FROM cms_media_derivatives
SQL)->fetch(PDO::FETCH_ASSOC);
    assertSameValue('960-webp', $derivative['variant'] ?? null, 'canonical first derivative should be WebP');
    assertSameValue('imagick', $derivative['processor_id'] ?? null, 'processor provenance should be stored');
    assertSameValue(2, (int) ($derivative['recipe_version'] ?? 0), 'recipe version should be durable');
    $derivativePath = $mediaRoot . '/' . ($derivative['object_key'] ?? '');
    assertSameValue(true, is_file($derivativePath), 'private derivative bytes should exist');
    assertSameValue(
        (int) ($derivative['byte_size'] ?? 0),
        filesize($derivativePath),
        'derivative file size should match MariaDB metadata',
    );
    assertSameValue(
        (string) ($derivative['checksum'] ?? ''),
        hash_file('sha256', $derivativePath),
        'derivative checksum should match immutable bytes',
    );
    assertSameValue(
        14,
        (int) $pdo->query('SELECT COUNT(*) FROM cms_audit_events')->fetchColumn(),
        'derivative completion should append one attributed audit event',
    );
    $previewRepository = new MariaDbPreviewRepository($pdo);
    $previewStore = new FilesystemPreviewStore($mediaRoot . '/previews');
    $previewService = new PreviewService(
        $previewRepository,
        ProfileManifest::gateZero(),
        $clock,
        $random,
        $previewStore,
    );
    $queuedPreview = $previewService->create($userId, includeDrafts: true);
    assertSameValue('queued', $queuedPreview->status, 'preview creation should freeze and queue atomically');
    assertSameValue(
        2,
        (int) $pdo->query('SELECT COUNT(*) FROM cms_jobs')->fetchColumn(),
        'preview should reuse the durable job table',
    );
    $previewRunner = new PreviewJobRunner(
        $previewRepository,
        $previewStore,
        new GateZeroPreviewCompiler($mediaRoot),
        $clock,
        $random,
    );
    $previewResult = $previewRunner->runOne('mariadb-preview');
    assertSameValue(true, $previewResult->completed, 'durable preview job should compile successfully');
    $readyPreview = $previewService->find($queuedPreview->id);
    assertSameValue('ready', $readyPreview?->status, 'verified preview should become ready');
    assertSameValue(
        7,
        count($readyPreview?->manifest['artifacts'] ?? []),
        'frozen MariaDB preview should include both types, indexes, CSS, and media',
    );
    $rootArtifact = $previewService->artifact($queuedPreview->id, 'index.html');
    assertSameValue('text/html; charset=utf-8', $rootArtifact?->mime, 'preview root should retain its exact media type');
    assertTrueValue(
        str_contains($rootArtifact->contents, 'Related article revised'),
        'preview should render the frozen current article revision',
    );
    assertSameValue(
        16,
        (int) $pdo->query('SELECT COUNT(*) FROM cms_audit_events')->fetchColumn(),
        'preview queue and completion should append attributed audit events',
    );
    $previewService->delete($queuedPreview->id, $userId);
    assertSameValue(null, $previewService->find($queuedPreview->id), 'deleted preview should leave no database record');
    assertSameValue(
        1,
        (int) $pdo->query('SELECT COUNT(*) FROM cms_jobs')->fetchColumn(),
        'preview deletion should remove its terminal job without touching media history',
    );
    assertSameValue(
        false,
        is_dir($mediaRoot . '/previews/artifacts/' . $queuedPreview->id),
        'preview deletion should purge quarantined artifact bytes',
    );
    assertSameValue(
        17,
        (int) $pdo->query('SELECT COUNT(*) FROM cms_audit_events')->fetchColumn(),
        'preview deletion should remain attributed after its record is removed',
    );
    $exports = new ExportService(
        new MariaDbExportRepository($pdo, publicUrlContext: \Utsuri\PortableCms\Conventional\Compiler\PublicUrlContext::from(['formatVersion' => 1, 'baseUrl' => 'https://export-source.example/site'])),
        ProfileManifest::gateZero(),
        $clock,
        $random,
    );
    $terminologyRepository = new \Utsuri\PortableCms\Conventional\Content\MariaDbTerminologyRepository($pdo);
    $terminologyLabels = [['typeId' => 'portfolio-entry', 'singular' => 'Case', 'plural' => 'Cases']];
    $named = $terminologyRepository->save(
        new \Utsuri\PortableCms\Conventional\Content\EditorialTerminology('portable-gate-zero', 0, $terminologyLabels),
        $userId,
        bin2hex($random->bytes(16)),
        $clock->now(),
    );
    assertSameValue(1, $named->revision, 'first naming update must create an attributed revision');
    try {
        $terminologyRepository->save(
            new \Utsuri\PortableCms\Conventional\Content\EditorialTerminology('portable-gate-zero', 0, $terminologyLabels),
            $userId,
            bin2hex($random->bytes(16)),
            $clock->now(),
        );
        throw new RuntimeException('Stale names unexpectedly saved');
    } catch (\Utsuri\PortableCms\Conventional\Content\TerminologyConflict $conflict) {
        assertSameValue(1, $conflict->current->revision, 'stale names must expose the current revision');
    }
    assertSameValue(1, (int) $pdo->query('SELECT COUNT(*) FROM cms_editorial_terminology_revisions')->fetchColumn(), 'a rejected naming save must not append history');
    $firstExport = $exports->create($userId);
    $document = $firstExport->document;
    assertSameValue('portable-gate-zero', $document['profile']['id'] ?? null, 'export should identify its profile');
    assertSameValue('https://export-source.example/site', $firstExport->sourcePublicUrlContext()?->baseUrl, 'actual MariaDB export carries operator-owned source provenance');
    assertSameValue($named->document(), $document['editorialTerminology'] ?? null, 'export must preserve installation-owned names');
    \Utsuri\PortableCms\Conventional\JsonSchemaValidator::validateJson($firstExport->json(), (string) file_get_contents(dirname(__DIR__, 2) . '/schemas/portable-cms-export-v1.schema.json'));
    assertSameValue(3, count($document['records'] ?? []), 'export should include all generic record kinds');
    assertSameValue(5, count($document['revisions'] ?? []), 'export should include immutable revision history');
    assertSameValue(1, count($document['relationships'] ?? []), 'export should include only current relationships');
    assertSameValue(1, count($document['media'] ?? []), 'export should include media identity');
    assertSameValue(
        '960-webp',
        $document['media'][0]['derivatives'][0]['variant'] ?? null,
        'export should include derivative provenance',
    );
    assertSameValue(
        hash('sha256', $imageBytes),
        $document['media'][0]['original']['sha256'] ?? null,
        'export should identify private original bytes by checksum',
    );
    $encodedExport = $firstExport->json();
    foreach (['object_key', 'original_key', $mediaRoot, $dsn, 'integration-secret'] as $forbidden) {
        assertTrueValue(!str_contains($encodedExport, $forbidden), 'export should exclude storage or secret detail');
    }
    $secondExport = $exports->create($userId);
    $firstComparable = $firstExport->document;
    $secondComparable = $secondExport->document;
    unset($firstComparable['exportId'], $firstComparable['createdAt']);
    unset($secondComparable['exportId'], $secondComparable['createdAt']);
    assertSameValue(
        $firstComparable,
        $secondComparable,
        'unchanged authoritative content should produce deterministic normalized collections',
    );
    assertSameValue(
        20,
        (int) $pdo->query('SELECT COUNT(*) FROM cms_audit_events')->fetchColumn(),
        'each normalized export should append one attributed audit event',
    );
    $mediaLibrary = new \Utsuri\PortableCms\Conventional\Media\MediaLibrary(
        new \Utsuri\PortableCms\Conventional\Media\MariaDbMediaLibrary($pdo),
        new FilesystemMediaStore($mediaRoot),
        $clock,
        $random,
    );
    $libraryMedia = $mediaLibrary->search()->items[0];
    $originalChecksum = $libraryMedia->thumbnail->checksum;
    $mediaText = \Utsuri\PortableCms\Conventional\Media\MediaText::from(['formatVersion' => 1, 'decorative' => false, 'translations' => [
        ['locale' => 'en', 'alternativeText' => 'Authored image alternative', 'caption' => 'Image caption', 'credit' => 'Image author'],
    ]]);
    assertSameValue(true, $mediaLibrary->rename($libraryMedia->id, 1, 'review_100%.webp', $userId, $mediaText, new \Utsuri\PortableCms\Conventional\Media\FocalPoint(85, 20)), 'library metadata checks current revision');
    assertSameValue(false, $mediaLibrary->rename($libraryMedia->id, 1, 'stale name', $userId), 'second connection or stale form cannot overwrite metadata');
    assertSameValue(1, count($mediaLibrary->search('100%')->items), 'media search treats wildcard as literal');
    assertSameValue(0, count($mediaLibrary->search('reviewX')->items), 'escaped underscore does not broaden matching');
    assertSameValue(2, $mediaLibrary->find($libraryMedia->id)->metadataRevision, 'library revision increases exactly once');
    assertSameValue(['x' => 85, 'y' => 20], $mediaLibrary->find($libraryMedia->id)->focalPoint->document(), 'focal point survives MariaDB hydration');
    assertSameValue($originalChecksum, $mediaLibrary->find($libraryMedia->id)->thumbnail->checksum, 'rename never changes derivative identity');
    assertSameValue(file_get_contents($derivativePath), $mediaLibrary->thumbnail($libraryMedia->id), 'private library reads verified bytes');
    assertSameValue(21, (int) $pdo->query('SELECT COUNT(*) FROM cms_audit_events')->fetchColumn(), 'only a successful metadata mutation is audited');
    assertSameValue($mediaText->document(), $mediaLibrary->find($libraryMedia->id)->text->document(), 'metadata survives MariaDB hydration');
    assertSameValue(1, (int) $pdo->query("SELECT COUNT(*) FROM cms_audit_events WHERE event_type = 'media_metadata_updated'")->fetchColumn(), 'metadata shares attributed audit transaction');
    $existingAudit = (string) $pdo->query("SELECT id FROM cms_audit_events WHERE event_type = 'media_metadata_updated'")->fetchColumn();
    try {
        (new \Utsuri\PortableCms\Conventional\Media\MariaDbMediaLibrary($pdo))->rename($libraryMedia->id, 2, 'rolled back', $userId, $existingAudit, $clock->now(), \Utsuri\PortableCms\Conventional\Media\MediaText::from(['formatVersion' => 1, 'decorative' => true, 'translations' => []]), new \Utsuri\PortableCms\Conventional\Media\FocalPoint(0, 100));
        throw new RuntimeException('Failed audit did not roll back metadata');
    } catch (PDOException) {
    }
    assertSameValue(2, $mediaLibrary->find($libraryMedia->id)->metadataRevision, 'audit failure rolls back the shared metadata revision');
    assertSameValue($mediaText->document(), $mediaLibrary->find($libraryMedia->id)->text->document(), 'audit failure cannot partially change descriptions');
    assertSameValue(['x' => 85, 'y' => 20], $mediaLibrary->find($libraryMedia->id)->focalPoint->document(), 'audit failure cannot partially change the focal point');
    for ($index = 1; $index <= 21; $index++) {
        $content->create('portfolio-entry', ['title' => 'Picker window ' . $index, 'summary' => ''], $userId);
    }
    $recordBrowser = new MariaDbRecordRepository($pdo);
    assertSameValue(20, count($recordBrowser->search('portfolio-entry', 'Picker window', 1)->records), 'relationship first page is bounded');
    assertSameValue(true, $recordBrowser->search('portfolio-entry', 'Picker window', 1)->hasNext, 'relationship browser exposes next page');
    assertSameValue(1, count($recordBrowser->search('portfolio-entry', 'Picker window', 2)->records), 'relationship later page remains reachable');
    assertSameValue(0, count($recordBrowser->search('article', 'Picker window', 1)->records), 'picker is scoped to target type');
    assertSameValue(0, count($recordBrowser->search('portfolio-entry', '%', 1)->records), 'record search wildcard is literal');
    $designValue = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/schemas/fixtures/contracts/design-registries.accepted.json'), true, 32, JSON_THROW_ON_ERROR);
    $designValue['profileId'] = 'portable-gate-zero';
    $designDefaults = \Utsuri\PortableCms\Conventional\Design\RegistryDocument::fromDocument($designValue);
    $designRepository = new \Utsuri\PortableCms\Conventional\Design\MariaDbRegistryRepository($pdo);
    assertSameValue(0, $designRepository->load($designDefaults)->revision(), 'profile defaults apply before first design save');
    $designSaved = $designRepository->save($designDefaults, $userId, bin2hex($random->bytes(16)), $clock->now());
    assertSameValue(1, $designSaved->revision(), 'first design update creates an immutable attributed revision');
    $designValue = $designSaved->document();
    $designValue['typography'][0]['label'] = 'Reading text';
    $designNext = $designRepository->save(\Utsuri\PortableCms\Conventional\Design\RegistryDocument::fromDocument($designValue), $userId, bin2hex($random->bytes(16)), $clock->now());
    $auditCount = (int) $pdo->query('SELECT COUNT(*) FROM cms_audit_events')->fetchColumn();
    try {
        $designRepository->save($designSaved, $userId, bin2hex($random->bytes(16)), $clock->now());
        throw new RuntimeException('Stale design unexpectedly saved');
    } catch (\Utsuri\PortableCms\Conventional\Design\RegistryConflict $conflict) {
        assertSameValue(2, $conflict->current->revision(), 'conflict exposes current design');
    }
    assertSameValue(2, (int) $pdo->query('SELECT COUNT(*) FROM cms_design_registry_revisions')->fetchColumn(), 'conflict cannot append design history');
    assertSameValue($auditCount, (int) $pdo->query('SELECT COUNT(*) FROM cms_audit_events')->fetchColumn(), 'conflict cannot append audit success');
    assertSameValue($designSaved->checksum(), $designRepository->load($designDefaults, 1)->checksum(), 'exact historical design remains resolvable');
    try {
        $designRepository->save($designNext, str_repeat('f', 32), bin2hex($random->bytes(16)), $clock->now());
        throw new RuntimeException('Unknown design actor unexpectedly saved');
    } catch (PDOException) {
    }
    assertSameValue(2, $designRepository->load($designDefaults)->revision(), 'history failure rolls back current design');
    $pinnedPreviewRepository = new MariaDbPreviewRepository($pdo, $designDefaults);
    $pinnedPreviews = new PreviewService($pinnedPreviewRepository, ProfileManifest::gateZero(), $clock, $random);
    $pinnedPreview = $pinnedPreviews->create($userId);
    $pinJob = $pinnedPreviewRepository->claim('design-pin-test', bin2hex($random->bytes(32)), $clock->now(), $clock->now()->modify('+60 seconds'));
    assertTrueValue($pinJob !== null, 'frozen preview job must be claimable');
    $designRepository->save($designNext, $userId, bin2hex($random->bytes(16)), $clock->now());
    $pinnedSnapshot = $pinnedPreviewRepository->snapshot($pinJob);
    assertSameValue($designNext->checksum(), $pinnedSnapshot->designRegistries?->checksum(), 'queued preview keeps its exact design after a later save');
    assertSameValue(3, $designRepository->load($designDefaults)->revision(), 'current design may advance without mutating a frozen preview');
    $designExport = $exports->create($userId);
    $restoredDesign = \Utsuri\PortableCms\Conventional\Design\RegistryDocument::fromDocument(json_decode($designExport->json(), true, 32, JSON_THROW_ON_ERROR)['designRegistries']);
    assertSameValue($designRepository->load($designDefaults)->checksum(), $restoredDesign->checksum(), 'normalized export preserves current design and font facts');
    \Utsuri\PortableCms\Conventional\JsonSchemaValidator::validateJson($designExport->json(), (string) file_get_contents(dirname(__DIR__, 2) . '/schemas/portable-cms-export-v1.schema.json'));
} finally {
    $remove = static function (string $directory) use (&$remove): void {
        if (!is_dir($directory)) {
            return;
        }
        foreach (scandir($directory) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $directory . '/' . $item;
            is_dir($path) && !is_link($path) ? $remove($path) : @unlink($path);
        }
        @rmdir($directory);
    };
    $remove($mediaRoot);
}

assertSameValue(null, $reopened->interfaceLanguage, 'accounts follow the browser until they choose a language');
$identity->changeInterfaceLanguage($reopened, 'de');
$german = $identity->openSession($reopened->sessionToken, $reopened->csrfToken);
assertSameValue('de', $german->interfaceLanguage, 'the account language is stored and reloaded with the session');
$identity->changeInterfaceLanguage($german, null);
assertSameValue(null, $identity->openSession($german->sessionToken, $german->csrfToken)->interfaceLanguage, 'System clears the stored language');
try {
    $pdo->exec("UPDATE cms_users SET interface_language = 'it'");
    throw new RuntimeException('an unsupported interface language was stored');
} catch (PDOException) {
}

fwrite(STDOUT, "PASS MariaDB identity, generic-content, media, preview and export integration\n");

$installDsn = getenv('CMS_TEST_INSTALL_DSN');
if (!is_string($installDsn) || $installDsn === '') {
    throw new RuntimeException('CMS_TEST_INSTALL_DSN is required');
}
$installRoot = sys_get_temp_dir() . '/portable-cms-install-' . bin2hex(random_bytes(8));
$installPublic = $installRoot . '/public';
$installPrivate = $installRoot . '/private';
mkdir($installPublic, 0700, true);
mkdir($installPrivate, 0700, true);
$runtimeConfigPath = $installPrivate . '/cms.json';
$gatePath = $installPrivate . '/install.json';
$token = bin2hex(random_bytes(32));
file_put_contents($runtimeConfigPath, json_encode([
    'origin' => 'https://cms.example.test',
    'database' => [
        'dsn' => $installDsn,
        'username' => 'portable',
        'password' => 'integration-secret',
    ],
    'throttleSecretBase64' => base64_encode(random_bytes(32)),
    'errorLog' => $installPrivate . '/errors.log',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
file_put_contents($gatePath, json_encode([
    'status' => 'open',
    'expiresAt' => '2026-09-30T16:00:00Z',
    'tokenHash' => hash('sha256', $token),
    'runtimeConfig' => $runtimeConfigPath,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
chmod($runtimeConfigPath, 0600);
chmod($gatePath, 0600);
try {
    $gate = InstallationGate::load($gatePath, $installPublic, $clock->current);
    $configuration = RuntimeConfiguration::load($runtimeConfigPath, $installPublic);
    $installer = new InstallationService(
        $gate,
        $configuration,
        $migrations,
        $clock,
        $random,
    );
    try {
        $installer->install(
            str_repeat('0', 64),
            'installer@example.test',
            'Installer',
            'a sufficiently long install password',
            $clock->current,
        );
        throw new RuntimeException('invalid installation token unexpectedly succeeded');
    } catch (UnexpectedValueException) {
    }
    $installed = $installer->install(
        $token,
        'INSTALLER@Example.Test',
        'Install <Administrator>',
        'a sufficiently long install password',
        $clock->current,
    );
    assertSameValue('installed', $installed->status, 'one-use installer should complete');
    assertSameValue(count($migrations), $installed->migrationsApplied, 'one-use installer should apply the schema');
    $closed = json_decode((string) file_get_contents($gatePath), true, flags: JSON_THROW_ON_ERROR);
    assertSameValue('closed', $closed['status'] ?? null, 'installer gate must close after bootstrap');
    assertTrueValue(!array_key_exists('tokenHash', $closed), 'closed gate must discard its token hash');
    try {
        InstallationGate::load($gatePath, $installPublic, $clock->current);
        throw new RuntimeException('closed installation gate unexpectedly reopened');
    } catch (RuntimeException $error) {
        assertTrueValue(str_contains($error->getMessage(), 'closed'), 'closed gate should fail closed');
    }
    $installPdo = $configuration->connect();
    assertSameValue(
        'installer@example.test',
        $installPdo->query('SELECT login_name FROM cms_users')->fetchColumn(),
        'web installation must normalize and persist its named administrator',
    );
} finally {
    @unlink($runtimeConfigPath);
    @unlink($gatePath);
    @unlink($installPrivate . '/errors.log');
    @rmdir($installPrivate);
    @rmdir($installPublic);
    @rmdir($installRoot);
}
fwrite(STDOUT, "PASS no-shell installation integration\n");
