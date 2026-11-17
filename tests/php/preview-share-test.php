<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Preview\InvalidShareExpiry;
use Utsuri\PortableCms\Conventional\Preview\InvalidShareNote;
use Utsuri\PortableCms\Conventional\Preview\LinkSecret;
use Utsuri\PortableCms\Conventional\Preview\PreviewShare;
use Utsuri\PortableCms\Conventional\Preview\PreviewShareRepository;
use Utsuri\PortableCms\Conventional\Preview\PreviewShareService;
use Utsuri\PortableCms\Conventional\Preview\SharedLinkLifetime;
use Utsuri\PortableCms\Conventional\Preview\SharedLinkNote;
use Utsuri\PortableCms\Conventional\Preview\ShareRateLimited;
use Utsuri\PortableCms\Conventional\Preview\ShareUnavailable;

final class MemoryPreviewShareRepository implements PreviewShareRepository
{
    /** @var array<string, array{share: PreviewShare, hash: string}> */
    public array $shares = [];

    /** @var array<string, array{share: string, expires: DateTimeImmutable}> */
    public array $fileTokens = [];

    public function createFiles(PreviewShare $share, string $tokenHash, DateTimeImmutable $expiresAt, DateTimeImmutable $now): void
    {
        $this->fileTokens[$tokenHash] = ['share' => $share->id, 'expires' => $expiresAt];
    }

    public function renewFiles(string $shareId, string $tokenHash, DateTimeImmutable $expiresAt, DateTimeImmutable $now): bool
    {
        if ($this->files($shareId, $tokenHash, $now) === null) {
            return false;
        }
        $this->fileTokens[$tokenHash]['expires'] = $expiresAt;
        return true;
    }

    public function files(string $shareId, string $tokenHash, DateTimeImmutable $now): ?PreviewShare
    {
        $file = $this->fileTokens[$tokenHash] ?? null;
        $share = $this->shares[$shareId]['share'] ?? null;
        return $file !== null && $file['share'] === $shareId && $file['expires'] > $now && $share !== null && $share->expiresAt > $now ? $share : null;
    }

    public function create(PreviewShare $share, string $tokenHash, string $actor, string $auditId): void
    {
        $this->shares[$share->id] = ['share' => $share, 'hash' => $tokenHash];
    }

    public function active(string $previewId, DateTimeImmutable $now): array
    {
        return array_values(array_map(static fn(array $row): PreviewShare => $row['share'], array_filter(
            $this->shares,
            static fn(array $row): bool => $row['share']->previewId === $previewId && $row['share']->expiresAt > $now,
        )));
    }

    public function listed(DateTimeImmutable $now): array
    {
        $floor = SharedLinkLifetime::listingFloor($now);
        $listed = array_values(array_map(static fn(array $row): PreviewShare => $row['share'], array_filter($this->shares, static fn(array $row): bool => $row['share']->expiresAt > $floor)));
        usort($listed, static fn(PreviewShare $a, PreviewShare $b): int => $b->createdAt <=> $a->createdAt);
        return $listed;
    }

    public function extend(string $id, DateTimeImmutable $expiresAt, string $actor, string $auditId, DateTimeImmutable $now): PreviewShare
    {
        $row = $this->shares[$id] ?? throw new ShareUnavailable();
        $share = $row['share'];
        $this->shares[$id]['share'] = new PreviewShare($share->id, $share->previewId, $share->createdAt, $expiresAt, $share->note, $share->createdBy, $share->sealedSecret);
        return $this->shares[$id]['share'];
    }

    public function revoke(string $id, string $actor, string $auditId, DateTimeImmutable $now): void
    {
        unset($this->shares[$id]);
    }

    public function use(string $id, string $tokenHash, string $auditId, DateTimeImmutable $now): ?PreviewShare
    {
        $row = $this->shares[$id] ?? null;
        return $row !== null && hash_equals($row['hash'], $tokenHash) && $row['share']->expiresAt > $now ? $row['share'] : null;
    }
}

