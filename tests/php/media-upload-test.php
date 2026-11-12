<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Media\FilesystemUploadStorage;
use Utsuri\PortableCms\Conventional\Media\MediaContract;
use Utsuri\PortableCms\Conventional\Media\MediaUploadService;
use Utsuri\PortableCms\Conventional\Media\StoredOriginal;
use Utsuri\PortableCms\Conventional\Media\UploadFailure;
use Utsuri\PortableCms\Conventional\Media\UploadPart;
use Utsuri\PortableCms\Conventional\Media\UploadRepository;
use Utsuri\PortableCms\Conventional\Media\UploadSession;

final class MemoryUploadRepository implements UploadRepository
{
    /** @var array<string, array{typeId: string, revisionNumber: int}> */
    public array $records = [];
    /** @var array<string, UploadSession> */
    public array $sessions = [];
    /** @var array<string, string> */
    public array $clientKeys = [];
    /** @var array<string, array<string, mixed>> */
    public array $media = [];
    /** @var array<string, array<string, mixed>> */
    public array $jobs = [];

    public function ownerRecord(string $recordId): ?array
    {
        return $this->records[$recordId] ?? null;
    }

    public function findByClientId(?string $recordId, string $actorUserId, string $clientUploadId): ?UploadSession
    {
        foreach (array_reverse($this->sessions, true) as $session) {
            if (
                $session->ownerRecordId === $recordId
                && $session->actorUserId === $actorUserId
                && $session->clientUploadId === $clientUploadId
                && in_array($session->status, ['uploading', 'completing', 'completed'], true)
            ) {
                return $session;
            }
        }
        return null;
    }

    public function create(
        UploadSession $session,
        string $activeClientKey,
        DateTimeImmutable $now,
    ): UploadSession {
        $encodedKey = bin2hex($activeClientKey);
        if (isset($this->clientKeys[$encodedKey])) {
            throw new RuntimeException('duplicate client upload key');
        }
        $this->clientKeys[$encodedKey] = $session->id;
        return $this->sessions[$session->id] = $session;
    }

    public function find(string $sessionId): ?UploadSession
    {
        return $this->sessions[$sessionId] ?? null;
    }

    public function recordPart(string $sessionId, UploadPart $part, DateTimeImmutable $now): UploadSession
    {
        $session = $this->sessions[$sessionId] ?? throw new RuntimeException('missing session');
        $parts = array_values(array_filter(
            $session->parts,
            static fn(UploadPart $stored): bool => $stored->partNumber !== $part->partNumber,
        ));
        $parts[] = $part;
        usort($parts, static fn(UploadPart $left, UploadPart $right): int => $left->partNumber <=> $right->partNumber);
        return $this->sessions[$sessionId] = self::copy($session, parts: $parts);
    }

    public function transition(
        string $sessionId,
        array $fromStatuses,
        string $status,
        DateTimeImmutable $now,
        bool $releaseClientKey = false,
    ): UploadSession {
        $session = $this->sessions[$sessionId] ?? throw new RuntimeException('missing session');
        if (!in_array($session->status, $fromStatuses, true) && $session->status !== $status) {
            throw new UploadFailure('upload_state_conflict', 409, 'upload.error.the_upload_changed_before_this_operation');
        }
        return $this->sessions[$sessionId] = self::copy($session, status: $status);
    }

    public function complete(
        string $sessionId,
        string $mediaId,
        string $jobId,
        StoredOriginal $original,
        string $auditId,
        DateTimeImmutable $now,
    ): array {
        $session = $this->sessions[$sessionId] ?? throw new RuntimeException('missing session');
        if ($session->status !== 'completed') {
            $this->media[$mediaId] = [
                'id' => $mediaId,
                'status' => 'uploaded',
                'original_name' => $session->originalName,
                'declared_type' => $session->declaredType,
                'byte_size' => $original->byteSize,
                'checksum' => $original->checksum,
            ];
            $this->jobs[$jobId] = [
                'id' => $jobId,
                'job_type' => 'media_derivatives',
                'status' => 'queued',
            ];
            $session = $this->sessions[$sessionId] = self::copy(
                $session,
                status: 'completed',
                mediaId: $mediaId,
                jobId: $jobId,
            );
        }
        return [
            'session' => $session,
            'media' => $this->media[(string) $session->mediaId],
            'job' => $this->jobs[(string) $session->jobId],
        ];
    }

    public function completed(string $sessionId): array
    {
        $session = $this->sessions[$sessionId] ?? throw new RuntimeException('missing session');
        if ($session->status !== 'completed') {
            throw new RuntimeException('session is not complete');
        }
        return [
            'session' => $session,
            'media' => $this->media[(string) $session->mediaId],
            'job' => $this->jobs[(string) $session->jobId],
        ];
    }

