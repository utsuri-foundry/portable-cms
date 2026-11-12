<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Content\ContentService;
use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Content\RecordConflict;
use Utsuri\PortableCms\Conventional\Content\RecordPermissionDenied;
use Utsuri\PortableCms\Conventional\Content\RecordSnapshot;
use Utsuri\PortableCms\Conventional\Content\ValidationFailure;
use Utsuri\PortableCms\Conventional\Http\Request;
use Utsuri\PortableCms\Conventional\Identity\IdentityService;
use Utsuri\PortableCms\Conventional\Identity\SessionContext;
use Utsuri\PortableCms\Conventional\Preview\PreviewNotReleasable;
use Utsuri\PortableCms\Conventional\Preview\PreviewService;
use Utsuri\PortableCms\Conventional\Preview\PreviewStatus;

function workflowTestActor(?string $role = 'administrator'): SessionContext
{
    return new SessionContext('session', 'authenticated', str_repeat('1', 32), 'admin@example.test', 'Administrator', $role, 'token', 'csrf', new DateTimeImmutable('2026-10-06T00:00:00Z'), false);
}

$tests['frozen selection includes saved drafts only by explicit boolean and never archives'] = static function (): void {
    $package = \Utsuri\PortableCms\Conventional\Content\ProfilePackage::load(dirname(__DIR__, 2) . '/profiles/reference-studio');
    $document = json_decode((string) file_get_contents($package->root . '/fixtures/snapshot.json'), true, flags: JSON_THROW_ON_ERROR);
    $draft = array_values(array_filter($document['records'], static fn(array $r): bool => $r['workflowState'] === 'draft'))[0];
    foreach ([false, true] as $mode) {
        $document['includeDrafts'] = $mode;
        $snapshot = \Utsuri\PortableCms\Conventional\Compiler\FrozenSnapshot::fromDocument($document, $package);
        assertSameValue($mode, $snapshot->includes($snapshot->record($draft['id'])), 'no draft state rewriting');
        foreach ($document['records'] as &$record) {
            if ($record['id'] === $draft['id']) {
                $record['workflowState'] = 'archived';
            }
        }
        unset($record);
        $archive = \Utsuri\PortableCms\Conventional\Compiler\FrozenSnapshot::fromDocument($document, $package);
        assertSameValue(false, $archive->includes($archive->record($draft['id'])), 'archives excluded in either mode');
        foreach ($document['records'] as &$record) {
            if ($record['id'] === $draft['id']) {
                $record['workflowState'] = 'draft';
            }
        }
        unset($record);
    }
    foreach ([null, 1, 'true', []] as $invalid) {
        $document['includeDrafts'] = $invalid;
        try {
            \Utsuri\PortableCms\Conventional\Compiler\FrozenSnapshot::fromDocument($document, $package);
            throw new RuntimeException('malformed preview mode accepted');
        } catch (InvalidArgumentException) {
        }
    }
};

$tests['saved record status is authorized revision scoped and content preserving'] = static function (): void {
    $clock = new IdentityTestClock(new DateTimeImmutable('2026-10-05T12:00:00Z'));
    $repository = new MemoryRecordRepository();
    $service = new ContentService($repository, ProfileManifest::gateZero(), $clock, new IdentityTestRandom());
    $record = $service->create('portfolio-entry', ['title' => 'Original content', 'summary' => 'Keep exactly'], str_repeat('1', 32));
    assertTrueValue($record instanceof RecordSnapshot, 'valid saved draft');
    foreach ([null, 'editor'] as $role) {
        try {
            $service->workflow()->transition($record->typeId, $record->id, 1, 'published', workflowTestActor($role));
            throw new RuntimeException('missing status permission accepted');
        } catch (RecordPermissionDenied) {
        }
    }
    foreach (['live', '', '<script>'] as $state) {
        try {
            $service->workflow()->transition($record->typeId, $record->id, 1, $state, workflowTestActor());
            throw new RuntimeException('unknown status accepted');
        } catch (InvalidArgumentException) {
        }
    }
    foreach (['published', 'draft', 'archived', 'draft'] as $state) {
        $next = $service->workflow()->transition($record->typeId, $record->id, $record->revisionNumber, $state, workflowTestActor());
        assertTrueValue($next instanceof RecordSnapshot, 'status transition succeeds');
        assertSameValue($state, $next->workflowState, 'explicit target state');
        assertSameValue($record->body, $next->body, 'no body mutation');
        assertSameValue($record->revisionNumber + 1, $next->revisionNumber, 'status changes invalidate stale editor authority');
        $stale = $service->workflow()->transition($record->typeId, $record->id, $record->revisionNumber, 'published', workflowTestActor());
        assertTrueValue($stale instanceof RecordConflict, 'stale status is rejected');
        assertSameValue($next->revisionNumber, $stale->current->revisionNumber, 'conflict projects latest authority');
        $record = $next;
    }
    $same = $service->workflow()->transition($record->typeId, $record->id, $record->revisionNumber, $record->workflowState, workflowTestActor());
    assertSameValue($record, $same, 'idempotent no-op does not allocate another revision');
};

