<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\View;

use Utsuri\PortableCms\Conventional\Content\EditorialControls;
use Utsuri\PortableCms\Conventional\Content\FieldDefinition;
use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Content\VimeoVideo;
use Utsuri\PortableCms\Conventional\Http\Html;
use Utsuri\PortableCms\Conventional\Http\Request;
use Utsuri\PortableCms\Conventional\I18n\Translator;
use Utsuri\PortableCms\Conventional\Media\MediaLibrary;

/** Native controls only: the administration never embeds a Vimeo frame. */
final class VimeoVideoControl
{
    public static function field(FieldDefinition $field, mixed $value, ?string $error, ProfileManifest $profile, Request $request, ?MediaLibrary $library, Translator $t): string
    {
        $value = EditorialControls::structured($value);
        $query = mb_substr($request->formValue('_query_' . $field->id), 0, 100);
        $page = max(1, min(1000, (int) $request->formValue('_page_' . $field->id)));
        $action = explode(':', $request->formValue('_editor_action'));
        if (($action[1] ?? '') === $field->id) {
            $page = ($action[0] ?? '') === 'page' ? max(1, min(1000, (int) ($action[2] ?? 1))) : 1;
        }
        $results = $library?->search($query, $page);
        $id = 'field-' . $field->id;
        $invalid = $error === null ? '' : ' aria-invalid="true" aria-describedby="' . Html::attribute($id . '-error') . '"';
        $html = '<fieldset class="admin-field admin-picker admin-picker--media" id="' . Html::attribute($id) . '"><legend>' . Html::text($field->label) . '</legend>'
            . '<input type="hidden" name="_present_' . Html::attribute($field->id) . '" value="1">';
        foreach ($field->localized ? $profile->localeDefinitions : [null] as $definition) {
            $locale = $definition->id ?? '';
            $entry = $field->localized && is_array($value) ? ($value[$locale] ?? null) : $value;
            $url = is_array($entry) && is_string($entry['url'] ?? null) ? $entry['url'] : '';
            try {
                if ($entry !== null && $url === '') {
                    $url = VimeoVideo::from($entry)->pageUrl();
                }
            } catch (\InvalidArgumentException) {
                // Invalid native values stay visible for correction below.
            }
            $title = is_array($entry) && is_string($entry['title'] ?? null) ? $entry['title'] : '';
            $posterId = is_array($entry) && is_string($entry['poster'] ?? null) ? $entry['poster'] : '';
            $poster = $library?->find($posterId);
            $prefix = $field->id . ($locale === '' ? '' : '__' . $locale);
            if ($definition !== null) {
                $html .= '<div class="admin-locale" data-locale-panel="' . Html::attribute($locale) . '" lang="' . Html::attribute($locale) . '"><h3 class="admin-locale__label">' . Html::text($definition->label) . '</h3>';
            }
            $html .= '<label for="' . Html::attribute($prefix . '__url') . '">' . $t->html('vimeo.url') . '</label><input id="' . Html::attribute($prefix . '__url') . '" name="' . Html::attribute($prefix . '__url') . '" type="url" inputmode="url" spellcheck="false" autocapitalize="none" maxlength="' . VimeoVideo::MAXIMUM_ADDRESS . '" value="' . Html::attribute($url) . '"' . $invalid . '>'
                . '<label for="' . Html::attribute($prefix . '__title') . '">' . $t->html('vimeo.title') . '</label><input id="' . Html::attribute($prefix . '__title') . '" name="' . Html::attribute($prefix . '__title') . '" type="text" maxlength="' . VimeoVideo::MAXIMUM_TITLE . '" value="' . Html::attribute($title) . '"' . $invalid . '>';
            try {
                if ($url !== '') {
                    $address = VimeoVideo::address($url);
                    $canonical = 'https://vimeo.com/' . $address['videoId'] . ($address['privacyHash'] === null ? '' : '/' . $address['privacyHash']);
                    $html .= '<p><a href="' . Html::url($canonical) . '" target="_blank" rel="noopener noreferrer">' . $t->html('vimeo.open') . '</a></p>';
                }
            } catch (\InvalidArgumentException) {
            }
            $html .= '<p class="admin-meta">' . $t->html('vimeo.poster') . ': ' . Html::text($poster->name ?? ($posterId === '' ? $t->text('field.none') : $t->text('video.missing'))) . '</p>'
                . ($poster === null ? '' : ReferenceControls::thumbnail($poster))
                . '<details class="admin-picker__browse"><summary>' . $t->html('vimeo.choose_poster') . '</summary><ul class="admin-picker__results">'
                . '<li><label><input type="radio" name="' . Html::attribute($prefix . '__poster') . '" value=""' . ($posterId === '' ? ' checked' : '') . '>' . $t->html('field.none') . '</label></li>';
            $items = [];
            if ($poster !== null) {
                $items[$poster->id] = $poster;
            }
            foreach ($results->items ?? [] as $item) {
                if (str_starts_with($item->mime, 'image/')) {
                    $items[$item->id] = $item;
                }
            }
            foreach ($items as $item) {
                $ready = $item->status === 'ready' && $item->thumbnail?->mime === 'image/webp' && str_starts_with($item->mime, 'image/');
                $html .= '<li><label>' . ReferenceControls::thumbnail($item) . '<input type="radio" name="' . Html::attribute($prefix . '__poster') . '" value="' . $item->id . '"' . ($posterId === $item->id ? ' checked' : '') . (!$ready && $posterId !== $item->id ? ' disabled' : '') . '>' . Html::text($item->name)
                    . '<small class="admin-meta">' . Html::text(ReferenceControls::metadata($item, $t)) . '</small></label></li>';
            }
            if ($posterId !== '' && $poster === null) {
                $html .= '<li><label><input type="radio" name="' . Html::attribute($prefix . '__poster') . '" value="' . Html::attribute($posterId) . '" checked>' . $t->html('video.missing') . '</label></li>';
            }
            $html .= '</ul></details>' . ($definition === null ? '' : '</div>');
        }
        $html .= '<div class="admin-picker__search"><label for="vimeo-query-' . Html::attribute($field->id) . '">' . $t->html('picker.find') . '</label><input id="vimeo-query-' . Html::attribute($field->id) . '" name="_query_' . Html::attribute($field->id) . '" type="search" maxlength="100" value="' . Html::attribute($query) . '">'
            . '<input type="hidden" name="_page_' . Html::attribute($field->id) . '" value="' . $page . '">' . ReferenceControls::button('search:' . $field->id, $t->text('picker.search'))
            . ($page > 1 ? ReferenceControls::button('page:' . $field->id . ':' . ($page - 1), $t->text('records.pages.previous')) : '')
            . ($results?->hasNext === true ? ReferenceControls::button('page:' . $field->id . ':' . ($page + 1), $t->text('records.pages.next')) : '') . '</div>'
            . '<p class="admin-field__hint">' . $t->html('vimeo.help') . '</p>';
        return $html . ($error === null ? '' : '<p role="alert" class="admin-field__error" id="' . Html::attribute($id . '-error') . '">' . Html::text($error) . '</p>') . '</fieldset>';
    }
}