$tests['preview share links last one, seven or thirty days, keep their note and store a hash plus a sealed copy of the secret'] = static function (): void {
    $repository = new MemoryPreviewShareRepository();
    $clock = new IdentityTestClock(new DateTimeImmutable('2026-10-05T12:00:00Z'));
    $service = new PreviewShareService($repository, new MemoryIdentityRepository(), $clock, new IdentityTestRandom(), str_repeat('s', 32));
    foreach ([0, -1, 2, 24, 168, PHP_INT_MAX] as $days) {
        try {
            $service->create(str_repeat('a', 32), str_repeat('b', 32), $days);
            throw new RuntimeException('Unbounded share lifetime accepted');
        } catch (InvalidShareExpiry) {
        }
    }
    assertSameValue([], $repository->shares, 'invalid lifetime creates no share');
    assertSameValue([1, 7, 30], SharedLinkLifetime::DAYS, 'editors choose between one, seven and thirty days');
    assertSameValue(7, SharedLinkLifetime::DEFAULT_DAYS, 'seven days is the default');
    foreach (SharedLinkLifetime::DAYS as $days) {
        $created = $service->create(str_repeat('a', 32), str_repeat('b', 32), $days, 'Marta, distributor');
        $share = $created['share'];
        assertSameValue(64, strlen($created['token']), 'secret contains 256 bits');
        assertSameValue(hash('sha256', $created['token']), $repository->shares[$share->id]['hash'], 'authorization stores only the hash');
        assertSameValue($clock->now()->modify('+' . $days . ' days')->getTimestamp(), $share->expiresAt->getTimestamp(), 'selected lifetime counts from the injected clock');
        assertSameValue('Marta, distributor', $share->note, 'the note says who the link is for');
        assertSameValue(str_repeat('b', 32), $share->createdBy, 'the link remembers who made it');
        assertTrueValue($share->sealedSecret !== null && !str_contains($share->sealedSecret, $created['token']), 'the sealed copy never contains the secret in clear');
        assertSameValue('https://cms.example.test/shared-previews/' . $share->id . '/#' . $created['token'], $service->url($share, 'https://cms.example.test'), 'the complete link can be copied again');
        assertSameValue($share, $service->authorize($share->id, $created['token'], 'client'), 'capability authorizes one share');
    }
    $anonymous = $service->create(str_repeat('a', 32), str_repeat('b', 32), 7);
    assertSameValue(null, $anonymous['share']->note, 'a link needs no note');
};

$tests['shared link notes are trimmed, bounded and free of control characters'] = static function (): void {
    assertSameValue('Marta Puig', SharedLinkNote::normalize("  Marta \n Puig "), 'whitespace collapses and trims');
    assertSameValue(null, SharedLinkNote::normalize("  \t "), 'an empty note is no note');
    foreach ([str_repeat('a', SharedLinkNote::MAX_LENGTH + 1), "a\x01b", "a\x7fb"] as $hostile) {
        try {
            SharedLinkNote::normalize($hostile);
            throw new RuntimeException('Hostile note accepted');
        } catch (InvalidShareNote) {
        }
    }
    assertSameValue(str_repeat('é', SharedLinkNote::MAX_LENGTH), SharedLinkNote::normalize(str_repeat('é', SharedLinkNote::MAX_LENGTH)), 'the bound counts characters, not bytes');
};

$tests['an expired link stays listed for thirty days, can be extended from today and is copied only while its secret is sealed'] = static function (): void {
    $repository = new MemoryPreviewShareRepository();
    $clock = new IdentityTestClock(new DateTimeImmutable('2026-10-05T12:00:00Z'));
    $service = new PreviewShareService($repository, new MemoryIdentityRepository(), $clock, new IdentityTestRandom(), str_repeat('s', 32));
    $created = $service->create(str_repeat('a', 32), str_repeat('b', 32), 1, 'Designer');
    $share = $created['share'];
    $clock->current = $clock->current->modify('+2 days');
    assertSameValue([], $service->active($share->previewId), 'an expired link no longer authorizes');
    assertSameValue([$share->id], array_map(static fn(PreviewShare $listed): string => $listed->id, $service->listed()), 'an expired link stays in the list');
    assertTrueValue($share->expired($clock->now()), 'the model knows it has expired');
    $extended = $service->extend($share->id, str_repeat('b', 32), 7);
    assertSameValue($clock->now()->modify('+7 days')->getTimestamp(), $extended->expiresAt->getTimestamp(), 'extending counts from today, not from the old expiry');
    assertSameValue('Designer', $extended->note, 'extending keeps the note');
    assertSameValue($service->url($share, 'https://cms.example.test'), $service->url($extended, 'https://cms.example.test'), 'extending keeps the same link');
    assertSameValue($extended, $service->authorize($share->id, $created['token'], 'client'), 'the same secret works again');
    try {
        $service->extend($share->id, str_repeat('b', 32), 2);
        throw new RuntimeException('Arbitrary extension accepted');
    } catch (InvalidShareExpiry) {
    }
    $unsealed = new PreviewShare(str_repeat('c', 32), str_repeat('a', 32), $clock->now(), $clock->now()->modify('+1 day'));
    assertSameValue(null, $service->url($unsealed, 'https://cms.example.test'), 'a link without a sealed secret cannot be copied again');
    $clock->current = $extended->expiresAt->modify('+31 days');
    assertSameValue([], $service->listed(), 'a link expired for more than thirty days leaves the list');
    $service->revoke($share->id, str_repeat('b', 32));
    assertSameValue(null, $service->authorize($share->id, $created['token'], 'client'), 'a stopped link is gone');
};

