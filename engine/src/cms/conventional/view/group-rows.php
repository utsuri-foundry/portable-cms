<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\View;

use Utsuri\PortableCms\Conventional\Content\EditorialControls;
use Utsuri\PortableCms\Conventional\Content\FieldDefinition;
use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Content\WebAddress;
use Utsuri\PortableCms\Conventional\Http\Html;
use Utsuri\PortableCms\Conventional\I18n\Translator;

/**
 * A repeatable group of short fields as one aligned list: column names once, the same tracks
 * on every row and one native actions menu per row. Every control keeps a complete accessible
 * name with its row position. Texts that name a position also carry a `{position}` template,
 * so the reordering island can renumber them without the message catalog.
 */
final class GroupRows
{
    private const POSITION = '{position}';

    public static function field(FieldDefinition $field, mixed $value, ProfileManifest $profile, ?string $error, string $focusAttributes, Translator $t): string
    {
        $group = EditorialControls::structured($value);
        $group = is_array($group) ? $group : [];
        $markup = '<fieldset class="admin-field admin-group" id="field-' . Html::attribute($field->id) . '"' . $focusAttributes . '><legend>' . Html::text($field->label)
            . ($field->localized ? RecordPages::localeTag() : '') . '</legend>'
            . '<input type="hidden" name="_present_' . Html::attribute($field->id) . '" value="1">'
            . ($error === null ? '' : '<p class="admin-field__error" role="alert">' . Html::text($error) . '</p>');
        foreach ($field->localized ? $profile->localeDefinitions : [null] as $definition) {
            $locale = $definition->id ?? '';
            $prefix = $field->id . ($locale === '' ? '' : '_' . $locale);
            $items = $field->localized ? ($group[$locale] ?? []) : $group;
            $items = is_array($items) ? array_values($items) : [];
            if ($definition !== null) {
                $markup .= '<div class="admin-locale" data-locale-panel="' . Html::attribute($locale) . '" lang="' . Html::attribute($locale) . '">'
                    . '<span class="admin-locale__label">' . Html::text($definition->label) . '</span>';
            }
            $children = array_map(static fn(FieldDefinition $child): FieldDefinition => GroupControls::child($child, '', $locale), $field->fields);
            $head = '<span></span>';
            foreach ($children as $index => $child) {
                $head .= '<span>' . Html::text($child->label) . ($child->localized ? RecordPages::localeTag() : '')
                    . ($child->hint === null ? '' : '<small class="admin-field__hint" id="hint-' . Html::attribute($prefix . '-' . $field->fields[$index]->id) . '">' . Html::text($child->hint) . '</small>') . '</span>';
            }
            $rows = '';
            foreach ($items as $position => $item) {
                $rows .= self::row($field, is_array($item) ? $item : [], $position, count($items), $prefix, $locale, $profile, $t);
            }
            $markup .= '<div class="admin-group-rows admin-group-rows--cells-' . count($field->fields) . '" data-group-rows>'
                . '<div class="admin-group-rows__head" aria-hidden="true">' . $head . '<span></span></div>'
                . '<ol class="admin-group-rows__list" data-group-list>' . $rows . '</ol>'
                . GroupControls::footer($field, count($items), $locale, $definition?->label, $t) . '</div>'
                . ($definition === null ? '' : '</div>');
        }
        return $markup . '</fieldset>';
    }

