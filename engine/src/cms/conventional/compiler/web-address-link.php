<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Compiler;

use Utsuri\PortableCms\Conventional\Content\WebAddress;
use Utsuri\PortableCms\Conventional\Http\Html;
use Utsuri\PortableCms\Conventional\View\TrustedHtml;

/** Profiles receive an escaped link, never permission to print a raw address. */
final class WebAddressLink
{
    public static function render(mixed $value): TrustedHtml
    {
        if ($value === null) {
            return TrustedHtml::fromEngineMarkup('');
        }
        $address = WebAddress::from($value);
        return TrustedHtml::fromEngineMarkup('<a href="' . Html::attribute($address->url) . '">' . Html::text(trim($address->linkText) === '' ? $address->url : $address->linkText) . '</a>');
    }
}
