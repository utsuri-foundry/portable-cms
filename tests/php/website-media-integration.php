<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Content\ContentService;
use Utsuri\PortableCms\Conventional\Content\MariaDbRecordRepository;
use Utsuri\PortableCms\Conventional\Content\ProfilePackage;
use Utsuri\PortableCms\Conventional\Content\RecordSnapshot;
use Utsuri\PortableCms\Conventional\Database\MigrationRunner;
use Utsuri\PortableCms\Conventional\Export\MariaDbExportRepository;
use Utsuri\PortableCms\Conventional\Identity\AdministratorBootstrap;
use Utsuri\PortableCms\Conventional\Identity\SessionContext;
use Utsuri\PortableCms\Conventional\Media\AdministrationLogoDelivery;
use Utsuri\PortableCms\Conventional\Media\FilesystemMediaStore;
use Utsuri\PortableCms\Conventional\Media\FilesystemUploadStorage;
use Utsuri\PortableCms\Conventional\Media\ImagickRasterProcessor;
use Utsuri\PortableCms\Conventional\Media\MariaDbMediaJobRepository;
use Utsuri\PortableCms\Conventional\Media\MariaDbMediaLibrary;
use Utsuri\PortableCms\Conventional\Media\MariaDbUploadRepository;
use Utsuri\PortableCms\Conventional\Media\MediaJobRunner;
use Utsuri\PortableCms\Conventional\Media\MediaLibrary;
use Utsuri\PortableCms\Conventional\Media\MediaUploadService;
use Utsuri\PortableCms\Conventional\Preview\MariaDbPreviewRepository;
use Utsuri\PortableCms\Conventional\Preview\ProfilePreviewCompiler;
use Utsuri\PortableCms\Conventional\Publication\HoldingSnapshotSource;
use Utsuri\PortableCms\Conventional\Publication\MariaDbWebsitePublication;
use Utsuri\PortableCms\Conventional\Publication\WebsitePublicationService;

require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/bootstrap.php';
require_once __DIR__ . '/assertions.php';
require_once __DIR__ . '/identity-test.php';
require_once __DIR__ . '/restore-fixture.php';

function websiteMediaBytes(string $colour): string
{
    $image = new Imagick();
    try {
        $image->newImage(640, 640, new ImagickPixel($colour));
        $image->setImageFormat('png');
        return $image->getImageBlob();
    } finally {
        $image->clear();
    }
}

function websiteMediaComplete(MediaUploadService $service, string $session, string $bytes, string $actor): string
{
    $stream = \Utsuri\PortableCms\Conventional\Http\RequestBody::fromString($bytes)->open();
    try {
        $service->uploadPart($session, 1, strlen($bytes), $stream, $actor);
    } finally {
        fclose($stream);
    }
    return $service->complete($session, $actor)['media']['id'];
}

