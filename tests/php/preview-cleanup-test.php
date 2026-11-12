<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Http\OriginPolicy;
use Utsuri\PortableCms\Conventional\Http\Request;
use Utsuri\PortableCms\Conventional\Identity\IdentityService;
use Utsuri\PortableCms\Conventional\IdentityApplication;
use Utsuri\PortableCms\Conventional\Preview\FilesystemPreviewStore;
use Utsuri\PortableCms\Conventional\Preview\PreviewBuild;
use Utsuri\PortableCms\Conventional\Preview\PreviewCleanupBudget;
use Utsuri\PortableCms\Conventional\Preview\PreviewCleanupHttp;
use Utsuri\PortableCms\Conventional\Preview\PreviewCleanupItem;
use Utsuri\PortableCms\Conventional\Preview\PreviewCleanupRepository;
use Utsuri\PortableCms\Conventional\Preview\PreviewCleanupService;
use Utsuri\PortableCms\Conventional\Preview\PreviewCursor;
use Utsuri\PortableCms\Conventional\Preview\PreviewDeletion;
use Utsuri\PortableCms\Conventional\Preview\PreviewDiagnostics;
use Utsuri\PortableCms\Conventional\Preview\PreviewJob;
use Utsuri\PortableCms\Conventional\Preview\PreviewNotDeletable;
use Utsuri\PortableCms\Conventional\Preview\PreviewNotFound;
use Utsuri\PortableCms\Conventional\Preview\PreviewSelection;
use Utsuri\PortableCms\Conventional\Preview\PreviewSelectionInvalid;
use Utsuri\PortableCms\Conventional\Preview\PreviewService;
use Utsuri\PortableCms\Conventional\Preview\PreviewSnapshot;
use Utsuri\PortableCms\Conventional\Preview\PreviewStatus;

final class CleanupTestRepository implements PreviewCleanupRepository
{
    /** @var array<string, PreviewCleanupItem> */
    public array $items = [];
    /** @var list<string> */
    public array $deleted = [];
    /** @var (Closure(string): void)|null */
    public ?Closure $before = null;
    public bool $auditFails = false;

    public function cleanupPage(?PreviewCursor $before, int $limit, DateTimeImmutable $now): array
    {
        $items = array_values(array_filter($this->items, static fn(PreviewCleanupItem $item): bool => $item->preview->buildKind === 'website' && ($before === null || PreviewCursor::after($item->preview)->value() < $before->value())));
        usort($items, static fn(PreviewCleanupItem $a, PreviewCleanupItem $b): int => strcmp(PreviewCursor::after($b->preview)->value(), PreviewCursor::after($a->preview)->value()));
        return array_slice($items, 0, $limit);
    }

    public function cleanupItem(string $id, DateTimeImmutable $now): ?PreviewCleanupItem
    {
        return $this->items[$id] ?? null;
    }

    public function find(string $previewId): ?PreviewStatus
    {
        return ($this->items[$previewId] ?? null)?->preview;
    }

    public function list(int $limit): array
    {
        return array_map(static fn(PreviewCleanupItem $item): PreviewStatus => $item->preview, array_slice(array_values($this->items), 0, $limit));
    }

    public function delete(string $previewId, string $actorUserId, string $auditId, DateTimeImmutable $now): void
    {
        $this->deleteGuarded($previewId, $actorUserId, $auditId, $now, static function (): void {});
    }

    public function deleteGuarded(string $id, string $actor, string $audit, DateTimeImmutable $now, Closure $quarantine): void
    {
        ($this->before ?? static function (): void {})($id);
        $item = $this->items[$id] ?? throw new PreviewNotFound();
        if (!$item->eligible()) {
            throw new PreviewNotDeletable();
        }
        $quarantine();
        if ($this->auditFails) {
            throw new RuntimeException('Injected attributed audit failure');
        }
        unset($this->items[$id]);
        $this->deleted[] = $id;
    }

