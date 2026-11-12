<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\View;

use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Design\DesignCss;
use Utsuri\PortableCms\Conventional\Design\RegistryDocument;
use Utsuri\PortableCms\Conventional\Design\RegistryForm;
use Utsuri\PortableCms\Conventional\Design\SizeCurve;
use Utsuri\PortableCms\Conventional\Http\Html;
use Utsuri\PortableCms\Conventional\I18n\Translator;

final class DesignPage
{
    private const GROUPS = ['typography', 'spacing', 'responsive', 'fonts'];

    /** @param array<string, string> $values */
    public static function render(string $csrf, ProfileManifest $profile, RegistryDocument $saved, bool $fontsAvailable, array $values = [], string $error = '', string $notice = '', bool $conflict = false, ?AdminChrome $chrome = null): string
    {
        $t = AdminShell::translator($chrome);
        $values += RegistryForm::values($saved);
        $document = $saved->document();
        $body = AdminShell::breadcrumbs([[$t->text('shell.group.website'), null], [$t->text('shell.nav.website_design'), null]], $t)
            . AdminShell::header($t->text('shell.nav.website_design'), '<p class="admin-lede">' . $t->html('design.lede') . '</p>');
        if ($error !== '') {
            $body .= '<div class="admin-notice admin-notice--danger" role="alert"><h2>' . $t->html('design.error.title') . '</h2><p>' . Html::text($error) . '</p><p>' . $t->html('design.error.preserved') . '</p></div>';
        }
        if ($notice !== '') {
            $body .= '<div class="admin-notice admin-notice--' . ($conflict ? 'warning' : 'success') . '" role="status"><p>' . Html::text($notice) . '</p></div>';
        }
        if ($conflict) {
            $body .= '<details open data-design-current class="admin-section"><summary>' . $t->html('design.conflict.current', ['revision' => (string) $saved->revision()]) . '</summary><dl>';
            foreach (RegistryForm::values($saved) as $name => $value) {
                $body .= '<dt>' . Html::text(str_replace('__', ' · ', $name)) . '</dt><dd>' . Html::text($value === '' ? $t->text('design.inherit') : $value) . '</dd>';
            }
            $body .= '</dl></details>';
        }
        $navigation = '';
        foreach (self::GROUPS as $group) {
            $navigation .= '<a href="#design-' . $group . '">' . $t->html('design.group.' . $group) . '</a>';
        }
        $body .= '<form class="admin-form" method="post" enctype="multipart/form-data" action="/settings/design" data-design-form>'
            . '<input type="hidden" name="_csrf" value="' . Html::attribute($csrf) . '"><input type="hidden" name="_revision" value="' . Html::attribute($conflict ? (string) $saved->revision() : ($values['_revision'] ?? (string) $saved->revision())) . '">'
            . '<div class="admin-subnav"><nav class="admin-segmented" aria-label="' . $t->html('design.sections') . '">' . $navigation . '</nav></div>';
        $specimens = DesignCss::specimens($saved);
        foreach (self::GROUPS as $group) {
            $body .= '<section class="admin-token-group" id="design-' . $group . '"' . ($group === 'typography' ? ' data-design-specimens' : '') . ' aria-labelledby="design-heading-' . $group . '">'
                . '<h2 id="design-heading-' . $group . '">' . $t->html('design.group.' . $group) . '</h2><p class="admin-help">' . $t->html('design.group.' . $group . '_help') . '</p>';
            foreach ($document[$group] as $item) {
                $prefix = $group . '__' . $item['id'] . '__';
                $body .= '<fieldset class="admin-token"><legend title="' . $t->html('design.stable_id', ['id' => $item['id']]) . '">' . Html::text($item['label']) . '</legend>' . '<div class="admin-token__grid">';
                $more = '';
                foreach (RegistryForm::fields($group) as $key) {
                    $control = match ($key) {
                        'fontId', 'fallback' => self::select($prefix . $key, $t->text($key === 'fontId' ? 'design.field.font' : 'design.field.fallback'), $values[$prefix . $key], self::choices($key, $document, $t)),
                        'minWidth' => self::input($prefix . $key, $t->text('design.field.min_width'), $values[$prefix . $key], true, true, unit: 'px'),
                        'lineHeight' => self::input($prefix . $key, $t->text('design.field.line_height'), $values[$prefix . $key], true, true, unit: '×'),
                        'weight' => self::input($prefix . $key, $t->text('design.field.weight'), $values[$prefix . $key], true, true),
                        default => self::input($prefix . $key, $t->text('design.field.label'), $values[$prefix . $key], false, true),
                    };
                    if (in_array($key, ['fontId', 'fallback', 'weight', 'lineHeight'], true)) {
                        $more .= $control;
                    } else {
                        $body .= $control;
                    }
                }
                if (isset($item['size'])) {
                    $body .= self::curve($prefix, $values, true, $t);
                }
                $body .= '</div>';
                if ($group === 'typography') {
                    $body .= self::specimen($specimens[$item['id']] ?? [], $values[$prefix . 'fallback'] ?? 'sans-serif', $values[$prefix . 'weight'] ?? '400', $t)
                        . '<details><summary>' . $t->html('design.more') . '</summary><div>' . $more . '</div></details>';
                    foreach ($document['responsive'] as $threshold) {
                        $body .= '<details><summary>' . $t->html('design.override', ['range' => $threshold['label']]) . '</summary><p class="admin-help">' . $t->html('design.override_help') . '</p><div>'
                            . self::curve($prefix . $threshold['id'] . '__', $values, false, $t) . '</div></details>';
                    }
                }
                if ($group === 'fonts') {
                    foreach ($saved->faces() as $face) {
                        if ($face['familyId'] !== $item['id']) {
                            continue;
                        }
                        $style = $t->text($face['style'] === 'italic' ? 'design.font.italic' : 'design.font.normal');
                        $body .= '<details data-font-face="' . Html::attribute($face['key']) . '"><summary>'
                            . $t->html('design.font.face_summary', ['label' => $face['label'], 'style' => $style, 'min' => (string) $face['weightMin'], 'max' => (string) $face['weightMax']])
                            . '</summary><p class="admin-help">' . Html::text($face['provenance']) . '</p><p class="admin-help">'
                            . $t->html('design.license', ['license' => $face['license']]) . '</p></details>';
                    }
                }
                $body .= '</fieldset>';
            }
            if ($group === 'fonts') {
                $body .= self::fontUpload($values, $fontsAvailable, $t, $saved);
            }
            $body .= '</section>';
        }
        $body .= '<div class="admin-save-bar"><p class="admin-meta">' . $t->html('design.saved_revision', ['revision' => (string) $saved->revision()]) . '</p><div class="admin-actions">'
            . '<button type="submit" name="_action" value="calculate">' . $t->html('design.calculate') . '</button><button type="submit" name="_action" value="save" class="admin-button--primary">'
            . ($conflict ? $t->html('design.save_over', ['revision' => (string) $saved->revision()]) : $t->html('design.save')) . '</button></div></div></form>';
        return AdminShell::application($t->text('shell.nav.website_design'), TrustedHtml::fromEngineMarkup($body), $profile, 'design', 'standard', $chrome);
    }

