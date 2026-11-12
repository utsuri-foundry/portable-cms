<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Preview;

/** Browser file leases are subordinate to an authorized share, newest resolution or editorial session. */
final class PreviewFilePolicy
{
    public const LIFETIME_SECONDS = 7200;
    public const MAX_VIEWERS = 50;

    public static function expiresAt(\DateTimeImmutable $now, ?\DateTimeImmutable $parentExpiry = null): \DateTimeImmutable
    {
        $expiry = $now->modify('+' . self::LIFETIME_SECONDS . ' seconds');
        return $parentExpiry === null ? $expiry : min($parentExpiry, $expiry);
    }

    public static function validToken(string $token): bool
    {
        return preg_match('/\A[a-f0-9]{64}\z/', $token) === 1;
    }
}
