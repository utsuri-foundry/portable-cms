<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\ApplicationFactory;
use Utsuri\PortableCms\Conventional\Compiler\FrozenSnapshot;
use Utsuri\PortableCms\Conventional\Content\ProfilePackage;
use Utsuri\PortableCms\Conventional\Database\DatabaseConnection;
use Utsuri\PortableCms\Conventional\Database\MigrationRunner;
use Utsuri\PortableCms\Conventional\Database\TablePrefix;
use Utsuri\PortableCms\Conventional\Identity\AdministratorBootstrap;
use Utsuri\PortableCms\Conventional\Identity\SystemClock;
use Utsuri\PortableCms\Conventional\Identity\SystemRandomSource;
use Utsuri\PortableCms\Conventional\Preview\FilesystemPreviewStore;
use Utsuri\PortableCms\Conventional\Preview\MariaDbPreviewRepository;
use Utsuri\PortableCms\Conventional\Preview\PreviewService;
use Utsuri\PortableCms\Conventional\Preview\ProfilePreviewCompiler;
use Utsuri\PortableCms\Conventional\RuntimeConfiguration;
use Utsuri\PortableCms\Conventional\Upgrade\DatabaseBackup;

require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/bootstrap.php';
require_once dirname(__DIR__, 2) . '/tools/development/reference-studio-seeder.php';
require_once dirname(__DIR__, 2) . '/tools/development/reference-media-seeder.php';
require_once __DIR__ . '/assertions.php';

