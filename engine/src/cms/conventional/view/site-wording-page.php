<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\View;

use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Content\SiteWording;
use Utsuri\PortableCms\Conventional\Http\Html;
use Utsuri\PortableCms\Conventional\I18n\Message;

/** Every wording key of the profile with a field per language editors work in. */
final class SiteWordingPage
{
    /**
     * @param ProfileManifest $profile The profile as editing sees it: its languages are those shown.
     * @param array<string, string> $values Submitted texts by field, kept when a save is refused.
     * @param array<string, Message> $errors
     */
    public static function render(ProfileManifest $profile, AdminChrome $chrome, SiteWording $saved, array $values = [], array $errors = [], bool $conflict = false, bool $notice = false, ?Message $failure = null): string
    {
        $t = $chrome->translator();
        $languages = [];
        foreach ($profile->localeDefinitions as $definition) {
            $languages[$definition->id] = $definition->preparation ? $t->text('wording.preparation', ['language' => $definition->label]) : $definition->label;
        }
        // Each problem in the summary names its text and language, and links to its field.
        $names = [];
        foreach ($profile->wording as $definition) {
            foreach ($languages as $locale => $language) {
                $names[SiteWording::field($definition->key, $locale)] = $definition->label . ', ' . $language . ': ';
            }
        }
        $body = AdminShell::header($t->text('wording.title'), '<p class="admin-lede">' . $t->html('wording.lede') . '</p><p class="admin-help">' . $t->html('wording.help') . '</p>');
        if ($notice) {
            $body .= '<div class="admin-notice admin-notice--success" role="status"><p>' . $t->html('wording.saved') . '</p></div>';
        }
        if ($conflict) {
            $body .= '<section class="admin-notice admin-notice--warning" role="alert"><h2>' . $t->html('wording.conflict') . '</h2><p>' . $t->html('wording.conflict_help') . '</p></section>';
        } elseif ($failure !== null || $errors !== []) {
            $body .= '<section class="admin-notice admin-notice--danger" role="alert"><h2>' . $t->html('wording.not_saved') . '</h2>'
                . ($failure === null ? '' : '<p>' . Html::text($t->message($failure)) . '</p>');
            if ($errors !== []) {
                $body .= '<ul>';
                foreach ($errors as $field => $error) {
                    $body .= '<li><a href="#' . Html::attribute($field) . '">' . Html::text(($names[$field] ?? '') . $t->message($error)) . '</a></li>';
                }
                $body .= '</ul>';
            }
            $body .= '</section>';
        }
        if ($profile->wording === []) {
            $body .= '<p class="admin-meta">' . $t->html('wording.none') . '</p>';
            return AdminShell::application($t->text('wording.title'), TrustedHtml::fromEngineMarkup($body), $profile, 'wording', 'standard', $chrome);
        }
        $entries = '';
        foreach ($profile->wording as $definition) {
            $fields = '';
            $changed = false;
            foreach ($languages as $locale => $language) {
                $field = SiteWording::field($definition->key, $locale);
                $value = $values[$field] ?? $saved->text($profile, $definition->key, $locale) ?? '';
                $default = $definition->defaults[$locale] ?? null;
                $changed = $changed || isset($saved->values[$definition->key][$locale]);
                $error = isset($errors[$field]) ? $t->message($errors[$field]) : null;
                $hint = match (true) {
                    $default === null && $value === '' => $t->html('wording.missing') . ' · ' . $t->html('wording.no_default'),
                    $default === null => $t->html('wording.no_default'),
                    $default !== $value => $t->html('wording.default', ['text' => $default]),
                    default => '',
                };
                $described = trim(($hint === '' ? '' : 'hint-' . $field) . ' ' . ($error === null ? '' : 'error-' . $field));
                $fields .= '<div class="admin-field"><label for="' . Html::attribute($field) . '" lang="' . Html::attribute($locale) . '">' . Html::text($language) . '</label>'
                    . '<input type="text" id="' . Html::attribute($field) . '" name="' . Html::attribute($field) . '" lang="' . Html::attribute($locale) . '" maxlength="' . $definition->maximumLength . '" value="' . Html::attribute($value) . '"'
                    . ($definition->kind === 'address_segment' ? ' pattern="[a-z0-9]+(-[a-z0-9]+)*" spellcheck="false" autocapitalize="off"' : '')
                    . ($described === '' ? '' : ' aria-describedby="' . Html::attribute($described) . '"') . ($error === null ? '' : ' aria-invalid="true"') . '>'
                    . ($hint === '' ? '' : '<p class="admin-field__hint" id="hint-' . Html::attribute($field) . '">' . $hint . '</p>')
                    . ($error === null ? '' : '<p class="admin-field__error" id="error-' . Html::attribute($field) . '">' . Html::text($error) . '</p>') . '</div>';
            }
            $entries .= '<fieldset class="admin-card admin-wording__entry" data-wording-key="' . Html::attribute($definition->key) . '"><legend>' . Html::text($definition->label) . '</legend>'
                . '<p class="admin-help">' . Html::text($definition->purpose) . '</p>'
                . ($definition->kind === 'address_segment' ? '<p class="admin-meta">' . $t->html('wording.address') . '</p>' : '')
                . '<div class="admin-wording__languages">' . $fields . '</div>'
                // Reset buttons submit their own form, so pressing Enter in a field saves instead.
                . ($changed ? '<p><button type="submit" form="wording-reset" name="_reset" value="' . Html::attribute($definition->key) . '" class="admin-button--secondary">' . $t->html('wording.reset') . '</button></p>' : '')
                . '</fieldset>';
        }
        $body .= '<form class="admin-form" method="post" action="/settings/wording"><input type="hidden" name="_csrf" value="' . Html::attribute($chrome->csrfToken) . '">'
            . '<input type="hidden" name="_revision" value="' . $saved->revision . '"><div class="admin-wording">' . $entries . '</div>'
            . '<div class="admin-save-bar"><p class="admin-meta">' . $t->html('wording.revision', ['revision' => (string) $saved->revision]) . '</p><button type="submit" class="admin-button--primary">' . $t->html('wording.save') . '</button></div></form>'
            . '<form id="wording-reset" method="post" action="/settings/wording"><input type="hidden" name="_csrf" value="' . Html::attribute($chrome->csrfToken) . '"><input type="hidden" name="_revision" value="' . $saved->revision . '"></form>';
        return AdminShell::application($t->text('wording.title'), TrustedHtml::fromEngineMarkup($body), $profile, 'wording', 'standard', $chrome);
    }
}
