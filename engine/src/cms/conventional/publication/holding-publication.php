<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Publication;

use Utsuri\PortableCms\Conventional\Preview\PreviewService;
use Utsuri\PortableCms\Conventional\Preview\PreviewStatus;

/** Internal holding builds are never listed or served as exact website previews. */
final readonly class HoldingPublication
{
    /** @param null|\Closure(): list<string> $languages The public languages the holding page shows. */
    public function __construct(public WebsitePublicationService $settings, public PreviewService $builds, private ?\Closure $languages = null) {}

    public function create(string $actorId, int $expectedRevision): PreviewStatus
    {
        $current = $this->settings->load();
        if ($current->mode() !== 'coming_soon' || $current->revision() !== $expectedRevision) {
            throw new PublicationConflict();
        }
        return $this->builds->create($actorId);
    }

    public function assertApproval(ReleaseArtifact $artifact): void
    {
        $settings = $this->settings->load();
        if ($artifact->holding() !== ($settings->mode() === 'coming_soon')) {
            throw new PublicationFailure('publication_mode_changed');
        }
        if (!$artifact->holding()) {
            return;
        }
        // The build shows the settings in the public languages; a change of either needs a new build.
        $shown = ($this->languages === null ? $settings : $settings->only(($this->languages)()))->forWebsite();
        if (($artifact->document['websitePublication']['revision'] ?? null) !== $settings->revision()
            || ($artifact->document['websitePublication']['sha256'] ?? null) !== hash('sha256', json_encode($shown->document(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))) {
            throw new PublicationFailure('new_holding_build_required');
        }
    }
}
