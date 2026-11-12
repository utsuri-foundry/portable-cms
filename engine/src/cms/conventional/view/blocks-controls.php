<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\View;

use Utsuri\PortableCms\Conventional\Content\Blocks;
use Utsuri\PortableCms\Conventional\Content\BlocksForm;
use Utsuri\PortableCms\Conventional\Content\EditorialControls;
use Utsuri\PortableCms\Conventional\Content\FieldDefinition;
use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Http\Html;
use Utsuri\PortableCms\Conventional\I18n\Message;
use Utsuri\PortableCms\Conventional\I18n\Translator;

/** Ordered block cards use the existing native leaves and the repeatable-list drag island. */
final class BlocksControls
{
    /** @param \Closure(FieldDefinition, mixed, ?string, FieldDefinition): string $render
     * @param array<string, Message> $errors
     */
    public static function field(FieldDefinition $field, mixed $value, ProfileManifest $profile, \Closure $render, ?string $error, array $errors, Translator $t): string
    {
        $body = EditorialControls::structured($value);
        $body = is_array($body) ? $body : [];
        // Rejected envelopes stay visible as a field error; never turn them into trusted controls.
        try {
            $entries = Blocks::items($field, $body, $profile->supported(), partial: true);
        } catch (\InvalidArgumentException) {
            return '<fieldset class="admin-field" id="field-' . Html::attribute($field->id) . '"><legend>' . Html::text($field->label) . '</legend><p class="admin-field__error" role="alert">' . Html::text($error ?? $t->text('validation.values_invalid', ['field' => $field->label])) . '</p></fieldset>';
        }
        $markup = '<fieldset class="admin-field" id="field-' . Html::attribute($field->id) . '"><legend>' . Html::text($field->label) . ($field->localized ? RecordPages::localeTag() : '') . '</legend>'
            . '<input type="hidden" name="_present_' . Html::attribute($field->id) . '" value="1">'
            . ($error === null ? '' : '<p class="admin-field__error" role="alert">' . Html::text($error) . '</p>');
        foreach ($field->localized ? $profile->localeDefinitions : [null] as $definition) {
            $locale = $definition->id ?? '';
            $prefix = BlocksForm::prefix($field, $locale);
            $items = array_values(array_filter($entries, static fn(array $entry): bool => $entry['locale'] === $locale));
            $minimum = ($definition->preparation ?? false) ? 0 : max($field->settings['minimumItems'], $field->required ? 1 : 0);
            $markup .= $definition === null ? '' : '<div class="admin-locale" data-locale-panel="' . Html::attribute($locale) . '" lang="' . Html::attribute($locale) . '"><span class="admin-locale__label">' . Html::text($definition->label) . '</span>';
            $markup .= '<div data-group-rows data-blocks="' . Html::attribute($prefix) . '"><ol class="admin-block-list" data-group-list>';
            foreach ($items as $position => $entry) {
                $item = $entry['item'];
                $group = $entry['field'];
                $block = $field->blockTypes[$item['typeId']];
                $id = $item['_itemId'];
                $title = $t->text('blocks.position', ['type' => $block->label, 'position' => '{position}']);
                $titleAt = str_replace('{position}', (string) ($position + 1), $title);
                $summary = self::summary($group, $item['values'], $profile, $t);
                $childErrors = [];
                foreach ($group->fields as $child) {
                    $name = EditorialControls::childName($group->id, $child->id);
                    if (isset($errors[$name])) {
                        $childErrors[$name] = $errors[$name];
                    }
                }
                $open = $childErrors !== [] || ($error !== null && count($errors) === 1) || $summary === '' || GroupControls::missing($group, $item['values']);
                $markup .= '<li class="admin-block-card" data-group-row data-block-type="' . Html::attribute($item['typeId']) . '"><span class="admin-group-row__grip" data-group-grip hidden aria-hidden="true"></span>'
                    . '<fieldset class="admin-group-card"><legend data-group-text="' . Html::attribute($title) . '">' . Html::text($titleAt) . '</legend>'
                    . '<input type="hidden" name="' . Html::attribute($prefix . '_items[]') . '" value="' . Html::attribute($id) . '">'
                    . '<input type="hidden" name="' . Html::attribute($prefix . '_i' . $id . '__type') . '" value="' . Html::attribute($item['typeId']) . '">'
                    . '<details class="admin-group-card__details"' . ($open ? ' open' : '') . '><summary><strong data-group-text="' . Html::attribute($title) . '">' . Html::text($titleAt) . '</strong>' . ($summary === '' ? '' : ' · ' . Html::text($summary)) . '</summary><div class="admin-group-card__body">';
                foreach ($group->fields as $child) {
                    $control = GroupControls::child($child, $group->id, '', $definition->preparation ?? false);
                    $message = $childErrors[$control->id] ?? null;
                    $html = $render($control, $item['values'][$child->id] ?? null, $message === null ? null : EditorialRecordLayout::fieldMessage($message, $child, $t), $group);
                    $path = \Utsuri\PortableCms\Conventional\Compiler\FieldMarks::itemPath($field->id, $id, $child->id);
                    $label = $field->label . ' · ' . $title . ' · ' . $child->label;
                    $match = match ($child->type) {
                        'short_text', 'localized_short_text' => 'text', 'rich_text' => 'rich', 'media_references' => 'media', default => '',
                    };
                    $attributes = ' data-field="' . Html::attribute($path) . '" data-field-control="' . Html::attribute($control->id) . '" data-field-item data-field-locale="' . Html::attribute($locale) . '" data-field-match="' . $match . '" data-field-label="' . Html::attribute(str_replace('{position}', (string) ($position + 1), $label)) . '" data-group-field-label="' . Html::attribute($label) . '"';
                    $markup .= preg_replace_callback('/\A\s*<[a-z][a-z0-9]*(?=[\s>])/', static fn(array $tag): string => $tag[0] . $attributes, $html, 1) ?? $html;
                }
                $suffix = ':' . $field->id . ':' . $locale . ':' . $id;
                $markup .= '<div class="admin-group-actions">';
                foreach ([['up', 'group.move_up', $position === 0], ['down', 'group.move_down', $position + 1 === count($items)], ['duplicate', 'blocks.duplicate', count($items) >= $field->settings['maximumItems']], ['remove', 'group.remove', count($items) <= $minimum]] as [$operation, $key, $disabled]) {
                    $action = $t->text($key);
                    $label = $t->text('blocks.action', ['action' => $action, 'block' => $title, 'field' => $field->label]);
                    $markup .= '<button type="submit" name="_editor_action" value="' . Html::attribute($operation . $suffix) . '" formnovalidate data-group-action="' . $operation . '" data-group-label="' . Html::attribute($label) . '" aria-label="' . Html::attribute(str_replace('{position}', (string) ($position + 1), $label)) . '"' . ($disabled ? ' disabled' : '') . '>' . Html::text($action) . '</button>';
                }
                $markup .= '</div>' . self::add($field, $prefix, $locale, $id, count($items), $title, $position, $t) . '</div></details></fieldset></li>';
            }
            $markup .= '</ol>' . self::add($field, $prefix, $locale, '', count($items), '', 0, $t) . '<p class="admin-group-count">' . $t->html('group.count', ['count' => count($items)]) . '</p></div>' . ($definition === null ? '' : '</div>');
        }
        return $markup . '</fieldset>';
    }

