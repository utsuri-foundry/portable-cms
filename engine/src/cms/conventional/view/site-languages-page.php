<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\View;

use Utsuri\PortableCms\Conventional\Content\Language;
use Utsuri\PortableCms\Conventional\Content\LanguageTextDeletion;
use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Content\SiteLanguages;
use Utsuri\PortableCms\Conventional\Http\Html;
use Utsuri\PortableCms\Conventional\I18n\Message;

final class SiteLanguagesPage
{
    /**
     * @param ProfileManifest $profile The installed profile, with the languages the installation added.
     * @param array<string, string> $input
     * @param list<Language> $addable Catalog languages the installation can add now.
     * @param LanguageTextDeletion|null $deletion The latest deletion of a language's text.
     */
    public static function render(ProfileManifest $profile, AdminChrome $chrome, SiteLanguages $saved, array $input = [], ?Message $error = null, bool $conflict = false, ?string $notice = null, array $addable = [], ?LanguageTextDeletion $deletion = null): string
    {
        $t = $chrome->translator();
        $body = AdminShell::header($t->text('languages.title'), '<p>' . $t->html('languages.help') . '</p>');
        if ($notice !== null) {
            $body .= '<div class="admin-notice admin-notice--success" role="status"><p>' . $t->html('languages.' . $notice) . '</p></div>';
        }
        if ($conflict) {
            $body .= '<section class="admin-notice admin-notice--warning" role="alert"><h2>' . $t->html('languages.conflict') . '</h2><p>' . $t->html('languages.conflict_help') . '</p></section>';
        } elseif ($error !== null) {
            $body .= '<section class="admin-notice admin-notice--danger" role="alert"><h2>' . $t->html('languages.not_saved') . '</h2><p>' . Html::text($t->message($error)) . '</p></section>';
        }
        if ($deletion?->state === 'running') {
            $name = $deletion->locale;
            foreach ($profile->localeDefinitions as $definition) {
                $name = $definition->id === $deletion->locale ? $definition->label : $name;
            }
            $body .= '<div class="admin-notice admin-notice--warning" role="status"><p>' . $t->html('languages.deletion_running', ['language' => $name])
                . ' <a href="/settings/languages/text?locale=' . Html::attribute(rawurlencode($deletion->locale)) . '">' . $t->html('languages.deletion_continue') . '</a></p></div>';
        }
        if ($chrome->role === 'editor') {
            $rows = '';
            foreach ($profile->localeDefinitions as $definition) {
                $state = $saved->states[$definition->id];
                $rows .= '<tr><th scope="row" lang="' . Html::attribute($definition->id) . '">' . Html::text($definition->label) . '</th><td>' . (in_array($definition->id, $profile->addedLocales, true) ? $t->html('languages.added_mark') . ' · ' : '') . $t->html('languages.state.' . $state)
                    . ($definition->id === $saved->primary ? ' · ' . $t->html('languages.primary_mark') : '') . '</td></tr>';
            }
            $body .= '<div class="admin-table-wrap"><table class="admin-table"><thead><tr><th scope="col">' . $t->html('languages.language') . '</th><th scope="col">' . $t->html('languages.state') . '</th></tr></thead><tbody>'
                . $rows . '</tbody></table></div><p class="admin-meta">' . $t->html('languages.not_allowed') . '</p>';
            return AdminShell::application($t->text('languages.title'), TrustedHtml::fromEngineMarkup($body), $profile, 'languages', 'standard', $chrome);
        }
        $revision = $conflict ? (string) $saved->revision : ($input['_revision'] ?? (string) $saved->revision);
        $primary = $input['primary'] ?? $saved->primary;
        $body .= '<form method="post" action="/settings/languages"><input type="hidden" name="_csrf" value="' . Html::attribute($chrome->csrfToken) . '"><input type="hidden" name="_revision" value="' . Html::attribute($revision) . '">';
        $options = '';
        foreach ($profile->localeDefinitions as $definition) {
            $current = $input['state__' . $definition->id] ?? $saved->states[$definition->id];
            $choices = '';
            foreach (SiteLanguages::STATES as $state) {
                $choices .= '<label><input type="radio" name="state__' . Html::attribute($definition->id) . '" value="' . $state . '"' . ($current === $state ? ' checked' : '') . '> ' . $t->html('languages.state.' . $state) . '</label>';
            }
            $added = in_array($definition->id, $profile->addedLocales, true);
            $body .= '<fieldset class="admin-card"><legend lang="' . Html::attribute($definition->id) . '">' . Html::text($definition->label) . '</legend>'
                . ($added ? '<p class="admin-meta">' . $t->html('languages.added_help') . '</p>' : '') . '<div class="admin-segmented">' . $choices . '</div>'
                // A separate form, so Enter in the language form saves instead of removing.
                . ($added ? '<p><button type="submit" form="languages-remove" name="locale" value="' . Html::attribute($definition->id) . '" class="admin-button--quiet">' . $t->html('languages.remove', ['language' => $definition->label]) . '</button></p>' : '')
                // Only the owner deletes the text of a language, and only once it is off.
                . ($chrome->role === 'owner' && $saved->states[$definition->id] === 'off' ? '<p><a href="/settings/languages/text?locale=' . Html::attribute(rawurlencode($definition->id)) . '">' . $t->html('languages.delete_text', ['language' => $definition->label]) . '</a></p>' : '') . '</fieldset>';
            $options .= '<option value="' . Html::attribute($definition->id) . '"' . ($primary === $definition->id ? ' selected' : '') . '>' . Html::text($definition->label) . '</option>';
        }
        $body .= '<section class="admin-card"><label for="languages-primary">' . $t->html('languages.primary') . '</label><select id="languages-primary" name="primary">' . $options . '</select>'
            . '<p class="admin-meta">' . $t->html('languages.primary_help') . '</p></section>'
            . '<section class="admin-card"><label><input type="checkbox" name="confirmed" value="1"> ' . $t->html('languages.confirm') . '</label><p class="admin-meta">' . $t->html('languages.confirm_help') . '</p></section>'
            . '<div class="admin-save-bar"><p class="admin-meta">' . $t->html('languages.revision', ['revision' => (string) $saved->revision]) . '</p><button type="submit" class="admin-button--primary">' . $t->html('languages.save') . '</button></div></form>';
        $change = static fn(string $action, string $id = ''): string => '<form method="post" action="/settings/languages"' . ($id === '' ? '' : ' id="' . $id . '"') . '><input type="hidden" name="_csrf" value="' . Html::attribute($chrome->csrfToken) . '"><input type="hidden" name="_revision" value="' . $saved->revision . '"><input type="hidden" name="_action" value="' . $action . '">';
        $body .= $profile->addedLocales === [] ? '' : $change('remove', 'languages-remove') . '</form>';
        $body .= '<section class="admin-card" aria-labelledby="languages-add-title"><h2 id="languages-add-title">' . $t->html('languages.add_title') . '</h2>';
        if ($profile->envelope === null) {
            $body .= '<p class="admin-meta">' . $t->html('languages.add_unavailable') . '</p></section>';
        } elseif ($addable === []) {
            $body .= '<p class="admin-meta">' . $t->html('languages.add_none') . '</p></section>';
        } else {
            $choices = '';
            foreach ($addable as $language) {
                $choices .= '<option value="' . Html::attribute($language->id) . '" lang="' . Html::attribute($language->id) . '">' . Html::text($language->name) . '</option>';
            }
            $body .= '<p class="admin-meta">' . $t->html('languages.add_help') . '</p>' . $change('add')
                . '<label for="languages-add">' . $t->html('languages.add_language') . '</label><select id="languages-add" name="locale">' . $choices . '</select> <button type="submit">' . $t->html('languages.add') . '</button></form></section>';
        }
        return AdminShell::application($t->text('languages.title'), TrustedHtml::fromEngineMarkup($body), $profile, 'languages', 'standard', $chrome);
    }
}
