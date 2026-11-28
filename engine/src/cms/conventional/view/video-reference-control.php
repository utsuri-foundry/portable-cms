<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\View;

use Utsuri\PortableCms\Conventional\Content\EditorialControls;
use Utsuri\PortableCms\Conventional\Content\FieldDefinition;
use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Content\VideoLimits;
use Utsuri\PortableCms\Conventional\Http\Html;
use Utsuri\PortableCms\Conventional\Http\Request;
use Utsuri\PortableCms\Conventional\I18n\Message;
use Utsuri\PortableCms\Conventional\I18n\Translator;
use Utsuri\PortableCms\Conventional\Media\LibraryMedia;
use Utsuri\PortableCms\Conventional\Media\MediaContract;
use Utsuri\PortableCms\Conventional\Media\MediaLibrary;
use Utsuri\PortableCms\Conventional\Media\MediaPreparation;

/**
 * A video field: the chosen video and poster image as rows like an image field's, each with its
 * thumbnail, name, a link to its details page and a quiet remove control; an empty row waits
 * hidden, and a file still being prepared says when it will be ready. Under the rows, the choices
 * for what is missing sit side by side as in an image field: Upload video and Choose from library
 * while there is no video, then Upload poster image and Choose from library while there is no
 * poster. A full field offers nothing more; removing a row is the way to change it. Native
 * buttons do the work; the island mirrors a library choice without a round trip, and the upload
 * island turns each upload choice into one button that opens the file chooser.
 */
final class VideoReferenceControl
{
    public static function field(FieldDefinition $field, mixed $value, ?string $error, ProfileManifest $profile, Request $request, ?MediaLibrary $library, Translator $t, string $upload = ''): string
    {
        $value = EditorialControls::structured($value);
        $library = MediaContract::profileVideoEnabled($profile) ? $library : null;
        $enabled = $library !== null;
        $action = explode(':', $request->formValue('_editor_action'));
        $acting = ($action[1] ?? '') === $field->id;
        $browsing = $acting && ($action[0] ?? '') === 'search' ? ($action[2] ?? '') : ($acting && ($action[0] ?? '') === 'page' ? ($action[3] ?? '') : '');
        $uploading = $upload !== '' && $request->formValue('_upload_field') === $field->id && $error !== null;
        $markup = '<fieldset class="admin-field admin-picker admin-video-field" id="field-' . Html::attribute($field->id) . '" data-video-reference data-video-none="' . $t->html('video.none') . '" tabindex="-1"' . ($acting ? ' autofocus' : '') . '><legend>' . Html::text($field->label) . ($field->required ? FieldHelp::required($t) : '') . '</legend>'
            . ($error === null ? '' : '<p role="alert" class="admin-field__error">' . Html::text($error) . '</p>')
            . ($library !== null ? '<input type="hidden" name="_present_' . Html::attribute($field->id) . '" value="1">' : '<p class="admin-meta">' . $t->html('video.unavailable') . '</p>');
        foreach ($field->localized ? $profile->locales : [''] as $locale) {
            $selected = $field->localized && is_array($value) ? ($value[$locale] ?? null) : $value;
            $prefix = $field->id . ($locale === '' ? '' : '__' . $locale);
            $ids = ['video' => is_array($selected) && is_string($selected['mediaId'] ?? null) ? $selected['mediaId'] : '', 'poster' => is_array($selected) && is_string($selected['posterId'] ?? null) ? $selected['posterId'] : ''];
            $items = ['video' => $library?->find($ids['video']), 'poster' => $library?->find($ids['poster'])];
            $markup .= '<div class="admin-video-selection" data-video-selection>';
            $rows = '';
            $retained = '';
            foreach (['video', 'poster'] as $kind) {
                $item = $items[$kind];
                $name = $prefix . '__' . $kind;
                $waiting = $item !== null && $item->status !== 'ready';
                $markup .= '<input type="hidden" name="' . Html::attribute($name) . '" value="' . Html::attribute($ids[$kind]) . '" data-video-value="' . $kind . '">';
                // A chosen file is one row like an image: thumbnail, name, its details page, remove. An empty row waits hidden.
                $rows .= '<li class="admin-video-row" data-video-row="' . $kind . '"' . ($ids[$kind] === '' ? ' hidden' : '') . ($waiting && !MediaPreparation::failed($item) ? ' data-media-pending="' . Html::attribute($item->id) . '"' : '') . '>'
                    . '<span class="admin-video-row__thumb" data-video-thumb="' . $kind . '">' . ($item === null ? '' : ($kind === 'video' && VideoPreview::ready($item) ? VideoPreview::thumbnail($item, false, $items['poster']) : ReferenceControls::thumbnail($item))) . '</span>'
                    . '<span class="admin-video-row__text"><span class="admin-video-row__name" data-video-name="' . $kind . '">' . Html::text($item->name ?? ($ids[$kind] === '' ? $t->text('video.none') : $t->text('video.missing'))) . '</span>'
                    . ($waiting ? ReferenceControls::preparationLine($item, $library, $t) : '')
                    . '<a data-video-link="' . $kind . '"' . ($item === null ? ' hidden' : ' href="' . Html::url('/media/' . $item->id) . '"') . '>' . $t->html($kind === 'video' ? 'video.details' : 'picker.image_details') . '</a></span>'
                    . '<span class="admin-selection__actions">'
                    . ($enabled ? '<button type="submit" name="_editor_action" value="' . Html::attribute('clear-' . $kind . ':' . $field->id) . '" formnovalidate data-video-clear="' . $kind . '" aria-label="' . $t->html('video.remove_' . $kind) . '"' . ($ids[$kind] === '' ? ' hidden' : '') . '>×</button>' : '') . '</span></li>';
                // Limits are measured on a prepared video; one still being prepared says so in its row instead.
                $reason = $item === null || $kind !== 'video' || !VideoPreview::ready($item) ? null : self::reason($field, $item, $library, $t);
                if ($reason !== null) {
                    $retained .= '<p role="alert" class="admin-field__error" data-video-limit-error>' . Html::text($reason) . '</p><input type="hidden" name="_retained_' . Html::attribute($name) . '" value="' . Html::attribute($ids[$kind]) . '">';
                }
            }
            $markup .= '<ul class="admin-video-rows">' . $rows . '</ul>' . $retained . '</div>';
            if ($library !== null) {
                // What is missing is offered: the video first, then its poster. The upload goes where it is needed.
                $missing = $ids['video'] === '' ? 'video' : ($ids['poster'] === '' ? 'poster' : 'video');
                $markup .= '<div class="admin-video-choosers">';
                foreach (['video', 'poster'] as $kind) {
                    $hidden = $kind === 'video' ? $ids['video'] !== '' : $ids['video'] === '' || $ids['poster'] !== '';
                    $markup .= self::chooser($field, $kind, $ids[$kind], $library, $request, $t, $browsing === $kind, $kind === $missing ? $upload : '', $uploading, $hidden);
                }
                $markup .= '</div>';
                // A localized field uploads once; the files land in the shared library for every language.
                $upload = '';
                $uploading = false;
            }
        }
        return $markup . '</fieldset>';
    }

