<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Content\ContentService;
use Utsuri\PortableCms\Conventional\Content\MariaDbRecordRepository;
use Utsuri\PortableCms\Conventional\Content\ProfilePackage;
use Utsuri\PortableCms\Conventional\Content\RecordConflict;
use Utsuri\PortableCms\Conventional\Content\RecordPermissionDenied;
use Utsuri\PortableCms\Conventional\Content\RecordSnapshot;
use Utsuri\PortableCms\Conventional\Content\ValidationFailure;
use Utsuri\PortableCms\Conventional\Database\DatabaseConnection;
use Utsuri\PortableCms\Conventional\Database\MigrationRunner;
use Utsuri\PortableCms\Conventional\Database\TablePrefix;
use Utsuri\PortableCms\Conventional\DatabaseReport;
use Utsuri\PortableCms\Conventional\Identity\Clock;
use Utsuri\PortableCms\Conventional\Identity\RandomSource;
use Utsuri\PortableCms\Conventional\Identity\SessionContext;
use Utsuri\PortableCms\Conventional\Preview\MariaDbPreviewRepository;

/** The destination socket is owned by the enclosing isolated restore journey.
 * @param list<string> $paths
 */
function publishedRecordMigration(string $dsn, array $paths, Clock $clock, RandomSource $random): void
{
    $prefix = new TablePrefix('pinned_');
    $pdo = new DatabaseConnection($dsn, 'portable', 'integration-secret', $prefix, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    try {
        $old = array_values(array_filter($paths, static fn(string $path): bool => basename($path) !== '0027-published-record-revisions.sql'));
        (new MigrationRunner($pdo))->apply($old);
        $actor = (new \Utsuri\PortableCms\Conventional\Identity\AdministratorBootstrap($pdo, $clock, $random))->create('migration@portable.test', 'Migration owner', 'portable-cms-local');
        $time = $clock->now()->format('Y-m-d H:i:s.u');
        foreach (['published', 'draft', 'archived'] as $state) {
            $id = substr(hash('sha256', 'published-migration-record-' . $state), 0, 32);
            $revision = substr(hash('sha256', 'published-migration-revision-' . $state), 0, 32);
            $pdo->prepare('INSERT INTO cms_records (id, type_id, workflow_state, current_revision_id, current_revision_number, created_by, created_at, updated_by, updated_at) VALUES (:id, :type, :state, :revision, 1, :actor, :time, :editor, :updated)')->execute(['id' => $id, 'type' => 'project', 'state' => $state, 'revision' => $revision, 'actor' => $actor, 'time' => $time, 'editor' => $actor, 'updated' => $time]);
            $pdo->prepare('INSERT INTO cms_record_revisions (id, record_id, revision_number, profile_id, profile_version, body_json, created_by, created_at) VALUES (:id, :record, 1, :profile, :version, :body, :actor, :time)')->execute(['id' => $revision, 'record' => $id, 'profile' => 'reference-studio', 'version' => '1.1.1', 'body' => '{"title":"Existing content"}', 'actor' => $actor, 'time' => $time]);
        }
        $owner = substr(hash('sha256', 'published-migration-record-published'), 0, 32);
        $target = substr(hash('sha256', 'published-migration-record-draft'), 0, 32);
        $ownerRevision = substr(hash('sha256', 'published-migration-revision-published'), 0, 32);
        $pdo->prepare("INSERT INTO cms_record_relationship_index (record_id, revision_id, field_id, relation_kind, target_record_id, position) VALUES (:owner, :revision, 'related', 'record', :target, 0)")->execute(['owner' => $owner, 'revision' => $ownerRevision, 'target' => $target]);
        (new MigrationRunner($pdo))->apply($paths);
        assertSameValue(1, (int) $pdo->query('SELECT COUNT(*) FROM cms_record_relationship_index')->fetchColumn(), 'the populated relationship projection survives its key migration');
        $rows = $pdo->query('SELECT workflow_state, current_revision_id, published_revision_id FROM cms_records ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            assertSameValue($row['workflow_state'] === 'published' ? $row['current_revision_id'] : null, $row['published_revision_id'], 'the forward migration pins only existing Published records');
        }
        assertSameValue(['current' => 3, 'published' => 1, 'unpublishedChanges' => 0, 'invalidPublished' => 0], DatabaseReport::recordVersions($pdo), 'the database report counts the migrated published head');
        $report = DatabaseReport::collect(['dsn' => $dsn, 'username' => 'portable', 'password' => 'integration-secret', 'tablePrefix' => $prefix->value]);
        assertSameValue(['current' => 3, 'published' => 1, 'unpublishedChanges' => 0, 'invalidPublished' => 0], $report['recordVersions'], 'qualification reports count only the configured installation prefix');
        (new MigrationRunner($pdo))->apply($paths);
        assertSameValue(3, (int) $pdo->query('SELECT COUNT(*) FROM cms_record_revisions')->fetchColumn(), 'migration replay creates no content revisions');
    } finally {
        // Every table in this new prefix belongs to this one ephemeral fixture.
        $tables = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'pinned\\_%'")->fetchAll(PDO::FETCH_COLUMN);
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($tables as $table) {
            if (is_string($table) && preg_match('/\Apinned_[a-z0-9_]+\z/', $table) === 1) {
                $pdo->exec('DROP TABLE `' . $table . '`');
            }
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
}

/** Leaves valid newer edits for the enclosing normalized export/restore round trip. */
function publishedRecordRoundTrip(PDO $pdo, ContentService $content, ProfilePackage $package, string $actorId, Clock $clock, RandomSource $random): void
{
    $actor = new SessionContext('published-test', 'authenticated', $actorId, 'admin@portable.test', 'Administrator', 'administrator', 'token', 'csrf', $clock->now()->modify('+1 hour'), false);
    $repository = new MariaDbRecordRepository($pdo);
    $recordId = '10000000000000000000000000000001';
    $record = $content->find('project', $recordId) ?? throw new RuntimeException('Published project fixture missing');
    $initial = $record->body;
    $initial['related'] = [];
    $record = $content->saveCanonical('project', $recordId, $record->revisionNumber, $initial, $actorId);
    assertTrueValue($record instanceof RecordSnapshot, 'publication fixture removes its reference to an intentionally draft project');
    $published = $content->workflow()->transition('project', $recordId, $record->revisionNumber, 'published', $actor);
    if ($published instanceof ValidationFailure) {
        throw new RuntimeException('Published fixture validation: ' . json_encode($published->errors, JSON_THROW_ON_ERROR));
    }
    assertTrueValue($published instanceof RecordSnapshot, 'setup intentionally publishes the prepared video and current authored fields');
    $edit = static function (array $body) use ($repository, $content, $package, $actorId, $clock, $random, $recordId): RecordSnapshot {
        $current = $repository->find('project', $recordId) ?? throw new RuntimeException('Project disappeared');
        $validated = $content->validateCanonical('project', $body);
        $saved = $repository->update('project', $recordId, $current->revisionNumber, bin2hex($random->bytes(16)), $package->manifest->id, $package->manifest->version, $body, $validated->relationships, $actorId, bin2hex($random->bytes(16)), $clock->now());
        return $saved instanceof RecordSnapshot ? $saved : throw new RuntimeException('Unexpected concurrent fixture edit');
    };
    $body = $published->body;
    $body['title']['en'] = 'Edits waiting for publication';
    $current = $edit($body);
    $previews = new MariaDbPreviewRepository($pdo, $package->manifest->designRegistries, $package->checksum);
    $selected = static function (bool $drafts, bool $live = false) use ($previews, $package, $clock, $recordId): \Utsuri\PortableCms\Conventional\Preview\PreviewRecord {
        $snapshot = $previews->freezeSnapshot($package->manifest->id, $package->manifest->version, $package->manifest->locales, $clock->now(), $drafts, false, $live);
        return array_values(array_filter($snapshot->records, static fn($record): bool => $record->id === $recordId))[0];
    };
    assertSameValue($published->revisionId, $selected(false)->revisionId, 'release candidates select the published revision');
    assertSameValue($published->body['title'], $selected(false)->body['title'], 'new edits cannot replace released words');
    assertSameValue($current->revisionId, $selected(true)->revisionId, 'draft-inclusive previews select current edits');
    assertSameValue($current->revisionId, $selected(false, true)->revisionId, 'live pages select current edits');
    assertTrueValue($content->workflow()->transition('project', $recordId, $published->revisionNumber, 'published', $actor) instanceof RecordConflict, 'a stale publish cannot advance the head');
    $editor = new SessionContext('published-editor', 'authenticated', $actorId, 'editor@portable.test', 'Editor', 'editor', 'token', 'csrf', $clock->now()->modify('+1 hour'), false);
    foreach (['publish', 'discard'] as $action) {
        try {
            if ($action === 'publish') {
                $content->workflow()->transition('project', $recordId, $current->revisionNumber, 'published', $editor);
            } else {
                $content->workflow()->discard('project', $recordId, $current->revisionNumber, $editor);
            }
            throw new RuntimeException('Editor changed a published head');
        } catch (RecordPermissionDenied) {
        }
    }
    $next = $content->workflow()->transition('project', $recordId, $current->revisionNumber, 'published', $actor);
    assertTrueValue($next instanceof RecordSnapshot && !$next->hasUnpublishedChanges(), 'Publish changes selects the saved version');
    assertSameValue('Edits waiting for publication', $selected(false)->body['title']['en'], 'the next published-only preview takes the deliberate publication');
    $bad = $next->body;
    $bad['slug']['en'] = '';
    $invalid = $edit($bad);
    assertTrueValue($content->workflow()->transition('project', $recordId, $invalid->revisionNumber, 'published', $actor) instanceof ValidationFailure, 'Publish changes still validates required public-language content');
    assertSameValue($next->revisionId, $selected(false)->revisionId, 'a refused publication preserves the former published version');
    $before = (int) $pdo->query('SELECT COUNT(*) FROM cms_record_revisions')->fetchColumn();
    $pdo->exec("CREATE TRIGGER published_record_audit_fault BEFORE INSERT ON cms_audit_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'published record test rollback'");
    try {
        try {
            $content->workflow()->discard('project', $recordId, $invalid->revisionNumber, $actor);
            throw new RuntimeException('Failed discard audit committed');
        } catch (PDOException) {
        }
        assertSameValue($before, (int) $pdo->query('SELECT COUNT(*) FROM cms_record_revisions')->fetchColumn(), 'audit failure rolls back the discard revision');
        assertSameValue($invalid->revisionId, $repository->find('project', $recordId)->revisionId, 'audit failure keeps current and published heads intact');
        assertSameValue($invalid->publishedRevisionId, $repository->find('project', $recordId)->publishedRevisionId, 'failed discard cannot advance the published head');
    } finally {
        $pdo->exec('DROP TRIGGER published_record_audit_fault');
    }
    $restored = $content->workflow()->discard('project', $recordId, $invalid->revisionNumber, $actor);
    assertTrueValue($restored instanceof RecordSnapshot && !$restored->hasUnpublishedChanges(), 'discard restores a clean published version');
    assertSameValue($next->body, $restored->body, 'discard copies published content exactly');
    assertSameValue($invalid->revisionNumber + 1, $restored->revisionNumber, 'discard appends history');
    assertSameValue($before + 1, (int) $pdo->query('SELECT COUNT(*) FROM cms_record_revisions')->fetchColumn(), 'discard never removes the discarded history');
    assertSameValue(1, (int) $pdo->query("SELECT COUNT(*) FROM cms_audit_events WHERE event_type = 'record_changes_discarded'")->fetchColumn(), 'discard audit commits with its revision');
    assertTrueValue((int) $pdo->query("SELECT COUNT(*) FROM cms_audit_events WHERE event_type = 'record_changes_published'")->fetchColumn() > 0, 'Publish changes is audited');
    $draft = $content->workflow()->transition('project', $recordId, $restored->revisionNumber, 'draft', $actor);
    assertTrueValue($draft instanceof RecordSnapshot && $draft->publishedRevisionId === null, 'returning to Draft removes the published head');
    $archived = $content->workflow()->transition('project', $recordId, $draft->revisionNumber, 'archived', $actor);
    assertTrueValue($archived instanceof RecordSnapshot && $archived->publishedRevisionId === null, 'archiving removes the published head');
    $again = $content->workflow()->transition('project', $recordId, $archived->revisionNumber, 'published', $actor);
    $pending = $again->body;
    $pending['title']['en'] = 'Portable unpublished project title';
    $edit($pending);
    // Changing a mixed reference must retain the published relationship projection too.
    $row = $pdo->query("SELECT r.id, r.type_id FROM cms_records r JOIN cms_record_revisions v ON v.id = r.current_revision_id WHERE v.body_json LIKE '%featuredContent%' ORDER BY r.id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $home = is_array($row) ? $content->find((string) $row['type_id'], (string) $row['id']) : null;
    if ($home === null) {
        throw new RuntimeException('Mixed reference fixture missing');
    }
    $home = $content->workflow()->transition($home->typeId, $home->id, $home->revisionNumber, 'published', $actor);
    $homeBody = $home->body;
    array_pop($homeBody['featuredContent']);
    $valid = $content->validateCanonical($home->typeId, $homeBody);
    $saved = $repository->update($home->typeId, $home->id, $home->revisionNumber, bin2hex($random->bytes(16)), $package->manifest->id, $package->manifest->version, $homeBody, $valid->relationships, $actorId, bin2hex($random->bytes(16)), $clock->now());
    assertTrueValue($saved instanceof RecordSnapshot, 'new mixed-reference edit is kept');
    $heads = $pdo->prepare('SELECT COUNT(DISTINCT revision_id) FROM cms_record_relationship_index WHERE record_id = :record');
    $heads->execute(['record' => $home->id]);
    assertSameValue(2, (int) $heads->fetchColumn(), 'both relationship projections survive saving');
    assertSameValue(0, DatabaseReport::recordVersions($pdo)['invalidPublished'], 'published revision ownership is intact');
    fwrite(STDOUT, "PASS published revisions: populated forward migration, frozen/current/live selection, permissions, stale publish, validation, discard/audit rollback, retained history, unpublish/archive and both relationship heads.\n");
}
