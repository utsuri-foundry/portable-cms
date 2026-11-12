<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\ApplicationFactory;
use Utsuri\PortableCms\Conventional\Content\ContentService;
use Utsuri\PortableCms\Conventional\Content\MariaDbRecordHistory;
use Utsuri\PortableCms\Conventional\Content\MariaDbRecordRepository;
use Utsuri\PortableCms\Conventional\Content\ProfilePackage;
use Utsuri\PortableCms\Conventional\Content\RecordHistoryService;
use Utsuri\PortableCms\Conventional\Content\RecordSnapshot;
use Utsuri\PortableCms\Conventional\Database\MigrationRunner;
use Utsuri\PortableCms\Conventional\Http\Request;
use Utsuri\PortableCms\Conventional\I18n\InvalidInput;
use Utsuri\PortableCms\Conventional\Identity\AdministratorBootstrap;
use Utsuri\PortableCms\Conventional\Identity\IdentityService;
use Utsuri\PortableCms\Conventional\Identity\SessionContext;
use Utsuri\PortableCms\Conventional\Identity\SystemClock;
use Utsuri\PortableCms\Conventional\Identity\SystemRandomSource;
use Utsuri\PortableCms\Conventional\RuntimeConfiguration;

require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/bootstrap.php';
require_once __DIR__ . '/assertions.php';
require_once __DIR__ . '/restore-fixture.php';

