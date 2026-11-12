<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Compiler;

use Utsuri\PortableCms\Conventional\Content\VimeoVideo;
use Utsuri\PortableCms\Conventional\Http\Html;
use Utsuri\PortableCms\Conventional\I18n\InvalidInput;
use Utsuri\PortableCms\Conventional\View\TrustedHtml;

/** Standard accessible markup over a frozen value and a verified, independently authored poster. */
final class VimeoPlayer
{
    public static function render(mixed $value, FrozenMedia $poster, string $route, string $locale, string $play = 'Play', ?string $defaultLocale = null, bool $scripts = true): TrustedHtml
    {
        $video = VimeoVideo::from($value);
        if ($poster->id !== $video->poster || !str_starts_with($poster->mime, 'image/') || $poster->width === null || $poster->height === null || $poster->width < 1 || $poster->height < 1) {
            throw new InvalidInput('vimeo.poster_invalid');
        }
        if (trim($play) === '' || mb_strlen($play) > 80 || preg_match('/[\x00-\x1f\x7f]/u', $play) !== 0) {
            throw new \InvalidArgumentException('Vimeo play wording is invalid');
        }
        $name = $play . ' ' . $video->title;
        $text = ($poster->text ?? \Utsuri\PortableCms\Conventional\Media\MediaText::from())->localized($locale, $defaultLocale ?? $locale);
        $link = '<a class="cms-vimeo__link" href="' . Html::url($video->pageUrl()) . '" target="_blank" rel="noopener">' . Html::text($name) . '</a>';
        return TrustedHtml::fromEngineMarkup('<div class="cms-vimeo"' . ($scripts ? ' data-vimeo-video data-vimeo-id="' . $video->videoId . '" data-vimeo-hash="' . Html::attribute($video->privacyHash ?? '') . '" data-vimeo-title="' . Html::attribute($video->title) . '"' : '') . '>'
            . '<img src="' . ArtifactUrl::escaped('/' . FilesystemMediaSource::publicPath($poster), $route) . '" width="' . $poster->width . '" height="' . $poster->height . '" alt="' . Html::attribute($text['alternativeText']) . '" loading="lazy">'
            . ($scripts ? '<button class="cms-vimeo__play" type="button" data-vimeo-play hidden>' . Html::text($name) . '</button>' . $link : $link) . '</div>');
    }
}
