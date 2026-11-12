<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\View;

use Utsuri\PortableCms\Conventional\Http\Html;
use Utsuri\PortableCms\Conventional\I18n\Translator;
use Utsuri\PortableCms\Conventional\Media\LibraryMedia;

/** Administration presentation only; frame hints never become authored posters. */
final class VideoPreview
{
    public static function ready(LibraryMedia $media): bool
    {
        return $media->status === 'ready' && $media->thumbnail?->variant === 'prepared-video' && in_array($media->mime, ['video/mp4', 'video/webm'], true);
    }

    public static function duration(LibraryMedia $media, Translator $t): string
    {
        $milliseconds = $media->thumbnail?->parameters['durationMilliseconds'] ?? null;
        if (!is_int($milliseconds) || $milliseconds < 1) {
            return $t->text('video.duration_unknown');
        }
        $seconds = (int) ceil($milliseconds / 1000);
        return $seconds >= 3600 ? sprintf('%d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60)
            : sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
    }

    public static function thumbnail(LibraryMedia $media, bool $capture = false, ?LibraryMedia $poster = null): string
    {
        $cached = $media->videoFrame !== null;
        $fallback = $poster?->status === 'ready' && $poster->thumbnail?->mime === 'image/webp';
        $source = $cached ? '/media/' . $media->id . '/thumbnail' : ($fallback ? '/media/' . $poster->id . '/thumbnail' : null);
        return '<span class="admin-thumbnail admin-video-thumbnail" data-video-thumbnail data-media-id="' . $media->id . '" data-video-source="' . Html::url('/media/' . $media->id . '/video') . '" data-video-checksum="' . Html::attribute($media->thumbnail->checksum ?? '') . '" data-video-capture="' . ($capture && !$cached && self::ready($media) ? 'auto' : 'none') . '">'
            . '<img class="admin-thumbnail" data-video-frame-image' . ($source === null ? ' hidden' : ' src="' . Html::url($source) . '"') . ' width="80" height="64" alt="" loading="lazy">'
            . '<span data-video-frame-fallback aria-hidden="true"' . ($source === null ? '' : ' hidden') . '>▶</span></span>';
    }

    public static function player(?LibraryMedia $media, ?LibraryMedia $poster = null, string $preload = 'none'): string
    {
        $ready = $media !== null && self::ready($media);
        $image = $poster?->status === 'ready' && $poster->thumbnail?->mime === 'image/webp' ? '/media/' . $poster->id . '/thumbnail' : null;
        return '<video class="admin-video-player" data-video-player controls muted preload="' . ($preload === 'metadata' ? 'metadata' : 'none') . '"'
            . ($ready ? ' src="' . Html::url('/media/' . $media->id . '/video') . '" aria-label="' . Html::attribute($media->name) . '" data-media-id="' . $media->id . '" data-video-checksum="' . Html::attribute($media->thumbnail->checksum) . '" width="' . $media->thumbnail->width . '" height="' . $media->thumbnail->height . '"' : ' hidden')
            . ($image === null ? '' : ' poster="' . Html::url($image) . '"') . '></video>';
    }
}
