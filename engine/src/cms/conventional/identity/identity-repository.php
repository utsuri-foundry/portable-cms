<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Identity;

interface IdentityRepository
{
    /**
     * @return array{
     *   id: string,
     *   kind: string,
     *   userId: string|null,
     *   loginName: string|null,
     *   displayName: string|null,
     *   role: string|null,
     *   interfaceLanguage: string|null,
     *   mustChangePassword?: bool,
     *   securityVersion?: int,
     *   csrfHash: string,
     *   expiresAt: \DateTimeImmutable,
     *   absoluteExpiresAt: \DateTimeImmutable
     * }|null
     */
    public function findSessionByTokenHash(string $tokenHash): ?array;

    public function createSession(
        string $id,
        string $kind,
        ?string $userId,
        string $tokenHash,
        string $csrfHash,
        \DateTimeImmutable $createdAt,
        \DateTimeImmutable $expiresAt,
        \DateTimeImmutable $absoluteExpiresAt,
        int $securityVersion = 0,
    ): void;

    public function touchSession(string $id, \DateTimeImmutable $lastSeenAt, \DateTimeImmutable $expiresAt): void;

    public function revokeSession(string $id, \DateTimeImmutable $revokedAt): void;

    /**
     * @return array{
     *   id: string,
     *   loginName: string,
     *   displayName: string,
     *   role: string,
     *   interfaceLanguage: string|null,
     *   passwordHash: string,
     *   mustChangePassword?: bool,
     *   securityVersion?: int
     * }|null
     */
    public function findActiveUserByLogin(string $normalizedLogin): ?array;

    public function replacePasswordHash(string $userId, string $passwordHash, \DateTimeImmutable $changedAt, ?string $expectedHash = null): void;

    /** Stores an active account's interface language; null follows the browser. */
    public function setInterfaceLanguage(string $userId, ?string $language): void;

    /** @param list<string> $keyHashes */
    public function loginAllowed(array $keyHashes, \DateTimeImmutable $now): bool;

    /** @param list<string> $keyHashes */
    public function recordLoginFailure(
        array $keyHashes,
        \DateTimeImmutable $now,
        int $windowSeconds,
        int $maximumFailures,
        int $blockSeconds,
    ): void;

    /** @param list<string> $keyHashes */
    public function clearLoginFailures(array $keyHashes): void;

    /** @param array<string, scalar|null> $metadata */
    public function appendAudit(
        string $id,
        ?string $actorUserId,
        string $eventType,
        array $metadata,
        \DateTimeImmutable $occurredAt,
    ): void;
}

interface Clock
{
    public function now(): \DateTimeImmutable;
}

interface RandomSource
{
    public function bytes(int $length): string;
}

final class SystemClock implements Clock
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}

final class SystemRandomSource implements RandomSource
{
    public function bytes(int $length): string
    {
        return random_bytes($length);
    }
}
