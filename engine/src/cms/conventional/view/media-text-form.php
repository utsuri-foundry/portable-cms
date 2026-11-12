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
        $html = '<input type="hidden" name="' . Html::attribute($prefix . '_media_text') . '" value="1"' . $association . '>' . ($instructions ? '<p class="admin-help">' . $t->html('media.text.help', ['locale' => $profile->defaultLocale]) . '</p>' : '')
            . '<label><input type="checkbox" name="' . Html::attribute($prefix . 'decorative') . '" value="1"' . $association . ($document['decorative'] ? ' checked' : '') . '> ' . $t->html('media.text.decorative') . '</label>';
        foreach ($profile->locales as $locale) {
            $html .= '<fieldset class="admin-section"><legend>' . Html::text(strtoupper($locale)) . '</legend>';
            foreach (['alternativeText' => 'media.text.alternative', 'caption' => 'media.text.caption', 'credit' => 'media.text.credit'] as $field => $key) {
                $id = $prefix . $field . '__' . $locale;
                $html .= '<div class="admin-field"><label for="' . Html::attribute($id) . '">' . $t->html($key) . '</label><input type="text" id="' . Html::attribute($id) . '" name="' . Html::attribute($id) . '" maxlength="500"' . $association . ' value="' . Html::attribute($translations[$locale][$field] ?? '') . '"></div>';
            }
            $html .= '</fieldset>';
        }
        return $html;
    }
}
