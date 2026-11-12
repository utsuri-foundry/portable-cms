<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Content;

use Utsuri\PortableCms\Conventional\Http\Request;

final class VimeoVideoForm
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
            $poster = $request->formValue($prefix . '__poster');
            $title = $request->formValue($prefix . '__title');
            $values[$locale] = $url === '' && $poster === '' && $title === '' ? null : compact('url', 'poster', 'title');
        }
        return $localized ? $values : $values[''];
    }
}
