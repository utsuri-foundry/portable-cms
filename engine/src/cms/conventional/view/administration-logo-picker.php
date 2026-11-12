<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\View;

use Utsuri\PortableCms\Conventional\Http\Html;
use Utsuri\PortableCms\Conventional\I18n\Translator;
use Utsuri\PortableCms\Conventional\Media\LibraryMedia;
use Utsuri\PortableCms\Conventional\Media\MediaPage;
use Utsuri\PortableCms\Conventional\Publication\AdministrationBranding;

/** Two bounded native pickers with independent search/page controls in the same settings form. */
final class AdministrationLogoPicker
{
    public static function render(string $field, string $selected, ?MediaPage $page, ?LibraryMedia $selection, Translator $t, bool $uploadsEnabled = false): string
    {
        if (!in_array($field, ['adminLogoMediaId', 'darkAdminLogoMediaId'], true)) {
            throw new \LogicException('Unknown administration logo field');
        }
        $title = $t->text($field === 'adminLogoMediaId' ? 'branding.logo' : 'branding.dark_logo');
        $body = '<fieldset class="admin-picker"><legend>' . Html::text($title) . '</legend>';
        if ($page === null) {
            return $body . '<p>' . $t->html('branding.unavailable') . '</p><input type="hidden" name="' . Html::attribute($field) . '" value="' . Html::attribute($selected) . '"></fieldset>';
        }
        $body .= '<label><input type="radio" name="' . $field . '" value=""' . ($selected === '' ? ' checked' : '') . '> ' . $t->html('picker.nothing') . '</label>';
        if ($selected !== '') {
            $body .= '<label>' . ($selection === null ? '' : ReferenceControls::thumbnail($selection)) . '<input type="radio" name="' . $field . '" value="' . Html::attribute($selected) . '" checked> ' . Html::text($selection->name ?? $selected) . '</label>';
        }
        $body .= '<details' . ($page->query !== '' || $page->page > 1 ? ' open' : '') . '><summary>' . $t->html('picker.add_media') . '</summary><label>' . $t->html('picker.find') . '<input type="search" name="_' . $field . '_query" maxlength="100" value="' . Html::attribute($page->query) . '"></label><button name="_' . $field . '_action" value="search" formnovalidate>' . $t->html('picker.search') . '</button><ul class="admin-picker__results">';
        foreach ($page->items as $item) {
            if ($item->id === $selected) {
                continue;
            }
            $face = $item->thumbnail;
            $eligible = $face !== null && $face->variant === '960-webp' && $face->mime === 'image/webp'
                && AdministrationBranding::eligible($item->status, $item->mime, $face->width, $face->height, $face->byteSize);
            $body .= '<li><label>' . ReferenceControls::thumbnail($item) . '<input type="radio" name="' . $field . '" value="' . Html::attribute($item->id) . '"' . ($eligible ? '' : ' disabled') . '> ' . Html::text($item->name) . '</label></li>';
        }
        $body .= '</ul><p>' . $t->html('records.pages.current', ['page' => (string) $page->page]) . '</p>';
        foreach ([$page->page > 1 ? $page->page - 1 : null, $page->hasNext ? $page->page + 1 : null] as $target) {
            if ($target !== null) {
                $body .= '<button name="_' . $field . '_action" value="page:' . $target . '" formnovalidate>' . $t->html($target < $page->page ? 'records.pages.previous' : 'records.pages.next') . '</button>';
            }
        }
        return $body . '</details>' . ($uploadsEnabled ? WebsiteImageUpload::controls($field, $t) : '') . '</fieldset>';
    }
}