    /** The eligibility rule as one sentence: what the video may be. */
    private static function rule(FieldDefinition $field, MediaLibrary $library, Translator $t): string
    {
        $parts = [$t->text('video.rule.format', ['limit' => (string) round(min($library->videoUploads->maximumBytes, MediaContract::videoFieldMaximum([$field])) / 1048576, 2)])];
        if (isset($field->settings['maximumDurationSeconds'])) {
            $parts[] = $t->text('video.rule.duration', ['limit' => (string) $field->settings['maximumDurationSeconds']]);
        }
        $width = $field->settings['maximumWidth'] ?? null;
        $height = $field->settings['maximumHeight'] ?? null;
        if ($width !== null && $height !== null) {
            $parts[] = $t->text('video.rule.size', ['width' => (string) $width, 'height' => (string) $height]);
        } elseif ($width !== null) {
            $parts[] = $t->text('video.rule.width', ['limit' => (string) $width]);
        } elseif ($height !== null) {
            $parts[] = $t->text('video.rule.height', ['limit' => (string) $height]);
        }
        if (($field->settings['sound'] ?? 'allowed') === 'none') {
            $parts[] = $t->text('video.rule.silent');
        }
        return $t->html('video.rule', ['video' => implode(', ', $parts)]);
    }

    /** One choice: Upload video or Upload poster image beside Choose from library, with the library's own search and paging. */
    private static function chooser(FieldDefinition $field, string $kind, string $selected, MediaLibrary $library, Request $request, Translator $t, bool $browsing, string $upload, bool $uploading, bool $hidden): string
    {
        $suffix = $field->id . '__' . $kind;
        $query = mb_substr($request->formValue('_query_' . $suffix), 0, 100);
        $action = explode(':', $request->formValue('_editor_action'));
        $page = max(1, min(1000, (int) $request->formValue('_page_' . $suffix)));
        if ($browsing) {
            $page = ($action[0] ?? '') === 'page' ? max(1, min(1000, (int) ($action[2] ?? 1))) : 1;
        }
        $results = $library->search($query, $page);
        $id = Html::attribute('video-query-' . $suffix);
        $uploading = $uploading && $upload !== '';
        $markup = '<div class="admin-video-choice" data-video-choosers="' . $kind . '"' . ($hidden && !$browsing && !$uploading ? ' hidden' : '') . '><div class="admin-picker__add">'
            . ($upload === '' ? '' : '<details class="admin-picker__upload"' . ($uploading ? ' open' : '') . '><summary>' . $t->html('video.upload_' . $kind) . '</summary><div class="admin-upload-tile">' . $upload . '</div></details>')
            . '<details class="admin-picker__library" data-video-browse="' . $kind . '"' . ($browsing ? ' open' : '') . '><summary>' . $t->html('picker.choose_library') . '</summary>'
            . '<div class="admin-picker__search"><label for="' . $id . '">' . $t->html('picker.find') . '</label><input id="' . $id . '" type="search" maxlength="100" name="_query_' . Html::attribute($suffix) . '" value="' . Html::attribute($query) . '">'
            . '<input type="hidden" name="_page_' . Html::attribute($suffix) . '" value="' . $page . '">' . ReferenceControls::button('search:' . $field->id . ':' . $kind, $t->text('picker.search')) . '</div><ul class="admin-picker__results">';
        $found = false;
        foreach ($results->items as $item) {
            if ($kind === 'video' ? !in_array($item->mime, ['video/mp4', 'video/webm'], true) : !str_starts_with($item->mime, 'image/')) {
                continue;
            }
            $found = true;
            $reason = $kind === 'video' ? self::reason($field, $item, $library, $t) : ($item->status !== 'ready' || $item->thumbnail?->mime !== 'image/webp' ? ReferenceControls::mediaState($item->status, $t) : null);
            $text = '<span><span>' . Html::text($item->name) . '</span><small class="admin-meta">' . Html::text(ReferenceControls::metadata($item, $t)) . '</small></span>';
            if ($reason === null && $item->id !== $selected) {
                // The whole file is one button: activating it puts it in the field at once.
                $markup .= '<li><button type="submit" name="_editor_action" value="' . Html::attribute('select-' . $kind . ':' . $field->id . ':' . $item->id) . '" formnovalidate class="admin-picker__pick" data-video-choose="' . $kind . '" data-media-id="' . $item->id . '" data-media-name="' . Html::attribute($item->name) . '"'
                    . ' aria-label="' . $t->html('picker.select', ['name' => $item->name]) . '">' . ReferenceControls::thumbnail($item) . $text . '</button></li>';
                continue;
            }
            $markup .= '<li data-media-id="' . $item->id . '" data-media-name="' . Html::attribute($item->name) . '">' . ReferenceControls::thumbnail($item) . '<div><span>' . Html::text($item->name) . '</span><small class="admin-meta">' . Html::text(ReferenceControls::metadata($item, $t)) . '</small>'
                . ($reason !== null && VideoPreview::ready($item) ? '<small class="admin-field__error">' . Html::text($reason) . '</small>' : '') . '</div>'
                . ($item->id === $selected ? '<span class="admin-meta">' . $t->html('picker.selected_state') . '</span>' : '') . '</li>';
        }
        $markup .= '</ul>' . ($found ? '' : '<p class="admin-meta">' . $t->html('picker.no_matches') . '</p>')
            . '<div class="admin-picker__pagination"><span>' . $t->html('records.pages.current', ['page' => (string) $page]) . '</span>'
            . ($page > 1 ? ReferenceControls::button('page:' . $field->id . ':' . ($page - 1) . ':' . $kind, $t->text('records.pages.previous')) : '')
            . ($results->hasNext ? ReferenceControls::button('page:' . $field->id . ':' . ($page + 1) . ':' . $kind, $t->text('records.pages.next')) : '') . '</div></details></div>';
        return $markup . ($kind === 'video' ? '<p class="admin-meta" data-video-rule>' . self::rule($field, $library, $t) . '</p>' : '') . '</div>';
    }

    private static function reason(FieldDefinition $field, LibraryMedia $item, ?MediaLibrary $library, Translator $t): ?string
    {
        if (!VideoPreview::ready($item) || $library === null) {
            return ReferenceControls::mediaState($item->status, $t);
        }
        try {
            VideoLimits::assert($field, $item->byteSize, $library->videoFacts($item));
            return null;
        } catch (\InvalidArgumentException $failure) {
            return $t->message(Message::fromFailure($failure));
        }
    }
}
