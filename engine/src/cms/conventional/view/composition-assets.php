<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\View;

use Utsuri\PortableCms\Conventional\Content\RecordTypeDefinition;
use Utsuri\PortableCms\Conventional\Content\RichText;
use Utsuri\PortableCms\Conventional\Http\Html;
use Utsuri\PortableCms\Conventional\I18n\InvalidInput;

final readonly class CompositionMedia
{
    public function __construct(public string $url, public int $width, public int $height, public string $alt = '', public string $mime = 'image/webp', public string $caption = '', public string $credit = '', public \Utsuri\PortableCms\Conventional\Media\FocalPoint $focalPoint = new \Utsuri\PortableCms\Conventional\Media\FocalPoint())
    {
        if (!str_starts_with($url, '/') || str_starts_with($url, '//') || $width < 1 || $height < 1 || mb_strlen($alt) > 500
            || mb_strlen($caption) > 500 || mb_strlen($credit) > 500
            || !in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/avif', 'video/mp4', 'video/webm'], true)) {
            throw new InvalidInput('validation.layout_asset.composition_media_metadata_is_invalid');
        }
        Html::url($url);
    }
}

/** Shared allowlisted semantic content renderer; adapter supplies only verified media facts. */
final class CompositionAssets
{
    /** @param array<string, mixed> $body
     * @param \Closure(string): ?CompositionMedia $media
     * @param array{kind: string, id?: string, field?: string, link?: array{typeId: string, recordId: string}, captions?: array<string, string>} $content
     * @param null|\Closure(\Utsuri\PortableCms\Conventional\Content\RecordReference): ?string $link
     */
    public static function render(array $content, RecordTypeDefinition $type, array $body, string $locale, \Closure $media, ?string $artifactRoute = null, ?\Closure $link = null, ?string $defaultLocale = null): string
    {
        if ($content['kind'] === 'media') {
            $image = $media($content['id'] ?? '');
            if ($image === null && isset($content['link'])) {
                throw new InvalidInput('validation.layout.image_not_ready');
            }
            $caption = \Utsuri\PortableCms\Conventional\Content\Composition::caption($content, $locale, $defaultLocale ?? $locale, $image->caption ?? '');
            $url = null;
            if (isset($content['link'])) {
                $url = $link === null ? null : $link(\Utsuri\PortableCms\Conventional\Content\RecordReference::from($content['link']));
                if ($url === null || !str_starts_with($url, '/') || str_starts_with($url, '//')) {
                    throw new InvalidInput('composition.link_unavailable');
                }
                \Utsuri\PortableCms\Conventional\Compiler\ArtifactUrl::relative($url, '/');
                if ($image?->alt === '' && trim($caption) === '') {
                    throw new InvalidInput('composition.link_name_required');
                }
            }
            return $image === null ? '<p>Image not ready.</p>' : self::image($image, $artifactRoute, $caption, $url);
        }
        foreach ($type->fields as $field) {
            if ($field->id !== ($content['field'] ?? '')) {
                continue;
            }
            $raw = $body[$field->id] ?? null;
            if ($field->type === 'video_reference') {
                $raw = \Utsuri\PortableCms\Conventional\Content\EditorialControls::structured($raw);
            }
            $value = $field->localized && is_array($raw) ? ($raw[$locale] ?? null) : $raw;
            if ($content['kind'] === 'rich_text') {
                return RichText::from($value ?? ['formatVersion' => 1, 'nodes' => []], $field)->html($artifactRoute);
            }
            if ($content['kind'] === 'block') {
                return '<p>' . nl2br(Html::text(is_string($value) ? $value : ''), false) . '</p>';
            }
            if ($content['kind'] === 'video') {
                if (!is_array($value) || !is_string($value['mediaId'] ?? null) || !is_string($value['posterId'] ?? null)
                    || array_diff(array_keys($value), ['mediaId', 'posterId']) !== []) {
                    throw new InvalidInput('validation.layout_asset.select_a_prepared_video_and_a');
                }
                $video = $media($value['mediaId']);
                $poster = $media($value['posterId']);
                if ($video === null || $poster === null || !str_starts_with($video->mime, 'video/') || !str_starts_with($poster->mime, 'image/')) {
                    throw new InvalidInput('validation.layout_asset.prepared_video_and_poster_are_unavailable');
                }
                return '<video controls preload="none" poster="' . self::url($poster->url, $artifactRoute) . '" width="' . $video->width . '" height="' . $video->height . '"><source src="' . self::url($video->url, $artifactRoute) . '" type="' . Html::attribute($video->mime) . '"></video>';
            }
        }
        throw new InvalidInput('validation.layout_asset.composition_text_source_is_unavailable');
    }

    private static function image(CompositionMedia $image, ?string $artifactRoute, string $caption, ?string $link): string
    {
        if (!str_starts_with($image->mime, 'image/')) {
            throw new InvalidInput('validation.layout_asset.select_an_image_source');
        }
        $markup = '<img class="' . \Utsuri\PortableCms\Conventional\Compiler\MediaFocalStyle::className($image->focalPoint) . '" src="' . self::url($image->url, $artifactRoute) . '" width="' . $image->width . '" height="' . $image->height . '" alt="' . Html::attribute($image->alt) . '" loading="lazy">';
        if ($link !== null) {
            $markup = '<a href="' . self::url($link, $artifactRoute) . '"' . ($image->alt === '' ? ' aria-label="' . Html::attribute($caption) . '"' : '') . '>' . $markup . '</a>';
        }
        return $caption === '' && $image->credit === '' ? $markup : '<figure>' . $markup . '<figcaption>' . Html::text($caption)
            . ($image->credit === '' ? '' : '<span class="cms-media-credit">' . ($caption === '' ? '' : ' · ') . Html::text($image->credit) . '</span>') . '</figcaption></figure>';
    }

    private static function url(string $url, ?string $artifactRoute): string
    {
        return $artifactRoute === null ? Html::url($url) : \Utsuri\PortableCms\Conventional\Compiler\ArtifactUrl::escaped($url, $artifactRoute);
    }
}
