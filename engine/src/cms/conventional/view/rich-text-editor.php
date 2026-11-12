<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\View;

use Utsuri\PortableCms\Conventional\Content\FieldDefinition;
use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Content\RichText;
use Utsuri\PortableCms\Conventional\Content\RichTextForm;
use Utsuri\PortableCms\Conventional\Http\Html;
use Utsuri\PortableCms\Conventional\I18n\Translator;

final class RichTextEditor
{
    public static function saved(FieldDefinition $field, mixed $value, ProfileManifest $profile, ?Translator $translator = null): string
    {
        $t = $translator ?? Translator::english();
        $markup = '';
        foreach ($field->localized ? $profile->localeDefinitions : [null] as $locale) {
            $document = $locale === null ? $value : (is_array($value) ? ($value[$locale->id] ?? null) : null);
            $markup .= $locale === null ? '' : '<h4>' . Html::text($locale->label) . '</h4>';
            try {
                $markup .= $document === null ? '<p>' . $t->html('rich.no_text') . '</p>' : RichText::from($document, $field)->html();
            } catch (\InvalidArgumentException) {
                $markup .= '<p>' . $t->html('rich.unrenderable') . '</p>';
            }
        }
        return $markup;
    }

    public static function field(FieldDefinition $field, mixed $value, ?string $error, ProfileManifest $profile, ?Translator $translator = null): string
    {
        $t = $translator ?? Translator::english();
        $panels = '';
        foreach ($field->localized ? $profile->localeDefinitions : [null] as $locale) {
            $name = $field->id . ($locale === null ? '' : '__' . $locale->id);
            $document = $locale === null ? $value : (is_array($value) ? ($value[$locale->id] ?? null) : null);
            $panel = self::editor($field, $name, $document, $error, $t);
            if ($locale !== null) {
                $panel = '<div class="admin-locale" data-locale-panel="' . Html::attribute($locale->id) . '" lang="' . Html::attribute($locale->id) . '"><span class="admin-locale__label">' . Html::text($locale->label) . '</span>' . $panel . '</div>';
            }
            $panels .= $panel;
        }
        return '<fieldset class="admin-field" id="field-' . Html::attribute($field->id) . '"><legend>' . Html::text($field->label) . ($field->localized ? RecordPages::localeTag() : '') . '</legend>'
            . (($field->settings['maximumBlocks'] ?? null) === 1 ? '' : '<p class="admin-help" data-rich-fallback-help>' . $t->html('rich.help') . '</p>')
            . $panels . ($error === null ? '' : '<p class="admin-field__error" id="error-' . Html::attribute($field->id) . '">' . Html::text($error) . '</p>') . '</fieldset>';
    }

