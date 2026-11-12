<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Compiler;

use Utsuri\PortableCms\Conventional\I18n\InvalidInput;
use Utsuri\PortableCms\Conventional\Media\MediaText;

/** Validate images actually emitted by reviewed profile renderers, including cards and layout cells. */
final class MediaPublicationValidation
{
    public static function assertSite(ProfileSiteBuild $site, FrozenSnapshot $snapshot): void
    {
        foreach ($site->routes as $route) {
            preg_match('/<html\b[^>]*\slang="([^"]+)"/i', $route->html, $language);
            $locale = $language[1] ?? $snapshot->defaultLocale;
            $images = [];
            foreach ($snapshot->media as $media) {
                if (!str_starts_with($media->mime, 'image/')) {
                    continue;
                }
                foreach ($media->renditions() as $rendition) {
                    $path = '/' . FilesystemMediaSource::publicPath($rendition);
                    $images[$path] = $media;
                    $images[ArtifactUrl::relative($path, $route->route)] = $media;
                }
            }
            preg_match_all('/<img\b(?:[^\'"<>]|"[^"]*"|\'[^\']*\')*>/i', $route->html, $tags);
            foreach ($tags[0] as $tag) {
                preg_match_all('/(?:\A|,)\s*([^\s,]+)/', self::attribute($tag, 'srcset') ?? '', $candidates);
                foreach (array_unique([self::attribute($tag, 'src') ?? '', ...$candidates[1]]) as $url) {
                    $media = $images[$url] ?? null;
                    if ($media === null) {
                        continue;
                    }
                    $text = $media->text ?? MediaText::from();
                    if (!$text->publishable($locale, $snapshot->defaultLocale)
                        || self::attribute($tag, 'alt') !== $text->localized($locale, $snapshot->defaultLocale)['alternativeText']) {
                        throw new InvalidInput('preflight.issue.media_text', ['route' => $route->route, 'id' => substr($media->id, 0, 8), 'locale' => $locale]);
                    }
                }
            }
        }
    }

    private static function attribute(string $tag, string $name): ?string
    {
        if (preg_match('/\s' . $name . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $tag, $value, PREG_UNMATCHED_AS_NULL) !== 1) {
            return null;
        }
        return html_entity_decode($value[1] ?? $value[2] ?? $value[3] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