$root = dirname(__DIR__, 2);
$dsn = getenv('CMS_TEST_WEBSITE_MEDIA_DSN');
if (!is_string($dsn) || $dsn === '') {
    throw new RuntimeException('An isolated website-media database is required');
}
$pdo = new PDO($dsn, 'portable', 'integration-secret', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
$migrations = glob($root . '/engine/migrations/*.sql') ?: throw new RuntimeException('Migrations are unavailable');
sort($migrations, SORT_STRING);
$legacy = array_values(array_filter($migrations, static fn(string $path): bool => basename($path) !== '0026-website-owned-images.sql'));
(new MigrationRunner($pdo))->apply($legacy);
$clock = new IdentityTestClock(new DateTimeImmutable('2026-10-24T10:00:00Z'));
$random = new IdentityTestRandom();
$actor = (new AdministratorBootstrap($pdo, $clock, $random))->create('website-media@example.test', 'Website media administrator', 'fictional website media password');
$session = new SessionContext('test-session', 'authenticated', $actor, 'website-media@example.test', 'Website media administrator', 'owner', 'token', 'csrf', $clock->now()->modify('+1 hour'), false);
$package = ProfilePackage::load($root . '/profiles/reference-studio');
$profile = $package->manifest;
$content = new ContentService(new MariaDbRecordRepository($pdo), $profile, $clock, $random);
$former = $content->create('project', ['title' => array_fill_keys($profile->locales, 'Former logo owner'), 'gallery' => '[]'], $actor);
assertTrueValue($former instanceof RecordSnapshot, 'the legacy owner is an ordinary collection record');
$state = sys_get_temp_dir() . '/website-media-' . bin2hex(random_bytes(8));
mkdir($state, 0700);
try {
    $repository = new MariaDbUploadRepository($pdo);
    $uploads = new MediaUploadService($repository, new FilesystemUploadStorage($state . '/media'), $clock, $random, profile: $profile);
    $bytes = websiteMediaBytes('#234455');
    $oldUpload = $uploads->create('project', $former->id, 1, 'Legacy logo.png', 'image/png', strlen($bytes), 'legacy-logo', $actor);
    $logo = websiteMediaComplete($uploads, $oldUpload->id, $bytes, $actor);
    assertSameValue(1, (new MigrationRunner($pdo))->apply($migrations)->applied, 'the additive migration upgrades populated media and upload tables');
    assertSameValue($former->id, $repository->find($oldUpload->id)?->ownerRecordId, 'legacy sessions keep their ownership and revision');
    $iconBytes = websiteMediaBytes('#7799aa');
    $upload = $uploads->createWebsite('Website icon.png', 'image/png', strlen($iconBytes), 'website-icon', $actor);
    assertSameValue([null, null], [$upload->ownerRecordId, $upload->initialRecordRevision], 'website uploads require no synthetic owner or revision');
    assertSameValue($upload->id, $uploads->createWebsite('Website icon.png', 'image/png', strlen($iconBytes), 'website-icon', $actor)->id, 'NULL-owner retry lookup is idempotent');
    $icon = websiteMediaComplete($uploads, $upload->id, $iconBytes, $actor);
    assertSameValue($icon, $uploads->complete($upload->id, $actor)['media']['id'], 'website completion replays without duplicate jobs');
    $runner = new MediaJobRunner(new MariaDbMediaJobRepository($pdo), new FilesystemMediaStore($state . '/media'), new ImagickRasterProcessor(), $clock, $random);
    assertTrueValue($runner->runOne('website-media')->completed, 'ordinary leased jobs prepare record images');
    assertTrueValue($runner->runOne('website-media')->completed, 'ordinary leased jobs prepare website images');
    $library = new MediaLibrary(new MariaDbMediaLibrary($pdo), new FilesystemMediaStore($state . '/media'), $clock, $random);
    $publication = new WebsitePublicationService(new MariaDbWebsitePublication($pdo), $profile, $clock, $random);
    $publication->save([...$publication->load()->document(), 'revision' => 1, 'websiteName' => 'Fictional website media', 'iconMediaId' => $icon,
        'administrationBranding' => ['logoMediaId' => $logo, 'darkLogoMediaId' => null],
        'translations' => array_map(static fn(string $locale): array => ['locale' => $locale, 'message' => 'A fictional studio opens soon.', 'contact' => ''], $profile->locales)], 0, $session, false);
    $saved = $publication->load()->document();
    $before = $library->find($logo);
    assertTrueValue($before !== null && $before->thumbnail !== null, 'the legacy logo is prepared');
    assertSameValue(false, $library->keepForWebsite($logo, $before->metadataRevision + 1, $actor), 'stale ownership changes do not overwrite metadata');
    assertSameValue(true, $library->keepForWebsite($logo, $before->metadataRevision, $actor), 'an administrator can retain the same prepared image for the website');
    assertSameValue(null, $library->find($logo)?->ownerRecordId, 'the image has no record owner');
    assertSameValue($before->thumbnail->checksum, $library->find($logo)?->thumbnail?->checksum, 'promotion does not rewrite derivative bytes');
    assertSameValue(null, $repository->find($oldUpload->id)?->ownerRecordId, 'completed receipts no longer retain a foreign-key dependency on the former owner');
    $archived = $content->workflow()->transition('project', $former->id, $former->revisionNumber, 'archived', $session);
    assertTrueValue($archived instanceof RecordSnapshot, 'the former entry can be archived normally');
    assertSameValue(true, $content->workflow()->delete('project', $former->id, $archived->revisionNumber, $session), 'the former owner can actually be deleted');
    assertSameValue($saved, $publication->load()->document(), 'deleting the former owner leaves Website settings exact');
    $delivery = new AdministrationLogoDelivery($library);
    assertSameValue(200, $delivery->serve($publication->load(), $before->thumbnail->checksum)->status, 'only the selected prepared logo remains available before sign-in');
    assertSameValue(404, $delivery->serve($publication->load(), hash('sha256', $bytes))->status, 'the original is never an anonymous logo derivative');
    assertSameValue(404, $delivery->serve($publication->load(), $library->find($icon)->thumbnail->checksum)->status, 'an unselected website image is not opened by website ownership');
    for ($index = 0; $index < 9; $index++) {
        $item = $uploads->createWebsite('Website library ' . $index . '.png', 'image/png', strlen($bytes), 'website-page-' . $index, $actor);
        websiteMediaComplete($uploads, $item->id, $bytes, $actor);
    }
    $other = $content->create('project', ['title' => array_fill_keys($profile->locales, 'An unrelated entry'), 'gallery' => '[]'], $actor);
    assertTrueValue($other instanceof RecordSnapshot, 'another record exercises mixed export ordering');
    $recordUpload = $uploads->create('project', $other->id, 1, 'Record image.png', 'image/png', strlen($bytes), 'record-image', $actor);
    websiteMediaComplete($uploads, $recordUpload->id, $bytes, $actor);
    $export = (new MariaDbExportRepository($pdo, $profile->designRegistries))->create(bin2hex($random->bytes(16)), bin2hex($random->bytes(16)), $actor, $profile->id, $profile->version, $profile->locales, $clock->now());
    assertSameValue(12, count($export->document['media']), 'keyset export spans more than one page of NULL owners and then record owners');
    assertSameValue(11, count(array_filter($export->document['media'], static fn(array $media): bool => $media['ownerRecordId'] === null)), 'every website image is exported without a record');
    assertSameValue(hash('sha256', $bytes), array_column($export->document['media'], 'original', 'id')[$logo]['sha256'], 'website originals retain checksum-addressed portability');
    $context = \Utsuri\PortableCms\Conventional\Compiler\PublicUrlContext::from(['formatVersion' => 1, 'baseUrl' => 'https://website-media.example']);
    $previews = new MariaDbPreviewRepository($pdo, $profile->designRegistries, $package->checksum, publicUrlContext: $context);
    $previews->enqueue(bin2hex($random->bytes(16)), bin2hex($random->bytes(16)), bin2hex($random->bytes(16)), $actor, $profile->id, $profile->version, $profile->locales, $clock->now(), true);
    $job = $previews->claim('website-media-preview', bin2hex($random->bytes(32)), $clock->now(), $clock->now()->modify('+15 minutes'));
    assertTrueValue($job !== null, 'a private preview was durably queued');
    $snapshot = $previews->snapshot($job);
    assertSameValue(2, count($snapshot->websiteMedia), 'private snapshot encoding preserves both prepared website images');
    $compiler = new ProfilePreviewCompiler($package, $state . '/media', $root . '/schemas');
    assertSameValue(null, $compiler->freeze($snapshot)->media($logo)?->ownerRecordId, 'normal previews retain website ownership without a placeholder record');
    $holding = (new HoldingSnapshotSource($pdo, new MariaDbWebsitePublication($pdo), $package->checksum, $context))->freeze($profile->id, $profile->version, $profile->locales, $clock->now());
    assertSameValue([], $holding->records, 'a website-owned icon does not need a fabricated holding-page owner');
    $build = (new \Utsuri\PortableCms\Conventional\Compiler\HoldingPageCompiler($root . '/schemas', $state . '/media', $package, new \Utsuri\PortableCms\Conventional\Compiler\ImagickWebsiteIconProcessor()))->compile($holding, $state . '/holding');
    assertTrueValue(count($build->artifacts) > 0 && is_file($state . '/holding/index.html'), 'the selected website-owned icon compiles into a verified holding artifact');
    assertTrueValue(!str_contains((string) file_get_contents($state . '/holding/artifact.json'), $logo), 'administration-only logo bytes do not enter the public holding artifact');
    $backup = new \Utsuri\PortableCms\Conventional\Upgrade\DatabaseBackup($pdo);
    $fingerprint = $backup->fingerprint();
    $backup->capture($state . '/database-backup');
    $backup->restore($state . '/database-backup', replace: true);
    assertSameValue($fingerprint, $backup->fingerprint(), 'the PHP-only database backup restores all website media and nullable receipts exactly');
    assertSameValue(null, $library->find($logo)?->ownerRecordId, 'backup restoration keeps website ownership');
    assertSameValue($saved, $publication->load()->document(), 'backup restoration retains the selected website images');
    printf("PASS website media: populated migration, NULL-owner upload/replay/jobs, promotion/conflict, former-owner deletion, exact settings, restricted branding, paginated export, frozen/holding previews and PHP-only backup restoration.\n");
} finally {
    RestoreTestFixture::remove($state);
}