    /** @param list<UploadPart>|null $parts */
    private static function copy(
        UploadSession $session,
        ?string $status = null,
        ?string $mediaId = null,
        ?string $jobId = null,
        ?array $parts = null,
    ): UploadSession {
        return new UploadSession(
            $session->id,
            $session->clientUploadId,
            $session->ownerRecordId,
            $session->actorUserId,
            $status ?? $session->status,
            $session->objectKey,
            $session->originalName,
            $session->declaredType,
            $session->byteSize,
            $session->partSize,
            $session->partCount,
            $session->initialRecordRevision,
            $mediaId ?? $session->mediaId,
            $jobId ?? $session->jobId,
            $session->expiresAt,
            $parts ?? $session->parts,
        );
    }
}

/** @return resource */
function uploadTestStream(string $bytes): mixed
{
    $stream = fopen('php://temp', 'w+b');
    if (!is_resource($stream)) {
        throw new RuntimeException('test stream unavailable');
    }
    fwrite($stream, $bytes);
    rewind($stream);
    return $stream;
}

function removeUploadTestTree(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }
    foreach (scandir($directory) ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $directory . '/' . $item;
        is_dir($path) && !is_link($path) ? removeUploadTestTree($path) : @unlink($path);
    }
    @rmdir($directory);
}

$tests['resumable filesystem upload survives interruption and completes exactly once'] = static function (): void {
    $root = sys_get_temp_dir() . '/portable-cms-upload-' . bin2hex(random_bytes(8));
    $repository = new MemoryUploadRepository();
    $recordId = str_repeat('a', 32);
    $actorId = str_repeat('b', 32);
    $repository->records[$recordId] = ['typeId' => 'portfolio-entry', 'revisionNumber' => 1];
    $clock = new IdentityTestClock(new DateTimeImmutable('2026-09-30T18:00:00Z'));
    $service = new MediaUploadService(
        $repository,
        new FilesystemUploadStorage($root),
        $clock,
        new IdentityTestRandom(),
    );
    $bytes = "\xFF\xD8\xFF" . str_repeat('a', MediaContract::PART_BYTES - 3) . 'tail';
    try {
        $session = $service->create(
            'portfolio-entry',
            $recordId,
            1,
            '../courtyard.jpg',
            'image/jpeg',
            strlen($bytes),
            'browser-upload-one',
            $actorId,
        );
        assertSameValue(2, $session->partCount, 'large upload should use exact multipart geometry');
        assertSameValue('courtyard.jpg', $session->originalName, 'client paths should be removed');
        $resumedEmpty = $service->create(
            'portfolio-entry',
            $recordId,
            1,
            'courtyard.jpg',
            'image/jpeg',
            strlen($bytes),
            'browser-upload-one',
            $actorId,
        );
        assertSameValue($session->id, $resumedEmpty->id, 'client upload ID should make creation idempotent');

        $partOne = substr($bytes, 0, MediaContract::PART_BYTES);
        $stream = uploadTestStream($partOne);
        try {
            $afterPart = $service->uploadPart(
                $session->id,
                1,
                strlen($partOne),
                $stream,
                $actorId,
            );
        } finally {
            fclose($stream);
        }
        assertSameValue(1, count($afterPart->parts), 'first chunk should become authoritative progress');
        $resumed = $service->create(
            'portfolio-entry',
            $recordId,
            1,
            'courtyard.jpg',
            'image/jpeg',
            strlen($bytes),
            'browser-upload-one',
            $actorId,
        );
        assertSameValue(1, count($resumed->parts), 'retry should expose already completed chunks');

        $partTwo = substr($bytes, MediaContract::PART_BYTES);
        $stream = uploadTestStream($partTwo);
        try {
            $service->uploadPart($session->id, 2, strlen($partTwo), $stream, $actorId);
        } finally {
            fclose($stream);
        }
        try {
            $service->complete($session->id, $actorId, str_repeat('0', 64));
            throw new RuntimeException('A changed checksum was accepted before processing handoff');
        } catch (UploadFailure $error) {
            assertSameValue('source_integrity_mismatch', $error->codeName, 'operator checksum binds assembled bytes');
            assertSameValue([], $repository->media, 'checksum rejection creates no media');
            assertSameValue([], $repository->jobs, 'checksum rejection queues no job');
        }
        $completed = $service->complete($session->id, $actorId, hash('sha256', $bytes));
        assertSameValue('completed', $completed['session']->status, 'complete upload should become terminal');
        assertSameValue('uploaded', $completed['media']['status'], 'private original should create media metadata');
        assertSameValue('queued', $completed['job']['status'], 'derivative work should be durably queued');
        assertSameValue(hash('sha256', $bytes), $completed['media']['checksum'], 'assembled checksum should be authoritative');
        assertSameValue(1, count($repository->media), 'completion should create one media record');
        assertSameValue(1, count($repository->jobs), 'completion should create one job');
        $repeated = $service->complete($session->id, $actorId);
        assertSameValue($completed['media']['id'], $repeated['media']['id'], 'lost completion response should be retry-safe');
        assertSameValue(1, count($repository->media), 'completion retry must not duplicate media');
    } finally {
        removeUploadTestTree($root);
    }
};