    /** @param array<int, float> $sizes Viewport width to CSS pixel size. */
    private static function specimen(array $sizes, string $fallback, string $weight, Translator $t): string
    {
        $family = in_array($fallback, ['serif', 'sans-serif', 'monospace'], true) ? $fallback : 'sans-serif';
        $weight = preg_match('/\A[1-9]00\z/', $weight) === 1 ? $weight : '400';
        $markup = '<div class="admin-specimen">';
        foreach ($sizes as $viewport => $size) {
            $height = (int) ceil($size * 1.25);
            $width = (int) ceil($size * 1.4);
            $markup .= '<figure><svg width="' . $width . '" height="' . $height . '" viewBox="0 0 ' . $width . ' ' . $height . '" aria-hidden="true">'
                . '<text x="0" y="' . (int) ceil($size) . '" font-size="' . Html::attribute(SizeCurve::number(round($size, 2))) . '" font-family="' . $family . '" font-weight="' . $weight . '">Aa</text></svg>'
                . '<figcaption>' . $t->html('design.specimen', ['size' => SizeCurve::number(round($size, 2)), 'viewport' => (string) $viewport]) . '</figcaption></figure>';
        }
        return $markup . '</div>';
    }

    /** @param array<string, string> $values */
    private static function fontUpload(array $values, bool $fontsAvailable, Translator $t, RegistryDocument $registry): string
    {
        $markup = '<fieldset class="admin-token"><legend>' . $t->html('design.font.add') . '</legend><p class="admin-help">' . $t->html('design.font.help') . '</p>';
        if (!$fontsAvailable) {
            return $markup . '<p class="admin-notice" data-font-unavailable>' . $t->html('design.font.unavailable') . '</p></fieldset>';
        }
        $families = ['' => $t->text('design.font.new_family')];
        foreach ($registry->document()['fonts'] as $font) {
            $families[$font['id']] = $font['label'];
        }
        $markup .= self::select('font_family', $t->text('design.font.family'), $values['font_family'] ?? '', $families)
            . '<p class="admin-help">' . $t->html('design.font.family_help') . '</p>';
        foreach (['id', 'label', 'provenance', 'license'] as $key) {
            $markup .= self::input('font_' . $key, $t->text('design.font.' . $key), $values['font_' . $key] ?? '', false, false, $key === 'provenance' || $key === 'license' ? 2000 : 80);
        }
        $markup .= self::input('font_face_id', $t->text('design.font.face_id'), $values['font_face_id'] ?? 'regular', false, false, 80)
            . self::input('font_face_label', $t->text('design.font.face_label'), $values['font_face_label'] ?? $t->text('design.font.normal'), false, false)
            . self::select('font_style', $t->text('design.font.style'), $values['font_style'] ?? 'normal', ['normal' => $t->text('design.font.normal'), 'italic' => $t->text('design.font.italic')])
            . self::input('font_weight_min', $t->text('design.font.weight_min'), $values['font_weight_min'] ?? '400', true, false)
            . self::input('font_weight_max', $t->text('design.font.weight_max'), $values['font_weight_max'] ?? '400', true, false)
            . '<p class="admin-help">' . $t->html('design.font.face_help') . '</p>';
        return $markup . '<div class="admin-field"><label for="font-file">' . $t->html('design.font.file') . '</label><input id="font-file" name="font_file" type="file" accept=".woff2,font/woff2"></div>'
            . '<p><label class="admin-checkbox"><input type="checkbox" name="font_permission" value="yes"' . (($values['font_permission'] ?? '') === 'yes' ? ' checked' : '') . '> ' . $t->html('design.font.permission') . '</label></p></fieldset>';
    }