    public function enqueue(string $previewId, string $jobId, string $auditId, string $actorUserId, string $profileId, string $profileVersion, array $locales, DateTimeImmutable $now, bool $includeDrafts = false, bool $includePreparation = false): PreviewStatus
    {
        throw new LogicException('Not used');
    }
    public function claim(string $workerId, string $leaseToken, DateTimeImmutable $now, DateTimeImmutable $leaseExpiresAt): ?PreviewJob
    {
        throw new LogicException('Not used');
    }
    public function snapshot(PreviewJob $job): PreviewSnapshot
    {
        throw new LogicException('Not used');
    }
    public function complete(PreviewJob $job, PreviewBuild $build, string $auditId, DateTimeImmutable $now): void
    {
        throw new LogicException('Not used');
    }
    public function fail(PreviewJob $job, string $errorCode, bool $retryable, DateTimeImmutable $retryAt, DateTimeImmutable $now, ?PreviewDiagnostics $diagnostics = null): void
    {
        throw new LogicException('Not used');
    }
}

function cleanupTestItem(int $number, string $status = 'ready', bool $release = false, string $kind = 'website'): PreviewCleanupItem
{
    $id = str_pad(dechex($number), 32, '0', STR_PAD_LEFT);
    $now = new DateTimeImmutable('2026-10-12T12:00:00Z');
    return new PreviewCleanupItem(new PreviewStatus($id, $status, str_repeat('b', 32), null, null, $now, $now, buildKind: $kind), $release, 2);
}

/** @return array{root: string, repository: CleanupTestRepository, storage: FilesystemPreviewStore, clock: IdentityTestClock, service: PreviewCleanupService, selection: PreviewSelection, deletion: PreviewDeletion} */
function cleanupTestFixture(): array
{
    $root = sys_get_temp_dir() . '/portable-cleanup-' . bin2hex(random_bytes(8));
    $repository = new CleanupTestRepository();
    $storage = new FilesystemPreviewStore($root);
    $clock = new IdentityTestClock(new DateTimeImmutable('2026-10-12T12:00:00Z'));
    $selection = new PreviewSelection(str_repeat('s', 32), $clock);
    $deletion = new PreviewDeletion($repository, $storage, $clock, new IdentityTestRandom());
    $service = new PreviewCleanupService($repository, $deletion, $selection, $clock);
    return compact('root', 'repository', 'storage', 'clock', 'service', 'selection', 'deletion');
}

$tests['preview selection is finite actor scoped expiring purpose bound and rejects hostile IDs'] = static function (): void {
    $clock = new IdentityTestClock(new DateTimeImmutable('2026-10-12T12:00:00Z'));
    $selection = new PreviewSelection(str_repeat('s', 32), $clock);
    $id = str_repeat('a', 32);
    assertSameValue([$id], PreviewSelection::ids([$id, $id]), 'deduplicate before work');
    foreach ([[], array_fill(0, 21, $id), ['../escape'], ['A' . substr($id, 1)]] as $ids) {
        try {
            PreviewSelection::ids($ids);
            throw new RuntimeException('Hostile selection accepted');
        } catch (PreviewSelectionInvalid) {
        }
    }
    $token = $selection->issue([$id], [$id], 'actor', 'confirm');
    assertSameValue(['ids' => [$id], 'approved' => [$id], 'recover' => []], $selection->read($token, 'actor', 'confirm'), 'signed exact IDs');
    foreach ([[$token . 'f', 'actor', 'confirm'], [$token, 'other', 'confirm'], [$token, 'actor', 'page']] as [$value, $actor, $purpose]) {
        try {
            $selection->read($value, $actor, $purpose);
            throw new RuntimeException('Forged authority accepted');
        } catch (PreviewSelectionInvalid) {
        }
    }
    $clock->current = $clock->current->modify('+15 minutes');
    try {
        $selection->read($token, 'actor', 'confirm');
        throw new RuntimeException('Expired selection accepted');
    } catch (PreviewSelectionInvalid) {
    }
};

$tests['preview pagination reaches more than fifty rows with stable ties and no expanding selection'] = static function (): void {
    ['root' => $root, 'repository' => $repository, 'service' => $service] = cleanupTestFixture();
    try {
        for ($i = 1; $i <= 65; ++$i) {
            $item = cleanupTestItem($i);
            $repository->items[$item->preview->id] = $item;
        }
        $page = $service->page('');
        assertSameValue(20, count($page->items), 'bounded first page');
        $token = $service->pageToken($page, 'actor');
        $seen = array_map(static fn(PreviewCleanupItem $item): string => $item->preview->id, $page->items);
        $new = cleanupTestItem(999);
        $repository->items[$new->preview->id] = $new;
        while ($page->next !== null) {
            $page = $service->page($page->next->value());
            foreach ($page->items as $item) {
                $seen[] = $item->preview->id;
            }
        }
        assertSameValue(65, count(array_unique($seen)), 'all original rows remain reachable');
        assertTrueValue(!in_array($new->preview->id, $seen, true), 'inserts do not shift older pages');
        $review = $service->review([], 'actor', $token);
        assertSameValue(20, count($review->items), 'page selection remains finite');
        assertTrueValue(!isset($review->items[$new->preview->id]), 'new row not selected later');
    } finally {
        staticCompilerRemoveTree($root);
    }
};

