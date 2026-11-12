<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Media;

interface UploadRepository
{
    /** @return array{typeId: string, revisionNumber: int}|null */
    public function ownerRecord(string $recordId): ?array;

    public function findByClientId(?string $recordId, string $actorUserId, string $clientUploadId): ?UploadSession;

    public function create(
        UploadSession $session,
        string $activeClientKey,
        \DateTimeImmutable $now,
    ): UploadSession;

    public function find(string $sessionId): ?UploadSession;

    public function recordPart(string $sessionId, UploadPart $part, \DateTimeImmutable $now): UploadSession;

    /** @param list<string> $fromStatuses */
    public function transition(
        string $sessionId,
        array $fromStatuses,
        string $status,
        \DateTimeImmutable $now,
        bool $releaseClientKey = false,
    ): UploadSession;

    /** @return array{session: UploadSession, media: array<string, mixed>, job: array<string, mixed>} */
    public function complete(
        string $sessionId,
        string $mediaId,
        string $jobId,
        StoredOriginal $original,
        string $auditId,
        \DateTimeImmutable $now,
    ): array;

    /** @return array{session: UploadSession, media: array<string, mixed>, job: array<string, mixed>} */
    public function completed(string $sessionId): array;
}
