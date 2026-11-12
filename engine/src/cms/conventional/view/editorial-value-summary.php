<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\View;

use Utsuri\PortableCms\Conventional\Content\EditorialControls;
use Utsuri\PortableCms\Conventional\Content\FieldDefinition;
use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Http\Html;
use Utsuri\PortableCms\Conventional\I18n\Translator;

/** Read-only conflict comparison, never a second form or serialized-data editor. */
final class EditorialValueSummary
{
    public static function field(FieldDefinition $field, mixed $value, ProfileManifest $profile, ?ReferenceControls $references, ?Translator $translator = null): string
    {
        $t = $translator ?? Translator::english();
        if ($field->type === 'vimeo_video') {
            $raw = EditorialControls::structured($value);
            $markup = '';
            foreach ($field->localized ? $profile->locales : [''] as $locale) {
                $entry = $field->localized && is_array($raw) ? ($raw[$locale] ?? null) : $raw;
                if ($entry !== null) {
                    $video = \Utsuri\PortableCms\Conventional\Content\VimeoVideo::from($entry);
                    $markup .= '<p>' . Html::text(($locale === '' ? '' : $locale . ': ') . $video->title) . '</p><p>' . Html::text($video->pageUrl()) . '</p>';
                }
            }
            return $markup === '' ? '<p>' . $t->html('field.none') . '</p>' : $markup;
        }
        if ($field->type === 'web_address') {
            $raw = EditorialControls::structured($value);
            $markup = '';
            foreach ($field->localized ? $profile->locales : [''] as $locale) {
                $entry = $field->localized && is_array($raw) ? ($raw[$locale] ?? null) : $raw;
                if (is_array($entry) && is_string($entry['url'] ?? null) && is_string($entry['linkText'] ?? null)) {
                    $markup .= '<p>' . Html::text(($locale === '' ? '' : $locale . ': ') . $entry['url']) . '</p><p>' . Html::text($entry['linkText']) . '</p>';
                }
            }
            return $markup === '' ? '<p>' . $t->html('field.none') . '</p>' : $markup;
        }
        if ($references !== null && in_array($field->type, ['record_reference', 'record_references', 'taxonomy_references', 'media_references', 'video_reference'], true)) {
            return $references->summary($field, $value);
        }
        if ($field->type === 'composition') {
            $layout = EditorialControls::structured($value);
            $rows = is_array($layout) && is_array($layout['rows'] ?? null) ? $layout['rows'] : [];
            $columns = 0;
            foreach ($rows as $row) {
                $columns += is_array($row) && is_array($row['columns'] ?? null) ? count($row['columns']) : 0;
            }
            return '<p>' . $t->html('summary.layout', ['rows' => count($rows), 'columns' => $columns]) . '</p>';
        }
        if (in_array($field->type, ['group', 'repeatable_group'], true)) {
            $group = EditorialControls::structured($value);
            $group = is_array($group) ? $group : [];
            $markup = '';
            foreach ($field->localized ? $profile->locales : [''] as $locale) {
                $container = $field->localized ? ($group[$locale] ?? []) : $group;
                $items = $field->type === 'group' ? [$container] : $container;
                if (!is_array($items)) {
                    continue;
                }
                foreach ($items as $position => $item) {
                    $item = is_array($item) ? $item : [];
                    $markup .= '<div><strong>' . Html::text($locale === '' ? $t->text('group.item', ['position' => (string) ((int) $position + 1)]) : $locale . ' · ' . ((int) $position + 1)) . '</strong><dl>';
                    foreach ($field->fields as $child) {
                        $definition = EditorialControls::childDefinition($child, $child->id, $locale !== '');
                        $markup .= '<dt>' . Html::text($child->label) . '</dt><dd>' . self::field($definition, $item[$child->id] ?? null, $profile, $references, $t) . '</dd>';
                    }
                    $markup .= '</dl></div>';
                }
            }
            return $markup === '' ? '<p>' . $t->html('field.none') . '</p>' : $markup;
        }
        if (is_bool($value)) {
            return $t->html($value ? 'summary.yes' : 'summary.no');
        }
        if (is_array($value)) {
            $markup = '';
            foreach ($value as $locale => $localized) {
                if (is_scalar($localized)) {
                    $markup .= '<p><span>' . Html::text((string) $locale) . ':</span> ' . Html::text((string) $localized) . '</p>';
                }
            }
            return $markup;
        }
        return '<p>' . Html::text(is_scalar($value) ? (string) $value : '') . '</p>';
    }
}