    /** @param array<string, mixed> $item */
    private static function row(FieldDefinition $field, array $item, int $position, int $count, string $prefix, string $locale, ProfileManifest $profile, Translator $t): string
    {
        $id = is_string($item['_itemId'] ?? null) ? $item['_itemId'] : EditorialControls::legacyIdentity($prefix, $position, $item);
        $itemPrefix = $prefix . '_i' . $id;
        $suffix = ':' . $field->id . ':' . $locale . ':' . $id;
        $row = '<li class="admin-group-row" data-group-row><input type="hidden" name="' . Html::attribute($prefix . '_items[]') . '" value="' . Html::attribute($id) . '">'
            . '<span class="admin-group-row__grip" data-group-grip aria-hidden="true" hidden></span>';
        foreach ($field->fields as $child) {
            $definition = GroupControls::child($child, $itemPrefix, $locale, $locale !== '' && self::preparation($profile, $locale));
            // A translated child's languages in one row are compared with each other only.
            $group = $definition->localized ? ' data-locale-group="' . Html::attribute($field->label . ': ' . $child->label) . '"' : '';
            $cell = self::cell($definition, $item[$child->id] ?? null, $position, $profile, $t);
            $row .= '<div class="admin-group-cell"' . $group . GroupControls::itemLink($field, $id, $child, $position, $locale, $t) . '>'
                . ($child->hint === null ? $cell : FieldHint::describe($cell, 'hint-' . $prefix . '-' . $child->id)) . '</div>';
        }
        $actions = [
            ['up', 'group.move_up', $position === 0],
            ['down', 'group.move_down', $position + 1 >= $count],
            ['insert', 'group.insert', $count >= (int) $field->maximumItems],
            ['remove', 'group.remove', false],
        ];
        $buttons = '';
        foreach ($actions as [$operation, $key, $disabled]) {
            $label = static fn(string $at): string => $t->text('group.row_action', ['action' => $t->text($key), 'position' => $at, 'group' => $field->label]);
            $buttons .= '<button type="submit" name="_editor_action" value="' . Html::attribute($operation . $suffix) . '" formnovalidate'
                . ($operation === 'remove' ? ' class="admin-group-row__remove"' : '')
                . ' aria-label="' . Html::attribute($label((string) ($position + 1))) . '" data-group-label="' . Html::attribute($label(self::POSITION)) . '"'
                . ($disabled ? ' disabled' : '') . ' data-group-action="' . $operation . '">' . $t->html($key) . '</button>';
        }
        $menu = static fn(string $at): string => $t->text('group.row_actions', ['position' => $at, 'group' => $field->label]);
        return $row . '<details class="admin-group-row__menu" data-group-menu><summary aria-label="' . Html::attribute($menu((string) ($position + 1))) . '" data-group-label="' . Html::attribute($menu(self::POSITION)) . '">'
            . '<span aria-hidden="true">⋯</span></summary><div class="admin-group-row__actions">' . $buttons . '</div></details></li>';
    }

    private static function preparation(ProfileManifest $profile, string $locale): bool
    {
        foreach ($profile->localeDefinitions as $definition) {
            if ($definition->id === $locale) {
                return $definition->preparation;
            }
        }
        return false;
    }

    /** One child's control, or one per language when the child is translated inside an untranslated group. */
    private static function cell(FieldDefinition $child, mixed $value, int $position, ProfileManifest $profile, Translator $t): string
    {
        if (!$child->localized) {
            return self::control($child, $child->id, 'field-' . $child->id, $value, $position, null, $t);
        }
        $values = is_array($value) ? $value : [];
        $markup = '';
        foreach ($profile->localeDefinitions as $definition) {
            $markup .= '<div class="admin-group-cell__locale" data-locale-panel="' . Html::attribute($definition->id) . '" lang="' . Html::attribute($definition->id) . '">'
                . self::control($child, $child->id . '__' . $definition->id, 'field-' . $child->id . '-' . $definition->id, $values[$definition->id] ?? null, $position, count($profile->locales) > 1 ? $definition->label : null, $t, $definition->preparation) . '</div>';
        }
        return $markup;
    }

