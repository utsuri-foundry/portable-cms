<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Content\ContentService;
use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Content\RecordConflict;
use Utsuri\PortableCms\Conventional\Content\RecordPermissionDenied;
use Utsuri\PortableCms\Conventional\Content\RecordSnapshot;
use Utsuri\PortableCms\Conventional\Export\NormalizedExport;
use Utsuri\PortableCms\Conventional\I18n\Translator;
use Utsuri\PortableCms\Conventional\View\RecordWorkflowPage;

$tests['published record edits retain the published head until an authorized optimistic publish'] = static function (): void {
    $repository = new MemoryRecordRepository();
    $clock = new IdentityTestClock(new DateTimeImmutable('2026-10-30T12:00:00Z'));
    $content = new ContentService($repository, ProfileManifest::gateZero(), $clock, new IdentityTestRandom());
    $draft = $content->create('portfolio-entry', ['title' => 'Released words', 'summary' => 'Original'], str_repeat('1', 32));
    assertTrueValue($draft instanceof RecordSnapshot, 'valid draft');
    $published = $content->workflow()->transition($draft->typeId, $draft->id, $draft->revisionNumber, 'published', workflowTestActor());
    assertTrueValue($published instanceof RecordSnapshot, 'published revision exists');
    assertSameValue($published->revisionId, $published->publishedRevisionId, 'Publish selects the saved content');
    $edited = $content->update($published->typeId, $published->id, $published->revisionNumber, ['title' => 'Kept edits', 'summary' => 'New'], str_repeat('1', 32));
    assertTrueValue($edited instanceof RecordSnapshot && $edited->hasUnpublishedChanges(), 'saving keeps a separate current head');
    assertSameValue($published->revisionId, $edited->publishedRevisionId, 'saving never advances the published head');
    assertSameValue(1, $content->pendingPublicationCount(), 'preview notice counts pending records');
    assertSameValue(1, count($content->search('portfolio-entry', '', 1, 'published_changes')->records), 'the dedicated filter finds pending records');
    foreach (['editor', null] as $role) {
        try {
            $content->workflow()->transition($edited->typeId, $edited->id, $edited->revisionNumber, 'published', workflowTestActor($role));
            throw new RuntimeException('Unprivileged publish accepted');
        } catch (RecordPermissionDenied) {
        }
    }
    assertTrueValue($content->workflow()->transition($edited->typeId, $edited->id, $published->revisionNumber, 'published', workflowTestActor()) instanceof RecordConflict, 'stale publication cannot pick a newer edit');
    $next = $content->workflow()->transition($edited->typeId, $edited->id, $edited->revisionNumber, 'published', workflowTestActor());
    assertTrueValue($next instanceof RecordSnapshot && !$next->hasUnpublishedChanges(), 'Publish changes advances both heads with a concurrency revision');
    assertSameValue('Kept edits', $repository->published($next)->body['title'], 'the deliberate action takes the latest saved words');
    assertSameValue(0, $content->pendingPublicationCount(), 'pending count clears');
};

$tests['discard restores published content as a new revision and unpublishing removes its published head'] = static function (): void {
    $repository = new MemoryRecordRepository();
    $content = new ContentService($repository, ProfileManifest::gateZero(), new IdentityTestClock(new DateTimeImmutable('2026-10-30T12:00:00Z')), new IdentityTestRandom());
    $draft = $content->create('portfolio-entry', ['title' => 'Published content', 'summary' => 'Keep'], str_repeat('1', 32));
    $published = $content->workflow()->transition($draft->typeId, $draft->id, $draft->revisionNumber, 'published', workflowTestActor());
    $edited = $content->update($draft->typeId, $draft->id, $published->revisionNumber, ['title' => 'Discard me', 'summary' => 'Changed'], str_repeat('1', 32));
    try {
        $content->workflow()->discard($draft->typeId, $draft->id, $edited->revisionNumber, workflowTestActor('editor'));
        throw new RuntimeException('Unprivileged discard accepted');
    } catch (RecordPermissionDenied) {
    }
    assertTrueValue($content->workflow()->discard($draft->typeId, $draft->id, $published->revisionNumber, workflowTestActor()) instanceof RecordConflict, 'discard checks the exact current revision');
    $restored = $content->workflow()->discard($draft->typeId, $draft->id, $edited->revisionNumber, workflowTestActor());
    assertSameValue($published->body, $restored->body, 'discard restores the published body exactly');
    assertSameValue($edited->revisionNumber + 1, $restored->revisionNumber, 'discard creates a new revision');
    assertSameValue(false, $restored->hasUnpublishedChanges(), 'discard leaves no pending content');
    $draftAgain = $content->workflow()->transition($draft->typeId, $draft->id, $restored->revisionNumber, 'draft', workflowTestActor());
    assertSameValue(null, $draftAgain->publishedRevisionId, 'unpublishing clears the published revision');
    $archived = $content->workflow()->transition($draft->typeId, $draft->id, $draftAgain->revisionNumber, 'archived', workflowTestActor());
    assertSameValue(null, $archived->publishedRevisionId, 'archives have no published revision');
    assertSameValue($published->body, $archived->body, 'status changes never edit the body');
};

