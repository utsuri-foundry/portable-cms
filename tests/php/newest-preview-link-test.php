<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\I18n\Translator;
use Utsuri\PortableCms\Conventional\Preview\InvalidShareExpiry;
use Utsuri\PortableCms\Conventional\Preview\NewestPreviewLink;
use Utsuri\PortableCms\Conventional\Preview\NewestPreviewLinkRepository;
use Utsuri\PortableCms\Conventional\Preview\NewestPreviewLinkService;
use Utsuri\PortableCms\Conventional\Preview\NewestPreviewResolution;
use Utsuri\PortableCms\Conventional\Preview\PreviewStatus;
use Utsuri\PortableCms\Conventional\Preview\SharedLink;
use Utsuri\PortableCms\Conventional\Preview\SharedLinkLifetime;
use Utsuri\PortableCms\Conventional\Preview\ShareRateLimited;
use Utsuri\PortableCms\Conventional\Preview\ShareUnavailable;
use Utsuri\PortableCms\Conventional\View\AdminChrome;
use Utsuri\PortableCms\Conventional\View\Pages;
use Utsuri\PortableCms\Conventional\View\SharedLinksView;
use Utsuri\PortableCms\Conventional\View\TrustedHtml;

final class TestNewestPreviewLinkRepository implements NewestPreviewLinkRepository
{
    public ?NewestPreviewLink $link = null;
    public string $hash = '';
    public ?string $pin = null;
    public int $calls = 0;
    public ?string $newest = null;

    /** @var array<string, array{resolution: NewestPreviewResolution, expires: DateTimeImmutable}> */
    public array $fileTokens = [];

    public function createFiles(NewestPreviewResolution $resolution, string $tokenHash, DateTimeImmutable $expiresAt, DateTimeImmutable $now): void
    {
        $this->fileTokens[$tokenHash] = ['resolution' => $resolution, 'expires' => $expiresAt];
    }

    public function files(string $id, string $tokenHash, DateTimeImmutable $now): ?NewestPreviewResolution
    {
        $row = $this->fileTokens[$tokenHash] ?? null;
        return $row !== null && $this->link !== null && $this->link->id === $id
            && $row['resolution']->link->id === $id && $row['expires'] > $now && $this->link->expiresAt > $now ? $row['resolution'] : null;
    }

    public function renewFiles(string $id, string $parentTokenHash, string $fileTokenHash, DateTimeImmutable $expiresAt, DateTimeImmutable $now): ?NewestPreviewResolution
    {
        $resolution = $this->files($id, $fileTokenHash, $now);
        if (!hash_equals($this->hash, $parentTokenHash) || $resolution === null) {
            return null;
        }
        $this->fileTokens[$fileTokenHash]['expires'] = min($resolution->link->expiresAt, $expiresAt);
        return $resolution;
    }

    public function create(NewestPreviewLink $link, string $tokenHash, string $actor, string $auditId): void
    {
        $this->link = $link;
        $this->hash = $tokenHash;
    }
    public function active(DateTimeImmutable $now): array
    {
        return $this->link === null || $this->link->expiresAt <= $now ? [] : [$this->link];
    }
    public function listed(DateTimeImmutable $now): array
    {
        return $this->link === null || $this->link->expiresAt <= SharedLinkLifetime::listingFloor($now) ? [] : [$this->link];
    }
    public function extend(string $id, DateTimeImmutable $expiresAt, string $actor, string $auditId, DateTimeImmutable $now): NewestPreviewLink
    {
        if ($this->link === null || $this->link->id !== $id) {
            throw new ShareUnavailable();
        }
        return $this->link = new NewestPreviewLink($id, $this->link->createdAt, $expiresAt, $this->link->note, $this->link->createdBy, $this->link->sealedSecret);
    }
    public function revoke(string $id, string $actor, string $auditId, DateTimeImmutable $now): void
    {
        $this->link = null;
    }
    public function resolve(string $id, string $tokenHash, ?string $pinnedPreview, string $auditId, DateTimeImmutable $now): ?NewestPreviewResolution
    {
        ++$this->calls;
        $this->pin = $pinnedPreview;
        return $this->link !== null && $id === $this->link->id && hash_equals($this->hash, $tokenHash)
            ? new NewestPreviewResolution($this->link, $pinnedPreview ?? $this->newest ?? str_repeat('a', 32)) : null;
    }
}

