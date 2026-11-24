<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\View;

use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Http\Html;
use Utsuri\PortableCms\Conventional\I18n\Message;
use Utsuri\PortableCms\Conventional\I18n\Translator;
use Utsuri\PortableCms\Conventional\Media\LibraryMedia;
use Utsuri\PortableCms\Conventional\Media\MediaPage;
use Utsuri\PortableCms\Conventional\Publication\WebsitePublication;

/**
 * Website settings in the order of an editor's tasks: what the website is called and its icon,
 * what visitors see before launch, what AI assistants read, and last the administration's own
 * appearance. One line under
 * the heading says what the public website shows now; saving names the next step, which is a
 * release. Editors read the page; owners and administrators change it.
 */
final class WebsitePublicationPage
{
    /**
     * @param array<string, string> $input
     * @param list<string>|null $visibleLocales The languages whose texts are shown; the others keep their saved text.
     */
    public static function render(
        ProfileManifest $profile,
        AdminChrome $chrome,
        WebsitePublication $saved,
        array $input = [],
        ?string $error = null,
        bool $conflict = false,
        ?MediaPage $media = null,
        ?LibraryMedia $selection = null,
        string $logos = '',
        bool $uploadsEnabled = false,
        ?Message $uploadNotice = null,
        ?Message $uploadError = null,
        ?string $publicState = null,
        ?array $visibleLocales = null,
        bool $readOnly = false,
        bool $justSaved = false,
    ): string {
        $t = $chrome->translator();
        $document = $saved->document();
        $value = static fn(string $key, string $default): string => $input[$key] ?? $default;
        $mode = $value('mode', $saved->mode());
        $lede = '<p class="admin-lede">' . $t->html('holding.lede') . '</p>'
            . ($publicState === null ? '' : '<p class="admin-meta" data-public-state="' . Html::attribute($publicState) . '">' . $t->html('holding.public.' . $publicState) . '</p>');
        $body = AdminShell::breadcrumbs([[$t->text('shell.group.website'), null], [$t->text('holding.settings'), null]], $t)
            . AdminShell::header($t->text('holding.settings'), $lede);
        if ($justSaved) {
            $body .= '<p class="admin-notice" role="status" data-settings-saved>' . $t->markup('holding.saved.' . $saved->mode(), [], ['releases' => '<a href="/releases">' . $t->html('shell.nav.releases') . '</a>']) . '</p>';
        }
        if ($uploadNotice !== null) {
            $body .= '<p class="admin-notice" role="status" data-website-upload-result>' . Html::text($t->message($uploadNotice)) . ' ' . $t->html('website_media.after_upload') . '</p>';
        }
        if ($uploadError !== null) {
            $body .= '<p class="admin-field__error" role="alert">' . Html::text($t->message($uploadError)) . '</p>';
        }
        if ($saved->identityUpgrade !== null && !$readOnly) {
            $body .= '<p class="admin-notice admin-meta" role="status">' . $t->html($saved->identityUpgrade->kept === 'website-settings' ? 'holding.identity_kept_website' : 'holding.identity_kept_profile') . '</p>';
        }
        if ($error !== null) {
            $body .= '<section class="admin-notice admin-notice--danger" role="alert"><h2>' . $t->html($conflict ? 'holding.conflict' : 'holding.invalid') . '</h2><p>' . self::reason($error, $conflict, $t) . '</p></section>';
        }
        $revision = $conflict ? (string) $saved->revision() : $value('_revision', (string) $saved->revision());
        $disabled = $readOnly ? ' disabled' : '';
        $body .= '<form method="post" action="/settings/publication" class="admin-holding" data-saved-mode="' . Html::attribute($saved->mode()) . '"><input type="hidden" name="_csrf" value="' . Html::attribute($chrome->csrfToken) . '"><input type="hidden" name="_revision" value="' . Html::attribute($revision) . '">';

        // The website: its name and its icon.
        $body .= '<section class="admin-section" aria-labelledby="settings-identity"><h2 id="settings-identity">' . $t->html('holding.identity') . '</h2>'
            . '<div class="admin-field"><label for="website-name">' . $t->html('holding.name') . FieldHelp::required($t) . '</label>'
            . FieldHelp::toggle('website-name-help', $t->text('holding.name'), $t) . FieldHelp::text('website-name-help', $t->html('holding.name_help'))
            . '<input id="website-name" name="websiteName" required maxlength="180" aria-describedby="website-name-help" value="' . Html::attribute($value('websiteName', $document['websiteName'])) . '"' . $disabled . '></div>'
            . HoldingIconPicker::render($value('iconMediaId', $document['iconMediaId'] ?? ''), $media, $selection, $t, $uploadsEnabled, $readOnly)
            . '</section>';

        // Before launch: what the next release publishes, and the coming soon texts.
        $body .= '<section class="admin-section admin-holding__launch" aria-labelledby="settings-launch"><h2 id="settings-launch">' . $t->html('holding.launch') . '</h2>'
            . '<fieldset class="admin-field" aria-labelledby="settings-mode-label" aria-describedby="settings-mode-help">'
            . FieldHelp::legend('settings-mode-label', $t->html('holding.mode'), 'settings-mode-help', $t->text('holding.mode'), $t) . FieldHelp::text('settings-mode-help', $t->html('holding.mode_help'))
            . '<div class="admin-holding__modes">';
        foreach (['coming_soon', 'live'] as $choice) {
            $body .= '<label class="admin-checkbox admin-holding__mode"><input type="radio" name="mode" value="' . $choice . '"' . ($mode === $choice ? ' checked' : '') . $disabled . '> <span>' . $t->html('holding.mode.' . $choice) . ' <span class="admin-meta">' . $t->html('holding.mode.' . $choice . '_help') . '</span></span></label>';
        }
        $body .= '</div></fieldset><div class="admin-holding__texts">' . self::languageTexts($profile, $document, $visibleLocales, $value, $t, $disabled) . '</div>';
        if ($saved->mode() === 'live' && !$readOnly) {
            $body .= '<div class="admin-holding__confirm"><label class="admin-checkbox"><input type="checkbox" name="confirmed" value="1"' . ($value('confirmed', '') === '1' ? ' checked' : '') . '> <span>' . $t->html('holding.confirm') . '</span></label></div>';
        }
        $body .= '</section>';

        // For AI assistants: the llms.txt each release writes from the pages, and its introductions.
        $llms = $saved->llmsTxt();
        $body .= '<section class="admin-section" aria-labelledby="settings-llms" aria-describedby="settings-llms-help">'
            . FieldHelp::heading('<h2 id="settings-llms">' . $t->html('llms.title') . '</h2>', 'settings-llms-help', $t->text('llms.title'), $t)
            . FieldHelp::text('settings-llms-help', $t->html('llms.help'))
            . '<div class="admin-field"><label class="admin-checkbox"><input type="checkbox" name="llmsTxt" value="1"' . ($value('llmsTxt', $llms->enabled ? '1' : '') === '1' ? ' checked' : '') . $disabled . '> <span>' . $t->html('llms.enabled') . '</span></label></div>'
            . self::llmsIntroductions($profile, $document, $llms, $visibleLocales, $value, $t, $disabled) . '</section>';

        // The administration's own appearance, last: it is the agency's part.
        if ($logos !== '') {
            $body .= '<section class="admin-section" aria-labelledby="settings-branding" aria-describedby="settings-branding-help">'
                . FieldHelp::heading('<h2 id="settings-branding">' . $t->html('branding.title') . '</h2>', 'settings-branding-help', $t->text('branding.title'), $t)
                . FieldHelp::text('settings-branding-help', $t->html('branding.help')) . $logos . '</section>';
        }
        $body .= '<input type="hidden" name="_complete" value="1">'
            . ($readOnly ? '' : '<div class="admin-save-bar"><button type="submit" class="admin-button--primary">' . $t->html('holding.save') . '</button></div>') . '</form>';
        return AdminShell::application($t->text('holding.settings'), TrustedHtml::fromEngineMarkup($body), $profile, 'publication-settings', 'standard', $chrome);
    }

