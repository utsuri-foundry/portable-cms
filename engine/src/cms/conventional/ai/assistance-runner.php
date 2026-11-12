<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Ai;

use Utsuri\PortableCms\Conventional\Identity\Clock;
use Utsuri\PortableCms\Conventional\Identity\RandomSource;
use Utsuri\PortableCms\Conventional\Media\MediaLibrary;

interface AssistanceImageSource
{
    public function read(string $mediaId, string $checksum): string;
}

final class LibraryAssistanceImageSource implements AssistanceImageSource
{
    public function __construct(private readonly MediaLibrary $media) {}

    public function read(string $mediaId, string $checksum): string
    {
        $bytes = $this->media->thumbnail($mediaId);
        if ($bytes === null || strlen($bytes) > 4194304 || !hash_equals($checksum, hash('sha256', $bytes))) {
            throw new AssistanceFailure('source_unavailable');
        }
        return $bytes;
    }
}

final class AssistanceRunner
{
    public function __construct(
        private readonly AssistanceRepository $repository,
        private readonly AssistanceService $service,
        private readonly ?AssistanceProvider $provider,
        private readonly AssistanceImageSource $images,
        private readonly Clock $clock,
        private readonly RandomSource $random,
    ) {}

    public function runOne(): bool
    {
        $job = $this->repository->claim(bin2hex($this->random->bytes(32)), $this->clock->now());
        if ($job === null) {
            return false;
        }
        try {
            if ($this->provider === null || $job->request === null) {
                throw new AssistanceFailure('unavailable');
            }
            $this->service->fresh($job->request);
            $frozen = $job->request->frozen;
            $bytes = $this->images->read((string) $frozen['mediaId'], (string) $frozen['derivativeChecksum']);
            $candidate = $this->provider->suggest($job->request, $bytes, $job->id);
            $this->service->fresh($job->request);
            $this->repository->finish($job, $candidate, null, false, $this->clock->now());
        } catch (\Throwable $error) {
            $failure = $error instanceof AssistanceFailure ? $error : new AssistanceFailure('provider_failed');
            // Never persist provider bodies, exception messages, credentials or arbitrary diagnostics.
            $safe = in_array($failure->safeCode, ['unavailable', 'stale', 'source_unavailable', 'invalid_candidate', 'provider_failed', 'provider_retry'], true) ? $failure->safeCode : 'provider_failed';
            $this->repository->finish($job, null, $safe, $failure->retryable, $this->clock->now());
        }
        return true;
    }
}