$tests['normalized exports validate both record heads and their independent relationship projections'] = static function (): void {
    $package = \Utsuri\PortableCms\Conventional\Content\ProfilePackage::load(dirname(__DIR__, 2) . '/profiles/reference-studio');
    $document = RestoreTestFixture::document($package);
    $record = &$document['records'][0];
    $old = $record['currentRevisionId'];
    $next = str_repeat('e', 32);
    $revision = array_values(array_filter($document['revisions'], static fn(array $r): bool => $r['id'] === $old))[0];
    $document['revisions'][] = [...$revision, 'id' => $next, 'revisionNumber' => 2];
    $record['publishedRevisionId'] = $old;
    $record['currentRevisionId'] = $next;
    $record['currentRevisionNumber'] = 2;
    foreach ($document['relationships'] as $relation) {
        if ($relation['recordId'] === $record['id']) {
            $document['relationships'][] = [...$relation, 'revisionId' => $next];
        }
    }
    unset($record);
    $export = new NormalizedExport($document);
    assertSameValue($old, NormalizedExport::publishedRevisionId($export->document['records'][0]), 'published identity is distinct and portable');
    new \Utsuri\PortableCms\Conventional\Restore\RestorePlan($export, hash('sha256', $export->json()), $package, new IdentityTestClock(new DateTimeImmutable('2026-10-30T12:00:00Z')), new IdentityTestRandom());
    $forged = $document;
    $forged['records'][0]['publishedRevisionId'] = $document['revisions'][1]['id'];
    try {
        new NormalizedExport($forged);
        throw new RuntimeException('Foreign published revision accepted');
    } catch (InvalidArgumentException) {
    }
    $forged = $document;
    $forged['relationships'] = array_values(array_filter($forged['relationships'], static fn(array $r): bool => $r['revisionId'] !== $old));
    try {
        $bad = new NormalizedExport($forged);
        new \Utsuri\PortableCms\Conventional\Restore\RestorePlan($bad, hash('sha256', $bad->json()), $package, new IdentityTestClock(new DateTimeImmutable('2026-10-30T12:00:00Z')), new IdentityTestRandom());
        throw new RuntimeException('Missing published relationships accepted');
    } catch (\Utsuri\PortableCms\Conventional\Restore\RestoreRejected) {
    }
};

$tests['published controls name the pinned version and require confirmation before discarding'] = static function (): void {
    $record = new RecordSnapshot(str_repeat('a', 32), 'project', 'published', str_repeat('c', 32), 3, ['title' => 'New'], new DateTimeImmutable('2026-10-30T12:00:00Z'), str_repeat('b', 32), 2, new DateTimeImmutable('2026-10-29T12:00:00Z'));
    $controls = RecordWorkflowPage::controls($record, Translator::english());
    assertTrueValue(str_contains($controls, 'Publish changes') && str_contains($controls, 'Discard changes'), 'explicit actions exist without changing saved content');
    assertTrueValue(str_contains($controls, 'form="record-discard-form" name="_confirmed" value="1" required'), 'discard has a real native confirmation');
    $form = RecordWorkflowPage::form($record, 'safe');
    assertTrueValue(str_contains($form, 'name="_revision" value="3"') && str_contains($form, 'name="_workflow_action" value="discard"'), 'each action binds the saved revision');
    assertSameValue('Published, with changes', \Utsuri\PortableCms\Conventional\View\AdminShell::stateLabel('published_changes', Translator::english()), 'status has a distinct readable label');
};
