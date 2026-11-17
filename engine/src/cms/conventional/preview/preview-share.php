<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Preview;

use Utsuri\PortableCms\Conventional\Identity\Clock;
use Utsuri\PortableCms\Conventional\Identity\IdentityRepository;
use Utsuri\PortableCms\Conventional\Identity\RandomSource;

/** A link to one frozen preview. The note says who it is for; the sealed secret lets the link be copied again. */
final readonly class PreviewShare
{
    public function __construct(
        public string $id,
        public string $previewId,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $expiresAt,
        public ?string $note = null,
        public ?string $createdBy = null,
        public ?string $sealedSecret = null,
    ) {
        if (preg_match('/\A[a-f0-9]{32}\z/', $id) !== 1
            || preg_match('/\A[a-f0-9]{32}\z/', $previewId) !== 1
            || $expiresAt <= $createdAt
            || ($note !== null && !SharedLinkNote::valid($note))) {
            throw new \InvalidArgumentException('Preview share is invalid');
        }
    }

    public function expired(\DateTimeImmutable $now): bool
    {
        return $this->expiresAt <= $now;
    }
}

/** The free text an editor writes when making a link: a name, a company, a purpose. */
final class SharedLinkNote
{
    public const MAX_LENGTH = 120;

    /** Trimmed and bounded; null when nothing was written. */
    public static function normalize(string $value): ?string
    {
        $note = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
        if ($note === '') {
            return null;
        }
        if (!self::valid($note)) {
            throw new InvalidShareNote();
        }
        return $note;
    }

    public static function valid(string $note): bool
    {
        return $note !== '' && mb_check_encoding($note, 'UTF-8') && mb_strlen($note) <= self::MAX_LENGTH && preg_match('/[\x00-\x1f\x7f]/u', $note) !== 1;
    }
}

/** The lifetimes an editor can choose, in days, and the default. Extending counts from today. */
final class SharedLinkLifetime
{
    public const DAYS = [1, 7, 30];
    public const DEFAULT_DAYS = 7;

    public static function expiry(int $days, \DateTimeImmutable $now): \DateTimeImmutable
    {
        if (!in_array($days, self::DAYS, true)) {
            throw new InvalidShareExpiry();
        }
        return $now->modify('+' . $days . ' days');
    }

    /** Links expired longer ago than this leave the list; until then they can be extended or removed. */
    public static function listingFloor(\DateTimeImmutable $now): \DateTimeImmutable
    {
        return $now->modify('-30 days');
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

    /** @return list<PreviewShare> Unexpired links of one preview. */
    public function active(string $previewId, \DateTimeImmutable $now): array;

    /** @return list<PreviewShare> Links of every preview that are not stopped, newest first, including recently expired ones. */
    public function listed(\DateTimeImmutable $now): array;

    public function extend(string $id, \DateTimeImmutable $expiresAt, string $actor, string $auditId, \DateTimeImmutable $now): PreviewShare;

    /** Stops a link for good; an expired link is removed from the list the same way. */
    public function revoke(string $id, string $actor, string $auditId, \DateTimeImmutable $now): void;

    /** Atomically authorize a live ready preview and audit use, comparing the supplied hash in constant time. */
    public function use(string $id, string $tokenHash, string $auditId, \DateTimeImmutable $now): ?PreviewShare;
}

final class PreviewShareService
{
    private readonly LinkSecret $secrets;

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
        $this->secrets = new LinkSecret($throttleSecret, $random);
    }

    /** @return array{share: PreviewShare, token: string} The token is returned once; storage keeps its hash and a sealed copy. */
    public function create(string $previewId, string $actor, int $days, ?string $note = null): array
    {
        $now = $this->clock->now();
        $token = bin2hex($this->random->bytes(32));
        $share = new PreviewShare($this->id(), $previewId, $now, SharedLinkLifetime::expiry($days, $now), $note, $actor, $this->secrets->seal($token));
        $this->repository->create($share, hash('sha256', $token), $actor, $this->id());
        return ['share' => $share, 'token' => $token];
    }

    /** @return list<PreviewShare> */
    public function active(string $previewId): array
    {
        return $this->repository->active($previewId, $this->clock->now());
    }

    /** @return list<PreviewShare> */
    public function listed(): array
    {
        return $this->repository->listed($this->clock->now());
    }

    public function extend(string $id, string $actor, int $days): PreviewShare
    {
        $now = $this->clock->now();
        return $this->repository->extend($id, SharedLinkLifetime::expiry($days, $now), $actor, $this->id(), $now);
    }

    public function revoke(string $id, string $actor): void
    {
        $this->repository->revoke($id, $actor, $this->id(), $this->clock->now());
    }

    /** The complete link again, or null when its secret cannot be opened here. */
    public function url(PreviewShare $share, string $origin): ?string
    {
        $secret = $this->secrets->open($share->sealedSecret);
        return $secret === null ? null : $origin . '/shared-previews/' . $share->id . '/#' . $secret;
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

final class InvalidShareNote extends \RuntimeException {}

final class ShareUnavailable extends \RuntimeException {}

final class ShareRateLimited extends \RuntimeException {}
