<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Ai;

use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Identity\Clock;
use Utsuri\PortableCms\Conventional\Identity\RandomSource;

final class AssistanceService
{
    public function __construct(
        private readonly AssistanceRepository $repository,
        public readonly ?AssistancePolicy $policy,
        private readonly ProfileManifest $profile,
        private readonly Clock $clock,
        private readonly RandomSource $random,
    ) {
        if ($this->policy === null) {
            $this->repository->disablePending();
        }
    }

    public function request(string $typeId, string $recordId, int $revision, string $mediaId, string $context, string $clientId, string $actorId, bool $consent): AssistanceJob
    {
        if ($this->policy === null || !in_array('ai_drafts', [...$this->profile->capabilities->optional, ...$this->profile->capabilities->required], true)) {
            throw new AssistanceFailure('unavailable');
        }
        if (!$consent || !$this->policy->permits($typeId, $mediaId)) {
            throw new AssistanceFailure('excluded');
        }
        foreach ([$recordId, $mediaId, $clientId, $actorId] as $id) {
            if ($id === '') {
                return $this->repository->latest($actorId, $recordId, $this->clock->now());
            }
            if (preg_match('/\A[a-f0-9]{32}\z/', $id) !== 1) {
                throw new AssistanceFailure('invalid_request');
            }
        }
        if (!mb_check_encoding($context, 'UTF-8') || mb_strlen($context) > 1000
            || preg_match('/[\x00-\x08\x0b-\x1f\x7f]/u', $context) !== 0) {
            throw new AssistanceFailure('invalid_request');
        }
        $source = $this->repository->source($typeId, $recordId, $mediaId);
        if ($source['sourceRevision'] !== $revision) {
            throw new AssistanceFailure('stale');
        }
        $request = new AssistanceRequest($source + ['formatVersion' => 1, 'locales' => $this->profile->locales,
            'profileId' => $this->profile->id, 'profileVersion' => $this->profile->version,
            'recipe' => 'image-editorial-v1', 'provider' => $this->policy->provider, 'model' => $this->policy->model,
            'policyFingerprint' => $this->policy->fingerprint(), 'context' => trim($context)]);
        $request->locales();
        return $this->repository->enqueue(bin2hex($this->random->bytes(16)), $clientId, $actorId, $request, $this->policy, $this->clock->now());
    }

    public function fresh(AssistanceRequest $request): void
    {
        $frozen = $request->frozen;
        if ($this->policy === null || $this->policy->fingerprint() !== ($frozen['policyFingerprint'] ?? '')
            || !$this->policy->permits((string) $frozen['typeId'], (string) $frozen['mediaId'])) {
            throw new AssistanceFailure('unavailable');
        }
        if ($request->locales() !== $this->profile->locales || $frozen['profileId'] !== $this->profile->id
            || $frozen['profileVersion'] !== $this->profile->version) {
            throw new AssistanceFailure('stale');
        }
        $source = $this->repository->source((string) $frozen['typeId'], (string) $frozen['recordId'], (string) $frozen['mediaId']);
        foreach ($source as $key => $value) {
            if (($frozen[$key] ?? null) !== $value) {
                throw new AssistanceFailure('stale');
            }
        }
    }

    public function find(string $id, string $actorId, string $recordId): ?AssistanceJob
    {
        if (preg_match('/\A[a-f0-9]{32}\z/', $id) !== 1) {
            return null;
        }
        return $this->repository->find($id, $actorId, $recordId, $this->clock->now());
    }

    public function cancel(string $id, string $actorId, string $recordId): void
    {
        $this->repository->cancel($id, $actorId, $recordId, $this->clock->now());
    }
}