$tests['publish validation rejects missing locales unready media and unpublished relationships'] = static function (): void {
    $package = \Utsuri\PortableCms\Conventional\Content\ProfilePackage::load(dirname(__DIR__, 2) . '/profiles/reference-studio');
    $clock = new IdentityTestClock(new DateTimeImmutable('2026-10-05T12:00:00Z'));
    $repository = new MemoryRecordRepository();
    $fixture = editorialMediaFixture();
    try {
        $service = new ContentService($repository, $package->manifest, $clock, new IdentityTestRandom(), $fixture['library']);
        $record = $service->create('article', ['title' => array_fill_keys($package->manifest->locales, 'Draft article')], str_repeat('1', 32));
        assertTrueValue($record instanceof RecordSnapshot, 'publish-incomplete content can still be saved');
        $invalid = $service->workflow()->transition('article', $record->id, 1, 'published', workflowTestActor());
        assertTrueValue($invalid instanceof ValidationFailure, 'publish fields enforced');
        assertTrueValue(isset($invalid->errors['slug'], $invalid->errors['body'], $invalid->errors['excerpt']), 'all required-on-publish fields reported');
        assertSameValue('draft', $service->find('article', $record->id)?->workflowState, 'failed publish leaves state untouched');
        $snapshot = json_decode((string) file_get_contents($package->root . '/fixtures/snapshot.json'), true, flags: JSON_THROW_ON_ERROR);
        $project = array_values(array_filter($snapshot['records'], static fn(array $r): bool => $r['typeId'] === 'project'))[0];
        // Canonical historical content may retain media that has since become unavailable.
        $repository->records[$project['id']] = new RecordSnapshot($project['id'], 'project', 'draft', $project['revisionId'], 1, $project['body'], $clock->now());
        $failed = $service->workflow()->transition('project', $project['id'], 1, 'published', workflowTestActor());
        assertTrueValue($failed instanceof ValidationFailure, 'retained unavailable sources cannot publish');
        assertTrueValue(isset($failed->errors['gallery']) || isset($failed->errors['_workflow']), 'unavailable media reported safely');
        $body = ['title' => array_fill_keys($package->manifest->locales, 'Article'), 'slug' => array_fill_keys($package->manifest->locales, 'article'), 'excerpt' => array_fill_keys($package->manifest->locales, 'Summary'), 'body' => array_fill_keys($package->manifest->locales, ['formatVersion' => 1, 'nodes' => [['type' => 'paragraph', 'children' => [['text' => 'Body', 'marks' => []]]]]]), 'relatedProjects' => [$project['id']]];
        $saved = $service->update('article', $record->id, 1, $body, str_repeat('1', 32));
        assertTrueValue($saved instanceof RecordSnapshot, 'draft can retain a draft relationship');
        $failed = $service->workflow()->transition('article', $record->id, $saved->revisionNumber, 'published', workflowTestActor());
        assertTrueValue($failed instanceof ValidationFailure && isset($failed->errors['relatedProjects']), 'draft relationship prevents publish');
        $body['relatedProjects'] = [];
        $body['searchSharing'] = ['en' => ['sharingImage' => [array_key_first($fixture['repository']->items)]]];
        $saved = $service->update('article', $record->id, $saved->revisionNumber, $body, str_repeat('1', 32));
        assertTrueValue($saved instanceof RecordSnapshot, 'metadata may retain ready status without thumbnail');
        $failed = $service->workflow()->transition('article', $record->id, $saved->revisionNumber, 'published', workflowTestActor());
        assertTrueValue($failed instanceof ValidationFailure && isset($failed->errors['searchSharing']), 'publish checks readiness in nested media fields');
        $mediaId = array_key_first($fixture['repository']->items);
        $asset = $fixture['repository']->items[$mediaId];
        $thumbnail = new \Utsuri\PortableCms\Conventional\Media\MediaDerivative($mediaId, '960-webp', 'derivatives/' . $mediaId . '/display.webp', 'display.webp', 'image/webp', 960, 640, 512, str_repeat('a', 64), 'fixture', '1', 1, []);
        $fixture['repository']->items[$mediaId] = new \Utsuri\PortableCms\Conventional\Media\LibraryMedia($mediaId, $asset->ownerRecordId, $asset->name, 'ready', 'image/webp', 512, 960, 640, 1, null, $thumbnail);
        $failed = $service->workflow()->transition('article', $record->id, $saved->revisionNumber, 'published', workflowTestActor());
        assertTrueValue($failed instanceof ValidationFailure && $failed->errors['searchSharing']->key === 'workflow.media_text', 'prepared image still requires an authored alternative or explicit decorative flag');
        $fixture['repository']->items[$mediaId] = new \Utsuri\PortableCms\Conventional\Media\LibraryMedia($mediaId, $asset->ownerRecordId, $asset->name, 'ready', 'image/webp', 512, 960, 640, 1, null, $thumbnail, \Utsuri\PortableCms\Conventional\Media\MediaText::from(['formatVersion' => 1, 'decorative' => true, 'translations' => []]));
        $published = $service->workflow()->transition('article', $record->id, $saved->revisionNumber, 'published', workflowTestActor());
        assertTrueValue($published instanceof RecordSnapshot && $published->workflowState === 'published', 'explicit decoration satisfies native publication validation');
    } finally {
        removeUploadTestTree($fixture['root']);
    }
};

