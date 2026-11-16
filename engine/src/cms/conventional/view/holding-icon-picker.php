<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\View;

use Utsuri\PortableCms\Conventional\Content\WebsiteIconSelection;
use Utsuri\PortableCms\Conventional\Http\Html;
use Utsuri\PortableCms\Conventional\I18n\Translator;
use Utsuri\PortableCms\Conventional\Media\LibraryMedia;
use Utsuri\PortableCms\Conventional\Media\MediaPage;

/**
 * The website icon: the rule first, the chosen image previewed at the sizes it becomes, then a
 * chooser that lists only images that qualify. Browsing the library preserves all unsaved settings.
 */
final class HoldingIconPicker
{
    /** The pixel sizes the engine generates, and the size each is drawn at here. */
    private const SIZES = [32 => 32, 180 => 90, 512 => 128];

    public static function render(string $selected, ?MediaPage $page, ?LibraryMedia $selection, Translator $t, bool $uploadsEnabled = false, bool $readOnly = false): string
    {
        $disabled = $readOnly ? ' disabled' : '';
        if ($page === null) {
            return '<div class="admin-field"><label for="website-icon-id">' . $t->html('holding.icon_id') . '</label>'
                . FieldHelp::toggle('website-icon-id-help', $t->text('holding.icon_id'), $t) . FieldHelp::text('website-icon-id-help', $t->html('holding.icon_help'))
                . '<input id="website-icon-id" name="iconMediaId" maxlength="32" pattern="[a-f0-9]{32}" aria-describedby="website-icon-id-help" value="' . Html::attribute($selected) . '"' . $disabled . '></div>';
        }
        $body = '<fieldset class="admin-field admin-picker admin-icon" aria-labelledby="website-icon-label" aria-describedby="website-icon-help" data-icon-picker>'
            . FieldHelp::legend('website-icon-label', $t->html('holding.icon'), 'website-icon-help', $t->text('holding.icon'), $t)
            . FieldHelp::text('website-icon-help', $t->html('holding.icon_help'));
        if ($selected !== '' && $selection !== null) {
            $body .= '<div class="admin-icon__current"><label class="admin-checkbox"><input type="radio" name="iconMediaId" value="' . Html::attribute($selected) . '" checked' . $disabled . '> <span>' . Html::text($selection->name) . '</span></label>' . self::preview($selection, $t) . '</div>';
        } elseif ($selected !== '') {
            $body .= '<div class="admin-icon__current"><label class="admin-checkbox"><input type="radio" name="iconMediaId" value="' . Html::attribute($selected) . '" checked' . $disabled . '> <span>' . $t->html('picker.unavailable_image') . '</span></label></div>';
        }
        $body .= '<label class="admin-checkbox"><input type="radio" name="iconMediaId" value=""' . ($selected === '' ? ' checked' : '') . $disabled . '> ' . $t->html('holding.icon_none') . '</label>';
        if ($readOnly) {
            return $body . '</fieldset>';
        }
        $eligible = array_values(array_filter($page->items, static fn(LibraryMedia $item): bool => $item->id !== $selected && WebsiteIconSelection::eligible($item->status, $item->mime, $item->width, $item->height)));
        $browsing = $page->query !== '' || $page->page > 1;
        $body .= '<details class="admin-optional admin-picker__chooser"' . ($browsing ? ' open' : '') . '><summary><span>' . $t->html('holding.icon_choose') . '</span></summary>'
            . '<div class="admin-picker__search"><label for="website-icon-query">' . $t->html('picker.find') . '</label><input id="website-icon-query" type="search" name="_icon_query" maxlength="100" value="' . Html::attribute($page->query) . '"><button name="_icon_action" value="search" formnovalidate>' . $t->html('picker.search') . '</button></div>';
        if ($eligible === []) {
            $body .= '<p class="admin-meta">' . $t->html($browsing ? 'picker.no_matches' : 'holding.icon_empty') . '</p>';
        } else {
            $body .= '<ul class="admin-picker__results">';
            foreach ($eligible as $item) {
                $body .= '<li><label class="admin-checkbox">' . ReferenceControls::thumbnail($item) . '<input type="radio" name="iconMediaId" value="' . Html::attribute($item->id) . '"> <span>' . Html::text($item->name) . '</span></label></li>';
            }
            $body .= '</ul>';
        }
        $body .= self::paging($page, '_icon_action', $t) . '</details>' . ($uploadsEnabled ? WebsiteImageUpload::controls('iconMediaId', $t) : '');
        return $body . '</fieldset>';
    }

    /** The chosen image as the browser tab, home screen and large icons it becomes. */
    public static function preview(LibraryMedia $selection, Translator $t): string
    {
        if ($selection->status !== 'ready' || $selection->thumbnail === null || $selection->thumbnail->mime !== 'image/webp') {
            return '<p class="admin-meta">' . $t->html('holding.icon_preparing') . '</p>';
        }
        $html = '<ul class="admin-icon__preview" data-icon-preview>';
        foreach (self::SIZES as $size => $drawn) {
            $html .= '<li><img src="' . Html::url('/media/' . $selection->id . '/thumbnail') . '" width="' . $drawn . '" height="' . $drawn . '" alt=""><span class="admin-meta">' . $t->html('holding.icon_size.' . $size) . '</span></li>';
        }
        return $html . '</ul>';
    }

    public static function paging(MediaPage $page, string $action, Translator $t): string
    {
        if ($page->page <= 1 && !$page->hasNext) {
            return '';
        }
        $html = '<div class="admin-picker__pages"><span class="admin-meta">' . $t->html('records.pages.current', ['page' => (string) $page->page]) . '</span>';
        if ($page->page > 1) {
            $html .= '<button name="' . $action . '" value="page:' . ($page->page - 1) . '" formnovalidate class="admin-button--small">' . $t->html('records.pages.previous') . '</button>';
        }
        if ($page->hasNext) {
            $html .= '<button name="' . $action . '" value="page:' . ($page->page + 1) . '" formnovalidate class="admin-button--small">' . $t->html('records.pages.next') . '</button>';
        }
        return $html . '</div>';
    }
}