$tests['resumable upload rejects wrong sizes spoofed media and foreign ownership'] = static function (): void {
    $root = sys_get_temp_dir() . '/portable-cms-upload-' . bin2hex(random_bytes(8));
    $repository = new MemoryUploadRepository();
    $recordId = str_repeat('c', 32);
    $actorId = str_repeat('d', 32);
    $repository->records[$recordId] = ['typeId' => 'portfolio-entry', 'revisionNumber' => 2];
    $service = new MediaUploadService(
        $repository,
        new FilesystemUploadStorage($root),
        new IdentityTestClock(new DateTimeImmutable('2026-09-30T18:00:00Z')),
        new IdentityTestRandom(),
    );
    try {
        $session = $service->create(
            'portfolio-entry',
            $recordId,
            2,
            'spoof.png',
            'image/png',
            12,
            'spoof-one',
            $actorId,
        );
        try {
            $stream = uploadTestStream('short');
            try {
                $service->uploadPart($session->id, 1, 5, $stream, $actorId);
            } finally {
                fclose($stream);
            }
            throw new RuntimeException('wrong-sized part unexpectedly saved');
        } catch (UploadFailure $error) {
            assertSameValue('invalid_part_size', $error->codeName, 'part geometry should fail before storage');
        }
        try {
            $service->status($session->id, str_repeat('e', 32));
            throw new RuntimeException('foreign actor unexpectedly read upload');
        } catch (UploadFailure $error) {
            assertSameValue(403, $error->statusCode, 'upload ownership should be enforced');
        }
        $stream = uploadTestStream('not a png!!!');
        try {
            $service->uploadPart($session->id, 1, 12, $stream, $actorId);
        } finally {
            fclose($stream);
        }
        try {
            $service->complete($session->id, $actorId);
            throw new RuntimeException('spoofed image unexpectedly completed');
        } catch (UploadFailure $error) {
            assertSameValue('media_signature_mismatch', $error->codeName, 'declared type must match file signature');
        }
        assertSameValue('aborted', $repository->sessions[$session->id]->status, 'spoofed original should be terminally rejected');
        assertSameValue(0, count($repository->media), 'spoofed upload must not create media');
    } finally {
        removeUploadTestTree($root);
    }
};

$tests['private upload storage refuses symlink traversal before writing bytes'] = static function (): void {
    $root = sys_get_temp_dir() . '/portable-cms-upload-' . bin2hex(random_bytes(8));
    $outside = sys_get_temp_dir() . '/portable-cms-upload-outside-' . bin2hex(random_bytes(8));
    mkdir($outside, 0700);
    $repository = new MemoryUploadRepository();
    $recordId = str_repeat('f', 32);
    $actorId = str_repeat('1', 32);
    $repository->records[$recordId] = ['typeId' => 'portfolio-entry', 'revisionNumber' => 1];
    $storage = new FilesystemUploadStorage($root);
    $service = new MediaUploadService(
        $repository,
        $storage,
        new IdentityTestClock(new DateTimeImmutable('2026-09-30T18:00:00Z')),
        new IdentityTestRandom(),
    );
    try {
        if (!symlink($outside, $root . '/uploads')) {
            throw new RuntimeException('test symlink could not be created');
        }
        $session = $service->create(
            'portfolio-entry',
            $recordId,
            1,
            'safe.png',
            'image/png',
            12,
            'symlink-test',
            $actorId,
        );
        $stream = uploadTestStream("\x89PNG\r\n\x1A\nDATA");
        try {
            try {
                $service->uploadPart($session->id, 1, 12, $stream, $actorId);
                throw new RuntimeException('symlinked upload root unexpectedly accepted bytes');
            } catch (RuntimeException $error) {
                assertTrueValue(
                    str_contains($error->getMessage(), 'symlink'),
                    'symlink traversal should fail at the private-storage boundary',
                );
            }
        } finally {
            fclose($stream);
        }
        assertSameValue([], array_values(array_diff(scandir($outside) ?: [], ['.', '..'])), 'outside directory must remain untouched');
    } finally {
        removeUploadTestTree($root);
        removeUploadTestTree($outside);
    }
};
