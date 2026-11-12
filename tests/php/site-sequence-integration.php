<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Content\MariaDbRecordRepository;
use Utsuri\PortableCms\Conventional\Content\RecordConflict;
use Utsuri\PortableCms\Conventional\Database\MariaDbSiteSequence;
use Utsuri\PortableCms\Conventional\Database\MigrationRunner;
use Utsuri\PortableCms\Conventional\Database\SiteSequence;
use Utsuri\PortableCms\Conventional\Export\MariaDbExportRepository;
use Utsuri\PortableCms\Conventional\Identity\AdministratorBootstrap;
use Utsuri\PortableCms\Conventional\Identity\SystemClock;
use Utsuri\PortableCms\Conventional\Identity\SystemRandomSource;
use Utsuri\PortableCms\Conventional\Publication\MariaDbPublicationRepository;
use Utsuri\PortableCms\Conventional\Publication\Release;

require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/bootstrap.php';
require_once __DIR__ . '/assertions.php';

/** Measures real lock acquisition and hold time without changing the repository algorithm. */
final class SequenceTimingConnection extends PDO
{
    public float $acquisitionNanoseconds = 0;
    public float $heldNanoseconds = 0;
    private ?float $acquiredAt = null;

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $start = hrtime(true);
        $result = $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs);
        if (str_contains($query, 'FROM cms_site_sequence') && str_ends_with($query, 'FOR UPDATE')) {
            $this->acquiredAt = hrtime(true);
            $this->acquisitionNanoseconds += $this->acquiredAt - $start;
        }
        return $result;
    }

    public function commit(): bool
    {
        $result = parent::commit();
        if ($this->acquiredAt !== null) {
            $this->heldNanoseconds += hrtime(true) - $this->acquiredAt;
            $this->acquiredAt = null;
        }
        return $result;
    }
}

