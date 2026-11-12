<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Preview;

final readonly class PreviewJob
{
    public function __construct(
        public string $id,
        public string $previewId,
        public int $attempts,
        public int $maxAttempts,
        public string $leaseToken,
        public \DateTimeImmutable $leaseExpiresAt,
    ) {}
}

final readonly class PreviewStatus
{
    /** @param array<string, mixed>|null $manifest */
    public function __construct(
        public string $id,
        public string $status,
        public string $jobId,
        public ?string $errorCode,
        public ?array $manifest,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
        public bool $includeDrafts = false,
        public string $buildKind = 'website',
        public ?PreviewDiagnostics $diagnostics = null,
        public bool $includePreparation = false,
    ) {}
}

interface PreviewRepository
{
    /** @param list<string> $locales */
    public function enqueue(
        string $previewId,
        string $jobId,
        string $auditId,
        string $actorUserId,
        string $profileId,
        string $profileVersion,
        array $locales,
        \DateTimeImmutable $now,
        bool $includeDrafts = false,
        bool $includePreparation = false,
    ): PreviewStatus;

    /** @return list<PreviewStatus> */
    public function list(int $limit): array;

    public function find(string $previewId): ?PreviewStatus;

    public function claim(
        string $workerId,
        string $leaseToken,
        \DateTimeImmutable $now,
        \DateTimeImmutable $leaseExpiresAt,
    ): ?PreviewJob;

    public function snapshot(PreviewJob $job): PreviewSnapshot;

    public function complete(
        PreviewJob $job,
        PreviewBuild $build,
        string $auditId,
        \DateTimeImmutable $now,
    ): void;

    public function fail(
        PreviewJob $job,
        string $errorCode,
        bool $retryable,
        \DateTimeImmutable $retryAt,
        \DateTimeImmutable $now,
        ?PreviewDiagnostics $diagnostics = null,
    ): void;

    public function delete(
        string $previewId,
        string $actorUserId,
        string $auditId,
        \DateTimeImmutable $now,
    ): void;
}

final class PreviewService
{
    /** @param null|\Closure(): list<string> $preparation The site's languages in preparation. */
    public function __construct(
        private readonly PreviewRepository $repository,
        private readonly \Utsuri\PortableCms\Conventional\Content\ProfileManifest $profile,
        private readonly \Utsuri\PortableCms\Conventional\Identity\Clock $clock,
        private readonly \Utsuri\PortableCms\Conventional\Identity\RandomSource $random,
        private readonly ?FilesystemPreviewStore $storage = null,
        private readonly string $buildKind = 'website',
        private readonly ?PreviewCheckHistory $checks = null,
        private readonly ?PreviewStartup $startup = null,
        private readonly ?\Closure $preparation = null,
    ) {}

    /** Languages in preparation enter only on request, and make the preview one for review that is never published. */
    public function create(string $actorUserId, bool $includeDrafts = false, bool $includePreparation = false): PreviewStatus
    {
        $previewId = $this->id();
        $jobId = $this->id();
        $auditId = $this->id();
        $now = $this->clock->now();
        try {
            return $this->repository->enqueue(
                $previewId,
                $jobId,
                $auditId,
                $actorUserId,
                $this->profile->id,
                $this->profile->version,
                $this->profile->locales,
                $now,
                $includeDrafts,
                $includePreparation,
            );
        } catch (PreviewPreflightFailure $failure) {
            $this->checks?->failed($this->profile->id, $actorUserId, $auditId, new PreviewCheck($now, $includeDrafts, $failure->issues, $failure->links, $failure->reference));
            throw new PreviewPreflightFailure($failure->issues, $failure->reference, $failure, $now, $failure->links);
        }
    }

    /** @return list<string> Languages in preparation a review preview may include. */
    public function preparationLanguages(): array
    {
        return $this->preparation === null ? [] : ($this->preparation)();
    }

    public function latestCheck(): ?PreviewCheck
    {
        return $this->checks?->latest($this->profile->id);
    }

    /** @return list<PreviewSetupItem> */
    public function setup(\Utsuri\PortableCms\Conventional\Content\ContentService $content): array
    {
        return $this->startup?->missing($content) ?? [];
    }

    /** Publication must enter through this guard, not through a successful build status. */
    public function releaseCandidate(string $previewId): PreviewStatus
    {
        $preview = $this->find($previewId);
        if ($preview === null || $preview->includeDrafts || $preview->includePreparation || $preview->status !== 'ready' || $preview->manifest === null) {
            throw new PreviewNotReleasable();
        }
        $artifact = $this->artifact($previewId, 'artifact.json');
        $manifest = $artifact === null ? null : json_decode($artifact->contents, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($manifest) || ($manifest['formatVersion'] ?? null) !== 1 || ($manifest['includeDrafts'] ?? false) !== false || isset($manifest['preparationLocales'])) {
            throw new PreviewNotReleasable();
        }
        return $preview;
    }

    /** @return list<PreviewStatus> */
    public function list(): array
    {
        return array_values(array_filter($this->repository->list(50), fn(PreviewStatus $status): bool => $status->buildKind === $this->buildKind));
    }

