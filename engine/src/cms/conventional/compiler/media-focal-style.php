<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Compiler;

use Utsuri\PortableCms\Conventional\Media\FocalPoint;

/** Safe renderer values and an external stylesheet, without editor-authored CSS. */
final class MediaFocalStyle
{
    public static function position(FocalPoint $point): string
    {
        return $point->x . '% ' . $point->y . '%';
    }

    public static function className(FocalPoint $point): string
    {
        return 'cms-focal-' . $point->x . '-' . $point->y;
    }

    /** @param list<FocalPoint> $points */
    public static function stylesheet(array $points): string
    {
        $rules = [];
        foreach ([new FocalPoint(), ...$points] as $point) {
            $rules[self::className($point)] = '{object-position:' . self::position($point) . '}';
        }
        ksort($rules, SORT_STRING);
        $css = '';
        foreach ($rules as $class => $rule) {
            $css .= '.' . $class . $rule . "\n";
        }
        return $css;
    }
}
