<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Content\MariaDbRecordRepository;
use Utsuri\PortableCms\Conventional\Content\ProfilePackage;
use Utsuri\PortableCms\Conventional\Content\RecordSnapshot;
use Utsuri\PortableCms\Conventional\Content\RelationshipProjection;
use Utsuri\PortableCms\Conventional\RuntimeConfiguration;

require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/bootstrap.php';

$configuration = RuntimeConfiguration::load($argv[1] ?? '');
$mode = $argv[2] ?? '';
if (!in_array(parse_url($configuration->origin, PHP_URL_HOST), ['localhost', '127.0.0.1'], true)
    || !str_starts_with($configuration->databaseDsn, 'mysql:unix_socket=') || !in_array($mode, ['prepare', 'restore'], true)) {
    throw new RuntimeException('An isolated loopback browser fixture is required');
}
$package = ProfilePackage::load($configuration->profileRoot ?? '');
if ($package->manifest->id !== 'reference-studio') {
    throw new RuntimeException('Only fictional Reference Studio content may be prepared');
}
$pdo = $configuration->connect();
$repository = new MariaDbRecordRepository($pdo);
$actor = (string) $pdo->query("SELECT id FROM cms_users WHERE role = 'owner' AND status = 'active'")->fetchColumn();
$path = dirname($argv[1]) . '/editor-exact-fixture.json';
$write = static function (string $id, array $body, array $relations) use ($repository, $actor, $package): void {
    $record = $repository->find('project', $id) ?? throw new RuntimeException('Fictional project missing');
    $projections = array_map(static fn(array $row): RelationshipProjection => new RelationshipProjection($row['field_id'], $row['relation_kind'], $row['target_record_id'], (int) $row['position']), $relations);
    $result = $repository->update('project', $id, $record->revisionNumber, bin2hex(random_bytes(16)), $package->manifest->id, $package->manifest->version, $body, $projections, $actor, bin2hex(random_bytes(16)), new DateTimeImmutable('now', new DateTimeZone('UTC')));
    if (!$result instanceof RecordSnapshot) {
        throw new RuntimeException('Fixture restoration conflicted');
    }
};
if ($mode === 'prepare') {
    if (file_exists($path)) {
        throw new RuntimeException('An exact-preview fixture is already prepared');
    }
    $original = [];
    foreach (['10000000000000000000000000000001', '10000000000000000000000000000002'] as $id) {
        $record = $repository->find('project', $id) ?? throw new RuntimeException('Fictional project missing');
        $relations = $pdo->prepare('SELECT field_id, relation_kind, target_record_id, position FROM cms_record_relationship_index WHERE record_id = :id AND revision_id = :revision ORDER BY field_id, position');
        $relations->execute(['id' => $id, 'revision' => $record->revisionId]);
        $original[$id] = ['body' => $record->body, 'relations' => $relations->fetchAll(PDO::FETCH_ASSOC)];
    }
    file_put_contents($path, json_encode($original, JSON_THROW_ON_ERROR));
    chmod($path, 0600);
    $draft = $original['10000000000000000000000000000002'];
    $draft['body']['title']['en'] = 'Light Workshop';
    $draft['body']['slug']['en'] = 'light-workshop';
    $write('10000000000000000000000000000002', $draft['body'], $draft['relations']);
} else {
    $original = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
    // Append restored fixture revisions: never mutate a published or preview-captured revision.
    foreach ($original as $id => $record) {
        $write($id, $record['body'], $record['relations']);
    }
    unlink($path);
}
