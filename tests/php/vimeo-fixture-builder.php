<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Compiler\CompiledRoute;
use Utsuri\PortableCms\Conventional\Compiler\FrozenSnapshot;
use Utsuri\PortableCms\Conventional\Compiler\ProfileSiteBuild;
use Utsuri\PortableCms\Conventional\Compiler\SharingImages;
use Utsuri\PortableCms\Conventional\Compiler\VimeoPlayer;
use Utsuri\PortableCms\Conventional\Compiler\WebsiteIcon;
use Utsuri\PortableCms\Conventional\Compiler\WebsiteIconSiteBuilder;

/** Reviewed fictional renderer: a field value is read exclusively from the supplied frozen record. */
final readonly class VimeoFixtureBuilder implements WebsiteIconSiteBuilder
{
    public function __construct(private WebsiteIconSiteBuilder $inner) {}

    public function build(FrozenSnapshot $snapshot): ProfileSiteBuild
    {
        return $this->decorate($this->inner->build($snapshot), $snapshot);
    }

    public function sharingMediaIds(FrozenSnapshot $snapshot): array
    {
        return $this->inner->sharingMediaIds($snapshot);
    }

    public function websiteIconMediaId(FrozenSnapshot $snapshot): ?string
    {
        return $this->inner->websiteIconMediaId($snapshot);
    }

    public function buildWithSharing(FrozenSnapshot $snapshot, SharingImages $images): ProfileSiteBuild
    {
        return $this->decorate($this->inner->buildWithSharing($snapshot, $images), $snapshot);
    }

    public function buildWithAssets(FrozenSnapshot $snapshot, SharingImages $images, ?WebsiteIcon $icon): ProfileSiteBuild
    {
        return $this->decorate($this->inner->buildWithAssets($snapshot, $images, $icon), $snapshot);
    }

    private function decorate(ProfileSiteBuild $site, FrozenSnapshot $snapshot): ProfileSiteBuild
    {
        $routes = [];
        $media = $site->publicMediaIds;
        foreach ($site->routes as $route) {
            $identity = $site->discovery?->route($route->route);
            $record = $identity !== null && str_starts_with($identity->translationGroup, 'record:') ? $snapshot->record(substr($identity->translationGroup, 7)) : null;
            $value = $record->body['film'] ?? null;
            $value = is_array($value) && !isset($value['formatVersion']) ? ($value[$identity->locale] ?? null) : $value;
            $html = $route->html;
            if ($value !== null) {
                $poster = $snapshot->media($value['poster']);
                if ($poster === null || $identity === null) {
                    throw new RuntimeException('Frozen Vimeo poster is unavailable');
                }
                $player = VimeoPlayer::render($value, $poster, $route->route, $identity->locale, defaultLocale: $snapshot->defaultLocale)->value;
                $html = str_replace('</main>', $player . '</main>', $html);
                $media[] = $poster->id;
            }
            $routes[] = new CompiledRoute($route->route, $html);
        }
        return new ProfileSiteBuild($routes, $site->searchIndexes, $site->redirects, array_values(array_unique($media)), $site->discovery);
    }
}
