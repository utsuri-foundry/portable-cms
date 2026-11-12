<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Content\ContentService;
use Utsuri\PortableCms\Conventional\Content\MariaDbRecordRepository;
use Utsuri\PortableCms\Conventional\Content\ProfilePackage;
use Utsuri\PortableCms\Conventional\Content\RecordSnapshot;
use Utsuri\PortableCms\Conventional\Database\MigrationRunner;
use Utsuri\PortableCms\Conventional\Identity\AdministratorBootstrap;
use Utsuri\PortableCms\Conventional\Identity\SystemClock;
use Utsuri\PortableCms\Conventional\Identity\SystemRandomSource;

require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/bootstrap.php';
require_once dirname(__DIR__, 2) . '/tools/development/reference-studio-seeder.php';
require_once __DIR__ . '/assertions.php';

$dsn = getenv('CMS_TEST_SANDBOX_DSN');
if (!is_string($dsn) || $dsn === '') {
    throw new RuntimeException('CMS_TEST_SANDBOX_DSN is required');
}
$pdo = new PDO($dsn, 'portable', 'integration-secret', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$migrationPaths = glob(dirname(__DIR__, 2) . '/engine/migrations/*.sql');
if ($migrationPaths === false) {
    throw new RuntimeException('Sandbox migration files could not be enumerated');
}
sort($migrationPaths, SORT_STRING);
(new MigrationRunner($pdo))->apply($migrationPaths);
$actor = (new AdministratorBootstrap(
    $pdo,
    new SystemClock(),
    new SystemRandomSource(),
))->create('sandbox@example.test', 'Sandbox administrator', 'sandbox integration password');

$root = dirname(__DIR__, 2);
$seeder = ReferenceStudioDevelopmentSeeder::load(
    $root . '/profiles/reference-studio',
    $root . '/schemas/portable-cms-snapshot-v1.schema.json',
);
assertSameValue(true, $seeder->seed($pdo, $actor), 'first sandbox preparation should seed content');
assertSameValue(false, $seeder->seed($pdo, $actor), 'second sandbox preparation should preserve content');

$profile = ProfilePackage::load($root . '/profiles/reference-studio')->manifest;
$service = new ContentService(
    new MariaDbRecordRepository($pdo),
    $profile,
    new SystemClock(),
    new SystemRandomSource(),
);
$projects = $service->list('project');
assertSameValue(2, count($projects), 'sandbox should expose both representative projects');
$featured = $service->find('project', '10000000000000000000000000000001');
assertTrueValue($featured instanceof RecordSnapshot, 'featured project should reload by stable fixture identity');
assertSameValue(true, $featured->body['featured'], 'stored booleans should survive MariaDB hydration');
assertSameValue(2025, $featured->body['year'], 'stored integers should survive MariaDB hydration');

$disciplineId = '80000000000000000000000000000001';
$updated = $service->update('discipline', $disciplineId, 1, [
    'label' => [
        'ca' => 'Arquitectura local',
        'es' => 'Arquitectura local',
        'en' => 'Local architecture',
    ],
], $actor);
assertTrueValue($updated instanceof RecordSnapshot, 'seeded content should remain editable');
assertSameValue(2, $updated->revisionNumber, 'sandbox edits should create immutable revisions');
$reloaded = $service->find('discipline', $disciplineId);
assertTrueValue($reloaded instanceof RecordSnapshot, 'edited sandbox content should reload');
assertSameValue(
    'Arquitectura local',
    $reloaded->body['label']['ca'] ?? null,
    'reloaded sandbox content should preserve the edit',
);

fwrite(STDOUT, "PASS persistent Reference Studio CMS sandbox seed and edit integration\n");