    private static function control(FieldDefinition $child, string $name, string $id, mixed $value, int $position, ?string $language, Translator $t, bool $preparation = false): string
    {
        $required = $child->required && !$preparation ? ' required' : '';
        if ($child->type === 'web_address') {
            $entry = is_array($value) ? $value : [];
            return self::label($id . '__url', $child->label . ': ' . $t->text('address.url'), $language, $position, $t)
                . '<input id="' . Html::attribute($id . '__url') . '" name="' . Html::attribute($name . '__url') . '" type="url" inputmode="url" spellcheck="false" autocapitalize="none" maxlength="'
                . ($child->maximumLength ?? WebAddress::MAXIMUM_LENGTH) . '" value="' . Html::attribute(is_string($entry['url'] ?? null) ? $entry['url'] : '') . '"' . $required . '>'
                . self::label($id . '__linkText', $child->label . ': ' . $t->text('address.link_text'), $language, $position, $t)
                . '<textarea id="' . Html::attribute($id . '__linkText') . '" name="' . Html::attribute($name . '__linkText') . '" rows="1" maxlength="' . WebAddress::MAXIMUM_TEXT . '" data-group-line>'
                . Html::text(is_string($entry['linkText'] ?? null) ? $entry['linkText'] : '') . '</textarea>';
        }
        $label = self::label($id, $child->label, $language, $position, $t);
        if ($child->type === 'boolean') {
            return $label . '<input id="' . Html::attribute($id) . '" name="' . Html::attribute($name) . '" type="checkbox" role="switch" value="1"' . ($value === true || $value === '1' ? ' checked' : '') . '>';
        }
        if ($child->type === 'enum') {
            $options = $required === '' ? '<option value="">' . $t->html('field.none') . '</option>' : '';
            foreach ($child->settings['options'] ?? [] as $option) {
                if (is_array($option) && is_string($option['value'] ?? null) && is_string($option['label'] ?? null)) {
                    $options .= '<option value="' . Html::attribute($option['value']) . '"' . ($value === $option['value'] ? ' selected' : '') . '>' . Html::text($option['label']) . '</option>';
                }
            }
            return $label . '<select id="' . Html::attribute($id) . '" name="' . Html::attribute($name) . '"' . $required . '>' . $options . '</select>';
        }
        if (in_array($child->type, ['integer', 'decimal'], true)) {
            $bounds = $child->type === 'decimal' ? ' step="any"' : '';
            foreach (['minimum' => 'min', 'maximum' => 'max'] as $setting => $attribute) {
                $bound = $child->settings[$setting] ?? null;
                $bounds .= is_int($bound) || is_float($bound) ? ' ' . $attribute . '="' . Html::attribute((string) $bound) . '"' : '';
            }
            return $label . '<input id="' . Html::attribute($id) . '" name="' . Html::attribute($name) . '" type="number" placeholder="' . Html::attribute($child->label) . '" value="' . Html::attribute(is_scalar($value) ? (string) $value : '') . '"' . $bounds . $required . '>';
        }
        // Long names wrap instead of scrolling out of sight; the island keeps them on one line.
        return $label . '<textarea id="' . Html::attribute($id) . '" name="' . Html::attribute($name) . '" rows="1" maxlength="' . (int) $child->maximumLength . '" placeholder="' . Html::attribute($child->label) . '" data-group-line' . $required . '>'
            . Html::text(is_string($value) ? $value : '') . '</textarea>';
    }

    /** The column name is shown only when rows stack; the position always completes the name. */
    private static function label(string $for, string $name, ?string $language, int $position, Translator $t): string
    {
        $at = static fn(string $position): string => $t->text('group.row_position', ['position' => $position]);
        return '<label class="admin-group-cell__label" for="' . Html::attribute($for) . '"><span class="admin-group-cell__name">' . Html::text($name) . '</span>'
            . ($language === null ? '' : ' <span class="admin-group-cell__language">' . Html::text($language) . '</span>')
            . '<span class="admin-visually-hidden" data-group-text="' . Html::attribute($at(self::POSITION)) . '">' . Html::text($at((string) ($position + 1))) . '</span></label>';
    }
}
