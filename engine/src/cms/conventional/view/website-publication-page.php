<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\View;

use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Http\Html;
use Utsuri\PortableCms\Conventional\Publication\WebsitePublication;

final class WebsitePublicationPage
{
    /** @param array<string, string> $input */
    public static function render(ProfileManifest $profile, AdminChrome $chrome, WebsitePublication $saved, array $input = [], ?string $error = null, bool $conflict = false, ?\Utsuri\PortableCms\Conventional\Media\MediaPage $media = null, ?\Utsuri\PortableCms\Conventional\Media\LibraryMedia $selection = null, string $logos = '', bool $uploadsEnabled = false, ?\Utsuri\PortableCms\Conventional\I18n\Message $uploadNotice = null, ?\Utsuri\PortableCms\Conventional\I18n\Message $uploadError = null): string
    {
        $t = $chrome->translator();
        $document = $saved->document();
        $value = static fn(string $key, string $default): string => $input[$key] ?? $default;
        $body = AdminShell::header($t->text('holding.settings'), '<p>' . $t->html('holding.settings_help') . '</p>');
        if ($uploadNotice !== null) {
            $body .= '<p class="admin-notice" role="status" data-website-upload-result>' . Html::text($t->message($uploadNotice)) . ' ' . $t->html('website_media.after_upload') . '</p>';
        }
        if ($uploadError !== null) {
            $body .= '<p class="admin-field__error" role="alert">' . Html::text($t->message($uploadError)) . '</p>';
        }
        if ($saved->identityUpgrade !== null) {
            $body .= '<section class="admin-notice" role="status"><p>' . $t->html($saved->identityUpgrade->kept === 'website-settings' ? 'holding.identity_kept_website' : 'holding.identity_kept_profile') . '</p><details><summary>' . $t->html('holding.identity_previous') . '</summary><ul>';
            foreach ($saved->identityUpgrade->previous as $previous) {
                $body .= '<li>' . Html::text($previous['typeId'] . ' / ' . substr($previous['recordId'], 0, 8))
                    . '<p>' . $t->html('holding.name') . ': ' . Html::text($previous['name'] ?? '') . '</p><p>' . $t->html('holding.icon') . ': ' . Html::text($previous['iconMediaId'] ?? '') . '</p></li>';
            }
            $body .= '</ul></details></section>';
        }
        if ($error !== null) {
            $body .= '<section class="admin-notice admin-notice--danger" role="alert"><h2>' . $t->html($conflict ? 'holding.conflict' : 'holding.invalid') . '</h2><p>' . $t->html('holding.preserved') . '</p><p>' . ($t->has('branding.error.' . $error) ? $t->html('branding.error.' . $error) : Html::text($error)) . '</p>'
                . ($conflict ? '<p>' . $t->html('holding.current_values', ['mode' => $saved->mode(), 'name' => $document['websiteName']]) . '</p>' : '') . '</section>';
        }
        $revision = $conflict ? (string) $saved->revision() : $value('_revision', (string) $saved->revision());
        $body .= '<form method="post" action="/settings/publication"><input type="hidden" name="_csrf" value="' . Html::attribute($chrome->csrfToken) . '"><input type="hidden" name="_revision" value="' . Html::attribute($revision) . '">'
            . '<section class="admin-card"><label>' . $t->html('holding.name') . '<input name="websiteName" required maxlength="180" value="' . Html::attribute($value('websiteName', $document['websiteName'])) . '"></label>'
            . '<label>' . $t->html('holding.mode') . '<select name="mode">';
        foreach (['coming_soon', 'live'] as $mode) {
            $body .= '<option value="' . $mode . '"' . ($value('mode', $saved->mode()) === $mode ? ' selected' : '') . '>' . $t->html('holding.mode.' . $mode) . '</option>';
        }
        $body .= '</select></label><p>' . $t->html('holding.standard') . '</p><details><summary>' . $t->html('holding.icon') . '</summary><p>' . $t->html('holding.icon_help') . '</p>'
            . HoldingIconPicker::render($value('iconMediaId', $document['iconMediaId'] ?? ''), $media, $selection, $t, $uploadsEnabled) . '</details></section>';
        if ($logos !== '') {
            $body .= '<section class="admin-card"><details' . ($input === [] ? '' : ' open') . '><summary>' . $t->html('branding.title') . '</summary><p>' . $t->html('branding.help') . '</p>' . $logos . '</details></section>';
        }
        foreach ($document['translations'] as $entry) {
            $locale = $entry['locale'];
            $body .= '<section class="admin-card" lang="' . Html::attribute($locale) . '"><h2>' . Html::text($locale) . '</h2><label>' . $t->html('holding.message')
                . '<textarea name="message_' . Html::attribute($locale) . '" required maxlength="600" rows="3">' . Html::text($value('message_' . $locale, $entry['message'])) . '</textarea></label><label>' . $t->html('holding.contact')
                . '<input name="contact_' . Html::attribute($locale) . '" maxlength="240" value="' . Html::attribute($value('contact_' . $locale, $entry['contact'])) . '"></label></section>';
        }
        $body .= '<section class="admin-card"><label><input type="checkbox" name="confirmed" value="1"> ' . $t->html('holding.confirm') . '</label><p>' . $t->html('holding.confirm_help') . '</p></section>'
            . '<input type="hidden" name="_complete" value="1"><div class="admin-save-bar"><button type="submit">' . $t->html('holding.save') . '</button><a href="/releases">' . $t->html('holding.releases') . '</a></div></form>';
        return AdminShell::application($t->text('holding.settings'), TrustedHtml::fromEngineMarkup($body), $profile, 'publication-settings', 'standard', $chrome);
    }
}
