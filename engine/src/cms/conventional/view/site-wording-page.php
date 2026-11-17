<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\View;

use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Content\SiteWording;
use Utsuri\PortableCms\Conventional\Content\WordingDefinition;
use Utsuri\PortableCms\Conventional\Http\Html;
use Utsuri\PortableCms\Conventional\I18n\Message;
use Utsuri\PortableCms\Conventional\I18n\Translator;

/** Every wording key of the profile, folded by where the text appears, with a field per language editors work in. */
final class SiteWordingPage
{
    /**
     * @param ProfileManifest $profile The profile as editing sees it: its languages are those shown.
     * @param array<string, string> $values Submitted texts by field, kept when a save is refused.
     * @param array<string, Message> $errors
     * @param string $query A search narrows the page to the texts whose name, explanation or words contain it.
     */
    public static function render(ProfileManifest $profile, AdminChrome $chrome, SiteWording $saved, array $values = [], array $errors = [], bool $conflict = false, bool $notice = false, ?Message $failure = null, string $query = ''): string
    {
        $t = $chrome->translator();
        $query = mb_substr(trim($query), 0, 100);
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
        $body = AdminShell::header($t->text('wording.title'), '<p class="admin-lede">' . $t->html('wording.lede') . ' ' . FieldHelp::toggle('wording-help', $t->text('wording.title'), $t) . '</p>' . FieldHelp::text('wording-help', $t->html('wording.help')));
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
        $body .= '<form class="admin-toolbar" method="get" action="/settings/wording" role="search"><label class="admin-visually-hidden" for="wording-query">' . $t->html('wording.find') . '</label>'
            . '<input type="search" id="wording-query" name="q" maxlength="100" placeholder="' . $t->html('wording.find') . '" value="' . Html::attribute($query) . '"><button type="submit">' . $t->html('wording.search') . '</button>'
            . ($query === '' ? '' : '<a class="admin-button admin-button--quiet" href="/settings/wording">' . $t->html('wording.show_all') . '</a>') . '</form>';
        $groups = '';
        $shown = 0;
        foreach (self::groups($profile, $t) as $index => $group) {
            $entries = '';
            $count = 0;
            $open = $index === 0 || $query !== '';
            foreach ($group['entries'] as $definition) {
                $texts = [];
                foreach ($languages as $locale => $language) {
                    $texts[] = $values[SiteWording::field($definition->key, $locale)] ?? $saved->text($profile, $definition->key, $locale) ?? '';
                }
                if ($query !== '' && !self::matches($definition, $texts, $query)) {
                    continue;
                }
                $count++;
                $entries .= self::entry($definition, $profile, $saved, $languages, $values, $errors, $t, $open);
            }
            if ($count === 0) {
                continue;
            }
            $shown += $count;
            $groups .= '<details class="admin-section admin-wording__group"' . ($open ? ' open' : '') . ' data-wording-group><summary><h2>' . Html::text($group['name']) . '</h2><span class="admin-wording__count">' . $t->html('wording.group.count', ['count' => (string) $count]) . '</span></summary>'
                . ($group['addresses'] ? '<p class="admin-meta">' . $t->html('wording.address') . '</p>' : '') . '<div class="admin-wording__entries">' . $entries . '</div></details>';
        }
        if ($shown === 0) {
            $groups = '<div class="admin-section admin-empty"><h2>' . $t->html('wording.no_match', ['query' => $query]) . '</h2><p><a href="/settings/wording">' . $t->html('wording.show_all') . '</a></p></div>';
        }
        $body .= '<form class="admin-form" method="post" action="/settings/wording"><input type="hidden" name="_csrf" value="' . Html::attribute($chrome->csrfToken) . '">'
            . '<input type="hidden" name="_revision" value="' . $saved->revision . '"><div class="admin-wording">' . $groups . '</div>'
            . ($shown === 0 ? '' : '<div class="admin-save-bar"><p class="admin-meta">' . $t->html('wording.next_release') . '</p><button type="submit" class="admin-button--primary">' . $t->html('wording.save') . '</button></div>') . '</form>'
            . '<form id="wording-reset" method="post" action="/settings/wording"><input type="hidden" name="_csrf" value="' . Html::attribute($chrome->csrfToken) . '"><input type="hidden" name="_revision" value="' . $saved->revision . '"></form>';
        return AdminShell::application($t->text('wording.title'), TrustedHtml::fromEngineMarkup($body), $profile, 'wording', 'standard', $chrome);
    }