    public function find(string $previewId): ?PreviewStatus
    {
        if (preg_match('/\A[a-f0-9]{32}\z/', $previewId) !== 1) {
            return null;
        }
        $status = $this->repository->find($previewId);
        return $status?->buildKind === $this->buildKind ? $status : null;
    }

    public function artifact(string $previewId, string $path): ?PreviewArtifactBody
    {
        $preview = $this->find($previewId);
        if ($preview === null || $preview->status !== 'ready' || $preview->manifest === null || $this->storage === null) {
            return null;
        }
        return $this->storage->artifact(
            $previewId,
            $path,
            PreviewBuild::fromManifest($preview->manifest),
        );
    }

    public function delete(string $previewId, string $actorUserId): void
    {
        if ($this->repository instanceof PreviewCleanupRepository && $this->storage !== null) {
            $outcome = (new PreviewDeletion($this->repository, $this->storage, $this->clock, $this->random))->remove($previewId, $actorUserId, new PreviewCleanupBudget($this->clock));
            if (in_array($outcome, ['cleanup_pending', 'unprocessed'], true)) {
                throw new PreviewCleanupPending($outcome);
            }
            if ($outcome === 'protected') {
                throw new PreviewNotDeletable();
            }
            return;
        }
        $preview = $this->find($previewId);
        if ($preview === null) {
            throw new PreviewNotFound();
        }
        if (!in_array($preview->status, ['ready', 'needs_attention'], true)) {
            throw new PreviewNotDeletable();
        }
        $token = bin2hex($this->random->bytes(16));
        $quarantined = $this->storage?->quarantine($previewId, $token);
        try {
            $this->repository->delete(
                $previewId,
                $actorUserId,
                bin2hex($this->random->bytes(16)),
                $this->clock->now(),
            );
        } catch (\Throwable $error) {
            if ($quarantined !== null) {
                $this->storage->restoreQuarantine($previewId, $quarantined);
            }
            throw $error;
        }
        if ($quarantined !== null) {
            $this->storage->purgeQuarantine($quarantined);
        }
    }

    /** The caller authorizes every request, including the rules fetch and speculative pages. */
    public function artifactResponse(string $previewId, string $path, string $mount, ?string $range = null, bool $sameOriginFrame = false): ?\Utsuri\PortableCms\Conventional\Http\Response
    {
        if (preg_match('#\A/(?:(?:(?:previews|shared-previews|releases/holding)/[a-f0-9]{32}|newest-previews/[a-f0-9]{32}/pinned/[a-f0-9]{32})/artifact|(?:shared|newest|editor)-preview-files/[a-f0-9]{32}/[a-f0-9]{64})/\z#', $mount) !== 1) {
            throw new \InvalidArgumentException('Preview artifact mount is invalid');
        }
        $artifact = $this->artifact($previewId, $path);
        if ($artifact === null) {
            return null;
        }
        $response = $artifact->response($range, $sameOriginFrame);
        if ($response->status !== 200 || !in_array($artifact->mime, ['text/html', 'text/html; charset=utf-8'], true)) {
            return $response;
        }
        $manifest = $this->find($previewId)?->manifest;
        $scriptHash = $this->vimeoScriptHash($previewId);
        if ($scriptHash !== null) {
            $response = $response->withVimeoFrame($scriptHash);
        }
        foreach ($manifest === null ? [] : PreviewBuild::fromManifest($manifest)->artifacts as $entry) {
            if ($entry->mime === \Utsuri\PortableCms\Conventional\Content\SpeculationRules::MIME
                && \Utsuri\PortableCms\Conventional\Content\SpeculationRules::validPath($entry->path)
                && $this->artifact($previewId, $entry->path) !== null) {
                return $response->withHeader('speculation-rules', '"' . $mount . $entry->path . '"');
            }
        }
        return $response;
    }

    /** Permission comes from the verified compiler artifact, not the preview queue envelope. */
    public function vimeoScriptHash(string $previewId): ?string
    {
        $artifact = $this->artifact($previewId, 'artifact.json');
        if ($artifact === null) {
            return null;
        }
        try {
            $document = json_decode($artifact->contents, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new PreviewBuildFailure('preview_artifact_verification_failed', false, 'Preview manifest is invalid.', $error);
        }
        if (($document['frameSources'] ?? []) !== [\Utsuri\PortableCms\Conventional\Compiler\VimeoPresentation::ORIGIN]) {
            return null;
        }
        $script = $this->artifact($previewId, \Utsuri\PortableCms\Conventional\Compiler\VimeoPresentation::SCRIPT);
        if ($script === null) {
            throw new PreviewBuildFailure('preview_artifact_verification_failed', false, 'Vimeo player asset is unavailable.');
        }
        return \Utsuri\PortableCms\Conventional\Compiler\VimeoPresentation::scriptHash($script->contents);
    }

    private function id(): string
    {
        return bin2hex($this->random->bytes(16));
    }
}

final class PreviewNotFound extends \RuntimeException {}

final class PreviewNotDeletable extends \RuntimeException {}

final class PreviewNotReleasable extends \RuntimeException {}