    /** @param array<string, mixed> $values */
    private static function summary(FieldDefinition $group, array $values, ProfileManifest $profile, Translator $t): string
    {
        foreach ($group->fields as $child) {
            $value = $values[$child->id] ?? null;
            if ($child->localized && is_array($value)) {
                $value = $value[$profile->defaultLocale] ?? null;
            }
            if ($child->type === 'rich_text') {
                try {
                    $values[$child->id] = \Utsuri\PortableCms\Conventional\Content\RichText::from($value, $child)->plainText();
                } catch (\InvalidArgumentException) {
                    unset($values[$child->id]);
                }
            } elseif (in_array($child->type, ['record_reference', 'record_references', 'taxonomy_references', 'media_references'], true)) {
                $count = count(EditorialControls::selections($value, $child));
                $values[$child->id] = $count === 0 ? '' : $child->label . ' · ' . $t->text('group.count', ['count' => $count]);
            } elseif (in_array($child->type, ['video_reference', 'composition'], true)) {
                // Technical IDs and serialized documents never become a card's summary.
                unset($values[$child->id]);
            }
        }
        return GroupControls::summary($group, $values, $profile);
    }

    private static function add(FieldDefinition $field, string $prefix, string $locale, string $target, int $count, string $block, int $position, Translator $t): string
    {
        if ($count >= $field->settings['maximumItems']) {
            return '';
        }
        $name = $prefix . '__add_' . ($target === '' ? 'end' : $target);
        $options = '';
        foreach ($field->blockTypes as $type) {
            $options .= '<option value="' . Html::attribute($type->id) . '">' . Html::text($type->label) . '</option>';
        }
        $label = $t->text($target === '' ? 'blocks.add' : 'blocks.insert');
        $accessible = $target === '' ? $label . ' · ' . $field->label . ($locale === '' ? '' : ' · ' . $locale) : $t->text('blocks.action', ['action' => $label, 'block' => $block, 'field' => $field->label]);
        $attrs = ' data-group-label="' . Html::attribute($accessible) . '" aria-label="' . Html::attribute(str_replace('{position}', (string) ($position + 1), $accessible)) . '"';
        return '<div class="admin-block-add"><label for="' . Html::attribute($name) . '">' . $t->html('blocks.type') . '</label><select id="' . Html::attribute($name) . '" name="' . Html::attribute($name) . '"' . $attrs . '>' . $options . '</select><button type="submit" name="_editor_action" value="' . Html::attribute(($target === '' ? 'add' : 'insert') . ':' . $field->id . ':' . $locale . ($target === '' ? '' : ':' . $target)) . '" formnovalidate' . $attrs . '>' . Html::text($label) . '</button></div>';
    }
}
