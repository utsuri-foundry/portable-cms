<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\View;

use Utsuri\PortableCms\Conventional\Http\Html;
use Utsuri\PortableCms\Conventional\I18n\Translator;
use Utsuri\PortableCms\Conventional\Media\LibraryMedia;
use Utsuri\PortableCms\Conventional\Media\MediaPage;
use Utsuri\PortableCms\Conventional\Publication\AdministrationBranding;

/**
 * The administration logo and its dark theme variant: the chosen image shown on the backing it
 * gets, then a chooser that lists only images that qualify. Two pickers share one settings form
 * with independent search and paging.
 */
final class AdministrationLogoPicker
{
    public static function render(string $field, string $selected, ?MediaPage $page, ?LibraryMedia $selection, Translator $t, bool $uploadsEnabled = false, bool $readOnly = false): string
    {
        if (!in_array($field, ['adminLogoMediaId', 'darkAdminLogoMediaId'], true)) {
            throw new \LogicException('Unknown administration logo field');
        }
        $dark = $field === 'darkAdminLogoMediaId';
        $title = $t->text($dark ? 'branding.dark_logo' : 'branding.logo');
        $id = 'logo-' . ($dark ? 'dark' : 'light');
        $body = '<fieldset class="admin-field admin-picker admin-logo-choice" aria-labelledby="' . $id . '-label" aria-describedby="' . $id . '-help" data-logo-picker="' . Html::attribute($field) . '">'
            . FieldHelp::legend($id . '-label', Html::text($title), $id . '-help', $title, $t) . FieldHelp::text($id . '-help', $t->html($dark ? 'branding.dark_help' : 'branding.logo_help'));
        if ($page === null) {
            return $body . '<p class="admin-meta">' . $t->html('branding.unavailable') . '</p><input type="hidden" name="' . Html::attribute($field) . '" value="' . Html::attribute($selected) . '"></fieldset>';
        }
        $disabled = $readOnly ? ' disabled' : '';
        if ($selected !== '') {
            $body .= '<div class="admin-icon__current"><label class="admin-checkbox"><input type="radio" name="' . $field . '" value="' . Html::attribute($selected) . '" checked' . $disabled . '> <span>' . Html::text($selection === null ? $t->text('picker.unavailable_image') : $selection->name) . '</span></label>'
                . ($selection === null ? '' : '<div class="admin-logo-preview' . ($dark ? ' admin-logo-preview--dark' : '') . '" data-logo-preview>' . ReferenceControls::thumbnail($selection) . '</div>') . '</div>';
        }
        $body .= '<label class="admin-checkbox"><input type="radio" name="' . $field . '" value=""' . ($selected === '' ? ' checked' : '') . $disabled . '> ' . $t->html('branding.none') . '</label>';
        if ($readOnly) {
            return $body . '</fieldset>';
        }
        $eligible = [];
        foreach ($page->items as $item) {
            $face = $item->thumbnail;
            if ($item->id !== $selected && $face !== null && $face->variant === '960-webp' && $face->mime === 'image/webp'
                && AdministrationBranding::eligible($item->status, $item->mime, $face->width, $face->height, $face->byteSize)) {
                $eligible[] = $item;
            }
        }
        $browsing = $page->query !== '' || $page->page > 1;
        $body .= '<details class="admin-optional admin-picker__chooser"' . ($browsing ? ' open' : '') . '><summary><span>' . $t->html('branding.choose') . '</span></summary>'
            . '<div class="admin-picker__search"><label for="' . $id . '-query">' . $t->html('picker.find') . '</label><input id="' . $id . '-query" type="search" name="_' . $field . '_query" maxlength="100" value="' . Html::attribute($page->query) . '"><button name="_' . $field . '_action" value="search" formnovalidate>' . $t->html('picker.search') . '</button></div>';
        if ($eligible === []) {
            $body .= '<p class="admin-meta">' . $t->html($browsing ? 'picker.no_matches' : 'branding.empty') . '</p>';
        } else {
            $body .= '<ul class="admin-picker__results">';
            foreach ($eligible as $item) {
                $body .= '<li><label class="admin-checkbox">' . ReferenceControls::thumbnail($item) . '<input type="radio" name="' . $field . '" value="' . Html::attribute($item->id) . '"> <span>' . Html::text($item->name) . '</span></label></li>';
            }
            $body .= '</ul>';
        }
        return $body . HoldingIconPicker::paging($page, '_' . $field . '_action', $t) . '</details>' . ($uploadsEnabled ? WebsiteImageUpload::controls($field, $t) : '') . '</fieldset>';
    }
}