$tests['link secrets open only with the same installation secret and reject tampering'] = static function (): void {
    $random = new IdentityTestRandom();
    $secrets = new LinkSecret(str_repeat('k', 32), $random);
    $token = str_repeat('ab', 32);
    $sealed = $secrets->seal($token);
    assertTrueValue($sealed !== null && !str_contains($sealed, $token), 'sealing hides the secret');
    assertSameValue($token, $secrets->open($sealed), 'the same installation opens its sealed secret');
    assertSameValue(null, (new LinkSecret(str_repeat('z', 32), $random))->open($sealed), 'another installation cannot open it');
    assertSameValue(null, $secrets->open(null), 'nothing sealed opens nothing');
    assertSameValue(null, $secrets->open('not base64!'), 'garbage opens nothing');
    $raw = base64_decode((string) $sealed, true);
    $tampered = base64_encode(substr((string) $raw, 0, -1) . chr(ord(substr((string) $raw, -1)) ^ 1));
    assertSameValue(null, $secrets->open($tampered), 'a changed byte fails authentication');
};

$tests['shared file capabilities are independent, renewable, bounded and scoped to their parent share'] = static function (): void {
    $repository = new MemoryPreviewShareRepository();
    $clock = new IdentityTestClock(new DateTimeImmutable('2026-10-30T12:00:00Z'));
    $service = new PreviewShareService($repository, new MemoryIdentityRepository(), $clock, new IdentityTestRandom(), str_repeat('s', 32));
    $created = $service->create(str_repeat('a', 32), str_repeat('b', 32), 1);
    $share = $created['share'];
    $token = $service->createFiles($share);
    assertSameValue(64, strlen($token), 'file address has 256 random bits');
    assertTrueValue($token !== $created['token'], 'file capability never exposes the share secret');
    assertSameValue($clock->now()->modify('+2 hours')->getTimestamp(), $repository->fileTokens[hash('sha256', $token)]['expires']->getTimestamp(), 'idle address lives only two hours');
    assertSameValue($share, $service->authorizeFiles($share->id, $token, 'files'), 'file capability reaches its one preview');
    assertSameValue(null, $service->authorize($share->id, $token, 'files'), 'file token cannot exchange or renew a share cookie');
    assertSameValue(null, $service->authorizeFiles(str_repeat('f', 32), $token, 'files'), 'file token cannot select another share');
    $clock->current = $clock->current->modify('+1 hour');
    assertSameValue(true, $service->renewFiles($share, $token), 'cookie-authorized viewer renews the same address');
    $clock->current = $clock->current->modify('+1 hour');
    assertSameValue($share, $service->authorizeFiles($share->id, $token, 'files'), 'renewal keeps the address beyond its original deadline');
    $clock->current = $clock->current->modify('+1 hour');
    assertSameValue(null, $service->authorizeFiles($share->id, $token, 'files'), 'idle address closes exactly at its deadline');
    assertSameValue(false, $service->renewFiles($share, $token), 'expired address cannot be revived');
    $clock->current = $share->expiresAt->modify('-1 minute');
    $last = $service->createFiles($share);
    assertSameValue($share->expiresAt, $repository->fileTokens[hash('sha256', $last)]['expires'], 'file lifetime cannot outlast the share');
    $service->revoke($share->id, str_repeat('b', 32));
    assertSameValue(null, $service->authorizeFiles($share->id, $last, 'files'), 'revocation closes every file capability');
};

$tests['preview guessing budget spans link IDs and expires without affecting CMS login'] = static function (): void {
    $abuse = new MemoryIdentityRepository();
    $clock = new IdentityTestClock(new DateTimeImmutable('2026-10-05T12:00:00Z'));
    $service = new PreviewShareService(new MemoryPreviewShareRepository(), $abuse, $clock, new IdentityTestRandom(), str_repeat('s', 32));
    for ($attempt = 0; $attempt < 10; ++$attempt) {
        assertSameValue(null, $service->authorize(str_pad(dechex($attempt), 32, '0', STR_PAD_LEFT), str_repeat('f', 64), 'client'), 'guessed secrets fail closed');
    }
    try {
        $service->authorize(str_repeat('a', 32), str_repeat('e', 64), 'client');
        throw new RuntimeException('Guessing budget was bypassed by changing link ID');
    } catch (ShareRateLimited) {
    }
    assertSameValue(null, $service->authorize('', '', 'other-client'), 'different client has a separate budget');
    assertSameValue(true, $abuse->loginAllowed([hash('sha256', 'login', true)], $clock->now()), 'share guesses do not block CMS login');
    $clock->current = $clock->current->modify('+300 seconds');
    assertSameValue(null, $service->authorize('', '', 'client'), 'throttle recovers at its exact boundary');
};