$tests['newest preview links keep raw secrets outside authorization storage and accept only one, seven or thirty days and pinned identities'] = static function (): void {
    $repository = new TestNewestPreviewLinkRepository();
    $clock = new IdentityTestClock(new DateTimeImmutable('2026-10-30T12:00:00Z'));
    $service = new NewestPreviewLinkService($repository, new MemoryIdentityRepository(), $clock, new IdentityTestRandom(), str_repeat('s', 32));
    foreach ([0, -1, 2, 24, 720, PHP_INT_MAX] as $days) {
        try {
            $service->create(str_repeat('b', 32), $days);
            throw new RuntimeException('Arbitrary lifetime accepted');
        } catch (InvalidShareExpiry) {
        }
    }
    foreach (SharedLinkLifetime::DAYS as $days) {
        $created = $service->create(str_repeat('b', 32), $days, 'Comparison tool');
        assertSameValue(64, strlen($created['token']), 'token has 256 random bits');
        assertSameValue(hash('sha256', $created['token']), $repository->hash, 'only the hash crosses the authorization boundary');
        assertSameValue($clock->now()->modify('+' . $days . ' days')->getTimestamp(), $created['link']->expiresAt->getTimestamp(), 'expiry uses the injected clock');
        assertSameValue('Comparison tool', $created['link']->note, 'the note survives');
        assertSameValue('https://cms.example.test/newest-previews/' . $created['link']->id . '/#' . $created['token'], $service->url($created['link'], 'https://cms.example.test'), 'the complete link can be copied again');
        assertSameValue($created['link'], $service->authorize($created['link']->id, $created['token'], 'client')?->link, 'valid capability reaches the repository');
        $service->authorize($created['link']->id, $created['token'], 'client', str_repeat('c', 32));
        assertSameValue(str_repeat('c', 32), $repository->pin, 'explicit pin identity crosses the authority boundary');
    }
    $calls = $repository->calls;
    assertSameValue(null, $service->authorize('../wrong', str_repeat('a', 64), 'client'), 'invalid ID rejected');
    assertSameValue(null, $service->authorize(str_repeat('a', 32), 'not-a-secret', 'client'), 'malformed secret rejected');
    assertSameValue(null, $service->authorize(str_repeat('a', 32), str_repeat('a', 64), 'client', '../preview'), 'malformed pin rejected');
    assertSameValue($calls, $repository->calls, 'malformed capabilities never reach storage');
    $extended = $service->extend($created['link']->id, str_repeat('b', 32), 1);
    assertSameValue($clock->now()->modify('+1 day')->getTimestamp(), $extended->expiresAt->getTimestamp(), 'extending counts from today');
    assertSameValue([$extended], $service->listed(), 'the list shows the extended link');
};

$tests['newest preview bearer guessing shares a bounded client budget across link IDs'] = static function (): void {
    $clock = new IdentityTestClock(new DateTimeImmutable('2026-10-30T12:00:00Z'));
    $abuse = new MemoryIdentityRepository();
    $service = new NewestPreviewLinkService(new TestNewestPreviewLinkRepository(), $abuse, $clock, new IdentityTestRandom(), str_repeat('s', 32));
    for ($i = 0; $i < 10; ++$i) {
        assertSameValue(null, $service->authorize(str_pad(dechex($i), 32, '0', STR_PAD_LEFT), str_repeat('f', 64), 'guesser'), 'guess fails');
    }
    try {
        $service->authorize(str_repeat('a', 32), str_repeat('f', 64), 'guesser');
        throw new RuntimeException('Guess budget bypassed');
    } catch (ShareRateLimited) {
    }
    $clock->current = $clock->current->modify('+300 seconds');
    assertSameValue(null, $service->authorize('', '', 'guesser'), 'budget reopens at the exact boundary');
};

