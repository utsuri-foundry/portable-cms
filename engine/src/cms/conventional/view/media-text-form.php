<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\View;

use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Http\Html;
use Utsuri\PortableCms\Conventional\Http\Request;
use Utsuri\PortableCms\Conventional\I18n\Translator;
use Utsuri\PortableCms\Conventional\Media\MediaText;

/** Controls live in the single revision-protected native media form. */
final class MediaTextForm
{
    /** @return array{formatVersion: int, decorative: bool, translations: list<array{locale: string, alternativeText: string, caption: string, credit: string}>} */
    public static function input(Request $request, ProfileManifest $profile, string $prefix = ''): array
    {
        $translations = [];
        foreach ($profile->locales as $locale) {
            $translations[] = ['locale' => $locale, 'alternativeText' => $request->formValue($prefix . 'alternativeText__' . $locale), 'caption' => $request->formValue($prefix . 'caption__' . $locale), 'credit' => $request->formValue($prefix . 'credit__' . $locale)];
        }
        return ['formatVersion' => 1, 'decorative' => $request->formValue($prefix . 'decorative') === '1', 'translations' => $translations];
    }

    /** @param ?array<string, mixed> $submitted */
    public static function render(?MediaText $text, ProfileManifest $profile, Translator $t, ?array $submitted = null, string $prefix = '', string $form = '', bool $instructions = true): string
    {
        $document = $submitted ?? ($text ?? MediaText::from())->document();
        $translations = [];
        foreach ($document['translations'] as $item) {
            $translations[$item['locale']] = $item;
        }
        $association = $form === '' ? '' : ' form="' . Html::attribute($form) . '"';
        $labels = [];
        foreach ($profile->localeDefinitions as $definition) {
            $labels[$definition->id] = $definition->label;
        }
        $html = '<input type="hidden" name="' . Html::attribute($prefix . '_media_text') . '" value="1"' . $association . '>' . ($instructions ? FieldHelp::heading('<span class="admin-field__label">' . $t->html('media.text.title') . '</span>', $prefix . 'media-text-help', $t->text('media.text.title'), $t) . FieldHelp::text($prefix . 'media-text-help', $t->html('media.text.help', ['locale' => $labels[$profile->defaultLocale] ?? $profile->defaultLocale])) : '');
        // One language at a time, by its name; caption and credit fold away until they hold text.
        foreach ($profile->locales as $locale) {
            $field = static fn(string $name): string => $prefix . $name . '__' . $locale;
            $input = static fn(string $name): string => '<input type="text" id="' . Html::attribute($field($name)) . '" name="' . Html::attribute($field($name)) . '" maxlength="500"' . $association . ' value="' . Html::attribute($translations[$locale][$name] ?? '') . '">';
            $more = ($translations[$locale]['caption'] ?? '') !== '' || ($translations[$locale]['credit'] ?? '') !== '';
            $html .= '<fieldset class="admin-media-text"><legend>' . Html::text($labels[$locale] ?? strtoupper($locale)) . '</legend>'
                . '<div class="admin-field"><label class="admin-visually-hidden" for="' . Html::attribute($field('alternativeText')) . '">' . $t->html('media.text.alternative') . '</label>' . $input('alternativeText') . '</div>'
                . '<details class="admin-optional admin-optional--quiet admin-media-text__more"' . ($more ? ' open' : '') . '><summary><span>' . $t->html('media.text.more') . '</span></summary>'
                . '<div class="admin-field"><label for="' . Html::attribute($field('caption')) . '">' . $t->html('media.text.caption') . '</label>' . $input('caption') . '</div>'
                . '<div class="admin-field"><label for="' . Html::attribute($field('credit')) . '">' . $t->html('media.text.credit') . '</label>' . $input('credit') . '</div></details></fieldset>';
        }
        // The decorative choice explains itself once, on the item page; batch cards repeat the control, not the help.
        return $html . '<div class="admin-help-row"><label class="admin-checkbox"><input type="checkbox" name="' . Html::attribute($prefix . 'decorative') . '" value="1"' . $association . ($document['decorative'] ? ' checked' : '') . '><span>' . $t->html('media.text.decorative') . '</span></label>'
            . ($instructions ? FieldHelp::toggle($prefix . 'media-decorative-help', $t->text('media.text.decorative'), $t) : '') . '</div>' . ($instructions ? FieldHelp::text($prefix . 'media-decorative-help', $t->html('media.text.decorative_help')) : '');
    }
}