$tests['preview cleanup rechecks protection independently and replays without duplicate deletion'] = static function (): void {
    ['root' => $root, 'repository' => $repository, 'service' => $service] = cleanupTestFixture();
    try {
        foreach ([cleanupTestItem(1), cleanupTestItem(2), cleanupTestItem(3, 'queued'), cleanupTestItem(4, release: true), cleanupTestItem(5, kind: 'holding'), cleanupTestItem(6), cleanupTestItem(7), cleanupTestItem(8)] as $item) {
            $repository->items[$item->preview->id] = $item;
        }
        $review = $service->review(array_keys($repository->items), 'actor');
        assertSameValue(5, count($review->approved), 'busy retained and holding protected during review');
        assertSameValue([], $repository->deleted, 'review does not mutate');
        $repository->before = static function (string $id) use ($repository): void {
            if ($id === cleanupTestItem(2)->preview->id) {
                $repository->items[$id] = cleanupTestItem(2, release: true);
            }
            if ($id === cleanupTestItem(6)->preview->id) {
                $repository->items[$id] = cleanupTestItem(6, 'building');
            }
            if ($id === cleanupTestItem(7)->preview->id) {
                throw new RuntimeException('One independent item failed');
            }
        };
        $result = $service->confirm($review->token, 'actor');
        assertSameValue('deleted', $result->outcomes[cleanupTestItem(1)->preview->id], 'independent successful item');
        assertSameValue('protected', $result->outcomes[cleanupTestItem(2)->preview->id], 'late release reference prevents deletion');
        assertSameValue('protected', $result->outcomes[cleanupTestItem(6)->preview->id], 'late status change prevents deletion');
        assertSameValue('failed', $result->outcomes[cleanupTestItem(7)->preview->id], 'independent failure is visible');
        assertSameValue('deleted', $result->outcomes[cleanupTestItem(8)->preview->id], 'an earlier failure does not prevent another deletion');
        assertTrueValue($result->retry !== null, 'only failed items need another explicit retry');
        $repository->items[cleanupTestItem(3)->preview->id] = cleanupTestItem(3);
        $replay = $service->confirm($review->token, 'actor');
        assertSameValue('absent', $replay->outcomes[cleanupTestItem(1)->preview->id], 'idempotent replay');
        assertSameValue('protected', $replay->outcomes[cleanupTestItem(3)->preview->id], 'review-protected items never gain approval');
        assertSameValue(2, count($repository->deleted), 'successful items do not repeat deletion and audit effects');
    } finally {
        staticCompilerRemoveTree($root);
    }
};

