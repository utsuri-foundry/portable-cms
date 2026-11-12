<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Compiler;

use Utsuri\PortableCms\Conventional\Content\EffectiveSearchSharing;
use Utsuri\PortableCms\Conventional\Media\MediaText;

/** Standard head fragment; presentation owns its fonts, styles, scripts and document wrapper. */
final class DocumentHead
{
    public static function render(
        string $path,
        string $locale,
        string $siteName,
        EffectiveSearchSharing $metadata,
        ?RouteDiscovery $discovery = null,
        ?SharingImage $sharingImage = null,
        ?StructuredData $structured = null,
        ?WebsiteIcon $icon = null,
        ?string $packagedSvgIcon = null,
    ): string {
        if (!CompiledRoute::validRoute($path) || preg_match('/\A[a-z]{2,3}(?:-[A-Z]{2})?\z/', $locale) !== 1) {
            throw new \InvalidArgumentException('Document head route or locale is invalid');
        }
        if ($discovery !== null && $discovery->route($path)->locale !== $locale) {
            throw new \InvalidArgumentException('Document head locale differs from discovery');
        }
        $text = $sharingImage->text ?? MediaText::from();
        return '<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . ($structured?->policyMeta() ?? '')
            . SearchSharingHead::render($metadata, $siteName, $sharingImage, $discovery?->context, $text->localized($locale, $sharingImage?->defaultLocale ?: $locale)['alternativeText'], $text->decorative)
            . ($discovery?->head($path) ?? '')
            . ($structured?->html() ?? '')
            . ($icon?->head($path) ?? ($packagedSvgIcon === null ? '' : '<link rel="icon" href="' . ArtifactUrl::escaped($packagedSvgIcon, $path) . '" type="image/svg+xml">'));
    }
}
