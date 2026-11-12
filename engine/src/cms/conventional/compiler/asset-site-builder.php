<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Compiler;

/** Optional substitution for the existing build interfaces; all assets enter one stateless build call. */
abstract class AssetSiteBuilder implements WebsiteIconSiteBuilder
{
    abstract protected function sharingSelections(FrozenSnapshot $snapshot): SharingSelections;

    abstract protected function selectedWebsiteIcon(FrozenSnapshot $snapshot): ?string;

    abstract protected function buildSite(FrozenSnapshot $snapshot, ?SharingImages $images, ?WebsiteIcon $icon): ProfileSiteBuild;

    final public function build(FrozenSnapshot $snapshot): ProfileSiteBuild
    {
        return $this->buildSite($snapshot, null, null);
    }

    /** @return list<string> */
    final public function sharingMediaIds(FrozenSnapshot $snapshot): array
    {
        return $this->sharingSelections($snapshot)->mediaIds();
    }

    final public function websiteIconMediaId(FrozenSnapshot $snapshot): ?string
    {
        return $snapshot->websiteIdentity !== null ? $snapshot->websiteIdentity->iconMediaId : $this->selectedWebsiteIcon($snapshot);
    }

    final public function buildWithSharing(FrozenSnapshot $snapshot, SharingImages $images): ProfileSiteBuild
    {
        return $this->buildSite($snapshot, $images, null);
    }

    final public function buildWithAssets(FrozenSnapshot $snapshot, SharingImages $images, ?WebsiteIcon $icon): ProfileSiteBuild
    {
        return $this->buildSite($snapshot, $snapshot->publicUrlContext === null ? null : $images, $icon);
    }
}
