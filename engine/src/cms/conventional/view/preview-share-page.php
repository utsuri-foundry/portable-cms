<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\View;

use Utsuri\PortableCms\Conventional\Http\Html;
use Utsuri\PortableCms\Conventional\I18n\Translator;

/** The pages a reviewer without an account sees: the landing that exchanges the secret, and the viewer, a frame filling the window. */
final class PreviewSharePage
{
    public static function landing(string $base, Translator $t): string
    {
        return self::document('<main><h1>' . $t->html('share.notice') . '</h1><form method="post" action="' . Html::url($base . 'open') . '" data-share-open><label for="share-token">'
            . $t->html('share.secret') . '</label><input id="share-token" name="token" type="password" required minlength="64" maxlength="64" autocomplete="off"><button type="submit">' . $t->html('share.open') . '</button></form><p>' . $t->html('share.landing_help') . '</p></main>', $t);
    }

    /**
     * The preview fills the window with nothing above it: the browser tab carries the private preview title and
     * visitors change language through the site's own links. The iframe response is the original checksum-verified artifact.
     */
    public static function viewer(string $base, Translator $t, string $files): string
    {
        return self::document('<iframe data-share-renew="' . Html::url($base . 'renew') . '" title="' . $t->html('share.notice') . '" src="' . Html::url($files) . '" sandbox="allow-scripts"></iframe>', $t);
    }

    private static function document(string $body, Translator $t): string
    {
        return '<!doctype html><html lang="' . Html::attribute($t->locale()) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $t->html('share.notice') . '</title><link rel="stylesheet" href="/assets/preview-share.css?v=3"><script src="/assets/preview-share.js?v=5" defer></script><script type="module" src="/assets/preview-vimeo.js?v=1"></script></head><body>' . $body . '</body></html>';
    }
}