    /**
     * @param array<string, mixed> $document
     * @return array<string, string>
     */
    private static function choices(string $key, array $document, Translator $t): array
    {
        if ($key === 'fallback') {
            return ['serif' => $t->text('design.fallback.serif'), 'sans-serif' => $t->text('design.fallback.sans_serif'), 'monospace' => $t->text('design.fallback.monospace')];
        }
        $choices = ['' => $t->text('design.system_font')];
        foreach ($document['fonts'] as $font) {
            $choices[$font['id']] = $font['label'];
        }
        return $choices;
    }

    /** @param array<string, string> $values */
    private static function curve(string $prefix, array $values, bool $required, Translator $t): string
    {
        $html = '';
        foreach (['min', 'max', 'from', 'to'] as $key) {
            $html .= self::input($prefix . $key, $t->text('design.curve.' . $key), $values[$prefix . $key] ?? '', true, $required, unit: 'px');
        }
        return $html;
    }

    private static function input(string $name, string $label, string $value, bool $numeric, bool $required, int $maximum = 100, string $unit = ''): string
    {
        $input = '<input id="' . Html::attribute($name) . '" name="' . Html::attribute($name) . '" type="text"'
            . ($numeric ? ' inputmode="decimal"' : '') . ($required ? ' required' : '') . ' maxlength="' . $maximum . '" value="' . Html::attribute($value) . '">';
        return '<div class="admin-field"><label for="' . Html::attribute($name) . '">' . Html::text($label) . '</label>'
            . ($unit === '' ? $input : '<div class="admin-input-group">' . $input . '<span class="admin-input-unit" aria-hidden="true">' . Html::text($unit) . '</span></div>') . '</div>';
    }

    /** @param array<string, string> $choices */
    private static function select(string $name, string $label, string $value, array $choices): string
    {
        $html = '<div class="admin-field"><label for="' . Html::attribute($name) . '">' . Html::text($label) . '</label><select id="' . Html::attribute($name) . '" name="' . Html::attribute($name) . '">';
        foreach ($choices as $id => $text) {
            $html .= '<option value="' . Html::attribute($id) . '"' . ($id === $value ? ' selected' : '') . '>' . Html::text($text) . '</option>';
        }
        return $html . '</select></div>';
    }
}
