<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Media;

final class MediaProcessingFailure extends \RuntimeException
{
    public function __construct(
        public readonly string $codeName,
        public readonly bool $retryable,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }
}

final readonly class MediaJob
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public string $id,
        public string $status,
        public array $payload,
        public int $attempts,
        public int $maxAttempts,
        public string $leaseToken,
        public \DateTimeImmutable $leaseExpiresAt,
    ) {}
}

final readonly class MediaSource
{
    public function __construct(
        public string $mediaId,
        public ?string $ownerRecordId,
        public string $actorUserId,
        public string $declaredType,
        public int $byteSize,
        public string $checksum,
        public string $originalKey,
        public int $maximumWidth = 960,
    ) {}
}

final readonly class MediaDerivative
{
    /** @param array<string, int|string|bool> $parameters */
    public function __construct(
        public string $mediaId,
        public string $variant,
        public string $objectKey,
        public string $filename,
        public string $mime,
        public int $width,
        public int $height,
        public int $byteSize,
        public string $checksum,
        public string $processorId,
        public string $processorVersion,
        public int $recipeVersion,
        public array $parameters,
    ) {}
}

final readonly class ProcessedMedia
{
    public function __construct(
        public string $checksum,
        public string $detectedType,
        public int $width,
        public int $height,
        public MediaDerivative $derivative,
    ) {}
}

interface MediaProcessor
{
    public function process(MediaSource $source, string $sourcePath, string $workingDirectory): ProcessedMedia;
}

interface RasterProcessor extends MediaProcessor {}

interface MediaJobRepository
{
    public function claim(
        string $workerId,
        string $leaseToken,
        \DateTimeImmutable $now,
        \DateTimeImmutable $leaseExpiresAt,
    ): ?MediaJob;

    public function source(MediaJob $job): MediaSource;

    public function complete(
        MediaJob $job,
        ProcessedMedia $processed,
        string $auditId,
        \DateTimeImmutable $now,
    ): void;

    public function fail(
        MediaJob $job,
        string $errorCode,
        bool $retryable,
        \DateTimeImmutable $retryAt,
        \DateTimeImmutable $now,
    ): void;

    /** @return array<string, int|string|null>|null */
    public function publicStatus(string $jobId): ?array;

    /** @return array<string, int|string|null>|null */
    public function latestPublicStatus(): ?array;
}
