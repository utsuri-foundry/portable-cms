<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Preview\InvalidShareExpiry;
use Utsuri\PortableCms\Conventional\Preview\PreviewShare;
use Utsuri\PortableCms\Conventional\Preview\PreviewShareRepository;
use Utsuri\PortableCms\Conventional\Preview\PreviewShareService;
use Utsuri\PortableCms\Conventional\Preview\ShareRateLimited;

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

    public function revoke(string $previewId, string $id, string $actor, string $auditId, DateTimeImmutable $now): void
    {
        unset($this->shares[$id]);
    }

    public function use(string $id, string $tokenHash, string $auditId, DateTimeImmutable $now): ?PreviewShare
    {
        $row = $this->shares[$id] ?? null;
        return $row !== null && hash_equals($row['hash'], $tokenHash) && $row['share']->expiresAt > $now ? $row['share'] : null;
    }
}

$tests['preview share secrets are hashed once with bounded expiry and injected randomness'] = static function (): void {
    $repository = new MemoryPreviewShareRepository();
    $abuse = new MemoryIdentityRepository();
    $clock = new IdentityTestClock(new DateTimeImmutable('2026-10-05T12:00:00Z'));
    $service = new PreviewShareService($repository, $abuse, $clock, new IdentityTestRandom(), str_repeat('s', 32));
    foreach ([0, -1, 2, 169, PHP_INT_MAX] as $hours) {
        try {
            $service->create(str_repeat('a', 32), str_repeat('b', 32), $hours);
            throw new RuntimeException('Unbounded share lifetime accepted');
        } catch (InvalidShareExpiry) {
        }
    }
    assertSameValue([], $repository->shares, 'invalid expiry creates no share');
    foreach (PreviewShareService::EXPIRY_HOURS as $hours) {
        $created = $service->create(str_repeat('a', 32), str_repeat('b', 32), $hours);
        assertSameValue(64, strlen($created['token']), 'secret contains 256 bits');
        assertSameValue(hash('sha256', $created['token']), $repository->shares[$created['share']->id]['hash'], 'only hash crosses storage boundary');
        assertSameValue($clock->now()->modify('+' . $hours . ' hours')->getTimestamp(), $created['share']->expiresAt->getTimestamp(), 'selected lifetime uses injected clock');
        assertSameValue($created['share'], $service->authorize($created['share']->id, $created['token'], 'client'), 'capability authorizes one share');
    }
};

$tests['shared file capabilities are independent, renewable, bounded and scoped to their parent share'] = static function (): void {
    $repository = new MemoryPreviewShareRepository();
    $clock = new IdentityTestClock(new DateTimeImmutable('2026-10-30T12:00:00Z'));
    $service = new PreviewShareService($repository, new MemoryIdentityRepository(), $clock, new IdentityTestRandom(), str_repeat('s', 32));
    $created = $service->create(str_repeat('a', 32), str_repeat('b', 32), 24);
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
    $service->revoke($share->previewId, $share->id, str_repeat('b', 32));
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
