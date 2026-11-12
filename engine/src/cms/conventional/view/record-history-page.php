<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\View;

use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Content\RecordHistorySettings;
use Utsuri\PortableCms\Conventional\Export\ExportCapacity;
use Utsuri\PortableCms\Conventional\Http\Html;
use Utsuri\PortableCms\Conventional\I18n\Message;

final class RecordHistoryPage
{
    public static function render(ProfileManifest $profile, AdminChrome $chrome, RecordHistorySettings $settings, int $retained, int $limit, ?int $preview = null, ?Message $error = null, bool $saved = false): string
    {
        $t = $chrome->translator();
        $csrf = '<input type="hidden" name="_csrf" value="' . Html::attribute($chrome->csrfToken) . '">';
        $capacity = ExportCapacity::limit('revisions');
        $body = AdminShell::header($t->text('history.title'), '<p class="admin-lede">' . $t->html('history.help') . '</p>')
            . ($error === null ? '' : '<p class="admin-notice admin-notice--danger" role="alert">' . Html::text($t->message($error)) . '</p>')
            . ($saved ? '<p role="status">' . $t->html('history.saved') . '</p>' : '')
            . '<section class="admin-card"><p>' . $t->html('history.capacity', ['count' => $retained, 'capacity' => $capacity]) . '</p>'
            . ($retained * 10 < $capacity * 9 ? '' : '<p class="admin-notice admin-notice--warning" role="status">' . $t->html('history.warning') . '</p>')
            . '<p><a href="/exports">' . $t->html('shell.nav.export') . '</a></p></section>';
        if ($settings->pending) {
            $body .= '<section class="admin-card"><p role="status">' . $t->html('history.pending') . '</p><form method="post" action="/settings/history">' . $csrf
                . '<input type="hidden" name="_action" value="continue"><button type="submit">' . $t->html('history.continue') . '</button></form></section>';
        }
        $body .= '<form class="admin-card admin-form" method="post" action="/settings/history">' . $csrf
            . '<input type="hidden" name="_revision" value="' . $settings->revision . '">';
        if ($preview !== null) {
            $body .= '<p>' . $t->html('history.confirm', ['limit' => $limit, 'count' => $preview]) . '</p><p>' . $t->html('history.backup') . '</p>'
                . '<input type="hidden" name="limit" value="' . $limit . '"><input type="hidden" name="removal_count" value="' . $preview . '">'
                . '<label><input type="checkbox" name="confirmed" value="1" required>' . $t->html('history.accept') . '</label>'
                . '<button type="submit" class="admin-button--danger">' . $t->html('history.apply') . '</button><a href="/settings/history">' . $t->html('history.cancel') . '</a>';
        } else {
            $body .= '<label for="history-limit">' . $t->html('history.limit') . '</label><input id="history-limit" name="limit" type="number" min="10" max="500" required value="' . $limit . '" aria-describedby="history-policy">'
                . '<p id="history-policy" class="admin-help">' . $t->html('history.policy') . '</p><button type="submit">' . $t->html('history.save') . '</button>';
        }
        return AdminShell::application($t->text('history.title'), TrustedHtml::fromEngineMarkup($body . '</form>'), $profile, 'history', 'standard', $chrome);
    }
}