$tests['preview deletion restores rollback and recovers bounded interrupted storage cleanup'] = static function (): void {
    ['root' => $root, 'repository' => $repository, 'storage' => $storage, 'clock' => $clock, 'service' => $service, 'deletion' => $deletion] = cleanupTestFixture();
    try {
        $item = cleanupTestItem(1);
        $id = $item->preview->id;
        $repository->items[$id] = $item;
        mkdir($root . '/artifacts/' . $id, 0700, true);
        file_put_contents($root . '/artifacts/' . $id . '/index.html', 'immutable');
        $review = $service->review([$id], 'actor');
        $repository->auditFails = true;
        assertSameValue('failed', $service->confirm($review->token, 'actor')->outcomes[$id], 'audit failure visible');
        assertSameValue('immutable', file_get_contents($root . '/artifacts/' . $id . '/index.html'), 'rollback restores artifact');
        assertSameValue([], $storage->cleanupFiles()->pending(), 'rollback journal removed');
        $repository->auditFails = false;
        $result = $service->confirm($review->token, 'actor', new PreviewCleanupBudget($clock, 2));
        assertSameValue('cleanup_pending', $result->outcomes[$id], 'committed deletion not falsely reported as reclaimed');
        assertSameValue(null, $repository->find($id), 'database deletion committed');
        assertTrueValue($service->recoveryToken('actor') !== null, 'interrupted cleanup discoverable on next list');
        assertTrueValue($result->retry !== null, 'finite remaining retry authority');
        // A hostile/unavailable trash parent simulates purge failure after database commit.
        rename($root . '/trash', $root . '/quarantine-backup');
        file_put_contents($root . '/keep.txt', 'outside quarantine');
        symlink($root, $root . '/trash');
        $failedPurge = $service->confirm($result->retry, 'actor');
        assertSameValue('cleanup_pending', $failedPurge->outcomes[$id], 'purge failure never claims reclaimed storage');
        assertSameValue('outside quarantine', file_get_contents($root . '/keep.txt'), 'purge never follows an escaped parent');
        assertTrueValue($failedPurge->retry !== null, 'failed purge preserves explicit retry');
        unlink($root . '/trash');
        rename($root . '/quarantine-backup', $root . '/trash');
        assertSameValue('deleted', $service->confirm($result->retry, 'actor')->outcomes[$id], 'retry finishes purge');
        assertSameValue([], $storage->cleanupFiles()->pending(), 'recovery journal cleared');
        assertSameValue([], array_values(array_diff(scandir($root . '/trash') ?: [], ['.', '..'])), 'artifact trash empty');
        $repository->items[$id] = $item;
        mkdir($root . '/artifacts/' . $id, 0700, true);
        file_put_contents($root . '/artifacts/' . $id . '/index.html', 'immutable');
        $token = str_repeat('c', 32);
        $storage->cleanupFiles()->begin($id, $token);
        $storage->quarantine($id, $token);
        $repository->items[$id] = cleanupTestItem(1, release: true);
        $recovery = $service->review([], 'actor', $service->recoveryToken('actor'));
        assertSameValue([], $recovery->approved, 'release-protected journal gets restoration authority only');
        assertSameValue([$id], $recovery->recover, 'exact interrupted ID can still recover');
        file_put_contents($root . '/artifacts/' . $id, 'conflicting destination');
        $restoreFailure = $service->confirm($recovery->token, 'actor');
        assertSameValue('failed', $restoreFailure->outcomes[$id], 'restore failure preserves the quarantine and its authority');
        assertTrueValue($restoreFailure->retry !== null, 'restore-only retry is available');
        unlink($root . '/artifacts/' . $id);
        $repository->items[$id] = $item;
        assertSameValue('restored', $service->confirm($restoreFailure->retry, 'actor')->outcomes[$id], 'restoration-only retry never upgrades to deletion when protection changes');
        assertTrueValue($repository->find($id) !== null, 'recovered snapshot still exists');
        assertSameValue('immutable', file_get_contents($root . '/artifacts/' . $id . '/index.html'), 'release artifact restored');
        $repository->items[$id] = $item;
        $review = $service->review([$id], 'actor');
        $budget = new PreviewCleanupBudget($clock);
        $clock->current = $clock->current->modify('+4 seconds');
        $bounded = $service->confirm($review->token, 'actor', $budget);
        assertSameValue('unprocessed', $bounded->outcomes[$id], 'elapsed deadline refuses another item');
        assertTrueValue($bounded->retry !== null, 'deadline preserves exact remaining ID');
    } finally {
        staticCompilerRemoveTree($root);
    }
};

