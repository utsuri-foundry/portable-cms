<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Compiler;

use Utsuri\PortableCms\Conventional\Content\EffectiveSearchSharing;
use Utsuri\PortableCms\Conventional\Http\Html;

/** Only escaped metadata, never editor-authored head markup or scripts. */
final class SearchSharingHead
{
    public static function render(EffectiveSearchSharing $metadata, string $siteName, ?SharingImage $image = null, ?PublicUrlContext $context = null, string $alternativeText = '', bool $decorative = false): string
    {
        if ($image !== null && ($context === null || (!$decorative && trim($alternativeText) === '') || ($decorative && $alternativeText !== '') || mb_strlen($alternativeText) > 500)) {
            throw new \InvalidArgumentException('Sharing image requires a public URL and bounded alternative text');
        }
        $imageHead = '';
        if ($image !== null && $context !== null) {
            $url = $context->asset($image->path);
            $imageHead = self::meta('property', 'og:image', $url)
                . self::meta('property', 'og:image:secure_url', $url)
                . self::meta('property', 'og:image:type', 'image/jpeg')
                . self::meta('property', 'og:image:width', '1200')
                . self::meta('property', 'og:image:height', '630')
                . self::meta('property', 'og:image:alt', $alternativeText)
                . self::meta('name', 'twitter:image', $url)
                . self::meta('name', 'twitter:image:alt', $alternativeText);
        }
        return '<title>' . Html::text($metadata->searchTitle) . '</title>'
            . self::meta('name', 'description', $metadata->description)
            . self::meta('property', 'og:title', $metadata->sharingTitle)
            . self::meta('property', 'og:description', $metadata->sharingDescription)
            . self::meta('property', 'og:site_name', $siteName)
            . $imageHead
            . self::meta('name', 'twitter:card', $image === null ? 'summary' : 'summary_large_image')
            . self::meta('name', 'twitter:title', $metadata->sharingTitle)
            . self::meta('name', 'twitter:description', $metadata->sharingDescription);
    }

    private static function meta(string $attribute, string $name, string $value): string
    {
        return $value === '' ? '' : '<meta ' . $attribute . '="' . Html::attribute($name)
            . '" content="' . Html::attribute($value) . '">';
    }
}
