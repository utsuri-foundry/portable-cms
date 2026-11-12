<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\View;

use Utsuri\PortableCms\Conventional\Content\EditorialControls;
use Utsuri\PortableCms\Conventional\Content\EditorialForm;
use Utsuri\PortableCms\Conventional\Content\FieldDefinition;
use Utsuri\PortableCms\Conventional\Content\RecordTypeDefinition;
use Utsuri\PortableCms\Conventional\Http\Html;
use Utsuri\PortableCms\Conventional\I18n\Message;
use Utsuri\PortableCms\Conventional\I18n\Translator;

final class EditorialRecordLayout
{
    /** Bounded editorial section vocabulary, in the order editors work: write first, configure later. */
    public const SECTIONS = ['content', 'media', 'layout', 'relationships', 'details', 'discovery'];

    /**
     * @param array<string, mixed> $values
     * @param array<string, Message> $errors
     * @param \Closure(FieldDefinition, mixed, ?string): string $render
     * @param array<string, mixed>|null $savedValues
     * @param array<string, string> $extras Trusted engine markup appended to a section.
     * @return array{outline: string, sections: string, properties: string, placed: list<string>}
     */
    public static function parts(RecordTypeDefinition $type, array $values, array $errors, \Closure $render, bool $technicalData, ?array $savedValues = null, array $extras = [], ?Translator $translator = null): array
    {
        $t = $translator ?? Translator::english();
        $groups = [...self::SECTIONS, 'properties'];
        $markup = array_fill_keys($groups, '');
        $issues = array_fill_keys($groups, 0);
        $pending = array_fill_keys($groups, []);
        $fields = array_fill_keys($groups, []);
        $technical = '';
        foreach ($type->fields as $field) {
            $section = EditorialForm::section($field);
            $value = $values[$field->id] ?? ($field->type === 'taxonomy_references' ? [] : '');
            $error = isset($errors[$field->id]) ? self::fieldMessage($errors[$field->id], $field, $t) : null;
            $issues[$section] += $error === null ? 0 : 1;
            if (!EditorialForm::supported($field)) {
                if ($field->type === 'blocks') {
                    $fields[$section][] = $field;
                    $markup[$section] .= '<div class="admin-notice"><h3>' . Html::text($field->label) . '</h3><p>' . $t->html('editor.pending_field') . '</p>'
                        . ($error === null ? '' : '<p role="alert">' . Html::text($error) . '</p>') . '</div>';
                }
                // Pending fields never expose editable serialized data; the server restores saved values.
                if ($technicalData) {
                    $json = is_string($value) ? $value : json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                    $technical .= '<h3>' . Html::text($field->label) . '</h3><p class="admin-meta">' . $t->html('editor.pending_field') . '</p><pre>' . Html::text($json) . '</pre>';
                }
                continue;
            }
            if ($savedValues !== null) {
                $saved = $savedValues[$field->id] ?? '';
                $submitted = $value;
                if (in_array($field->type, ['integer', 'decimal', 'date', 'short_text', 'enum', 'record_reference'], true)) {
                    $saved = is_scalar($saved) ? (string) $saved : '';
                    $submitted = is_scalar($submitted) ? (string) $submitted : '';
                }
                if ($saved !== $submitted) {
                    $pending[$section][] = $field->id;
                }
            }
            $fields[$section][] = $field;
            $control = self::identified($render($field, $value, $error), $field);
            if ($field->type === 'group' && !is_array($field->settings['searchSharing'] ?? null) && !self::requiresInput($field) && $error === null && self::empty($value)) {
                // An optional group nobody has filled reads as one line until it is opened.
                $control = preg_replace('/<legend>/', '<legend class="admin-visually-hidden">', $control, 1) ?? $control;
                $control = '<details class="admin-optional"><summary><span>' . Html::text($field->label) . '</span>'
                    . '<span class="admin-meta">' . $t->html('editor.optional.empty') . '</span></summary>' . $control . '</details>';
            }
            $markup[$section] .= $control;
        }
        $outline = '';
        $body = '';
        $placed = [];
        foreach (self::SECTIONS as $id) {
            if ($markup[$id] === '') {
                continue;
            }
            if (isset($extras[$id])) {
                $markup[$id] .= $extras[$id];
                $placed[] = $id;
            }
            $label = $t->text('editor.section.' . $id);
            $localized = $fields[$id] !== [] && array_filter($fields[$id], static fn(FieldDefinition $field): bool => !$field->localized) === [];
            if ($localized) {
                // One language badge for the section instead of one per field.
                $markup[$id] = str_replace(RecordPages::localeTag(), '', $markup[$id]);
            }
            $issueMarkup = $issues[$id] === 0 ? '' : '<span class="admin-outline__issues">' . $t->html('editor.issues', ['count' => $issues[$id]]) . '</span>';
            $outline .= '<li><a href="#section-' . $id . '" data-section-link="' . $id . '"><span>' . Html::text($label) . '</span>'
                . $issueMarkup . self::changes($id, $pending[$id], $t) . '</a></li>';
            $heading = '<h2 id="section-heading-' . $id . '"><span>' . Html::text($label) . ($localized ? RecordPages::localeTag() : '') . '</span>' . $issueMarkup . '</h2>';
            $collapsed = $issues[$id] === 0 && !isset($extras[$id]) && self::optional($fields[$id], $values);
            $body .= '<section class="admin-field-group' . ($issues[$id] === 0 ? '' : ' admin-field-group--issues') . '" id="section-' . $id . '" data-editorial-section="' . $id . '"'
                . ' data-issue-count="' . $issues[$id] . '" aria-labelledby="section-heading-' . $id . '">'
                . ($collapsed
                    ? '<details class="admin-section-toggle" data-section-toggle><summary>' . $heading . '<span class="admin-meta">' . $t->html('editor.optional.empty') . '</span></summary>'
                        . '<div class="admin-section-toggle__body">' . $markup[$id] . '</div></details>'
                    : $heading . $markup[$id])
                . '</section>';
        }
        if ($technical !== '') {
            $body .= '<details class="admin-technical"><summary>' . $t->html('editor.technical.summary') . '</summary>'
                . '<p>' . $t->html('editor.technical.help') . '</p>' . $technical . '</details>';
        }
        $properties = $markup['properties'] === '' ? '' : '<section class="admin-rail-card admin-properties' . ($issues['properties'] === 0 ? '' : ' admin-field-group--issues') . '" id="section-properties"'
            . ' data-editorial-section="properties" data-issue-count="' . $issues['properties'] . '" aria-labelledby="section-heading-properties">'
            . '<h2 id="section-heading-properties"><span>' . $t->html('editor.properties') . '</span>'
            . ($issues['properties'] === 0 ? '' : '<span class="admin-outline__issues">' . $t->html('editor.issues', ['count' => $issues['properties']]) . '</span>')
            . self::changes('properties', $pending['properties'], $t) . '</h2>' . $markup['properties'] . '</section>';
        return ['outline' => $outline, 'sections' => $body, 'properties' => $properties, 'placed' => $placed];
    }

