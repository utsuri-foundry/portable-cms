<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Media;

use Utsuri\PortableCms\Conventional\Http\Response;
use Utsuri\PortableCms\Conventional\Publication\AdministrationBranding;
use Utsuri\PortableCms\Conventional\Publication\WebsitePublication;

final readonly class AdministrationLogo
{
    public function __construct(public string $mediaId, public string $sha256, public int $width, public int $height) {}

    public function url(): string
    {
        return '/administration-logo/' . $this->sha256 . '.webp';
    }
}

final readonly class AdministrationLogos
{
    public function __construct(public AdministrationLogo $light, public AdministrationLogo $dark, public bool $icon) {}
}

/** The only anonymous media boundary: currently selected, bounded, verified raster derivatives. */
final readonly class AdministrationLogoDelivery
{
    public function __construct(private MediaLibrary $media) {}

    public function resolve(WebsitePublication $settings): ?AdministrationLogos
    {
        $light = $this->logo($settings->administrationBranding->logoMediaId);
        $icon = $light === null;
        if ($light === null) {
            $light = $this->logo($settings->identity()->iconMediaId, true);
        }
        return $light === null ? null : new AdministrationLogos($light, $icon ? $light : ($this->logo($settings->administrationBranding->darkLogoMediaId) ?? $light), $icon);
    }

    public function serve(WebsitePublication $settings, string $sha256): Response
    {
        $logos = $this->resolve($settings);
        foreach ($logos === null ? [] : [$logos->light, $logos->dark] as $logo) {
            if (!hash_equals($logo->sha256, $sha256)) {
                continue;
            }
            try {
                $bytes = $this->media->thumbnail($logo->mediaId);
                if ($bytes !== null && strlen($bytes) <= AdministrationBranding::MAX_BYTES && hash_equals($sha256, hash('sha256', $bytes))) {
                    return (new Response(200, $bytes))->withHeader('content-type', 'image/webp')->withHeader('cross-origin-resource-policy', 'same-origin');
                }
            } catch (MediaProcessingFailure) {
                // Missing, changed or unsafe files are unavailable, never a route to originals.
            }
        }
        return new Response(404);
    }

    private function logo(?string $id, bool $icon = false): ?AdministrationLogo
    {
        $media = $id === null ? null : $this->media->find($id);
        $face = $media?->thumbnail;
        return $media !== null && $face !== null && $face->variant === '960-webp' && $face->mime === 'image/webp'
            && (!$icon || \Utsuri\PortableCms\Conventional\Content\WebsiteIconSelection::eligible($media->status, $media->mime, $media->width, $media->height))
            && preg_match('/\A[a-f0-9]{64}\z/', $face->checksum) === 1
            && AdministrationBranding::eligible($media->status, $media->mime, $face->width, $face->height, $face->byteSize)
            ? new AdministrationLogo($media->id, $face->checksum, $face->width, $face->height) : null;
    }
}
