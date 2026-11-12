<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\View;

use Utsuri\PortableCms\Conventional\Http\Html;
use Utsuri\PortableCms\Conventional\I18n\Translator;
use Utsuri\PortableCms\Conventional\Preview\NewestPreviewLink;

final class NewestPreviewLinksView
{
    /** @param list<NewestPreviewLink> $links */
    public static function render(string $csrf, array $links, Translator $t, ?string $url = null, ?string $manifest = null): TrustedHtml
    {
        $route = '/previews/shares/newest';
        $token = '<input type="hidden" name="_csrf" value="' . Html::attribute($csrf) . '">';
        $html = '<section class="admin-section" id="newest-preview-links"><h2>' . $t->html('follow.title') . '</h2><p>' . $t->html('follow.help') . '</p>';
        if ($url !== null && $manifest !== null) {
            $html .= '<label for="newest-share-link">' . $t->html('share.link') . '</label><input id="newest-share-link" type="text" readonly value="' . Html::attribute($url) . '"><p>' . $t->html('share.once') . '</p>'
                . '<label for="newest-share-manifest">' . $t->html('follow.manifest') . '</label><input id="newest-share-manifest" type="text" readonly value="' . Html::attribute($manifest) . '"><p>' . $t->html('follow.bearer') . '</p>';
        }
        $html .= '<form method="post" action="' . $route . '">' . $token . '<label for="newest-share-expiry">' . $t->html('share.expiry') . '</label><select id="newest-share-expiry" name="expiry_hours">';
        foreach (['1' => 'share.hour', '24' => 'share.day', '168' => 'share.week', '720' => 'follow.month'] as $hours => $key) {
            $html .= '<option value="' . $hours . '">' . $t->html($key) . '</option>';
        }
        $html .= '</select><button type="submit">' . $t->html('follow.create') . '</button></form><h3>' . $t->html('share.active') . '</h3>';
        foreach ($links as $link) {
            $html .= '<form method="post" action="' . Html::url($route . '/' . $link->id . '/revoke') . '">' . $token
                . '<p>' . $t->html('share.name', ['id' => substr($link->id, 0, 8)]) . '</p><p>' . $t->html('share.expires', ['time' => $link->expiresAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i') . ' UTC']) . '</p><button type="submit" class="admin-button--danger">' . $t->html('share.revoke') . '</button></form>';
        }
        return TrustedHtml::fromEngineMarkup($html . ($links === [] ? '<p>' . $t->html('share.empty') . '</p>' : '') . '</section>');
    }
}