    /**
     * Entries by where the text appears, in the order the profile first names each group. Texts without a
     * declared group share one; page address segments always form the last group, whatever they declare.
     * @return list<array{name: string, entries: list<WordingDefinition>, addresses: bool}>
     */
    public static function groups(ProfileManifest $profile, Translator $t): array
    {
        $groups = [];
        $addresses = [];
        foreach ($profile->wording as $definition) {
            if ($definition->kind === 'address_segment') {
                $addresses[] = $definition;
                continue;
            }
            $name = $definition->group ?? $t->text('wording.group.other');
            $groups[$name] ??= ['name' => $name, 'entries' => [], 'addresses' => false];
            $groups[$name]['entries'][] = $definition;
        }
        $groups = array_values($groups);
        if ($addresses !== []) {
            $groups[] = ['name' => $t->text('wording.group.addresses'), 'entries' => $addresses, 'addresses' => true];
        }
        return $groups;
    }

    /** @param list<string> $texts The texts shown for the entry, in the languages shown. */
    private static function matches(WordingDefinition $definition, array $texts, string $query): bool
    {
        foreach ([$definition->label, $definition->purpose, $definition->key, ...$texts, ...array_values($definition->defaults)] as $candidate) {
            if (mb_stripos($candidate, $query, 0, 'UTF-8') !== false) {
                return true;
            }
        }
        return false;
    }

    /** One row: the name with its explanation folded, a field per language, and the way back to the profile text once it differs.
     * @param array<string, string> $languages
     * @param array<string, string> $values
     * @param array<string, Message> $errors
     */
    private static function entry(WordingDefinition $definition, ProfileManifest $profile, SiteWording $saved, array $languages, array $values, array $errors, Translator $t, bool &$open): string
    {
        $fields = '';
        $changed = false;
        foreach ($languages as $locale => $language) {
            $field = SiteWording::field($definition->key, $locale);
            $value = $values[$field] ?? $saved->text($profile, $definition->key, $locale) ?? '';
            $default = $definition->defaults[$locale] ?? null;
            $changed = $changed || isset($saved->values[$definition->key][$locale]);
            $error = isset($errors[$field]) ? $t->message($errors[$field]) : null;
            $open = $open || $error !== null;
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
        $entryId = 'wording-' . str_replace('.', '-', $definition->key);
        return '<div class="admin-wording__entry" role="group" data-wording-key="' . Html::attribute($definition->key) . '" aria-labelledby="' . Html::attribute($entryId . '-label') . '" aria-describedby="' . Html::attribute($entryId . '-help') . '">'
            . '<div class="admin-wording__name"><p class="admin-help-row"><span class="admin-legend__text" id="' . Html::attribute($entryId . '-label') . '">' . Html::text($definition->label) . '</span>' . FieldHelp::toggle($entryId . '-help', $definition->label, $t) . '</p>'
            // Reset buttons submit their own form, so pressing Enter in a field saves instead.
            . ($changed ? '<button type="submit" form="wording-reset" name="_reset" value="' . Html::attribute($definition->key) . '" class="admin-button--small admin-button--quiet">' . $t->html('wording.reset') . '</button>' : '') . '</div>'
            . '<div class="admin-wording__languages">' . $fields . '</div>'
            . FieldHelp::text($entryId . '-help', Html::text($definition->purpose)) . '</div>';
    }
}
