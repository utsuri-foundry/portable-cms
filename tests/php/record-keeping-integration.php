<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Content\ContentService;
use Utsuri\PortableCms\Conventional\Content\KeptRecord;
use Utsuri\PortableCms\Conventional\Content\MariaDbRecordRepository;
use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Content\RecordConflict;
use Utsuri\PortableCms\Conventional\Content\RecordEditIntent;
use Utsuri\PortableCms\Conventional\Content\RecordSnapshot;
use Utsuri\PortableCms\Conventional\Content\ValidationFailure;
use Utsuri\PortableCms\Conventional\Database\DatabaseConnection;
use Utsuri\PortableCms\Conventional\Database\MigrationRunner;
use Utsuri\PortableCms\Conventional\Database\TablePrefix;
use Utsuri\PortableCms\Conventional\Export\MariaDbExportRepository;
use Utsuri\PortableCms\Conventional\Identity\AdministratorBootstrap;
use Utsuri\PortableCms\Conventional\Identity\Clock;
use Utsuri\PortableCms\Conventional\Identity\SessionContext;
use Utsuri\PortableCms\Conventional\Identity\SystemRandomSource;
use Utsuri\PortableCms\Conventional\Preview\MariaDbPreviewRepository;

require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/bootstrap.php';
require_once __DIR__ . '/assertions.php';