$tests['newest browser file leases stay pinned while cookie renewal never resolves a newer preview'] = static function (): void {
    $repository = new TestNewestPreviewLinkRepository();
    $clock = new IdentityTestClock(new DateTimeImmutable('2026-10-30T12:00:00Z'));
    $service = new NewestPreviewLinkService($repository, new MemoryIdentityRepository(), $clock, new IdentityTestRandom(), str_repeat('s', 32));
    $created = $service->create(str_repeat('b', 32), 1);
    $id = $created['link']->id;
    $parentToken = $created['token'];
    $resolved = $service->authorize($id, $parentToken, 'lease');
    assertTrueValue($resolved !== null, 'parent resolves a preview first');
    $fileToken = $service->createFiles($resolved);
    assertTrueValue($fileToken !== $parentToken && strlen($fileToken) === 64, 'file token has independent entropy');
    assertSameValue($clock->now()->modify('+2 hours')->getTimestamp(), $repository->fileTokens[hash('sha256', $fileToken)]['expires']->getTimestamp(), 'file lease is bounded to two hours');
    assertSameValue(null, $service->authorize($id, $fileToken, 'wrong-purpose'), 'file token never authenticates a bearer fetch');
    assertSameValue(null, $service->authorizeFiles($id, $parentToken, 'wrong-purpose'), 'parent secret never substitutes for a file token');
    assertSameValue(null, $service->authorizeFiles(str_repeat('f', 32), $fileToken, 'wrong-link'), 'file token belongs to one link');
    assertSameValue(false, $service->renewFiles($id, $fileToken, str_repeat('f', 64), 'wrong-cookie'), 'renewal requires the original parent cookie');
    $clock->current = $clock->current->modify('+1 hour');
    $repository->newest = str_repeat('d', 32);
    assertSameValue(str_repeat('d', 32), $service->authorize($id, $parentToken, 'newer')?->previewId, 'root resolution follows the newer preview');
    $calls = $repository->calls;
    assertSameValue(true, $service->renewFiles($id, $fileToken, $parentToken, 'lease'), 'parent cookie renews the original file lease');
    assertSameValue($calls, $repository->calls, 'renewal never resolves or audits a different preview');
    $clock->current = $clock->current->modify('+1 hour');
    assertSameValue($resolved->previewId, $service->authorizeFiles($id, $fileToken, 'lease')?->previewId, 'renewed address remains pinned beyond its original expiry');
    $clock->current = $clock->current->modify('+1 hour');
    assertSameValue(null, $service->authorizeFiles($id, $fileToken, 'lease'), 'lease closes exactly at its idle deadline');
    assertSameValue(false, $service->renewFiles($id, $fileToken, $parentToken, 'lease'), 'renewal cannot revive an expired lease');
    $clock->current = $created['link']->expiresAt->modify('-1 minute');
    $last = $service->createFiles($resolved);
    assertSameValue($created['link']->expiresAt, $repository->fileTokens[hash('sha256', $last)]['expires'], 'lease cannot outlast the parent link');
    $service->revoke($id, str_repeat('b', 32));
    assertSameValue(null, $service->authorizeFiles($id, $last, 'revoked'), 'parent revocation closes file leases');
};

