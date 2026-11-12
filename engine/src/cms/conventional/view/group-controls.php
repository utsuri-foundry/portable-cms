<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\View;

use Utsuri\PortableCms\Conventional\Content\EditorialControls;
use Utsuri\PortableCms\Conventional\Content\FieldDefinition;
use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Http\Html;
use Utsuri\PortableCms\Conventional\I18n\Translator;

final class GroupControls
{
    /** @param \Closure(FieldDefinition, mixed): string $render */
    public static function field(FieldDefinition $field, mixed $value, ProfileManifest $profile, \Closure $render, ?string $error, string $focusAttributes = '', ?Translator $translator = null): string
    {
        $t = $translator ?? Translator::english();
        if ($field->rows()) {
            return GroupRows::field($field, $value, $profile, $error, $focusAttributes, $t);
        }
        $group = EditorialControls::structured($value);
        $group = is_array($group) ? $group : [];
        $markup = '<fieldset class="admin-field" id="field-' . Html::attribute($field->id) . '"' . $focusAttributes . '><legend>' . Html::text($field->label)
            . ($field->localized ? RecordPages::localeTag() : '') . '</legend>'
            . '<input type="hidden" name="_present_' . Html::attribute($field->id) . '" value="1">'
            . ($error === null ? '' : '<p class="admin-field__error" role="alert">' . Html::text($error) . '</p>');
        $locales = $field->localized ? $profile->localeDefinitions : [null];
        foreach ($locales as $definition) {
            $locale = $definition->id ?? '';
            $prefix = $field->id . ($locale === '' ? '' : '_' . $locale);
            $container = $field->localized ? ($group[$locale] ?? []) : $group;
            $container = is_array($container) ? $container : [];
            $items = $field->type === 'repeatable_group' ? $container : [$container];
            if ($definition !== null) {
                $markup .= '<div class="admin-locale" data-locale-panel="' . Html::attribute($locale) . '" lang="' . Html::attribute($locale) . '">'
                    . '<span class="admin-locale__label">' . Html::text($definition->label) . '</span>';
            }
            foreach ($items as $position => $item) {
                $item = is_array($item) ? $item : [];
                $id = is_string($item['_itemId'] ?? null) ? $item['_itemId'] : EditorialControls::legacyIdentity($prefix, (int) $position, $item);
                $itemPrefix = $field->type === 'repeatable_group' ? $prefix . '_i' . $id : $prefix;
                $markup .= '<fieldset class="admin-group-card"><legend>' . Html::text($field->type === 'repeatable_group' ? $t->text('group.item', ['position' => (string) ($position + 1)]) : $field->label) . '</legend>';
                if ($field->type === 'repeatable_group') {
                    // Items fold to their first values; one that still needs attention stays open.
                    $summary = self::summary($field, $item, $profile);
                    $markup .= '<details class="admin-group-card__details"' . ($error !== null || $summary === '' || self::missing($field, $item) ? ' open' : '') . '>'
                        . '<summary>' . Html::text($summary === '' ? $t->text('group.item', ['position' => (string) ($position + 1)]) : $summary) . '</summary><div class="admin-group-card__body">'
                        . '<input type="hidden" name="' . Html::attribute($prefix . '_items[]') . '" value="' . Html::attribute($id) . '">';
                }
                foreach ($field->fields as $child) {
                    $control = $render(self::child($child, $itemPrefix, $locale, $definition->preparation ?? false), $item[$child->id] ?? null);
                    $markup .= $field->type === 'repeatable_group'
                        ? (preg_replace_callback('/\A\s*<[a-z][a-z0-9]*(?=[\s>])/', static fn(array $tag): string => $tag[0] . self::itemLink($field, $id, $child, (int) $position, $locale, $t), $control, 1) ?? $control) : $control;
                }
                if ($field->type === 'repeatable_group') {
                    $suffix = ':' . $field->id . ':' . $locale . ':' . $id;
                    $name = ['position' => (string) ($position + 1), 'group' => $field->label];
                    $markup .= '<div class="admin-group-actions">'
                        . ($position > 0 ? ReferenceControls::button('up' . $suffix, '↑', $t->text('group.move_earlier', $name)) : '')
                        . ($position + 1 < count($items) ? ReferenceControls::button('down' . $suffix, '↓', $t->text('group.move_later', $name)) : '')
                        . ReferenceControls::button('remove' . $suffix, $t->text('group.remove'), $t->text('group.remove_label', $name), 'admin-button--quiet') . '</div></div></details>';
                }
                $markup .= '</fieldset>';
            }
            if ($field->type === 'repeatable_group') {
                $markup .= self::footer($field, count($items), $locale, $definition?->label, $t);
            }
            $markup .= $definition === null ? '' : '</div>';
        }
        return $markup . '</fieldset>';
    }