function sequenceConnection(): SequenceTimingConnection
{
    $dsn = getenv('CMS_TEST_SITE_SEQUENCE_DSN');
    if (!is_string($dsn) || $dsn === '') {
        throw new RuntimeException('CMS_TEST_SITE_SEQUENCE_DSN is required');
    }
    return new SequenceTimingConnection($dsn, 'portable', 'integration-secret', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
}

function sequenceId(string $name): string
{
    return md5('site-sequence-fixture:' . $name);
}

function sequenceCreate(PDO $pdo, string $name, string $actor, ?string $audit = null): \Utsuri\PortableCms\Conventional\Content\RecordSnapshot
{
    return (new MariaDbRecordRepository($pdo))->create(sequenceId($name), sequenceId($name . '-revision'), 'article', 'portable-gate-zero', '1.1.0', ['title' => $name], [], $actor, $audit ?? sequenceId($name . '-audit'), new DateTimeImmutable('2026-10-19T12:00:00Z'));
}

// An independent PHP process must actually reach a lock timeout before the first writer commits.
if (($argv[1] ?? '') === '--contender') {
    $actor = trim((string) fgets(STDIN));
    $pdo = sequenceConnection();
    $pdo->exec('SET SESSION innodb_lock_wait_timeout = 1');
    try {
        sequenceCreate($pdo, 'contender', $actor);
        throw new RuntimeException('Concurrent writer escaped the sequence lock');
    } catch (PDOException $error) {
        if (($error->errorInfo[1] ?? null) !== 1205) {
            throw $error;
        }
    }
    fwrite(STDOUT, "blocked\n");
    fflush(STDOUT);
    if (trim((string) fgets(STDIN)) !== 'retry') {
        throw new RuntimeException('Missing bounded retry signal');
    }
    sequenceCreate($pdo, 'contender', $actor);
    fwrite(STDOUT, "committed\n");
    exit(0);
}

$pdo = sequenceConnection();
$paths = glob(dirname(__DIR__, 2) . '/engine/migrations/*.sql') ?: [];
sort($paths, SORT_STRING);
$migration = dirname(__DIR__, 2) . '/engine/migrations/0021-site-sequence.sql';
assertTrueValue(in_array($migration, $paths, true), 'fixture identifies the sequence migration independently of later migrations');
$runner = new MigrationRunner($pdo);
$runner->apply(array_values(array_filter($paths, static fn(string $path): bool => strcmp($path, $migration) < 0)));
$actor = (new AdministratorBootstrap($pdo, new SystemClock(), new SystemRandomSource()))->create('sequence@example.test', 'Fictional operator', 'fictional sequence password');
$legacy = sequenceId('legacy');
$legacyRevision = sequenceId('legacy-revision');
$time = '2026-10-19 12:00:00.000000';
$pdo->prepare("INSERT INTO cms_records (id, type_id, workflow_state, current_revision_id, current_revision_number, created_by, created_at, updated_by, updated_at) VALUES (:id, 'article', 'archived', :revision, 1, :created, :at, :updated, :updated_at)")->execute(['id' => $legacy, 'revision' => $legacyRevision, 'created' => $actor, 'at' => $time, 'updated' => $actor, 'updated_at' => $time]);
$pdo->prepare("INSERT INTO cms_record_revisions (id, record_id, revision_number, profile_id, profile_version, body_json, created_by, created_at) VALUES (:id, :record, 1, 'portable-gate-zero', '1.1.0', '{\"title\":\"Legacy\"}', :actor, :at)")->execute(['id' => $legacyRevision, 'record' => $legacy, 'actor' => $actor, 'at' => $time]);
$runner->apply(array_values(array_filter($paths, static fn(string $path): bool => strcmp($path, $migration) >= 0)));
$sequence = new MariaDbSiteSequence($pdo);
assertSameValue(0, $sequence->current(), 'migration starts an empty sequence without renumbering history');
assertSameValue(null, $pdo->query('SELECT site_sequence FROM cms_record_revisions')->fetchColumn(), 'legacy revisions remain unsequenced');
$now = new DateTimeImmutable('2026-10-19T12:00:00Z');
$reader = sequenceConnection();
$pdo->beginTransaction();
$sequence->removal('article', $legacy, $legacyRevision, 1, $now);
$pdo->prepare('DELETE FROM cms_record_revisions WHERE record_id = :id')->execute(['id' => $legacy]);
$pdo->prepare('DELETE FROM cms_records WHERE id = :id')->execute(['id' => $legacy]);
$process = proc_open([PHP_BINARY, __FILE__, '--contender'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
if (!is_resource($process)) {
    throw new RuntimeException('Concurrent fixture process did not start');
}
$processClosed = false;
try {
    stream_set_timeout($pipes[1], 10);
    fwrite($pipes[0], $actor . "\n");
    fflush($pipes[0]);
    assertSameValue("blocked\n", fgets($pipes[1]), 'a second transaction cannot commit a later number while the first is open');
    assertSameValue(0, (new MariaDbSiteSequence($reader))->current(), 'uncommitted sequence is invisible');
    assertSameValue(0, (int) $reader->query('SELECT COUNT(*) FROM cms_site_changes')->fetchColumn(), 'no later change is visible');
    assertSameValue(1, (int) $reader->query('SELECT COUNT(*) FROM cms_records')->fetchColumn(), 'reader still sees the record before its removal commits');
    $pdo->commit();
    fwrite($pipes[0], "retry\n");
    fflush($pipes[0]);
    assertSameValue("committed\n", fgets($pipes[1]), 'contending transaction succeeds after the first commit');
    fclose($pipes[0]);
    fclose($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    $processClosed = true;
    assertSameValue(0, $exitCode, 'concurrent child succeeded: ' . $errors);
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if (!$processClosed) {
        proc_terminate($process);
        proc_close($process);
    }
}
assertSameValue([1, 2], array_map('intval', $pdo->query('SELECT sequence FROM cms_site_changes ORDER BY sequence')->fetchAll(PDO::FETCH_COLUMN)), 'commits are gap-free after a waiting transaction rolls back and retries');
try {
    sequenceCreate($pdo, 'failed-audit', $actor, sequenceId('contender-audit'));
    throw new RuntimeException('Duplicate audit unexpectedly committed');
} catch (PDOException) {
    assertSameValue(2, $sequence->current(), 'failure after assigning a number rolls the counter back');
    assertSameValue(0, (int) $pdo->query("SELECT COUNT(*) FROM cms_records WHERE id = '" . sequenceId('failed-audit') . "'")->fetchColumn(), 'failed transaction leaves no record');
}
$record = sequenceCreate($pdo, 'bulk', $actor);
assertSameValue(3, $sequence->current(), 'next successful revision reuses the invisible rolled-back position');
$repository = new MariaDbRecordRepository($pdo);
$pdo->acquisitionNanoseconds = 0;
$pdo->heldNanoseconds = 0;
$start = hrtime(true);
for ($index = 0; $index < 500; ++$index) {
    $record = $repository->update('article', $record->id, $record->revisionNumber, sequenceId('bulk-revision-' . $index), 'portable-gate-zero', '1.1.0', ['title' => 'Revision ' . $index], [], $actor, sequenceId('bulk-audit-' . $index), $now);
    assertTrueValue(!$record instanceof RecordConflict, 'bulk revision succeeds');
}
$milliseconds = (hrtime(true) - $start) / 1_000_000;
assertSameValue(503, $sequence->current(), 'all 500 ordinary revisions receive a gap-free sequence');
assertSameValue(range(1, 503), array_map('intval', $pdo->query('SELECT sequence FROM cms_site_changes ORDER BY sequence')->fetchAll(PDO::FETCH_COLUMN)), 'each committed number has exactly one change');
fwrite(STDOUT, sprintf("MEASURE 500 revisions with commit-order lock: %.2f ms total, %.3f ms/revision (includes revision, relationships, audit and commit).\n", $milliseconds, $milliseconds / 500));
fwrite(STDOUT, sprintf("MEASURE sequence lock across 500 revisions: %.2f ms acquiring, %.2f ms held through commit, %.3f ms held/revision.\n", $pdo->acquisitionNanoseconds / 1_000_000, $pdo->heldNanoseconds / 1_000_000, $pdo->heldNanoseconds / 500_000_000));
$conflict = $repository->update('article', $record->id, 1, sequenceId('conflict'), 'portable-gate-zero', '1.1.0', ['title' => 'Stale'], [], $actor, sequenceId('conflict-audit'), $now);
assertTrueValue($conflict instanceof RecordConflict, 'stale revision conflicts');
assertSameValue(503, $sequence->current(), 'conflicts consume no number');
$archived = $repository->transition($record, 'archived', sequenceId('archive'), 'portable-gate-zero', '1.1.0', $actor, sequenceId('archive-audit'), $now);
assertTrueValue(!$archived instanceof RecordConflict, 'archive has an ordinary sequenced revision');
assertSameValue(true, $repository->deleteArchived($archived, $actor, sequenceId('delete-audit'), $now), 'archived record removal succeeds');
assertSameValue(505, $sequence->current(), 'archive and removal each receive one number');
$removed = $pdo->query('SELECT record_type, record_id, revision_id, revision_number FROM cms_site_changes WHERE sequence = 505')->fetch(PDO::FETCH_ASSOC);
assertSameValue(['article', $record->id, $archived->revisionId, $archived->revisionNumber], [$removed['record_type'], $removed['record_id'], $removed['revision_id'], (int) $removed['revision_number']], 'tombstone survives deletion of record and revisions');

// Real publication repository: approval is not an activation; an older preview can go live again.
$preview = sequenceId('preview');
$job = sequenceId('preview-job');
$pdo->prepare("INSERT INTO cms_jobs (id, job_type, status, payload_json, available_at, created_at, updated_at) VALUES (:id, 'preview_build', 'completed', '{}', :available, :created, :updated)")->execute(['id' => $job, 'available' => $time, 'created' => $time, 'updated' => $time]);
$pdo->prepare("INSERT INTO cms_previews (id, profile_id, profile_version, status, snapshot_json, job_id, created_by, created_at, updated_at) VALUES (:id, 'portable-gate-zero', '1.1.0', 'ready', '{}', :job, :actor, :created, :updated)")->execute(['id' => $preview, 'job' => $job, 'actor' => $actor, 'created' => $time, 'updated' => $time]);
$publications = new MariaDbPublicationRepository($pdo);
foreach (['first', 'rollback'] as $name) {
    $release = new Release(sequenceId($name), $preview, $publications->head()->activeId, 'queued', str_repeat('a', 64), $actor, null, str_repeat('b', 64));
    $before = $sequence->current();
    $publications->enqueue($release, sequenceId($name . '-approve'), $now);
    assertSameValue($before, $sequence->current(), 'approval consumes no activation number');
    $publications->activate($release, null, '{}', sequenceId($name . '-activate'), $now);
    assertSameValue($before + 1, $sequence->current(), 'each activation including rollback advances the site sequence');
}
assertSameValue([506, 507], array_map('intval', $pdo->query('SELECT site_sequence FROM cms_releases ORDER BY site_sequence')->fetchAll(PDO::FETCH_COLUMN)), 'activation identity is persisted outside prunable history');
$export = (new MariaDbExportRepository($pdo))->create(sequenceId('export'), sequenceId('export-audit'), $actor, 'portable-gate-zero', '1.1.0', ['en'], $now);
assertSameValue(507, $export->document['siteSequence'], 'export preserves high-water mark including activations');
assertTrueValue(!array_key_exists('siteChanges', $export->document), 'history is not exported');

// Retention is a prefix, even when the injected clock moves backwards.
$pdo->exec("UPDATE cms_site_changes SET occurred_at = '2026-01-01 00:00:00'");
$pdo->exec("UPDATE cms_site_changes SET occurred_at = '2026-10-19 12:00:00' WHERE sequence = 3");
assertSameValue(2, $sequence->prune($now), 'prune stops before a fresh earlier position');
assertSameValue(2, (int) $pdo->query('SELECT pruned_through FROM cms_site_sequence')->fetchColumn(), 'expired cursor floor is explicit');
assertSameValue(505, $sequence->prune($now->modify('+31 days')), 'later worker prunes the now-expired retained prefix');
assertSameValue(507, $sequence->current(), 'pruning never resets the counter');
$pdo->beginTransaction();
for ($index = 0; $index < SiteSequence::PRUNE_BATCH + 1; ++$index) {
    $sequence->removal('article', $legacy, $legacyRevision, 1, $now->modify('-31 days'));
}
$pdo->commit();
assertSameValue(SiteSequence::PRUNE_BATCH, $sequence->prune($now), 'one worker wake is bounded');
assertSameValue(1, $sequence->prune($now), 'the next wake resumes the expired prefix');
$pdo->beginTransaction();
$pdo->exec('UPDATE cms_site_sequence SET current_sequence = 100000, pruned_through = 0 WHERE singleton_id = 1');
$pdo->prepare("INSERT INTO cms_site_changes (sequence, kind, record_type, record_id, revision_id, revision_number, occurred_at) VALUES (1, 'record_removed', 'article', :record, :revision, 1, :at)")->execute(['record' => $legacy, 'revision' => $legacyRevision, 'at' => $time]);
$sequence->removal('article', $legacy, $legacyRevision, 1, $now);
assertSameValue(1, (int) $pdo->query('SELECT pruned_through FROM cms_site_sequence')->fetchColumn(), 'the hard history cap advances independently of clock age');
assertSameValue([100001], array_map('intval', $pdo->query('SELECT sequence FROM cms_site_changes')->fetchAll(PDO::FETCH_COLUMN)), 'a still-fresh entry beyond the capacity window is evicted');
$pdo->rollBack();
$pdo->beginTransaction();
$sequence->restore(1_000_000);
$pdo->commit();
assertSameValue(1_000_000, $sequence->current(), 'restore keeps source high-water mark');
$pdo->beginTransaction();
$sequence->restore(1);
$pdo->commit();
assertSameValue(1_000_000, $sequence->current(), 'restore never lowers destination high-water mark');
$record = sequenceCreate($pdo, 'after-restore', $actor);
assertSameValue(1_000_001, $sequence->current(), 'post-restore numbers do not repeat');
$pdo->beginTransaction();
$sequence->restore(SiteSequence::MAXIMUM);
$pdo->commit();
try {
    sequenceCreate($pdo, 'overflow', $actor);
    throw new RuntimeException('Sequence overflow unexpectedly succeeded');
} catch (OverflowException) {
    assertSameValue(SiteSequence::MAXIMUM, $sequence->current(), 'exhaustion fails atomically');
}
fwrite(STDOUT, "PASS site sequence migration, concurrent commit ordering, failed transactions, 500 revisions, removal, activations/rollback, pruning, export and restore high-water mark.\n");
