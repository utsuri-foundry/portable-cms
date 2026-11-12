<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\View;

use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Http\Html;
use Utsuri\PortableCms\Conventional\I18n\Translator;
use Utsuri\PortableCms\Conventional\Preview\PreviewShare;

final class PreviewSharePage
{
    /** @param list<PreviewShare> $shares */
    public static function manage(string $previewId, string $csrf, array $shares, ?string $url, ?ProfileManifest $profile, AdminChrome $chrome): string
    {
        $t = AdminShell::translator($chrome);
        $route = '/previews/' . $previewId . '/shares';
        $csrfInput = '<input type="hidden" name="_csrf" value="' . Html::attribute($csrf) . '">';
        $body = AdminShell::breadcrumbs([[$t->text('preview.list.title'), '/previews'], [$t->text('preview.status.title'), '/previews/' . $previewId], [$t->text('share.title'), null]], $t)
            . AdminShell::header($t->text('share.title')) . '<section class="admin-section"><p>' . $t->html('share.help') . '</p>';
        if ($url !== null) {
            $body .= '<label for="share-link">' . $t->html('share.link') . '</label><input id="share-link" type="text" readonly value="' . Html::attribute($url) . '"><p>' . $t->html('share.once') . '</p>';
        }
        $body .= '<form method="post" action="' . Html::url($route) . '">' . $csrfInput . '<label for="share-expiry">' . $t->html('share.expiry') . '</label><select id="share-expiry" name="expiry_hours">';
        foreach (['1' => 'share.hour', '24' => 'share.day', '168' => 'share.week'] as $value => $key) {
            $body .= '<option value="' . $value . '">' . $t->html($key) . '</option>';
        }
        $body .= '</select><button type="submit">' . $t->html('share.create') . '</button></form></section><section class="admin-section"><h2>' . $t->html('share.active') . '</h2>';
        foreach ($shares as $share) {
            $body .= '<form method="post" action="' . Html::url($route . '/' . $share->id . '/revoke') . '">' . $csrfInput
                . '<p>' . $t->html('share.name', ['id' => substr($share->id, 0, 8)]) . '</p><p>' . $t->html('share.expires', ['time' => $share->expiresAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i') . ' UTC']) . '</p><button type="submit" class="admin-button--danger">' . $t->html('share.revoke') . '</button></form>';
        }
        if ($shares === []) {
            $body .= '<p>' . $t->html('share.empty') . '</p>';
        }
        return AdminShell::application($t->text('share.title'), TrustedHtml::fromEngineMarkup($body . '</section>'), $profile, 'previews', 'standard', $chrome);
    }

    public static function landing(string $base, Translator $t): string
    {
        return self::document('<main><h1>' . $t->html('share.notice') . '</h1><form method="post" action="' . Html::url($base . 'open') . '" data-share-open><label for="share-token">'
            . $t->html('share.secret') . '</label><input id="share-token" name="token" type="password" required minlength="64" maxlength="64" autocomplete="off"><button type="submit">' . $t->html('share.open') . '</button></form><p>' . $t->html('share.landing_help') . '</p></main>', $t);
    }

    public static function viewer(string $base, Translator $t, string $files): string
    {
        // The notice belongs to this shell. The iframe response is the original checksum-verified artifact.
        return self::document('<aside data-share-notice><span>' . $t->html('share.notice') . '</span><button type="button" data-share-dismiss>' . $t->html('share.dismiss') . '</button></aside><iframe data-share-renew="' . Html::url($base . 'renew') . '" title="' . $t->html('share.notice') . '" src="' . Html::url($files) . '" sandbox="allow-scripts"></iframe>', $t);
    }

    private static function document(string $body, Translator $t): string
    {
        return '<!doctype html><html lang="' . Html::attribute($t->locale()) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $t->html('share.notice') . '</title><link rel="stylesheet" href="/assets/preview-share.css"><script src="/assets/preview-share.js?v=3" defer></script><script type="module" src="/assets/preview-vimeo.js?v=1"></script></head><body>' . $body . '</body></html>';
    }
}