    /** The add button, named after one item, and how many items the list holds. */
    public static function footer(FieldDefinition $field, int $count, string $locale, ?string $language, Translator $t): string
    {
        $item = $field->itemLabel();
        $text = $item === null ? $t->text('group.add_to', ['group' => $field->label]) : $t->text('group.add_item', ['item' => $item]);
        $button = $count < (int) $field->maximumItems
            ? ReferenceControls::button('add:' . $field->id . ':' . $locale, '+ ' . $text, $language === null ? null : $t->text('group.add_language', ['action' => $text, 'language' => $language]), 'admin-group-add')
            : '';
        return '<div class="admin-group-foot">' . $button . '<span class="admin-group-count">' . $t->html('group.count', ['count' => $count]) . '</span></div>';
    }

    /** Live-page linking metadata; the ordinary native field remains the authority. */
    public static function itemLink(FieldDefinition $group, string $id, FieldDefinition $child, int $position, string $locale, Translator $t): string
    {
        $path = \Utsuri\PortableCms\Conventional\Compiler\FieldMarks::itemPath($group->id, $id, $child->id);
        $label = static fn(string $at): string => $group->label . ' · ' . $t->text('group.item', ['position' => $at]) . ' · ' . $child->label;
        return ' data-field="' . Html::attribute($path) . '" data-field-label="' . Html::attribute($label((string) ($position + 1))) . '"'
            . ' data-group-field-label="' . Html::attribute($label('{position}')) . '" data-field-locale="' . Html::attribute($locale) . '" data-field-item'
            . (in_array($child->type, ['short_text', 'localized_short_text'], true) ? ' data-field-match="text"' : '');
    }

    /**
     * The first two values of an item on one line, in the default language when translated.
     *
     * @param array<mixed> $item
     */
    public static function summary(FieldDefinition $field, array $item, ProfileManifest $profile): string
    {
        $values = [];
        foreach ($field->fields as $child) {
            $value = $item[$child->id] ?? null;
            if (is_array($value) && isset($value[$profile->defaultLocale])) {
                $value = $value[$profile->defaultLocale];
            }
            if (is_array($value)) {
                $value = $value['linkText'] ?? $value['url'] ?? null;
            }
            if ($child->type === 'enum') {
                foreach ($child->settings['options'] ?? [] as $option) {
                    $value = is_array($option) && ($option['value'] ?? null) === $value ? ($option['label'] ?? $value) : $value;
                }
            }
            $text = is_string($value) || is_int($value) || is_float($value) ? trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '') : '';
            if ($text !== '' && $child->type !== 'boolean') {
                $values[] = $text;
            }
            if (count($values) === 2) {
                break;
            }
        }
        $line = implode(' · ', $values);
        return mb_strlen($line) > 120 ? mb_substr($line, 0, 119) . '…' : $line;
    }

    /** @param array<mixed> $item */
    public static function missing(FieldDefinition $field, array $item): bool
    {
        foreach ($field->fields as $child) {
            $value = $item[$child->id] ?? null;
            if (($child->requiredOnDraft || $child->requiredOnPublish) && ($value === null || $value === '' || $value === [] || (is_array($value) && in_array('', $value, true)))) {
                return true;
            }
        }
        return false;
    }
    /**
     * A group leaf as its own native control, named so the ordinary group input reads it back.
     * In a language in preparation nothing is required.
     */
    public static function child(FieldDefinition $child, string $itemPrefix, string $locale, bool $preparation = false): FieldDefinition
    {
        return new FieldDefinition(
            EditorialControls::childName($itemPrefix, $child->id),
            $child->label,
            $locale !== '' && $child->type === 'localized_short_text' ? 'short_text' : $child->type,
            $locale === '' && $child->localized,
            $child->requiredOnDraft && !$preparation,
            $child->requiredOnPublish && !$preparation,
            $child->settings,
            [],
            $child->maximumLength,
            $child->targetType,
            $child->maximumItems,
            hint: $child->hint,
        );
    }
}