$tests['native status forms enforce confirmation CSRF and saved revision authority'] = static function (): void {
    ['app' => $app, 'contentRepository' => $repository] = contentTestApplication();
    $cookies = authenticatedTestCookies($app);
    $created = $app->handle(new Request('POST', '/records/portfolio-entry/new', contentMutationHeaders(), ['_csrf' => $cookies[IdentityService::CSRF_COOKIE], 'title' => 'Saved title', 'summary' => 'Saved summary'], $cookies));
    $path = $created->headers()['location'][0];
    $record = array_values($repository->records)[0];
    $post = ['_csrf' => $cookies[IdentityService::CSRF_COOKIE], '_revision' => '1', '_workflow_state' => 'published', '_confirmed' => '1', 'title' => 'Do not save this open form'];
    assertSameValue(403, $app->handle(new Request('POST', $path . '/status', contentMutationHeaders(), $post))->status, 'anonymous mutation forbidden');
    assertSameValue(403, $app->handle(new Request('POST', $path . '/status', contentMutationHeaders(), [...$post, '_csrf' => 'wrong'], $cookies))->status, 'CSRF failure');
    assertSameValue(400, $app->handle(new Request('POST', $path . '/status', contentMutationHeaders(), [...$post, '_confirmed' => ''], $cookies))->status, 'confirmation required');
    assertSameValue(400, $app->handle(new Request('POST', $path . '/status', contentMutationHeaders(), [...$post, '_workflow_state' => 'live'], $cookies))->status, 'state allowlist');
    assertSameValue(303, $app->handle(new Request('POST', $path . '/status', contentMutationHeaders(), $post, $cookies))->status, 'native POST redirect');
    $current = $repository->records[$record->id];
    assertSameValue($record->body, $current->body, 'status ignores open content fields');
    $stale = $app->handle(new Request('POST', $path . '/status', contentMutationHeaders(), [...$post, '_workflow_state' => 'archived'], $cookies));
    assertSameValue(409, $stale->status, 'conflict re-render not redirect');
    assertTrueValue(str_contains($stale->body, 'value="archived" selected') && str_contains($stale->body, 'value="2"'), 'requested state preserved against fresh revision');
    $edit = $app->handle(new Request('GET', $path, cookies: $cookies));
    assertTrueValue(str_contains($edit->body, 'form="record-status-form"') && str_contains($edit->body, 'Website releases take the published version'), 'distinct accessible saved-state controls explain publication semantics');
    assertTrueValue(str_contains($edit->body, '>Save changes</button>'), 'ordinary saves on published records are not mislabeled as draft transitions');
    $save = ['_csrf' => $cookies[IdentityService::CSRF_COOKIE], '_revision' => '1', 'title' => 'Stale open form', 'summary' => 'Saved summary'];
    assertSameValue(409, $app->handle(new Request('POST', $path, contentMutationHeaders(), $save, $cookies))->status, 'content opened before the transition cannot silently overwrite it');
    assertSameValue(303, $app->handle(new Request('POST', $path, contentMutationHeaders(), [...$save, '_revision' => '2'], $cookies))->status, 'explicit current content save');
    assertSameValue('published', $repository->records[$record->id]->workflowState, 'ordinary update preserves the selected workflow state');
};

$tests['exact previews freeze explicit saved draft mode and reject release promotion'] = static function (): void {
    ['app' => $app, 'previewRepository' => $repository] = contentTestApplication(withPreviews: true);
    $cookies = authenticatedTestCookies($app);
    assertSameValue(202, $app->handle(new Request('POST', '/previews', contentMutationHeaders(), ['_csrf' => $cookies[IdentityService::CSRF_COOKIE], 'include_drafts' => '1'], $cookies))->status, 'saved drafts opt in');
    $preview = array_values($repository->previews)[0];
    assertSameValue(true, $preview->includeDrafts, 'mode persists separately from record workflow');
    $page = $app->handle(new Request('GET', '/previews/' . $preview->id, cookies: $cookies));
    assertTrueValue(str_contains($page->body, 'data-preview-mode="drafts"'), 'mode visible on preview');
    assertSameValue(400, $app->handle(new Request('POST', '/previews', contentMutationHeaders(), ['_csrf' => $cookies[IdentityService::CSRF_COOKIE], 'include_drafts' => 'true'], $cookies))->status, 'malformed selection fails closed');
    $repository->previews[$preview->id] = new PreviewStatus($preview->id, 'ready', $preview->jobId, null, ['formatVersion' => 1, 'artifacts' => []], $preview->createdAt, $preview->updatedAt, true);
    $service = new PreviewService($repository, ProfileManifest::gateZero(), new IdentityTestClock($preview->createdAt), new IdentityTestRandom());
    try {
        $service->releaseCandidate($preview->id);
        throw new RuntimeException('draft-inclusive build accepted as release');
    } catch (PreviewNotReleasable) {
    }
};
