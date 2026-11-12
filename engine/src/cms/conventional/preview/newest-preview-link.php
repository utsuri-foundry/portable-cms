<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Preview;

use Utsuri\PortableCms\Conventional\Identity\Clock;
use Utsuri\PortableCms\Conventional\Identity\IdentityRepository;
use Utsuri\PortableCms\Conventional\Identity\RandomSource;

final readonly class NewestPreviewLink
{
    public function __construct(public string $id, public \DateTimeImmutable $createdAt, public \DateTimeImmutable $expiresAt)
    {
        if (preg_match('/\A[a-f0-9]{32}\z/', $id) !== 1 || $expiresAt <= $createdAt || $expiresAt > $createdAt->modify('+720 hours')) {
            throw new \InvalidArgumentException('Newest preview link is invalid');
        }
    }
}

final readonly class NewestPreviewResolution
{
    public function __construct(public NewestPreviewLink $link, public string $previewId)
    {
        if (preg_match('/\A[a-f0-9]{32}\z/', $previewId) !== 1) {
            throw new \InvalidArgumentException('Preview resolution is invalid');
        }
    }
}

interface NewestPreviewLinkRepository
{
    public function createFiles(NewestPreviewResolution $resolution, string $tokenHash, \DateTimeImmutable $expiresAt, \DateTimeImmutable $now): void;

    public function files(string $id, string $tokenHash, \DateTimeImmutable $now): ?NewestPreviewResolution;

    /** Reauthenticate the parent cookie and renew only its existing resolved preview. */
    public function renewFiles(string $id, string $parentTokenHash, string $fileTokenHash, \DateTimeImmutable $expiresAt, \DateTimeImmutable $now): ?NewestPreviewResolution;

    public function create(NewestPreviewLink $link, string $tokenHash, string $actor, string $auditId): void;

    /** @return list<NewestPreviewLink> */
    public function active(\DateTimeImmutable $now): array;

    public function revoke(string $id, string $actor, string $auditId, \DateTimeImmutable $now): void;

    /** Authorize and resolve atomically; a pinned preview must have been resolved by this link already.
     * Audit the first use of each link/preview pair, never individual file requests.
     */
    public function resolve(string $id, string $tokenHash, ?string $pinnedPreview, string $auditId, \DateTimeImmutable $now): ?NewestPreviewResolution;
}

final class NewestPreviewLinkService
{
    public const EXPIRY_HOURS = [1, 24, 168, 720];

    public function __construct(private readonly NewestPreviewLinkRepository $repository, private readonly IdentityRepository $abuse, private readonly Clock $clock, private readonly RandomSource $random, private readonly string $throttleSecret)
    {
        if (strlen($throttleSecret) < 32) {
            throw new \InvalidArgumentException('Share throttle secret must contain at least 32 bytes');
        }
    }

    /** @return array{link: NewestPreviewLink, token: string} The raw token is returned only once. */
    public function create(string $actor, int $hours): array
    {
        if (!in_array($hours, self::EXPIRY_HOURS, true)) {
            throw new InvalidShareExpiry();
        }
        $now = $this->clock->now();
        $link = new NewestPreviewLink($this->id(), $now, $now->modify('+' . $hours . ' hours'));
        $token = bin2hex($this->random->bytes(32));
        $this->repository->create($link, hash('sha256', $token), $actor, $this->id());
        return ['link' => $link, 'token' => $token];
    }

    /** @return list<NewestPreviewLink> */
    public function active(): array
    {
        return $this->repository->active($this->clock->now());
    }

    public function revoke(string $id, string $actor): void
    {
        $this->repository->revoke($id, $actor, $this->id(), $this->clock->now());
    }

    public function authorize(string $id, string $token, string $remoteAddress, ?string $pinnedPreview = null): ?NewestPreviewResolution
    {
        return $this->authorization($id, $token, $remoteAddress, $pinnedPreview);
    }

    public function createFiles(NewestPreviewResolution $resolution): string
    {
        $now = $this->clock->now();
        $token = bin2hex($this->random->bytes(32));
        $this->repository->createFiles($resolution, hash('sha256', $token), PreviewFilePolicy::expiresAt($now, $resolution->link->expiresAt), $now);
        return $token;
    }

    public function authorizeFiles(string $id, string $token, string $remoteAddress): ?NewestPreviewResolution
    {
        return $this->authorization($id, $token, $remoteAddress, null, true);
    }

    public function renewFiles(string $id, string $fileToken, string $parentToken, string $remoteAddress): bool
    {
        return $this->authorization($id, $parentToken, $remoteAddress, null, false, $fileToken) !== null;
    }

    private function authorization(string $id, string $token, string $remoteAddress, ?string $pinnedPreview, bool $files = false, ?string $fileToken = null): ?NewestPreviewResolution
    {
        $now = $this->clock->now();
        // Cookie, bearer and file attempts share a budget across newest-link IDs, separate from fixed shares and login.
        $key = hash_hmac('sha256', 'newest-preview-client:' . substr($remoteAddress, 0, 128), $this->throttleSecret, true);
        if (!$this->abuse->loginAllowed([$key], $now)) {
            throw new ShareRateLimited();
        }
        $resolution = null;
        if (preg_match('/\A[a-f0-9]{32}\z/', $id) === 1 && preg_match('/\A[a-f0-9]{64}\z/', $token) === 1
            && ($pinnedPreview === null || preg_match('/\A[a-f0-9]{32}\z/', $pinnedPreview) === 1)
            && ($fileToken === null || PreviewFilePolicy::validToken($fileToken))) {
            $resolution = $fileToken !== null
                ? $this->repository->renewFiles($id, hash('sha256', $token), hash('sha256', $fileToken), PreviewFilePolicy::expiresAt($now), $now)
                : ($files ? $this->repository->files($id, hash('sha256', $token), $now)
                    : $this->repository->resolve($id, hash('sha256', $token), $pinnedPreview, $this->id(), $now));
        }
        if ($resolution === null) {
            $this->abuse->recordLoginFailure([$key], $now, 300, 10, 300);
        }
        return $resolution;
    }

    private function id(): string
    {
        return bin2hex($this->random->bytes(16));
    }
}