    private static function editor(FieldDefinition $field, string $name, mixed $value, ?string $error, Translator $t): string
    {
        $rows = RichTextForm::rows($value);
        $single = ($field->settings['maximumBlocks'] ?? null) === 1;
        if ($single && $rows === []) {
            $rows = [['type' => $field->settings['blocks'][0], 'children' => [['text' => '', 'marks' => []]]]];
        }
        $markup = '<input type="hidden" name="' . Html::attribute($name . '__rows') . '" value="' . count($rows) . '" data-rich-count>';
        foreach ($rows as $index => $row) {
            $prefix = $name . '__' . $index;
            $position = ['position' => (string) ($index + 1)];
            $options = '';
            foreach ($field->settings['blocks'] as $type) {
                $options .= '<option value="' . Html::attribute($type) . '"' . ($row['type'] === $type ? ' selected' : '') . '>' . $t->html('rich.type.' . $type) . '</option>';
            }
            $markup .= '<fieldset data-rich-row><legend' . ($single ? ' class="admin-visually-hidden"' : '') . '>' . $t->html('rich.block', $position) . '</legend>'
                . ($single ? '' : '<label><input type="checkbox" data-rich-selected> ' . $t->html('rich.select_block', $position) . '</label>')
                . ($single && $error === null
                    ? '<input type="hidden" name="' . Html::attribute($prefix . '__type') . '" value="' . Html::attribute($row['type']) . '" data-rich-type>'
                    : '<label>' . $t->html('rich.block_type') . '<select name="' . Html::attribute($prefix . '__type') . '" data-rich-type>' . $options . '</select></label>')
                . '<input type="hidden" name="' . Html::attribute($prefix . '__runs') . '" value="' . count($row['children']) . '" data-rich-runs>';
            foreach ($row['children'] as $span => $run) {
                $runName = $prefix . '__' . $span;
                $id = 'rich-' . $runName;
                $markup .= '<div class="admin-rich-run" data-rich-run><label for="' . Html::attribute($id) . '">' . $t->html('rich.segment', ['position' => (string) ($span + 1)]) . '</label>'
                    . '<textarea id="' . Html::attribute($id) . '" name="' . Html::attribute($runName . '__text') . '" maxlength="10000" rows="' . ($single ? '1' : '3') . '"' . ($single ? ' wrap="off"' : '') . ' data-rich-text'
                    . ($error === null ? '' : ' aria-invalid="true" aria-describedby="error-' . Html::attribute($field->id) . '"') . '>' . Html::text($run['text']) . '</textarea><div class="admin-rich-tools">';
                foreach (['bold', 'italic', 'underline', 'smallCaps'] as $mark) {
                    if (in_array($mark, $field->settings['marks'], true)) {
                        $key = $mark === 'smallCaps' ? 'small_caps' : $mark;
                        $markup .= '<label><input type="checkbox" name="' . Html::attribute($runName . '__' . $mark) . '" value="1" data-rich-mark="' . $mark . '"' . (in_array($mark, $run['marks'], true) ? ' checked' : '') . '> ' . $t->html('rich.mark.' . $key) . '</label>';
                    }
                }
                $href = '';
                foreach ($run['marks'] as $mark) {
                    if (is_array($mark)) {
                        $href = $mark['href'];
                    }
                }
                if (in_array('link', $field->settings['marks'], true)) {
                    $markup .= '<label>' . $t->html('rich.link') . '<input type="text" name="' . Html::attribute($runName . '__link') . '" value="' . Html::attribute($href) . '" maxlength="2048" data-rich-link></label>';
                }
                $markup .= self::button($name, 'delete-run', $index, $span, $t->text('rich.remove_segment')) . '</div></div>';
            }
            $markup .= '<div class="admin-rich-tools">' . self::button($name, 'run', $index, -1, $t->text('rich.add_segment'))
                . ($single ? '' : self::button($name, 'up', $index, -1, $t->text('rich.move_up')) . self::button($name, 'down', $index, -1, $t->text('rich.move_down')))
                . (!$single || count($rows) > 1 ? self::button($name, 'remove', $index, -1, $t->text('rich.remove_block')) : '') . '</div></fieldset>';
        }
        $markup .= '<input type="hidden" name="' . Html::attribute($name . '__end') . '" value="1">';
        return '<div class="admin-rich-editor' . ($single ? ' admin-rich-editor--single' : '') . '" data-rich-editor' . ($single ? ' data-rich-maximum-blocks="1"' : '') . ' data-rich-name="' . Html::attribute($name) . '" data-rich-blocks="' . Html::attribute(implode(' ', $field->settings['blocks'])) . '" data-rich-marks="' . Html::attribute(implode(' ', $field->settings['marks'])) . '"><div data-rich-rows>' . $markup . '</div>'
            . ($single ? '' : self::button($name, 'add', -1, -1, $t->text('rich.add_block'))) . '</div>';
    }

    private static function button(string $name, string $action, int $row, int $span, string $label): string
    {
        return '<button type="submit" name="_rich_action" value="' . Html::attribute($name . ':' . $action . ':' . $row . ':' . $span) . '" formnovalidate data-rich-action="' . $action . '">' . Html::text($label) . '</button>';
    }
}