    /**
     * @param array{translations: list<array{locale: string, message: string, contact: string}>} $document
     * @param list<string>|null $visibleLocales
     * @param \Closure(string, string): string $value
     */
    private static function languageTexts(ProfileManifest $profile, array $document, ?array $visibleLocales, \Closure $value, Translator $t, string $disabled): string
    {
        $labels = [];
        foreach ($profile->localeDefinitions as $definition) {
            $labels[$definition->id] = $definition->label;
        }
        $html = '';
        foreach ($document['translations'] as $entry) {
            $locale = $entry['locale'];
            $messageValue = $value('message_' . $locale, $entry['message']);
            $contactValue = $value('contact_' . $locale, $entry['contact']);
            if ($visibleLocales !== null && !in_array($locale, $visibleLocales, true)) {
                // A language that is off or in preparation keeps its text without taking space.
                $html .= '<input type="hidden" name="message_' . Html::attribute($locale) . '" value="' . Html::attribute($messageValue) . '"><input type="hidden" name="contact_' . Html::attribute($locale) . '" value="' . Html::attribute($contactValue) . '">';
                continue;
            }
            $id = 'holding-' . $locale;
            $html .= '<fieldset class="admin-field admin-holding__language" lang="' . Html::attribute($locale) . '"><legend>' . Html::text($labels[$locale] ?? $locale) . '</legend>'
                . '<div class="admin-field"><label for="' . $id . '-message">' . $t->html('holding.message') . FieldHelp::required($t) . '</label>'
                . FieldHelp::toggle($id . '-message-help', $t->text('holding.message'), $t) . FieldHelp::text($id . '-message-help', $t->html('holding.message_help'))
                . '<textarea id="' . $id . '-message" name="message_' . Html::attribute($locale) . '" required maxlength="600" rows="3" aria-describedby="' . $id . '-message-help"' . $disabled . '>' . Html::text($messageValue) . '</textarea></div>'
                . '<div class="admin-field"><label for="' . $id . '-contact">' . $t->html('holding.contact') . '</label>'
                . FieldHelp::toggle($id . '-contact-help', $t->text('holding.contact'), $t) . FieldHelp::text($id . '-contact-help', $t->html('holding.contact_help'))
                . '<input id="' . $id . '-contact" name="contact_' . Html::attribute($locale) . '" maxlength="240" aria-describedby="' . $id . '-contact-help" value="' . Html::attribute($contactValue) . '"' . $disabled . '></div></fieldset>';
        }
        return $html;
    }

