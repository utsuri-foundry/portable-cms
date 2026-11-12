<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Content;

use Utsuri\PortableCms\Conventional\Http\Request;

/** Lossless projection of native controls; validation belongs to WebAddress. */
final class WebAddressForm
{
    /** @param list<string> $locales
     * @return array<string, mixed>|null
     */
    public static function input(Request $request, string $name, bool $localized, array $locales): ?array
    {
        $values = [];
        foreach ($localized ? $locales : [''] as $locale) {
            $prefix = $name . ($locale === '' ? '' : '__' . $locale);
            $url = $request->formValue($prefix . '__url');
            $text = $request->formValue($prefix . '__linkText');
            $values[$locale] = $url === '' && $text === '' ? null : ['formatVersion' => 1, 'url' => $url, 'linkText' => $text];
        }
        return $localized ? $values : $values[''];
    }
}