    /**
     * Validation text with the field named as the editor sees it. Domain validation names
     * fields with profile defaults; the presented field carries the interface-language label.
     */
    public static function fieldMessage(Message $message, ?FieldDefinition $field, Translator $t): string
    {
        if ($field !== null && isset($message->parameters['field'])) {
            $message = new Message($message->key, ['field' => $field->label] + $message->parameters);
        }
        return $t->message($message);
    }

    /**
     * Names the field on its outermost element, so the live preview can find, reveal and focus
     * it, and says how its value is recognised on a page without profile marks.
     */
    private static function identified(string $control, FieldDefinition $field): string
    {
        $match = match ($field->type) {
            'short_text', 'localized_short_text' => 'text',
            'media_references' => 'media',
            'rich_text' => 'rich',
            default => '',
        };
        $attributes = ' data-field="' . Html::attribute($field->id) . '" data-field-label="' . Html::attribute($field->label) . '"'
            . ($match === '' ? '' : ' data-field-match="' . $match . '"');
        return preg_replace_callback('/\A\s*<[a-z][a-z0-9]*(?=[\s>])/', static fn(array $tag): string => $tag[0] . $attributes, $control, 1) ?? $control;
    }

    /** @param list<string> $pending */
    private static function changes(string $id, array $pending, Translator $t): string
    {
        return '<span data-section-changes="' . $id . '" data-pending-fields="' . Html::attribute(implode(' ', $pending)) . '">'
            . (count($pending) === 0 ? '' : $t->html('client.editor.changed', ['count' => (string) count($pending)])) . '</span>';
    }

    /**
     * A section starts collapsed only when nothing in it is required and nothing is filled in.
     *
     * @param list<FieldDefinition> $fields
     * @param array<string, mixed> $values
     */
    private static function optional(array $fields, array $values): bool
    {
        foreach ($fields as $field) {
            if (self::requiresInput($field) || !self::empty($values[$field->id] ?? null)) {
                return false;
            }
        }
        return $fields !== [];
    }

    /** Collapsed areas never hide a required control, which native validation could not focus. */
    private static function requiresInput(FieldDefinition $field): bool
    {
        foreach ($field->fields as $child) {
            if (self::requiresInput($child)) {
                return true;
            }
        }
        return $field->requiredOnDraft || $field->requiredOnPublish;
    }

    /** Whether a submitted or saved value carries nothing an editor entered. */
    private static function empty(mixed $value): bool
    {
        $value = EditorialControls::structured($value);
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if ($key !== 'formatVersion' && $key !== '_itemId' && !self::empty($item)) {
                    return false;
                }
            }
            return true;
        }
        return $value === null || $value === false || (is_string($value) && trim($value) === '');
    }
}
