<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Preview\InvalidShareExpiry;
use Utsuri\PortableCms\Conventional\Preview\NewestPreviewLink;
use Utsuri\PortableCms\Conventional\Preview\NewestPreviewLinkRepository;
use Utsuri\PortableCms\Conventional\Preview\NewestPreviewLinkService;
use Utsuri\PortableCms\Conventional\Preview\NewestPreviewResolution;
use Utsuri\PortableCms\Conventional\Preview\ShareRateLimited;

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
        return $this->link === null ? [] : [$this->link];
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

$tests['newest preview capabilities keep raw secrets outside storage and accept only bounded expiry and pinned identities'] = static function (): void {
    $repository = new TestNewestPreviewLinkRepository();
    $clock = new IdentityTestClock(new DateTimeImmutable('2026-10-30T12:00:00Z'));
    $service = new NewestPreviewLinkService($repository, new MemoryIdentityRepository(), $clock, new IdentityTestRandom(), str_repeat('s', 32));
    foreach ([0, -1, 2, 719, 721, PHP_INT_MAX] as $hours) {
        try {
            $service->create(str_repeat('b', 32), $hours);
            throw new RuntimeException('Arbitrary lifetime accepted');
        } catch (InvalidShareExpiry) {
        }
    }
    foreach (NewestPreviewLinkService::EXPIRY_HOURS as $hours) {
        $created = $service->create(str_repeat('b', 32), $hours);
        assertSameValue(64, strlen($created['token']), 'token has 256 random bits');
        assertSameValue(hash('sha256', $created['token']), $repository->hash, 'only the hash crosses the repository boundary');
        assertSameValue($clock->now()->modify('+' . $hours . ' hours')->getTimestamp(), $created['link']->expiresAt->getTimestamp(), 'expiry uses the injected clock');
        assertSameValue($created['link'], $service->authorize($created['link']->id, $created['token'], 'client')?->link, 'valid capability reaches the repository');
        $service->authorize($created['link']->id, $created['token'], 'client', str_repeat('c', 32));
        assertSameValue(str_repeat('c', 32), $repository->pin, 'explicit pin identity crosses the authority boundary');
    }
    $calls = $repository->calls;
    assertSameValue(null, $service->authorize('../wrong', str_repeat('a', 64), 'client'), 'invalid ID rejected');
    assertSameValue(null, $service->authorize(str_repeat('a', 32), 'not-a-secret', 'client'), 'malformed secret rejected');
    assertSameValue(null, $service->authorize(str_repeat('a', 32), str_repeat('a', 64), 'client', '../preview'), 'malformed pin rejected');
    assertSameValue($calls, $repository->calls, 'malformed capabilities never reach storage');
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
    $created = $service->create(str_repeat('b', 32), 24);
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

$tests['preview list preserves pending publication notice alongside newest preview controls'] = static function (): void {
    $links = \Utsuri\PortableCms\Conventional\View\NewestPreviewLinksView::render('csrf', [], \Utsuri\PortableCms\Conventional\I18n\Translator::english());
    $page = \Utsuri\PortableCms\Conventional\View\Pages::previewList('csrf', null, [], pendingPublication: 3, newestLinks: $links);
    assertTrueValue(str_contains($page, 'data-pending-publication="3"'), 'newest link controls retain the published-version notice');
    assertTrueValue(str_contains($page, 'id="newest-preview-links"'), 'publication notice retains native newest-link controls');
};
