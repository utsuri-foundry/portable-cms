<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\View;

use Utsuri\PortableCms\Conventional\Http\Html;
use Utsuri\PortableCms\Conventional\I18n\Translator;

/** The pages a reviewer without an account sees: the landing that exchanges the secret, and the viewer with its thin bar. */
final class PreviewSharePage
{
    public static function landing(string $base, Translator $t): string
    {
        return self::document('<main><h1>' . $t->html('share.notice') . '</h1><form method="post" action="' . Html::url($base . 'open') . '" data-share-open><label for="share-token">'
            . $t->html('share.secret') . '</label><input id="share-token" name="token" type="password" required minlength="64" maxlength="64" autocomplete="off"><button type="submit">' . $t->html('share.open') . '</button></form><p>' . $t->html('share.landing_help') . '</p></main>', $t);
    }

    /**
     * The bar says whose site this is, that it is not public, and who shared it; the language choice
     * moves the frame between the preview's languages. The iframe response is the original
     * checksum-verified artifact.
     * @param array<string, string> $languages Locale to label, in the site's order.
     */
    public static function viewer(string $base, Translator $t, string $files, ?string $site = null, ?string $sharer = null, array $languages = []): string
    {
        $site ??= $t->text('share.site_fallback');
        $sentence = $sharer === null ? $t->html('share.bar_anonymous', ['site' => $site]) : $t->html('share.bar', ['site' => $site, 'name' => $sharer]);
        $choice = '';
        if (count($languages) > 1) {
            $choice = '<nav aria-label="' . $t->html('share.language') . '" data-share-languages>';
            foreach ($languages as $locale => $label) {
                $choice .= '<a href="' . Html::url($files . $locale . '/') . '" hreflang="' . Html::attribute($locale) . '" data-share-language="' . Html::attribute($locale) . '">' . Html::text($label) . '</a>';
            }
            $choice .= '</nav>';
        }
        return self::document('<header class="share-bar" data-share-notice><p>' . $sentence . '</p>' . $choice . '</header>'
            . '<iframe data-share-renew="' . Html::url($base . 'renew') . '" title="' . $t->html('share.notice') . '" src="' . Html::url($files) . '" sandbox="allow-scripts"></iframe>', $t);
    }

    private static function document(string $body, Translator $t): string
    {
        return '<!doctype html><html lang="' . Html::attribute($t->locale()) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $t->html('share.notice') . '</title><link rel="stylesheet" href="/assets/preview-share.css?v=2"><script src="/assets/preview-share.js?v=4" defer></script><script type="module" src="/assets/preview-vimeo.js?v=1"></script></head><body>' . $body . '</body></html>';
    }
}
