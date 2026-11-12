<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\View;

use Utsuri\PortableCms\Conventional\Content\EditorialControls;
use Utsuri\PortableCms\Conventional\Content\FieldDefinition;
use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Content\WebAddress;
use Utsuri\PortableCms\Conventional\Http\Html;
use Utsuri\PortableCms\Conventional\I18n\Translator;

final class WebAddressControl
{
    public static function field(FieldDefinition $field, mixed $value, ?string $error, ProfileManifest $profile, Translator $t): string
    {
        $value = EditorialControls::structured($value);
        $maximum = $field->maximumLength ?? WebAddress::MAXIMUM_LENGTH;
        $id = 'field-' . $field->id;
        $invalid = $error === null ? '' : ' aria-invalid="true" aria-describedby="' . Html::attribute($id . '-error') . '"';
        $body = '<fieldset class="admin-field" id="' . Html::attribute($id) . '"><legend>' . Html::text($field->label)
            . ($field->required ? ' <span class="admin-field__required">' . $t->html('field.required') . '</span>' : '')
            . ($field->localized ? RecordPages::localeTag() : '') . '</legend><input type="hidden" name="_present_' . Html::attribute($field->id) . '" value="1">';
        foreach ($field->localized ? $profile->localeDefinitions : [null] as $definition) {
            $locale = $definition === null ? '' : $definition->id;
            $entry = $field->localized && is_array($value) ? ($value[$locale] ?? null) : $value;
            $url = is_array($entry) && is_string($entry['url'] ?? null) ? $entry['url'] : '';
            $text = is_array($entry) && is_string($entry['linkText'] ?? null) ? $entry['linkText'] : '';
            $prefix = $field->id . ($locale === '' ? '' : '__' . $locale);
            if ($definition !== null) {
                $body .= '<div class="admin-locale" data-locale-panel="' . Html::attribute($locale) . '" lang="' . Html::attribute($locale) . '"><h3 class="admin-locale__label">' . Html::text($definition->label) . '</h3>';
            }
            $body .= '<label for="' . Html::attribute($prefix . '__url') . '">' . $t->html('address.url') . '</label><input id="' . Html::attribute($prefix . '__url') . '" name="' . Html::attribute($prefix . '__url') . '" type="url" inputmode="url" spellcheck="false" autocapitalize="none" maxlength="' . $maximum . '" value="' . Html::attribute($url) . '"' . ($field->required && !($definition->preparation ?? false) ? ' required' : '') . $invalid . '>'
                . '<label for="' . Html::attribute($prefix . '__linkText') . '">' . $t->html('address.link_text') . '</label><input id="' . Html::attribute($prefix . '__linkText') . '" name="' . Html::attribute($prefix . '__linkText') . '" type="text" maxlength="' . WebAddress::MAXIMUM_TEXT . '" value="' . Html::attribute($text) . '"' . $invalid . '>';
            $body .= $definition === null ? '' : '</div>';
        }
        $body .= '<p class="admin-field__hint">' . $t->html('address.help', ['maximum' => $maximum]) . '</p>';
        return $body . ($error === null ? '' : '<p class="admin-field__error" id="' . Html::attribute($id . '-error') . '" role="alert">' . Html::text($error) . '</p>') . '</fieldset>';
    }
}
