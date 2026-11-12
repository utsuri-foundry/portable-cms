<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Preview;

use Utsuri\PortableCms\Conventional\Identity\Clock;
use Utsuri\PortableCms\Conventional\Identity\IdentityRepository;
use Utsuri\PortableCms\Conventional\Identity\RandomSource;

final readonly class PreviewShare
{
    public function __construct(
        public string $id,
        public string $previewId,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $expiresAt,
    ) {
        if (preg_match('/\A[a-f0-9]{32}\z/', $id) !== 1
            || preg_match('/\A[a-f0-9]{32}\z/', $previewId) !== 1
            || $expiresAt <= $createdAt || $expiresAt > $createdAt->modify('+168 hours')) {
            throw new \InvalidArgumentException('Preview share is invalid');
        }
    }
}

interface PreviewShareRepository
{
    /** Store at most 50 unexpired file capabilities per share; only hashes cross this boundary. */
    public function createFiles(PreviewShare $share, string $tokenHash, \DateTimeImmutable $expiresAt, \DateTimeImmutable $now): void;

    public function renewFiles(string $shareId, string $tokenHash, \DateTimeImmutable $expiresAt, \DateTimeImmutable $now): bool;

    /** Recheck the token, parent share, ready preview, expiry and revocation on every file request. */
    public function files(string $shareId, string $tokenHash, \DateTimeImmutable $now): ?PreviewShare;

    public function create(PreviewShare $share, string $tokenHash, string $actor, string $auditId): void;

    /** @return list<PreviewShare> */
    public function active(string $previewId, \DateTimeImmutable $now): array;

    public function revoke(string $previewId, string $id, string $actor, string $auditId, \DateTimeImmutable $now): void;

    /** Atomically authorize a live ready preview and audit use, comparing the supplied hash in constant time. */
    public function use(string $id, string $tokenHash, string $auditId, \DateTimeImmutable $now): ?PreviewShare;
}

final class PreviewShareService
{
    public const EXPIRY_HOURS = [1, 24, 168];

    public function __construct(
        private readonly PreviewShareRepository $repository,
        private readonly IdentityRepository $abuse,
        private readonly Clock $clock,
        private readonly RandomSource $random,
        private readonly string $throttleSecret,
    ) {
        if (strlen($throttleSecret) < 32) {
            throw new \InvalidArgumentException('Share throttle secret must contain at least 32 bytes');
        }
    }

    /** @return array{share: PreviewShare, token: string} Token is returned once, never persisted. */
    public function create(string $previewId, string $actor, int $hours): array
    {
        if (!in_array($hours, self::EXPIRY_HOURS, true)) {
            throw new InvalidShareExpiry();
        }
        $now = $this->clock->now();
        $share = new PreviewShare($this->id(), $previewId, $now, $now->modify('+' . $hours . ' hours'));
        $token = bin2hex($this->random->bytes(32));
        $this->repository->create($share, hash('sha256', $token), $actor, $this->id());
        return ['share' => $share, 'token' => $token];
    }

    /** @return list<PreviewShare> */
    public function active(string $previewId): array
    {
        return $this->repository->active($previewId, $this->clock->now());
    }

    public function revoke(string $previewId, string $id, string $actor): void
    {
        $this->repository->revoke($previewId, $id, $actor, $this->id(), $this->clock->now());
    }

    public function authorize(string $id, string $token, string $remoteAddress): ?PreviewShare
    {
        return $this->authorization($id, $token, $remoteAddress, false);
    }

    /** Browser file addresses carry no CMS or share cookie authority. */
    public function createFiles(PreviewShare $share): string
    {
        $now = $this->clock->now();
        $token = bin2hex($this->random->bytes(32));
        $this->repository->createFiles($share, hash('sha256', $token), PreviewFilePolicy::expiresAt($now, $share->expiresAt), $now);
        return $token;
    }

    public function renewFiles(PreviewShare $share, string $token): bool
    {
        $now = $this->clock->now();
        return PreviewFilePolicy::validToken($token)
            && $this->repository->renewFiles($share->id, hash('sha256', $token), PreviewFilePolicy::expiresAt($now, $share->expiresAt), $now);
    }

    public function authorizeFiles(string $id, string $token, string $remoteAddress): ?PreviewShare
    {
        return $this->authorization($id, $token, $remoteAddress, true);
    }

    private function authorization(string $id, string $token, string $remoteAddress, bool $files): ?PreviewShare
    {
        $now = $this->clock->now();
        // Separate from login throttles; guessing different link IDs shares the same client budget.
        $key = hash_hmac('sha256', 'preview-share-client:' . substr($remoteAddress, 0, 128), $this->throttleSecret, true);
        if (!$this->abuse->loginAllowed([$key], $now)) {
            throw new ShareRateLimited();
        }
        $share = null;
        if (preg_match('/\A[a-f0-9]{32}\z/', $id) === 1 && preg_match('/\A[a-f0-9]{64}\z/', $token) === 1) {
            $share = $files ? $this->repository->files($id, hash('sha256', $token), $now)
                : $this->repository->use($id, hash('sha256', $token), $this->id(), $now);
        }
        if ($share === null) {
            $this->abuse->recordLoginFailure([$key], $now, 300, 10, 300);
        }
        return $share;
    }

    private function id(): string
    {
        return bin2hex($this->random->bytes(16));
    }
}

final class InvalidShareExpiry extends \RuntimeException {}

final class ShareUnavailable extends \RuntimeException {}

final class ShareRateLimited extends \RuntimeException {}
