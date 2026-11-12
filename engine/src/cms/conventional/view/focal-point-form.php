<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\View;

use Utsuri\PortableCms\Conventional\Http\Html;
use Utsuri\PortableCms\Conventional\I18n\InvalidInput;
use Utsuri\PortableCms\Conventional\I18n\Translator;
use Utsuri\PortableCms\Conventional\Media\FocalPoint;
use Utsuri\PortableCms\Conventional\Media\LibraryMedia;

final class FocalPointForm
{
    /** @param array{x: string, y: string, reset: bool} $input */
    public static function point(array $input): FocalPoint
    {
        if ($input['reset']) {
            return new FocalPoint();
        }
        if (preg_match('/\A[0-9]{1,3}\z/', $input['x']) !== 1 || preg_match('/\A[0-9]{1,3}\z/', $input['y']) !== 1) {
            throw new InvalidInput('media.focal.invalid');
        }
        return new FocalPoint((int) $input['x'], (int) $input['y']);
    }

    /** @param ?array{x: string, y: string, reset: bool} $submitted */
    public static function render(LibraryMedia $media, Translator $t, ?array $submitted = null): string
    {
        if (!str_starts_with($media->mime, 'image/')) {
            return '';
        }
        $input = $submitted ?? ['x' => (string) $media->focalPoint->x, 'y' => (string) $media->focalPoint->y, 'reset' => false];
        try {
            $point = self::point($input);
        } catch (InvalidInput) {
            $point = $media->focalPoint;
        }
        $html = '<link rel="stylesheet" href="/assets/focal-point.css?v=1"><fieldset class="admin-section admin-focal" data-focal-point data-messages="' . $t->bundleAttribute('client') . '"><legend>' . $t->html('media.focal.title') . '</legend>'
            . '<input type="hidden" name="_focal_point" value="1"><p id="focal-help" class="admin-help">' . $t->html('media.focal.help') . '</p>';
        if ($media->status === 'ready' && $media->thumbnail?->mime === 'image/webp') {
            $url = Html::url('/media/' . $media->id . '/thumbnail');
            $width = $media->thumbnail->width;
            $height = $media->thumbnail->height;
            $html .= '<div class="admin-focal__surface" data-focal-surface><img src="' . $url . '" width="' . $width . '" height="' . $height . '" alt="">'
                . '<svg viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true"><circle data-focal-marker cx="' . $point->x . '" cy="' . $point->y . '" r="2"></circle></svg>'
                . '<button type="button" class="admin-focal__pointer" data-focal-pointer hidden aria-label="' . $t->html('media.focal.pick') . '" aria-describedby="focal-help"></button></div>'
                . '<p class="admin-help">' . $t->html('media.focal.result') . '</p><svg class="admin-focal__crop" viewBox="0 0 400 240" role="img" aria-label="' . $t->html('media.focal.result') . '">'
                . '<image data-focal-crop href="' . $url . '" data-width="' . $width . '" data-height="' . $height . '" ' . self::crop($point, $width, $height) . '></image></svg>';
        }
        foreach (['x' => 'media.focal.horizontal', 'y' => 'media.focal.vertical'] as $axis => $key) {
            $html .= '<div class="admin-field"><label for="focal-' . $axis . '">' . $t->html($key) . '</label><input id="focal-' . $axis . '" name="focal_' . $axis . '" type="number" min="0" max="100" step="1" required value="' . Html::attribute($input[$axis]) . '"></div>';
        }
        return $html . '<output data-focal-output for="focal-x focal-y">' . $t->html('client.focal.position', ['x' => $point->x, 'y' => $point->y]) . '</output>'
            . '<label><input type="checkbox" name="focal_reset" value="1" data-focal-reset' . ($input['reset'] ? ' checked' : '') . '> ' . $t->html('media.focal.reset') . '</label></fieldset>';
    }

    private static function crop(FocalPoint $point, int $width, int $height): string
    {
        $scale = max(400 / $width, 240 / $height);
        $values = ['x' => (400 - $width * $scale) * $point->x / 100, 'y' => (240 - $height * $scale) * $point->y / 100, 'width' => $width * $scale, 'height' => $height * $scale];
        $attributes = '';
        foreach ($values as $name => $value) {
            $attributes .= $name . '="' . \Utsuri\PortableCms\Conventional\Design\SizeCurve::number($value) . '" ';
        }
        return $attributes;
    }
}
