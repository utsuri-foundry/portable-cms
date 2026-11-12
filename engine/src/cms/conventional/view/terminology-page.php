<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\View;

use Utsuri\PortableCms\Conventional\Content\AdministrationLabels;
use Utsuri\PortableCms\Conventional\Content\EditorialTerminology;
use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Http\Html;
use Utsuri\PortableCms\Conventional\I18n\InterfaceLanguage;
use Utsuri\PortableCms\Conventional\I18n\Message;
use Utsuri\PortableCms\Conventional\I18n\Translator;

final class TerminologyPage
{
    /** @param array<string, mixed> $values
     * @param array<string, Message> $errors
     */
    public static function render(
        string $csrf,
        ProfileManifest $profile,
        EditorialTerminology $saved,
        array $values = [],
        array $errors = [],
        bool $conflict = false,
        ?AdminChrome $chrome = null,
        string $notice = '',
        ?AdministrationLabels $administration = null,
    ): string {
        $t = AdminShell::translator($chrome);
        $presented = $saved->present($profile);
        $fields = '';
        foreach ($presented->types as $type) {
            $fields .= '<fieldset><legend>' . Html::text($type->label) . '</legend>';
            foreach (['singular' => $type->singularLabel, 'plural' => $type->label] as $key => $default) {
                $name = $type->id . '__' . $key;
                $value = $values[$name] ?? $default;
                $value = is_string($value) ? $value : '';
                $error = isset($errors[$name]) ? $t->message($errors[$name]) : null;
                $fields .= '<div class="admin-field"><label for="' . Html::attribute($name) . '">'
                    . $t->html($key === 'singular' ? 'names.singular' : 'names.plural') . '</label>'
                    . '<input type="text" id="' . Html::attribute($name) . '" name="' . Html::attribute($name) . '" required maxlength="100" value="'
                    . Html::attribute($value) . '"' . ($error === null ? '' : ' aria-invalid="true" aria-describedby="error-' . Html::attribute($name) . '"') . '>'
                    . ($error === null ? '' : '<p class="admin-field__error" id="error-' . Html::attribute($name) . '">' . Html::text($error) . '</p>') . '</div>';
            }
            $fields .= self::languages($type->id, $saved, $values, $errors, $administration ?? AdministrationLabels::none(), $t) . '</fieldset>';
        }
        $messages = $notice === '' ? '' : '<div class="admin-notice admin-notice--success" role="status"><p>' . Html::text($notice) . '</p></div>';
        if ($conflict) {
            $messages .= '<section class="admin-notice admin-notice--warning" role="alert"><h2>' . $t->html('names.conflict.title') . '</h2>'
                . '<p>' . $t->html('names.conflict.detail') . '</p><dl>';
            foreach ($presented->types as $type) {
                $messages .= '<dt>' . Html::text($type->label) . '</dt><dd>' . Html::text($type->singularLabel . ' / ' . $type->label) . '</dd>';
            }
            $messages .= '</dl></section>';
        }
        if ($errors !== []) {
            $messages .= '<section class="admin-notice admin-notice--danger" role="alert"><h2>' . $t->html('names.errors.title') . '</h2><ul>';
            foreach ($errors as $name => $message) {
                $messages .= '<li><a href="#' . Html::attribute($name) . '">' . Html::text($t->message($message)) . '</a></li>';
            }
            $messages .= '</ul></section>';
        }
        return AdminShell::application($t->text('names.title'), TrustedHtml::fromEngineMarkup(
            AdminShell::breadcrumbs([[$t->text('shell.group.website'), null], [$t->text('names.title'), null]], $t)
            . AdminShell::header($t->text('names.title'), '<p class="admin-lede">' . $t->html('names.lede') . '</p>'
                . '<p class="admin-help">' . $t->html('names.help') . '</p>')
            . $messages . '<form class="admin-form" method="post" action="/settings/terminology">'
            . '<input type="hidden" name="_csrf" value="' . Html::attribute($csrf) . '">'
            . '<input type="hidden" name="_revision" value="' . $saved->revision . '"><div class="admin-names">' . $fields . '</div>'
            . '<div class="admin-save-bar"><p class="admin-meta">' . $t->html('names.saved_revision', ['revision' => (string) $saved->revision]) . '</p><button type="submit" class="admin-button--primary">'
            . ($conflict ? $t->html('names.save_over', ['revision' => (string) $saved->revision]) : $t->html('names.save')) . '</button></div></form>',
        ), $saved->present($profile, $t->locale(), $administration), 'terminology', 'standard', $chrome);
    }

    /**
     * Optional installation names per interface language; empty languages fall back to the profile.
     *
     * @param array<string, mixed> $values
     * @param array<string, Message> $errors
     */
    private static function languages(string $typeId, EditorialTerminology $saved, array $values, array $errors, AdministrationLabels $administration, Translator $t): string
    {
        $stored = [];
        foreach ($saved->labels as $label) {
            if ($label['typeId'] === $typeId) {
                $stored = $label['languages'] ?? [];
            }
        }
        $open = false;
        $markup = '';
        foreach (InterfaceLanguage::AUTONYMS as $language => $autonym) {
            $profileLabel = $administration->type($language, $typeId);
            $markup .= '<fieldset class="admin-names__language"><legend lang="' . $language . '">' . Html::text($autonym) . '</legend>';
            foreach (['singular' => 'singularLabel', 'plural' => 'label'] as $key => $profileKey) {
                $name = $typeId . '__' . $key . '__' . $language;
                $value = $values[$name] ?? $stored[$language][$key] ?? '';
                $value = is_string($value) ? $value : '';
                $error = isset($errors[$name]) ? $t->message($errors[$name]) : null;
                $open = $open || $value !== '' || $error !== null;
                $markup .= '<div class="admin-field"><label for="' . Html::attribute($name) . '">'
                    . $t->html($key === 'singular' ? 'names.singular' : 'names.plural') . '</label>'
                    . '<input type="text" id="' . Html::attribute($name) . '" name="' . Html::attribute($name) . '" lang="' . $language . '" maxlength="100" value="' . Html::attribute($value) . '"'
                    . ($profileLabel === null ? '' : ' placeholder="' . Html::attribute($profileLabel[$profileKey]) . '"')
                    . ($error === null ? '' : ' aria-invalid="true" aria-describedby="error-' . Html::attribute($name) . '"') . '>'
                    . ($error === null ? '' : '<p class="admin-field__error" id="error-' . Html::attribute($name) . '">' . Html::text($error) . '</p>') . '</div>';
            }
            $markup .= '</fieldset>';
        }
        return '<details class="admin-names__languages"' . ($open ? ' open' : '') . '><summary>' . $t->html('names.languages.title') . '</summary>'
            . '<p class="admin-help">' . $t->html('names.languages.help') . '</p><div class="admin-names__grid">' . $markup . '</div></details>';
    }
}