$dsn = getenv('CMS_TEST_SITE_SEQUENCE_DSN');
if (!is_string($dsn) || $dsn === '') {
    throw new RuntimeException('An isolated editing database is required');
}
$pdo = new DatabaseConnection($dsn, 'portable', 'integration-secret', new TablePrefix('editing_'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$migrations = glob(dirname(__DIR__, 2) . '/engine/migrations/*.sql');
sort($migrations);
(new MigrationRunner($pdo))->apply($migrations);
$clock = new class implements Clock {
    public DateTimeImmutable $value;
    public function __construct()
    {
        $this->value = new DateTimeImmutable('2026-10-30T12:00:00Z');
    }
    public function now(): DateTimeImmutable
    {
        return $this->value;
    }
};
$random = new SystemRandomSource();
$actor = (new AdministratorBootstrap($pdo, $clock, $random))->create('editing@portable.test', 'Editing owner', 'portable editing test password');
$other = bin2hex($random->bytes(16));
$pdo->prepare("INSERT INTO cms_users (id, login_name, display_name, password_hash, role, status, created_at, password_changed_at) SELECT :id, 'other@portable.test', 'Other editor', password_hash, 'editor', status, created_at, password_changed_at FROM cms_users WHERE id = :owner")->execute(['id' => $other, 'owner' => $actor]);
$profile = ProfileManifest::gateZero();
$repository = new MariaDbRecordRepository($pdo);
$content = new ContentService($repository, $profile, $clock, $random);
$id = bin2hex($random->bytes(16));
$session = hash('sha256', 'first editing page');
$input = ['title' => '', 'summary' => 'Kept even while incomplete'];
$request = bin2hex($random->bytes(16));
$first = $content->keep('portfolio-entry', $id, $input, $actor, new RecordEditIntent(0, $session, $request));
assertTrueValue($first instanceof KeptRecord && $first->errors === [], 'empty required text is a valid incomplete draft');
$record = $first->record;
assertSameValue('draft', $record->workflowState, 'first kept edit creates a draft');
$replayed = $content->keep($record->typeId, $id, $input, $actor, new RecordEditIntent(0, $session, $request));
assertSameValue($record->editSequence, $replayed->record->editSequence, 'lost response retry creates no second revision or sequence');
assertTrueValue($content->keep($record->typeId, $id, ['title' => 'A changed request under an old identity'], $actor, new RecordEditIntent(0, $session, $request)) instanceof RecordConflict, 'a replay identity cannot acknowledge a different body');
$input['title'] = 'First kept title';
$second = $content->keep($record->typeId, $id, $input, $actor, new RecordEditIntent($record->editSequence, $session, bin2hex($random->bytes(16))));
assertSameValue($record->revisionId, $second->record->revisionId, 'same page/person coalesces into one editing revision');
assertTrueValue($second->record->editSequence > $record->editSequence, 'each commit advances the stale-tab token and change cursor');
assertTrueValue($content->keep($record->typeId, $id, ['title' => 'Stale overwrite'], $actor, new RecordEditIntent($record->editSequence, $session, bin2hex($random->bytes(16)))) instanceof RecordConflict, 'same-person stale tab never overwrites a coalesced revision');
assertTrueValue($content->rewrite($record->typeId, $id, $record->revisionNumber, $record->body, $actor, $record->editSequence) instanceof RecordConflict, 'language maintenance cannot overwrite a newer edit within the same revision');
$record = $second->record;
$editingActor = new SessionContext(bin2hex($random->bytes(16)), 'authenticated', $actor, 'editing@portable.test', 'Owner', 'owner', 'session-token', 'csrf-token', $clock->now()->modify('+1 hour'), false);
$http = new \Utsuri\PortableCms\Conventional\Content\EditorialRecordHttp($content, $profile, $random, null, false, false);
$transient = ['_revision' => (string) $record->revisionNumber, '_edit_sequence' => (string) $first->record->editSequence, '_editorial_end' => '1', '_editor_action' => 'search:topics', 'title' => 'Stale tab keeps its words', 'summary' => 'Unsent words'];
$path = '/records/' . $record->typeId . '/' . $id;
$route = ['kind' => 'record', 'typeId' => $record->typeId, 'recordId' => $id];
$conflict = $http->handle(new \Utsuri\PortableCms\Conventional\Http\Request('POST', $path, form: $transient), $route, $editingActor, 'editing-conflict');
assertSameValue(409, $conflict->status, 'a transient search cannot renew a stale same-revision form');
assertTrueValue(str_contains($conflict->body, 'Stale tab keeps its words') && str_contains($conflict->body, 'name="_editor_base_sequence" value="' . $first->record->editSequence . '"'), 'conflict preserves input and its original editing sequence');
$transient['_editor_base_sequence'] = $transient['_edit_sequence'];
$transient['_edit_sequence'] = (string) $record->editSequence;
$conflictAgain = $http->handle(new \Utsuri\PortableCms\Conventional\Http\Request('POST', $path, form: $transient), $route, $editingActor, 'editing-conflict-again');
assertSameValue(409, $conflictAgain->status, 'searching again from the conflict view cannot silently enable keeping');
assertSameValue($record->body, $repository->find($record->typeId, $id)->body, 'transient conflicts write no content');
$bad = $content->keep($record->typeId, $id, ['title' => str_repeat('x', 161), 'summary' => 'Valid sibling is kept'], $actor, new RecordEditIntent($record->editSequence, $session, bin2hex($random->bytes(16))));
assertTrueValue(isset($bad->errors['title']), 'invalid value is reported at its field');
assertSameValue('First kept title', $bad->record->body['title'], 'invalid field retains its last good server value');
assertSameValue('Valid sibling is kept', $bad->record->body['summary'], 'other fields commit despite an invalid value');
$record = $bad->record;
$clock->value = $clock->value->modify('+10 minutes');
$afterIdle = $content->keep($record->typeId, $id, ['title' => 'After idle'], $actor, new RecordEditIntent($record->editSequence, $session, bin2hex($random->bytes(16))));
assertSameValue($record->revisionNumber + 1, $afterIdle->record->revisionNumber, 'ten minutes without changes starts a fresh revision');
$record = $afterIdle->record;
$otherEdit = $content->keep($record->typeId, $id, ['title' => 'Another person'], $other, new RecordEditIntent($record->editSequence, $session, bin2hex($random->bytes(16))));
assertSameValue($record->revisionNumber + 1, $otherEdit->record->revisionNumber, 'another person starts a new revision even with the same supplied session key');
$record = $otherEdit->record;
$own = $content->keep($record->typeId, $id, ['title' => 'Owner resumes'], $actor, new RecordEditIntent($record->editSequence, $session, bin2hex($random->bytes(16))));
assertSameValue($record->revisionNumber + 1, $own->record->revisionNumber, 'resuming after another person starts a revision');
$record = $own->record;
$actorSession = new SessionContext(bin2hex($random->bytes(16)), 'authenticated', $actor, 'editing@portable.test', 'Owner', 'owner', 'session-token', 'csrf-token', $clock->now()->modify('+1 hour'), false);
$published = $content->workflow()->transition($record->typeId, $id, $record->revisionNumber, 'published', $actorSession, $record->editSequence);
assertTrueValue($published instanceof RecordSnapshot, 'complete content can be published');
$unchanged = $content->keep($record->typeId, $id, $published->body, $actor, new RecordEditIntent($published->editSequence, $session, bin2hex($random->bytes(16))));
assertSameValue($published->revisionId, $unchanged->record->revisionId, 'picker-only submissions do not create unpublished changes on an unchanged record');
assertSameValue($published->editSequence, $unchanged->record->editSequence, 'unchanged values consume no revision, audit or sequence');
$edit = $content->keep($record->typeId, $id, ['title' => 'New unpublished title'], $actor, new RecordEditIntent($published->editSequence, $session, bin2hex($random->bytes(16))));
assertTrueValue($edit->record->revisionId !== $published->revisionId, 'published revision is never coalesced');
assertSameValue('Owner resumes', $repository->published($edit->record)->body['title'], 'published bytes remain intact');
$previews = new MariaDbPreviewRepository($pdo);
$preview = $previews->enqueue(bin2hex($random->bytes(16)), bin2hex($random->bytes(16)), bin2hex($random->bytes(16)), $actor, $profile->id, $profile->version, $profile->locales, $clock->now(), true);
$frozenId = $edit->record->revisionId;
$frozenBody = $pdo->query("SELECT body_json FROM cms_record_revisions WHERE id = '" . $frozenId . "'")->fetchColumn();
$next = $content->keep($record->typeId, $id, ['title' => 'After exact preview'], $actor, new RecordEditIntent($edit->record->editSequence, $session, bin2hex($random->bytes(16))));
assertTrueValue($next->record->revisionId !== $frozenId, 'preview capture seals the editing revision');
assertSameValue($frozenBody, $pdo->query("SELECT body_json FROM cms_record_revisions WHERE id = '" . $frozenId . "'")->fetchColumn(), 'revision referenced by exact preview never changes');
$record = $next->record;
$audit = (string) $pdo->query('SELECT id FROM cms_audit_events LIMIT 1')->fetchColumn();
try {
    $repository->keep($record->typeId, $id, $record, new RecordEditIntent($record->editSequence, $session, bin2hex($random->bytes(16))), bin2hex($random->bytes(16)), $profile->id, $profile->version, ['title' => 'Must roll back'], [], $actor, $audit, $clock->now());
    throw new RuntimeException('Audit failure was accepted');
} catch (PDOException) {
}
assertSameValue($record->body, $repository->find($record->typeId, $id)->body, 'audit failure rolls back a coalesced body');
assertSameValue($record->editSequence, $repository->find($record->typeId, $id)->editSequence, 'audit failure rolls back the sequence');
assertTrueValue($content->workflow()->transition($record->typeId, $id, $record->revisionNumber, 'published', $actorSession, $published->editSequence) instanceof RecordConflict, 'stale publish checks the editing token, not just revision number');
$empty = $content->keep($record->typeId, $id, ['title' => ''], $actor, new RecordEditIntent($record->editSequence, $session, bin2hex($random->bytes(16))));
assertTrueValue($content->workflow()->transition($record->typeId, $id, $empty->record->revisionNumber, 'published', $actorSession) instanceof ValidationFailure, 'keeping incomplete content never bypasses publication requirements');
$content->validateCanonical($record->typeId, $empty->record->body);
$futureTarget = bin2hex($random->bytes(16));
$articleId = bin2hex($random->bytes(16));
$articleRequest = bin2hex($random->bytes(16));
$articleInput = ['title' => 'Partial save with a lost reply', 'body' => 'Keep the valid text', 'featuredEntry' => $futureTarget];
$partial = $content->keep('article', $articleId, $articleInput, $actor, new RecordEditIntent(0, $session, $articleRequest));
assertTrueValue(isset($partial->errors['featuredEntry']), 'unavailable reference is left out of the partial save');
$repository->create($futureTarget, bin2hex($random->bytes(16)), 'portfolio-entry', $profile->id, $profile->version, ['title' => 'Now available', 'summary' => '', 'topics' => []], [], $actor, bin2hex($random->bytes(16)), $clock->now());
$recovered = $content->keep('article', $articleId, $articleInput, $actor, new RecordEditIntent(0, $session, $articleRequest));
assertTrueValue($recovered instanceof KeptRecord && $recovered->errors === [], 'retry resumes the partial request when readiness changes');
assertSameValue($futureTarget, $recovered->record->body['featuredEntry'], 'recovery never acknowledges values that were not stored');
assertSameValue($partial->record->revisionId, $recovered->record->revisionId, 'partial recovery remains one editing revision');
$again = $content->keep('article', $articleId, $articleInput, $actor, new RecordEditIntent(0, $session, $articleRequest));
assertSameValue($recovered->record->editSequence, $again->record->editSequence, 'another lost reply remains idempotent after completing the partial save');
$pdo->exec('UPDATE cms_record_history_settings SET history_limit = 10 WHERE id = 1');
$historyRecord = $repository->find($record->typeId, $id);
for ($index = 0; $index < 15; $index++) {
    $historyRecord = $content->update($record->typeId, $id, $historyRecord->revisionNumber, ['title' => 'History boundary ' . $index], $actor, $historyRecord->editSequence);
    assertTrueValue($historyRecord instanceof RecordSnapshot, 'ordinary saves retain the configured history policy');
}
$grouped = $content->keep($record->typeId, $id, ['title' => 'Grouped after pruning'], $actor, new RecordEditIntent($historyRecord->editSequence, $session, bin2hex($random->bytes(16))));
$coalesced = $content->keep($record->typeId, $id, ['title' => 'Another grouped change'], $actor, new RecordEditIntent($grouped->record->editSequence, $session, bin2hex($random->bytes(16))));
assertSameValue($grouped->record->revisionId, $coalesced->record->revisionId, 'history pruning preserves the active editing revision');
$count = $pdo->prepare('SELECT COUNT(*) FROM cms_record_revisions WHERE record_id = :id');
$count->execute(['id' => $id]);
assertSameValue(12, (int) $count->fetchColumn(), 'automatic keeping retains ten historical revisions plus current and published heads');
assertSameValue('Owner resumes', $repository->published($coalesced->record)->body['title'], 'pruning and grouped edits preserve published content');
$export = (new MariaDbExportRepository($pdo))->create(bin2hex($random->bytes(16)), bin2hex($random->bytes(16)), $actor, $profile->id, $profile->version, $profile->locales, $clock->now());
assertTrueValue(count(iterator_to_array($export->rows('revisions'))) >= 6, 'editing session revisions remain portable');
// A rejected numeric edit must retain canonical numbers, not reinterpret them as empty form text.
$numericDocument = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/engine/src/cms/conventional/profile/gate-zero.json'), true, 64, JSON_THROW_ON_ERROR);
foreach ([['id' => 'year', 'label' => 'Year', 'type' => 'integer', 'settings' => ['minimum' => 1900, 'maximum' => 2200]],
    ['id' => 'amount', 'label' => 'Amount', 'type' => 'decimal', 'settings' => ['minimum' => '0.00', 'maximum' => '9999.99', 'scale' => 2]]] as $field) {
    $numericDocument['recordTypes'][0]['fields'][] = $field + ['localized' => false, 'requiredOnDraft' => false, 'requiredOnPublish' => false];
}
$numericPath = tempnam(sys_get_temp_dir(), 'portable-keep-numbers-');
if (!is_string($numericPath)) {
    throw new RuntimeException('Could not create numeric fixture profile');
}
try {
    file_put_contents($numericPath, json_encode($numericDocument, JSON_THROW_ON_ERROR));
    $numericProfile = ProfileManifest::load($numericPath);
} finally {
    unlink($numericPath);
}
$numericContent = new ContentService($repository, $numericProfile, $clock, $random);
$numericId = bin2hex($random->bytes(16));
$numeric = $numericContent->keep('portfolio-entry', $numericId, ['title' => 'Numeric fixture', 'year' => '2025', 'amount' => '123.45'], $actor, new RecordEditIntent(0, $session, bin2hex($random->bytes(16))));
assertTrueValue($numeric instanceof KeptRecord && $numeric->errors === [], 'valid numbers are kept before rejecting edits');
$rejected = $numericContent->keep('portfolio-entry', $numericId, ['title' => 'A valid sibling changes', 'year' => '1800', 'amount' => 'not a number'], $actor, new RecordEditIntent($numeric->record->editSequence, $session, bin2hex($random->bytes(16))));
assertTrueValue($rejected instanceof KeptRecord && isset($rejected->errors['year'], $rejected->errors['amount']), 'invalid integer bounds and decimal syntax remain field errors');
assertSameValue($numeric->record->body['year'], $rejected->record->body['year'], 'rejected integer retains its last good canonical value and type');
assertSameValue($numeric->record->body['amount'], $rejected->record->body['amount'], 'rejected decimal retains its last good canonical value and type');
assertSameValue('A valid sibling changes', $rejected->record->body['title'], 'numeric errors do not prevent valid sibling edits');
assertSameValue($rejected->record->body, $repository->find('portfolio-entry', $numericId)->body, 'typed numeric fallback survives the database JSON round trip');
$cleared = $numericContent->keep('portfolio-entry', $numericId, ['title' => 'Numbers deliberately cleared', 'year' => '', 'amount' => ''], $actor, new RecordEditIntent($rejected->record->editSequence, $session, bin2hex($random->bytes(16))));
assertTrueValue($cleared instanceof KeptRecord && $cleared->errors === [], 'optional numbers may still be deliberately cleared');
assertSameValue(null, $cleared->record->body['year'], 'intentional integer clearing is distinct from a rejected value');
assertSameValue(null, $cleared->record->body['amount'], 'intentional decimal clearing is distinct from a rejected value');
fwrite(STDOUT, "PASS numeric record keeping: rejected integer/decimal edits retain typed canonical values, valid siblings save and explicit clearing remains available.\n");
fwrite(STDOUT, "PASS automatic record keeping: incomplete drafts, partial validation, retry replay, session grouping, idle and person boundaries, stale tabs/publication, published and preview sealing, audit rollback and portable revisions.\n");
