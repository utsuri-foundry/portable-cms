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
    /**
     * The address and title as plain inputs, a small link to the video, then the poster as one
     * row like an image field's. "Choose poster image" ("Change" once chosen) folds the library
     * search, its paging and the radio list, per language. The engine's own explanation gives
     * way to the profile's hint when there is one, so a label carries one help control.
     */
    public static function field(FieldDefinition $field, mixed $value, ?string $error, ProfileManifest $profile, Request $request, ?MediaLibrary $library, Translator $t): string
    {
        $value = EditorialControls::structured($value);
        $action = explode(':', $request->formValue('_editor_action'));
        $id = 'field-' . $field->id;
        $invalid = $error === null ? '' : ' aria-invalid="true" aria-describedby="' . Html::attribute($id . '-error') . '"';
        $label = Html::text($field->label) . ($field->required ? FieldHelp::required($t) : '');
        $html = $field->hint === null
            ? '<fieldset class="admin-field admin-picker admin-picker--media" id="' . Html::attribute($id) . '" aria-labelledby="' . Html::attribute($id . '-label') . '" aria-describedby="' . Html::attribute($id . '-help') . '">'
                . FieldHelp::legend($id . '-label', $label, $id . '-help', $field->label, $t) . FieldHelp::text($id . '-help', $t->html('vimeo.help'))
            : '<fieldset class="admin-field admin-picker admin-picker--media" id="' . Html::attribute($id) . '"><legend>' . $label . '</legend>';
        $html .= '<input type="hidden" name="_present_' . Html::attribute($field->id) . '" value="1">';
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
                    $html .= '<p class="admin-meta"><a href="' . Html::url($canonical) . '" target="_blank" rel="noopener noreferrer">' . $t->html('vimeo.open') . '</a></p>';
                }
            } catch (\InvalidArgumentException) {
            }
            if ($poster !== null) {
                $html .= '<ol class="admin-selections"><li class="admin-selection">' . ReferenceControls::thumbnail($poster) . '<span>' . Html::text($poster->name)
                    . '<a class="admin-selection__link" href="' . Html::url('/media/' . $poster->id) . '">' . $t->html('picker.image_details') . '</a></span></li></ol>';
            } elseif ($posterId !== '') {
                $html .= '<p class="admin-field__error">' . $t->html('video.missing') . '</p>';
            }
            // Each language searches on its own; the disclosure stays open while its search or paging is in progress.
            $query = mb_substr($request->formValue('_query_' . $prefix), 0, 100);
            $page = max(1, min(1000, (int) $request->formValue('_page_' . $prefix)));
            $browsing = ($action[1] ?? '') === $field->id && (($action[0] === 'search' && ($action[2] ?? '') === $locale) || ($action[0] === 'page' && ($action[3] ?? '') === $locale));
            if ($browsing) {
                $page = $action[0] === 'page' ? max(1, min(1000, (int) ($action[2] ?? 1))) : 1;
            }
            $results = $library?->search($query, $page);
            $pageAction = static fn(int $number): string => 'page:' . $field->id . ':' . $number . ($locale === '' ? '' : ':' . $locale);
            $html .= '<details class="admin-picker__browse"' . ($browsing ? ' open' : '') . '><summary>' . $t->html($poster === null ? 'vimeo.choose_poster' : 'vimeo.change_poster') . '</summary>'
                . '<div class="admin-picker__search"><label for="vimeo-query-' . Html::attribute($prefix) . '">' . $t->html('picker.find') . '</label><input id="vimeo-query-' . Html::attribute($prefix) . '" name="_query_' . Html::attribute($prefix) . '" type="search" maxlength="100" value="' . Html::attribute($query) . '">'
                . '<input type="hidden" name="_page_' . Html::attribute($prefix) . '" value="' . $page . '">' . ReferenceControls::button('search:' . $field->id . ($locale === '' ? '' : ':' . $locale), $t->text('picker.search')) . '</div>'
                . '<ul class="admin-picker__results">'
                . '<li><label><input type="radio" name="' . Html::attribute($prefix . '__poster') . '" value=""' . ($posterId === '' ? ' checked' : '') . '>' . $t->html('vimeo.no_poster') . '</label></li>';
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
            $html .= '</ul><div class="admin-picker__pagination"><span>' . $t->html('records.pages.current', ['page' => (string) $page]) . '</span>'
                . ($page > 1 ? ReferenceControls::button($pageAction($page - 1), $t->text('records.pages.previous')) : '')
                . ($results?->hasNext === true ? ReferenceControls::button($pageAction($page + 1), $t->text('records.pages.next')) : '') . '</div></details>' . ($definition === null ? '' : '</div>');
        }
        return $html . ($error === null ? '' : '<p role="alert" class="admin-field__error" id="' . Html::attribute($id . '-error') . '">' . Html::text($error) . '</p>') . '</fieldset>';
    }
}
