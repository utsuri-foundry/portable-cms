<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Preview;

use Utsuri\PortableCms\Conventional\Identity\Clock;
use Utsuri\PortableCms\Conventional\Identity\IdentityRepository;
use Utsuri\PortableCms\Conventional\Identity\RandomSource;

/** A link that always opens the newest ready website preview. Note and sealed secret as for a fixed share. */
final readonly class NewestPreviewLink
{
    public function __construct(
        public string $id,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $expiresAt,
        public ?string $note = null,
        public ?string $createdBy = null,
        public ?string $sealedSecret = null,
    ) {
        if (preg_match('/\A[a-f0-9]{32}\z/', $id) !== 1 || $expiresAt <= $createdAt || ($note !== null && !SharedLinkNote::valid($note))) {
            throw new \InvalidArgumentException('Newest preview link is invalid');
        }
    }

    public function expired(\DateTimeImmutable $now): bool
    {
        return $this->expiresAt <= $now;
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

    /** @return list<NewestPreviewLink> Unexpired links. */
    public function active(\DateTimeImmutable $now): array;

    /** @return list<NewestPreviewLink> Links that are not stopped, newest first, including recently expired ones. */
    public function listed(\DateTimeImmutable $now): array;

    public function extend(string $id, \DateTimeImmutable $expiresAt, string $actor, string $auditId, \DateTimeImmutable $now): NewestPreviewLink;

    public function revoke(string $id, string $actor, string $auditId, \DateTimeImmutable $now): void;

    /** Authorize and resolve atomically; a pinned preview must have been resolved by this link already.
     * Audit the first use of each link/preview pair, never individual file requests.
     */
    public function resolve(string $id, string $tokenHash, ?string $pinnedPreview, string $auditId, \DateTimeImmutable $now): ?NewestPreviewResolution;
}

final class NewestPreviewLinkService
{
    private readonly LinkSecret $secrets;

    public function __construct(private readonly NewestPreviewLinkRepository $repository, private readonly IdentityRepository $abuse, private readonly Clock $clock, private readonly RandomSource $random, private readonly string $throttleSecret)
    {
        if (strlen($throttleSecret) < 32) {
            throw new \InvalidArgumentException('Share throttle secret must contain at least 32 bytes');
        }
        $this->secrets = new LinkSecret($throttleSecret, $random);
    }

    /** @return array{link: NewestPreviewLink, token: string} The raw token is returned once; storage keeps its hash and a sealed copy. */
    public function create(string $actor, int $days, ?string $note = null): array
    {
        $now = $this->clock->now();
        $token = bin2hex($this->random->bytes(32));
        $link = new NewestPreviewLink($this->id(), $now, SharedLinkLifetime::expiry($days, $now), $note, $actor, $this->secrets->seal($token));
        $this->repository->create($link, hash('sha256', $token), $actor, $this->id());
        return ['link' => $link, 'token' => $token];
    }

    /** @return list<NewestPreviewLink> */
    public function active(): array
    {
        return $this->repository->active($this->clock->now());
    }

    /** @return list<NewestPreviewLink> */
    public function listed(): array
    {
        return $this->repository->listed($this->clock->now());
    }

    public function extend(string $id, string $actor, int $days): NewestPreviewLink
    {
        $now = $this->clock->now();
        return $this->repository->extend($id, SharedLinkLifetime::expiry($days, $now), $actor, $this->id(), $now);
    }

    public function revoke(string $id, string $actor): void
    {
        $this->repository->revoke($id, $actor, $this->id(), $this->clock->now());
    }

    public function url(NewestPreviewLink $link, string $origin): ?string
    {
        $secret = $this->secrets->open($link->sealedSecret);
        return $secret === null ? null : $origin . '/newest-previews/' . $link->id . '/#' . $secret;
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
        // Browser file addresses are server-minted, unguessable and short-lived; a failure there is
        // a viewer left open past its lease and never counts toward the visitor's budget.
        if ($resolution === null && !$files) {
            $this->abuse->recordLoginFailure([$key], $now, 300, 10, 300);
        }
        return $resolution;
    }

    private function id(): string
    {
        return bin2hex($this->random->bytes(16));
    }
}