$root = dirname(__DIR__, 2);
$dsn = getenv('CMS_TEST_HISTORY_DSN');
if (!is_string($dsn) || $dsn === '') {
    throw new RuntimeException('An isolated history database is required');
}
$connect = static fn(): PDO => new PDO($dsn, 'portable', 'integration-secret', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
$pdo = $connect();
$paths = glob($root . '/engine/migrations/*.sql');
sort($paths, SORT_STRING);
(new MigrationRunner($pdo))->apply($paths);
$clock = new SystemClock();
$random = new SystemRandomSource();
$actorId = (new AdministratorBootstrap($pdo, $clock, $random))->create('history@portable.test', 'History owner', 'portable-cms-local');
$actor = new SessionContext('history', 'authenticated', $actorId, 'history@portable.test', 'History owner', 'owner', 'token', 'csrf', $clock->now()->modify('+1 hour'), false);
$package = ProfilePackage::load($root . '/profiles/reference-studio');
$profile = $package->manifest;
$repository = new MariaDbRecordRepository($pdo);
$content = new ContentService($repository, $profile, $clock, $random);
$history = new MariaDbRecordHistory($pdo);
$service = new RecordHistoryService($history, $clock, $random);
$create = static function (string $title) use ($content, $profile, $actorId): RecordSnapshot {
    $record = $content->create('article', ['title' => array_fill_keys($profile->locales, $title)], $actorId);
    return $record instanceof RecordSnapshot ? $record : throw new RuntimeException('History fixture rejected');
};
$write = static function (RecordSnapshot $record, ?string $state = null) use ($repository, $profile, $actorId, $random, $clock): RecordSnapshot {
    $result = $repository->update('article', $record->id, $record->revisionNumber, bin2hex($random->bytes(16)), $profile->id, $profile->version, $record->body, [], $actorId, bin2hex($random->bytes(16)), $clock->now(), $state);
    return $result instanceof RecordSnapshot ? $result : throw new RuntimeException('Unexpected fixture conflict');
};
$numbers = static function (string $id) use ($pdo): array {
    $query = $pdo->prepare('SELECT revision_number FROM cms_record_revisions WHERE record_id = :id ORDER BY revision_number');
    $query->execute(['id' => $id]);
    return array_map('intval', $query->fetchAll(PDO::FETCH_COLUMN));
};

assertSameValue(50, $service->load()->limit, 'migration installs the default without deleting history');
$record = $create('Fictional revision history');
for ($i = 0; $i < 50; $i++) {
    $record = $write($record);
}
assertSameValue(range(1, 51), $numbers($record->id), 'exactly 50 historical revisions fit alongside current');
$record = $write($record);
assertSameValue(range(2, 52), $numbers($record->id), 'first excess revision removes the oldest');
$record = $write($record, 'published');
$published = $record->revisionNumber;
for ($i = 0; $i < 65; $i++) {
    $record = $write($record);
}
assertSameValue([$published, ...range($record->revisionNumber - 50, $record->revisionNumber)], $numbers($record->id), 'an old published head is additional to 50 historical revisions and current');
assertSameValue($published, $repository->find('article', $record->id)?->publishedRevisionNumber, 'published pointer remains readable');
assertSameValue(0, $history->excess(50), 'protected heads do not count as excess');
foreach ([9, 501] as $limit) {
    try {
        $service->save($limit, 0, null, $actor);
        throw new RuntimeException('Invalid history allowance accepted');
    } catch (InvalidInput $error) {
        assertSameValue('history.invalid', $error->text()->key, 'range enforced by service');
    }
}
$count = $service->retained();
$policyConnection = $connect();
$policyConnection->exec('SET SESSION innodb_lock_wait_timeout = 1');
$concurrentPolicy = new RecordHistoryService(new MariaDbRecordHistory($policyConnection), $clock, $random);
$pdo->beginTransaction();
assertSameValue(50, $history->lockPolicy()->limit, 'save pins its policy before taking a record lock');
try {
    $concurrentPolicy->save(500, 0, null, $actor);
    throw new RuntimeException('Policy changed during a revision transaction');
} catch (PDOException $error) {
    assertSameValue(1205, $error->errorInfo[1] ?? null, 'policy change waits until in-flight saves finish');
}
$pdo->commit();
$service->save(500, 0, null, $actor);
assertSameValue($count, $service->retained(), 'raising removes nothing');
$second = $create('Second fictional history');
for ($i = 0; $i < 255; $i++) {
    $second = $write($second);
}
assertSameValue(256, count($numbers($second->id)), 'configured upper allowance permits larger histories');
$removals = $service->excess(10);
assertSameValue(285, $removals, 'site-wide count excludes two protected heads and one current head');
foreach ([null, $removals - 1] as $confirmation) {
    try {
        $service->save(10, 1, $confirmation, $actor);
        throw new RuntimeException('Unconfirmed or stale reduction accepted');
    } catch (InvalidInput $error) {
        assertSameValue('history.changed', $error->text()->key, 'confirmation is checked before destructive work');
    }
}
$service->save(10, 1, $removals, $actor);
assertSameValue(308, $service->retained(), 'confirmed policy save itself removes no content');
assertTrueValue($service->load()->pending, 'reduction persists pending progress');
// Inject interruption after deletion but before the audit; the whole batch must roll back.
$pdo->exec("CREATE TRIGGER history_interrupt BEFORE INSERT ON cms_audit_events FOR EACH ROW BEGIN IF NEW.event_type = 'record_revisions_pruned' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'fictional interrupted batch'; END IF; END");
try {
    $service->continue($actor);
    throw new RuntimeException('Injected batch interruption ignored');
} catch (PDOException $error) {
    assertTrueValue(str_contains($error->getMessage(), 'fictional interrupted batch'), 'expected failure boundary reached');
} finally {
    $pdo->exec('DROP TRIGGER history_interrupt');
}
assertSameValue(308, $service->retained(), 'interrupted transaction restores deleted rows');
assertTrueValue($service->load()->pending, 'interrupted reduction remains resumable');
$before = [$numbers($record->id), $numbers($second->id)];
$service->continue($actor);
$after = [$numbers($record->id), $numbers($second->id)];
assertSameValue(1, (int) ($before[0] !== $after[0]) + (int) ($before[1] !== $after[1]), 'one request handles exactly one record');
assertTrueValue(count($before[0]) + count($before[1]) - count($after[0]) - count($after[1]) <= 100, 'bounded deletion batch');
$resumed = new RecordHistoryService(new MariaDbRecordHistory($connect()), $clock, $random);
for ($i = 0; $i < 10 && $resumed->load()->pending; $i++) {
    $resumed->continue($actor);
}
assertSameValue(false, $resumed->load()->pending, 'fresh connection resumes and completes');
assertSameValue(23, $service->retained(), 'exact requested histories plus protected heads remain');
assertSameValue([$published, ...range($record->revisionNumber - 10, $record->revisionNumber)], $numbers($record->id), 'newest historical revisions retained');
assertSameValue(range(246, 256), $numbers($second->id), 'oldest-first pruning preserves revision gaps');
$auditCount = (int) $pdo->query("SELECT COUNT(*) FROM cms_audit_events WHERE event_type = 'record_revisions_pruned'")->fetchColumn();
$resumed->continue($actor);
assertSameValue($auditCount, (int) $pdo->query("SELECT COUNT(*) FROM cms_audit_events WHERE event_type = 'record_revisions_pruned'")->fetchColumn(), 'repeated completion makes no duplicate audit');
foreach ($pdo->query("SELECT metadata_json FROM cms_audit_events WHERE event_type = 'record_revisions_pruned'")->fetchAll(PDO::FETCH_COLUMN) as $json) {
    $metadata = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    assertSameValue(['recordId', 'removed'], array_keys($metadata), 'audit contains only record identity and removal count');
    assertTrueValue($metadata['removed'] > 0 && $metadata['removed'] <= 100, 'audit bound');
}
// A raise cancels an unfinished reduction and performs no delayed deletion.
$service->save(500, 2, null, $actor);
for ($i = 0; $i < 30; $i++) {
    $second = $write($second);
}
$service->save(10, 3, $service->excess(10), $actor);
$retained = $service->retained();
$service->save(100, 4, null, $actor);
$service->continue($actor);
assertSameValue($retained, $service->retained(), 'raising stops obsolete pending cleanup');

// Real language deletion contends on the same record lock and safely resumes after pruning.
$other = $connect();
$other->exec('SET SESSION innodb_lock_wait_timeout = 1');
$languages = ApplicationFactory::siteLanguages($profile, $other);
$languageSettings = $languages->load();
$states = $languageSettings->states;
$states['ca'] = 'off';
$languages->save($states, 'en', $languageSettings->revision, $actor, true);
$deletions = ApplicationFactory::languageText($profile, $other, $languages, null, null);
$pdo->beginTransaction();
$lockedPolicy = $history->lockPolicy();
$history->pruneRecord($record->id, $actorId, bin2hex($random->bytes(16)), $clock->now(), $lockedPolicy);
try {
    $deletions->start('ca', 2, true, $actor);
    throw new RuntimeException('Language rewrite bypassed pruning lock');
} catch (PDOException $error) {
    assertSameValue(1205, $error->errorInfo[1] ?? null, 'concurrent rewrite waits for owning-record lock');
}
$pdo->commit();
for ($i = 0; $i < 5 && $deletions->latest()?->state === 'running'; $i++) {
    $deletions->continue($actor);
}
assertSameValue('completed', $deletions->latest()?->state, 'language deletion resumes without interfering with pruning');
assertSameValue(false, \Utsuri\PortableCms\Conventional\Content\LanguageText::record($profile->type('article')->fields, $repository->find('article', $record->id)->body, 'ca'), 'language text removed from current content');
assertSameValue($published, $repository->find('article', $record->id)?->publishedRevisionNumber, 'language rewrite and pruning preserve published history');

// Exercise the real authenticated native settings route and its security boundary.
$mediaRoot = sys_get_temp_dir() . '/history-http-' . bin2hex($random->bytes(8));
mkdir($mediaRoot, 0700);
try {
    $app = ApplicationFactory::identity(new RuntimeConfiguration('https://cms.example.test', $dsn, 'portable', 'integration-secret', str_repeat('s', 32), null, $mediaRoot, 'cron_only', profileRoot: $package->root));
    $cookies = [];
    $request = static function (string $method, string $path, array $form = [], string $origin = 'https://cms.example.test') use ($app, &$cookies) {
        $response = $app->handle(new Request($method, $path, ['origin' => $origin, 'sec-fetch-site' => 'same-origin'], $form, $cookies, '192.0.2.1'));
        foreach ($response->headers()['set-cookie'] ?? [] as $header) {
            [$name, $value] = explode('=', explode(';', $header, 2)[0], 2);
            $cookies[$name] = $value;
        }
        return $response;
    };
    assertSameValue(303, $request('GET', '/settings/history')->status, 'anonymous settings denied');
    $request('GET', '/login');
    assertSameValue(303, $request('POST', '/login', ['_csrf' => $cookies[IdentityService::CSRF_COOKIE], 'login' => 'history@portable.test', 'password' => 'portable-cms-local'])->status, 'native login');
    $page = $request('GET', '/settings/history');
    assertSameValue(200, $page->status, 'settings available to owner');
    assertTrueValue(str_contains($page->body, '20,000') && str_contains($page->body, 'name="limit"'), 'native settings includes measured capacity and control');
    $form = ['_csrf' => $cookies[IdentityService::CSRF_COOKIE], '_revision' => (string) $service->load()->revision, 'limit' => '10'];
    assertSameValue(403, $request('POST', '/settings/history', [...$form, '_csrf' => 'wrong'])->status, 'CSRF enforced');
    assertSameValue(403, $request('POST', '/settings/history', $form, 'https://hostile.example')->status, 'origin enforced');
    $count = $service->retained();
    $page = $request('POST', '/settings/history', $form);
    assertTrueValue($page->status === 200 && str_contains($page->body, 'name="removal_count"') && str_contains($page->body, 'name="confirmed"'), 'lowering presents native removal preview and explicit confirmation');
    assertSameValue($count, $service->retained(), 'review removes nothing');
    assertSameValue(303, $request('POST', '/settings/history', [...$form, 'confirmed' => '1', 'removal_count' => (string) $service->excess(10)])->status, 'confirmed native reduction');
    assertTrueValue($service->load()->pending, 'native reduction exposes resumable cleanup');
} finally {
    RestoreTestFixture::remove($mediaRoot);
}

for ($i = 0; $i < 10 && $service->load()->pending; $i++) {
    $service->continue($actor);
}
assertSameValue(false, $service->load()->pending, 'native reduction completes');
// Simulate retained history on a pre-policy installation without spending hundreds of new saves.
$seedOlder = static function (int $count) use ($pdo, $repository, $second, $profile, $actorId, $random, $clock): void {
    $current = $repository->find('article', $second->id);
    $insert = $pdo->prepare('INSERT INTO cms_record_revisions (id, record_id, revision_number, profile_id, profile_version, body_json, created_by, created_at) VALUES (:id, :record, :number, :profile, :version, :body, :actor, :time)');
    $pdo->beginTransaction();
    for ($number = 1; $number <= $count; $number++) {
        $insert->execute(['id' => bin2hex($random->bytes(16)), 'record' => $second->id, 'number' => $number, 'profile' => $profile->id, 'version' => $profile->version, 'body' => json_encode($current->body, JSON_THROW_ON_ERROR), 'actor' => $actorId, 'time' => $clock->now()->format('Y-m-d H:i:s.u')]);
    }
    $pdo->commit();
};
$seedOlder(220);
assertSameValue(false, $service->load()->pending, 'untouched legacy history is not silently swept');
$second = $write($repository->find('article', $second->id));
assertSameValue(132, count($numbers($second->id)), 'save prunes only its bounded first batch of legacy history');
assertTrueValue($service->load()->pending, 'save leaves durable continuation for remaining excess');
$settings = $service->load();
$service->save(10, $settings->revision, null, $actor);
assertSameValue($settings->revision, $service->load()->revision, 'unchanged policy preserves queued progress');
for ($i = 0; $i < 3 && $service->load()->pending; $i++) {
    $resumed->continue($actor);
}
assertSameValue(11, count($numbers($second->id)), 'automatic backlog resumes to the exact allowance');
assertSameValue(false, $service->load()->pending, 'automatic backlog finishes without a permanent pending marker');
$seedOlder(120);
$second = $write($repository->find('article', $second->id));
assertTrueValue($service->load()->pending, 'another automatic backlog is pending');
$retained = $service->retained();
$service->save(500, $service->load()->revision, null, $actor);
$service->continue($actor);
assertSameValue(false, $service->load()->pending, 'raising invalidates automatic cleanup from an earlier policy');
assertSameValue($retained, $service->retained(), 'raising removes no remaining legacy history');

fwrite(STDOUT, "PASS bounded record history: protected heads, default/configured limits, one-record batches, interruption, audits, native confirmation and concurrent language deletion\n");
