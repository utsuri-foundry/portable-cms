<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\View;

use Utsuri\PortableCms\Conventional\Content\LanguageTextDeletion;
use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Http\Html;
use Utsuri\PortableCms\Conventional\I18n\Message;

final class LanguageTextDeletionPage
{
    /**
     * @param string|null $locale The language, or null when the address names none the site has.
     * @param array{records: int, media: int}|null $affected What a new deletion would change.
     * @param LanguageTextDeletion|null $deletion The running deletion, or the one just finished.
     */
    public static function render(ProfileManifest $profile, AdminChrome $chrome, ?string $locale, string $language, ?string $state, ?array $affected, ?LanguageTextDeletion $deletion, bool $owner, ?Message $error = null): string
    {
        $t = $chrome->translator();
        $title = $locale === null ? $t->text('language_text.title_any') : $t->text('language_text.title', ['language' => $language]);
        $body = AdminShell::header($title, '<p><a href="/settings/languages">' . $t->html('language_text.back') . '</a></p>');
        if ($error !== null) {
            $body .= '<section class="admin-notice admin-notice--danger" role="alert"><h2>' . $t->html('language_text.not_deleted') . '</h2><p>' . Html::text($t->message($error)) . '</p></section>';
        }
        $csrf = '<input type="hidden" name="_csrf" value="' . Html::attribute($chrome->csrfToken) . '">';
        if ($deletion !== null && $deletion->state === 'completed') {
            $body .= '<div class="admin-notice admin-notice--success" role="status"><p>' . $t->html('language_text.done', ['language' => $language, 'records' => $deletion->recordsDone, 'media' => $deletion->mediaDone]) . '</p></div>'
                . '<p>' . $t->html('language_text.history') . '</p>';
        } elseif ($deletion !== null) {
            $body .= '<section class="admin-card" aria-labelledby="language-text-progress"><h2 id="language-text-progress">' . $t->html('language_text.running', ['language' => $language]) . '</h2>'
                . '<p data-language-text-progress>' . $t->html('language_text.progress', ['done' => $deletion->recordsDone, 'records' => $deletion->records, 'media' => $deletion->mediaDone]) . '</p>'
                . '<p>' . $t->html('language_text.continue_help') . '</p>'
                . ($owner ? '<form method="post" action="/settings/languages/text">' . $csrf . '<input type="hidden" name="_action" value="continue"><input type="hidden" name="locale" value="' . Html::attribute($deletion->locale) . '"><button type="submit" class="admin-button--primary">' . $t->html('language_text.continue') . '</button></form>' : '')
                . '</section>';
        } elseif ($locale === null) {
            $body .= '<p>' . $t->html('language_text.unknown') . '</p>';
        } elseif ($state !== 'off' || $affected === null) {
            $body .= '<p>' . $t->html('language_text.turn_off', ['language' => $language]) . '</p>';
        } elseif (!$owner) {
            $body .= '<p>' . $t->html('language_text.not_allowed') . '</p>';
        } else {
            $body .= '<section class="admin-card" aria-labelledby="language-text-consequences"><h2 id="language-text-consequences">' . $t->html('language_text.consequences') . '</h2>'
                . '<ul><li>' . $t->html('language_text.records', ['records' => $affected['records'], 'language' => $language]) . '</li>'
                . '<li>' . $t->html('language_text.media', ['media' => $affected['media']]) . '</li>'
                . '<li>' . $t->html('language_text.settings') . '</li></ul>'
                . '<p>' . $t->html('language_text.history') . '</p>'
                . '<p><strong>' . $t->html('language_text.backup') . '</strong> <a href="/exports">' . $t->html('language_text.export') . '</a></p></section>'
                . '<form method="post" action="/settings/languages/text" class="admin-card">' . $csrf
                . '<input type="hidden" name="_action" value="start"><input type="hidden" name="locale" value="' . Html::attribute($locale) . '"><input type="hidden" name="records" value="' . $affected['records'] . '">'
                . '<p><label><input type="checkbox" name="confirmed" value="1" required> ' . $t->html('language_text.confirm_label', ['language' => $language, 'records' => $affected['records']]) . '</label></p>'
                . '<button type="submit" class="admin-button--danger">' . $t->html('language_text.delete', ['language' => $language]) . '</button></form>';
        }
        return AdminShell::application($title, TrustedHtml::fromEngineMarkup($body), $profile, 'languages', 'standard', $chrome);
    }
}