// Two complete installations share one database under different table prefixes.
$dsn = getenv('CMS_TEST_PREFIX_DSN');
if (!is_string($dsn) || $dsn === '') {
    throw new RuntimeException('An isolated table prefix database is required');
}
$repository = dirname(__DIR__, 2);
$connect = static fn(string $prefix): DatabaseConnection => new DatabaseConnection($dsn, 'portable', 'integration-secret', new TablePrefix($prefix), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
$raw = new PDO($dsn, 'portable', 'integration-secret', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$canonical = $connect('cms_');
$next = $connect('next_');
$paths = glob($repository . '/engine/migrations/*.sql') ?: [];
sort($paths, SORT_STRING);
(new MigrationRunner($canonical))->apply($paths);
(new MigrationRunner($next))->apply($paths);

$checksums = static fn(string $table): array => $raw->query('SELECT id, checksum, status FROM ' . $table . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
assertSameValue(count($paths), count($checksums('cms_migrations')), 'canonical ledger records every migration');
assertSameValue($checksums('cms_migrations'), $checksums('next_migrations'), 'both table sets record the same migration checksums');
assertSameValue(0, (int) $raw->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND (COLUMN_NAME LIKE 'cms\\_%' OR COLUMN_NAME LIKE 'next\\_%')")->fetchColumn(), 'no column name looks like a table prefix');
$crossing = (int) $raw->query("SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND SUBSTRING_INDEX(TABLE_NAME, '_', 1) <> SUBSTRING_INDEX(REFERENCED_TABLE_NAME, '_', 1)")->fetchColumn();
assertSameValue(0, $crossing, 'foreign keys stay within their table set');
assertSameValue(0, (int) $raw->query("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'next\\_%' AND CONSTRAINT_TYPE = 'FOREIGN KEY' AND CONSTRAINT_NAME NOT LIKE 'next\\_%'")->fetchColumn(), 'foreign key names, unique across a database, carry their table set prefix');

$canonicalBackup = new DatabaseBackup($canonical);
$nextBackup = new DatabaseBackup($next);
$before = $canonicalBackup->fingerprint();
assertSameValue($canonicalBackup->schemas(), $nextBackup->schemas(), 'both table sets have the same canonical schemas');
assertTrueValue($canonicalBackup->identity() !== $nextBackup->identity(), 'table sets in one database have distinct identities');

// Migration locks are per table set: one set migrating never blocks another.
MigrationRunner::locked($canonical, static function () use ($connect): void {
    MigrationRunner::locked($connect('next_'), static fn(): bool => true);
    try {
        MigrationRunner::locked($connect('cms_'), static fn(): bool => true);
        throw new RuntimeException('a busy table set lock was granted twice');
    } catch (RuntimeException $error) {
        assertTrueValue(str_contains($error->getMessage(), 'busy'), 'the same table set stays locked');
    }
});

// The prefixed installation authors, processes media and builds a preview through ordinary services.
$state = sys_get_temp_dir() . '/portable-prefix-' . bin2hex(random_bytes(8));
mkdir($state . '/media', 0700, true);
try {
    $clock = new SystemClock();
    $random = new SystemRandomSource();
    $configuration = new RuntimeConfiguration('https://cms.example.test', $dsn, 'portable', 'integration-secret', str_repeat('s', 32), null, $state . '/media', 'cron_only', profileRoot: $repository . '/profiles/reference-studio', tablePrefix: new TablePrefix('next_'));
    $pdo = $configuration->connect();
    $actor = (new AdministratorBootstrap($pdo, $clock, $random))->create('prefix@example.test', 'Prefix Operator', 'test-prefix-password');
    $package = ProfilePackage::load($repository . '/profiles/reference-studio');
    ReferenceStudioDevelopmentSeeder::load($package->root, $repository . '/schemas/portable-cms-snapshot-v1.schema.json')->seed($pdo, $actor);
    $pdo->exec("UPDATE cms_record_revisions SET body_json = JSON_SET(body_json, '$.title.en', 'Light Workshop') WHERE record_id = '10000000000000000000000000000002'");
    $snapshot = FrozenSnapshot::load($package->root . '/fixtures/snapshot.json', $repository . '/schemas/portable-cms-snapshot-v1.schema.json', $package);
    ReferenceMediaSeeder::seed($pdo, $package, $snapshot, $actor, $state . '/media');
    ApplicationFactory::mediaRunner($configuration)->runBounded('prefix-fixture', 10, 60);
    $compiler = new ProfilePreviewCompiler($package, $configuration->mediaRoot, $repository . '/schemas');
    $previews = new PreviewService(new MariaDbPreviewRepository($pdo, $package->manifest->designRegistries, $package->checksum, $compiler->preflight(...), null), $package->manifest, $clock, $random, new FilesystemPreviewStore($configuration->mediaRoot . '/previews'));
    $previews->create($actor, false);
    assertTrueValue(ApplicationFactory::previewRunner($configuration)->runOne('prefix-fixture')->completed, 'the prefixed installation compiles a preview');
    assertTrueValue((int) $raw->query('SELECT COUNT(*) FROM next_records')->fetchColumn() > 0, 'content lives in the prefixed table set');
    assertSameValue(0, (int) $raw->query('SELECT COUNT(*) FROM cms_records')->fetchColumn(), 'the canonical set gains no content');
    assertSameValue(0, (int) $raw->query('SELECT COUNT(*) FROM cms_users')->fetchColumn(), 'the canonical set gains no accounts');
    assertSameValue($before, $canonicalBackup->fingerprint(), 'the canonical table set is untouched');

    // A backup records canonical names and restores exactly into another table set.
    $nextBackup->capture($state . '/backup');
    $document = json_decode((string) file_get_contents($state . '/backup/database.json'), true, 16, JSON_THROW_ON_ERROR);
    assertSameValue(array_keys($canonicalBackup->schemas()), $document['tables'], 'the backup lists canonical tables only');
    $spare = new DatabaseBackup($connect('spare_'));
    $spare->restore($state . '/backup');
    assertSameValue($nextBackup->fingerprint(), $spare->fingerprint(), 'the restored table set matches its source');
    assertSameValue($before, $canonicalBackup->fingerprint(), 'restoring a table set leaves the others untouched');
    fwrite(STDOUT, "PASS table prefixes: two installations in one database, separate ledgers, constraints, locks and content, prefix-independent backups and restore into a third set.\n");
} finally {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($state, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
        $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($state);
}