$tests['the previews page names previews by time, folds Share behind one panel, lists shared links in plain words and shows the pending notice only when something is pending'] = static function (): void {
    $zone = new DateTimeZone('Europe/Madrid');
    $chrome = new AdminChrome('csrf', 'Montse', now: new DateTimeImmutable('2026-11-16T12:00:00Z'));
    $preview = new PreviewStatus(str_repeat('a', 32), 'ready', str_repeat('b', 32), null, null, new DateTimeImmutable('2026-11-16T10:00:00Z'), new DateTimeImmutable('2026-11-16T10:05:00Z'), true, createdBy: str_repeat('c', 32));
    $names = static fn(string $id): ?string => $id === str_repeat('c', 32) ? 'Montse' : null;
    $panel = SharedLinksView::panel($preview, 'csrf', $chrome);
    foreach (['data-share-panel', 'action="/previews/shares"', 'name="kind" value="preview" checked', 'name="kind" value="newest"', 'name="days" value="7" checked', 'name="days" value="30"', 'name="note" maxlength="120"', 'Who is it for'] as $expected) {
        assertTrueValue(str_contains($panel, $expected), 'the Share panel asks what, how long and for whom: ' . $expected);
    }
    $link = new SharedLink('preview', str_repeat('d', 32), $chrome->now, $chrome->now->modify('+7 days'), 'Marta', str_repeat('c', 32), $preview->id, 'https://cms.example.test/shared-previews/' . str_repeat('d', 32) . '/#' . str_repeat('e', 64));
    $newest = new SharedLink('newest', str_repeat('f', 32), $chrome->now->modify('-10 days'), $chrome->now->modify('-1 day'), null, str_repeat('c', 32), null, null);
    $section = SharedLinksView::section([$link, $newest], $link, 'csrf', $chrome, $zone, $names, static fn(string $id): ?DateTimeImmutable => $id === $preview->id ? $preview->createdAt : null);
    foreach (['id="shared-links"', 'data-share-created', 'id="share-link"', 'data-copy="https://cms.example.test/shared-previews/' . str_repeat('d', 32) . '/#' . str_repeat('e', 64) . '"', 'Shows the preview of today, 11:00 until 23 Nov, 13:00.', 'Preview of today, 11:00</a> · until 23 Nov, 13:00 · by Montse', 'Always the newest preview · expired yesterday, 13:00 · by Montse', 'Link 1', '/previews/shares/preview/' . str_repeat('d', 32) . '/extend', '/previews/shares/newest/' . str_repeat('f', 32) . '/stop', '>Stop<', '>Remove<', '>Extend<'] as $expected) {
        assertTrueValue(str_contains($section, $expected), 'shared links read in the site zone with plain actions: ' . $expected);
    }
    assertTrueValue(!str_contains($section, 'UTC') && !str_contains(strip_tags($section), str_repeat('d', 32)), 'no UTC and no identities in the visible text');
    assertSameValue(2, preg_match_all('/data-copy=/', $section), 'the new link and the live row offer Copy; the expired link does not');
    $page = Pages::previewList('csrf', null, [$preview], $chrome, pendingPublication: 3, sharedLinks: TrustedHtml::fromEngineMarkup($section), sharePanel: static fn(PreviewStatus $status): string => SharedLinksView::panel($status, 'csrf', $chrome), actorName: $names, zone: $zone);
    foreach (['data-pending-publication="3"', 'See the website as it would be published.', 'name="include_drafts" value="0" checked', 'name="include_drafts" value="1"', 'Preview the website', '>Today, 11:00</a>', 'by Montse', '>Drafts included<', 'data-share-panel', '/previews/' . $preview->id . '/artifact/', 'id="shared-links"'] as $expected) {
        assertTrueValue(str_contains($page, $expected), 'the previews page reads as seeing the website: ' . $expected);
    }
    assertTrueValue(!str_contains($page, 'data-preview-selection') && !str_contains($page, 'Freeze'), 'no bulk controls or engine words on a one-page list');
    $quiet = Pages::previewList('csrf', null, []);
    assertTrueValue(!str_contains($quiet, 'data-pending-publication') && str_contains($quiet, 'No previews yet'), 'nothing pending shows no notice');
    $status = Pages::previewStatus('csrf', null, $preview, $chrome, true, '', 0, $panel, 'Montse', $zone);
    foreach (['<h1>Today, 11:00</h1>', 'by Montse', 'data-preview-state="ready"', 'data-share-panel', '/previews/' . $preview->id . '/delete', '>Open<'] as $expected) {
        assertTrueValue(str_contains($status, $expected), 'a preview page is titled by its time and offers Open, Share and Delete: ' . $expected);
    }
    assertSameValue(0, preg_match('/Preview [a-f0-9]{8}/', $status), 'no identity names a preview');
    assertTrueValue(!str_contains(Pages::previewStatus('csrf', null, $preview, $chrome, false, '', 0, $panel), 'data-share-panel'), 'without sharing the page offers no Share');
    assertSameValue('Previews', Translator::english()->text('preview.list.title'), 'the page keeps its name');
};
