<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\View;

use Utsuri\PortableCms\Conventional\Content\WebsiteIconSelection;
use Utsuri\PortableCms\Conventional\Http\Html;
use Utsuri\PortableCms\Conventional\I18n\Translator;
use Utsuri\PortableCms\Conventional\Media\LibraryMedia;
use Utsuri\PortableCms\Conventional\Media\MediaPage;

/** Bounded native image picker; browsing preserves all unsaved settings. */
final class HoldingIconPicker
{
    public static function render(string $selected, ?MediaPage $page, ?LibraryMedia $selection, Translator $t, bool $uploadsEnabled = false): string
    {
        if ($page === null) {
            return '<label>' . $t->html('holding.icon_id') . '<input name="iconMediaId" maxlength="32" pattern="[a-f0-9]{32}" value="' . Html::attribute($selected) . '"></label>';
        }
        $body = '<fieldset class="admin-picker"><legend>' . $t->html('holding.icon') . '</legend><label><input type="radio" name="iconMediaId" value=""' . ($selected === '' ? ' checked' : '') . '> ' . $t->html('picker.nothing') . '</label>';
        if ($selected !== '') {
            $body .= '<label>' . ($selection === null ? '' : ReferenceControls::thumbnail($selection)) . '<input type="radio" name="iconMediaId" value="' . Html::attribute($selected) . '" checked> ' . Html::text($selection === null ? $selected : $selection->name) . '</label>';
        }
        $body .= '<details' . ($page->query !== '' || $page->page > 1 ? ' open' : '') . '><summary>' . $t->html('picker.add_media') . '</summary><label>' . $t->html('picker.find') . '<input type="search" name="_icon_query" maxlength="100" value="' . Html::attribute($page->query) . '"></label><button name="_icon_action" value="search" formnovalidate>' . $t->html('picker.search') . '</button><ul class="admin-picker__results">';
        foreach ($page->items as $item) {
            if ($item->id === $selected) {
                continue;
            }
            $eligible = WebsiteIconSelection::eligible($item->status, $item->mime, $item->width, $item->height);
            $body .= '<li><label>' . ReferenceControls::thumbnail($item) . '<input type="radio" name="iconMediaId" value="' . Html::attribute($item->id) . '"' . ($eligible ? '' : ' disabled') . '> ' . Html::text($item->name) . '</label>'
                . ($eligible ? '' : '<small>' . $t->html('picker.icon_requirements') . '</small>') . '</li>';
        }
        $body .= '</ul><p>' . $t->html('records.pages.current', ['page' => (string) $page->page]) . '</p>';
        if ($page->page > 1) {
            $body .= '<button name="_icon_action" value="page:' . ($page->page - 1) . '" formnovalidate>' . $t->html('records.pages.previous') . '</button>';
        }
        if ($page->hasNext) {
            $body .= '<button name="_icon_action" value="page:' . ($page->page + 1) . '" formnovalidate>' . $t->html('records.pages.next') . '</button>';
        }
        return $body . '</details>' . ($uploadsEnabled ? WebsiteImageUpload::controls('iconMediaId', $t) : '') . '</fieldset>';
    }
}