    /**
     * One optional introduction for AI assistants per public language; the others keep theirs
     * in hidden fields, as their coming soon texts do.
     * @param array{translations: list<array{locale: string, message: string, contact: string}>} $document
     * @param list<string>|null $visibleLocales
     * @param \Closure(string, string): string $value
     */
    private static function llmsIntroductions(ProfileManifest $profile, array $document, \Utsuri\PortableCms\Conventional\Content\LlmsTxtSettings $llms, ?array $visibleLocales, \Closure $value, Translator $t, string $disabled): string
    {
        $labels = [];
        foreach ($profile->localeDefinitions as $definition) {
            $labels[$definition->id] = $definition->label;
        }
        $html = '';
        foreach (array_column($document['translations'], 'locale') as $locale) {
            $text = $value('llms_' . $locale, $llms->introductions[$locale] ?? '');
            if ($visibleLocales !== null && !in_array($locale, $visibleLocales, true)) {
                $html .= '<input type="hidden" name="llms_' . Html::attribute($locale) . '" value="' . Html::attribute($text) . '">';
                continue;
            }
            $id = 'llms-' . $locale;
            $label = $t->text('llms.introduction', ['language' => $labels[$locale] ?? $locale]);
            $html .= '<div class="admin-field" lang="' . Html::attribute($locale) . '"><label for="' . $id . '">' . Html::text($label) . '</label>'
                . FieldHelp::toggle($id . '-help', $label, $t) . FieldHelp::text($id . '-help', $t->html('llms.introduction_help'))
                . '<textarea id="' . $id . '" name="llms_' . Html::attribute($locale) . '" maxlength="' . \Utsuri\PortableCms\Conventional\Content\LlmsTxtSettings::INTRODUCTION_LENGTH . '" rows="4" aria-describedby="' . $id . '-help"' . $disabled . '>' . Html::text($text) . '</textarea></div>';
        }
        return $html;
    }

    /** Every failure the page can show has a sentence; no code reaches the editor. */
    private static function reason(string $error, bool $conflict, Translator $t): string
    {
        if ($conflict) {
            return $t->html('holding.conflict_help');
        }
        foreach (['holding.error.' . $error, 'branding.error.' . $error] as $key) {
            if ($t->has($key)) {
                return $t->html($key);
            }
        }
        return $t->html('holding.error.unknown');
    }
}
