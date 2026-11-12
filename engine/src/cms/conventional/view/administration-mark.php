<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\View;

use Utsuri\PortableCms\Conventional\Http\Html;
use Utsuri\PortableCms\Conventional\Media\AdministrationLogos;

/** Selected logos name the brand; fallback icons accompany the visible website name. */
final class AdministrationMark
{
    public static function render(string $name, ?AdministrationLogos $logos): string
    {
        if ($logos === null) {
            return '<span class="admin-mark" aria-hidden="true">' . Html::text(AdminShell::monogram($name)) . '</span>';
        }
        $alternative = $logos->icon ? '' : $name;
        $markup = '<span class="admin-logo' . ($logos->icon ? ' admin-logo--icon' : '') . ($logos->dark->sha256 === $logos->light->sha256 ? '' : ' admin-logo--paired') . '"' . ($logos->icon ? ' aria-hidden="true"' : '') . '>'
            . '<img class="admin-logo__light" src="' . Html::url($logos->light->url()) . '" alt="' . Html::attribute($alternative) . '" width="' . $logos->light->width . '" height="' . $logos->light->height . '">';
        if ($logos->dark->sha256 !== $logos->light->sha256) {
            $markup .= '<img class="admin-logo__dark" src="' . Html::url($logos->dark->url()) . '" alt="' . Html::attribute($alternative) . '" width="' . $logos->dark->width . '" height="' . $logos->dark->height . '">';
        }
        return $markup . '</span>';
    }
}
