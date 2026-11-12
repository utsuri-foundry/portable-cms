<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Preview;

use Utsuri\PortableCms\Conventional\Compiler\CompiledRoute;
use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Identity\Clock;
use Utsuri\PortableCms\Conventional\Identity\RandomSource;
use Utsuri\PortableCms\Conventional\Identity\SessionContext;

interface EditorPreviewRepository
{
    /** The newest ready website containing saved drafts. */
    public function latestReadyPreview(): ?PreviewStatus;

    public function capturedRecord(string $previewId, string $recordId, ProfileManifest $profile): bool;

    /** A ready or already queued draft-inclusive capture of the current kept site. No content is saved. */
    public function reusableDraftPreview(ProfileManifest $profile, \DateTimeImmutable $now): ?PreviewStatus;
}

interface EditorPreviewFilesRepository
{
    public function create(string $previewId, string $sessionId, string $tokenHash, \DateTimeImmutable $now, \DateTimeImmutable $expiresAt): void;

    /** The original ready build, only while the creating editorial session is still authorized. */
    public function authorize(string $previewId, string $tokenHash, \DateTimeImmutable $now): ?string;
}

final readonly class EditorPreviewPage
{
    public function __construct(public string $path, public bool $home)
    {
        if (!CompiledRoute::validRoute($path)) {
            throw new \InvalidArgumentException('Editor preview page is invalid');
        }
    }
}

/** Signed-in newest resolution is separate from guest sharing and never creates a share link. */
final readonly class EditorExactPreview
{
    public function __construct(private PreviewService $previews, private EditorPreviewRepository $repository, private EditorPreviewFilesRepository $files, private ProfileManifest $profile, private Clock $clock, private RandomSource $random) {}

    public function open(string $actorId): PreviewStatus
    {
        return $this->repository->reusableDraftPreview($this->profile, $this->clock->now()) ?? $this->previews->create($actorId, true);
    }

    public function resolve(?string $waiting = null): ?PreviewStatus
    {
        $ready = $this->repository->latestReadyPreview();
        $requested = $waiting === null ? null : $this->previews->find($waiting);
        if ($requested === null || !$requested->includeDrafts) {
            return $ready;
        }
        // A newer ready capture may satisfy an older pending request, but never show an older site while waiting.
        if ($ready !== null && ($ready->createdAt > $requested->createdAt || ($ready->createdAt == $requested->createdAt && strcmp($ready->id, $requested->id) >= 0))) {
            return $ready;
        }
        return $requested;
    }

    public function hasPage(string $typeId, string $recordId, string $locale): ?bool
    {
        $preview = $this->repository->latestReadyPreview();
        return $preview === null || !$this->repository->capturedRecord($preview->id, $recordId, $this->profile)
            ? null : !$this->page($preview, $typeId, $recordId, $locale)->home;
    }

    public function page(PreviewStatus $preview, string $typeId, string $recordId, string $locale): EditorPreviewPage
    {
        $artifact = $this->previews->artifact($preview->id, 'artifact.json');
        $manifest = $artifact === null ? null : json_decode($artifact->contents, true, 64, JSON_THROW_ON_ERROR);
        $identities = is_array($manifest) && is_array($manifest['routeIdentities'] ?? null) ? $manifest['routeIdentities'] : [];
        foreach (['record:' . $recordId, 'page:' . $typeId] as $group) {
            foreach ($identities as $route) {
                if (is_array($route) && ($route['locale'] ?? null) === $locale && ($route['translationGroup'] ?? null) === $group
                    && is_string($route['path'] ?? null) && CompiledRoute::validRoute($route['path'])) {
                    return new EditorPreviewPage($route['path'], false);
                }
            }
        }
        foreach (['/' . $locale . '/', '/'] as $path) {
            if ($this->previews->artifact($preview->id, ltrim($path, '/') . 'index.html') !== null) {
                return new EditorPreviewPage($path, true);
            }
        }
        throw new PreviewNotFound();
    }

    public function files(PreviewStatus $preview, SessionContext $actor): string
    {
        if (!$actor->editorial() || $preview->status !== 'ready' || !$preview->includeDrafts || $preview->buildKind !== 'website') {
            throw new PreviewNotFound();
        }
        $token = bin2hex($this->random->bytes(32));
        $now = $this->clock->now();
        $this->files->create($preview->id, $actor->id, hash('sha256', $token), $now, PreviewFilePolicy::expiresAt($now));
        return (new PreviewFileHttp($this->previews, 'editor-preview-files'))->base($preview->id, $token);
    }

    public function serveFiles(\Utsuri\PortableCms\Conventional\Http\Request $request, \Utsuri\PortableCms\Conventional\I18n\Translator $translator): \Utsuri\PortableCms\Conventional\Http\Response
    {
        return (new PreviewFileHttp($this->previews, 'editor-preview-files'))->serve(
            $request,
            $translator,
            fn(string $id, string $token, string $remote): ?string => $this->files->authorize($id, hash('sha256', $token), $this->clock->now()),
        );
    }
}