$tests['native preview cleanup routes retain authentication origin CSRF and finite review authority'] = static function (): void {
    ['root' => $root, 'repository' => $repository, 'storage' => $storage, 'clock' => $clock, 'service' => $service] = cleanupTestFixture();
    try {
        $identity = new MemoryIdentityRepository();
        $identity->addUser(str_repeat('1', 32), 'admin@example.test', 'Admin', password_hash('correct horse battery staple', PASSWORD_BCRYPT, ['cost' => 4]));
        $profile = ProfileManifest::gateZero();
        $random = new IdentityTestRandom();
        $previews = new PreviewService($repository, $profile, $clock, $random, $storage);
        $app = new IdentityApplication(new IdentityService($identity, $clock, $random, str_repeat('s', 32)), new OriginPolicy('https://cms.example.test'), $clock, $random, profile: $profile, previews: $previews, previewCleanup: new PreviewCleanupHttp($service, $previews));
        $item = cleanupTestItem(1);
        $id = $item->preview->id;
        $repository->items[$id] = $item;
        $path = '/previews/delete/review';
        assertSameValue(403, $app->handle(new Request('POST', $path))->status, 'unauthenticated cross-origin-shaped POST refused');
        assertSameValue(403, $app->handle(new Request('POST', $path, contentMutationHeaders()))->status, 'same-origin anonymous cannot review without session authority');
        $cookies = authenticatedTestCookies($app);
        $csrf = $cookies[IdentityService::CSRF_COOKIE];
        foreach (['owner', 'administrator', 'editor'] as $role) {
            $identity->users[str_repeat('1', 32)]['role'] = $role;
            assertSameValue(200, $app->handle(new Request('POST', $path, contentMutationHeaders(), ['_csrf' => $csrf, 'preview_ids' => [$id]], $cookies))->status, $role . ' retains existing preview deletion policy');
        }
        $identity->users[str_repeat('1', 32)]['role'] = 'reader';
        assertSameValue(403, $app->handle(new Request('POST', $path, contentMutationHeaders(), ['_csrf' => $csrf, 'preview_ids' => [$id]], $cookies))->status, 'non-editorial role cannot review');
        $identity->users[str_repeat('1', 32)]['role'] = 'administrator';
        $identity->users[str_repeat('1', 32)]['mustChangePassword'] = true;
        assertSameValue('/account', $app->handle(new Request('POST', $path, contentMutationHeaders(), ['_csrf' => $csrf, 'preview_ids' => [$id]], $cookies))->headers()['location'][0] ?? null, 'temporary credentials cannot bypass password replacement');
        $identity->users[str_repeat('1', 32)]['mustChangePassword'] = false;
        assertSameValue(403, $app->handle(new Request('POST', $path, ['origin' => 'https://foreign.test'], ['_csrf' => $csrf, 'preview_ids' => [$id]], $cookies))->status, 'correct CSRF never bypasses origin');
        foreach ([['origin' => 'https://foreign.test'], ['origin' => 'https://cms.example.test']] as $headers) {
            assertSameValue(403, $app->handle(new Request('POST', $path, $headers, ['_csrf' => 'wrong', 'preview_ids' => [$id]], $cookies))->status, 'bad origin or CSRF refused');
        }
        $list = $app->handle(new Request('GET', '/previews', cookies: $cookies));
        assertTrueValue(str_contains($list->body, 'name="preview_ids[]"') && preg_match('/<input[^>]*name="preview_ids\[\]"[^>]*\bchecked\b/', $list->body) === 0, 'native default-unselected form');
        assertSameValue(404, $app->handle(new Request('GET', $path, cookies: $cookies))->status, 'GET cannot review or mutate');
        $review = $app->handle(new Request('POST', $path, contentMutationHeaders(), ['_csrf' => $csrf, 'preview_ids' => [$id, $id]], $cookies));
        assertSameValue(200, $review->status, 'no-JS manual selection works');
        assertTrueValue(str_contains($review->body, 'There is no undo') && str_contains($review->body, '2 active review links'), 'review explains consequences');
        assertSameValue([], $repository->deleted, 'review and cancel do not delete');
        assertSameValue(1, preg_match('/name="selection" value="([^"]+)"/', $review->body, $matches), 'bound confirmation');
        $result = $app->handle(new Request('POST', '/previews/delete/confirm', contentMutationHeaders(), ['_csrf' => $csrf, 'selection' => html_entity_decode($matches[1], ENT_QUOTES)], $cookies));
        assertSameValue(200, $result->status, 'native confirm works');
        assertSameValue([$id], $repository->deleted, 'deduplicated deletion');
        assertSameValue(422, $app->handle(new Request('POST', $path, contentMutationHeaders(), ['_csrf' => $csrf, 'preview_ids' => array_fill(0, 21, $id)], $cookies))->status, 'oversized rejected before effects');
        $identity->users[str_repeat('1', 32)]['status'] = 'disabled';
        assertSameValue(303, $app->handle(new Request('POST', path: $path, headers: contentMutationHeaders(), form: ['_csrf' => $csrf, 'preview_ids' => [$id]], cookies: $cookies))->status, 'revoked account loses cleanup authority');
    } finally {
        staticCompilerRemoveTree($root);
    }
};
