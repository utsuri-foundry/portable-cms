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

/** The image picker's cards and native actions, with two independent single choices. */
final class VideoReferenceControl
{
    public static function field(FieldDefinition $field, mixed $value, ?string $error, ProfileManifest $profile, Request $request, ?MediaLibrary $library, Translator $t, string $upload = ''): string
    {
        $value = EditorialControls::structured($value);
        $enabled = MediaContract::profileVideoEnabled($profile) && $library !== null;
        $query = mb_substr($request->formValue('_query_' . $field->id), 0, 100);
        $action = explode(':', $request->formValue('_editor_action'));
        $page = max(1, min(1000, (int) $request->formValue('_page_' . $field->id)));
        if (($action[1] ?? '') === $field->id && ($action[0] ?? '') === 'page') {
            $page = max(1, min(1000, (int) ($action[2] ?? 1)));
        } elseif (($action[1] ?? '') === $field->id && ($action[0] ?? '') === 'search') {
            $page = 1;
        }
        $results = $library?->search($query, $page);
        $markup = '<fieldset class="admin-field admin-picker admin-picker--media admin-video-field" id="field-' . Html::attribute($field->id) . '" data-video-reference data-video-none="' . $t->html('field.none') . '" tabindex="-1"' . (($action[1] ?? '') === $field->id ? ' autofocus' : '') . '><legend>' . Html::text($field->label) . '</legend>'
            . ($error === null ? '' : '<p role="alert" class="admin-field__error">' . Html::text($error) . '</p>')
            . ($enabled ? '' : '<small class="admin-meta">' . $t->html('video.unavailable') . '</small>');
        if ($enabled) {
            $limits = $upload === '' ? $t->html('video.field_limit', ['limit' => (string) round(min($library->videoUploads->maximumBytes, MediaContract::videoFieldMaximum([$field])) / 1048576, 2)]) : '';
            foreach (['maximumDurationSeconds' => 'duration_limit', 'maximumWidth' => 'width_limit', 'maximumHeight' => 'height_limit'] as $setting => $message) {
                if (isset($field->settings[$setting])) {
                    $limits .= ' ' . $t->html('video.' . $message, ['limit' => $field->settings[$setting]]);
                }
            }
            $limits .= ($field->settings['sound'] ?? 'allowed') === 'none' ? ' ' . $t->html('video.sound_none') : '';
            $markup .= trim($limits) === '' ? '' : '<small class="admin-meta">' . trim($limits) . '</small>';
            $markup .= '<input type="hidden" name="_present_' . Html::attribute($field->id) . '" value="1">';
        }
        foreach ($field->localized ? $profile->locales : [''] as $locale) {
            $selected = $field->localized && is_array($value) ? ($value[$locale] ?? null) : $value;
            $prefix = $field->id . ($locale === '' ? '' : '__' . $locale);
            $ids = ['video' => is_array($selected) && is_string($selected['mediaId'] ?? null) ? $selected['mediaId'] : '', 'poster' => is_array($selected) && is_string($selected['posterId'] ?? null) ? $selected['posterId'] : ''];
            $video = $library?->find($ids['video']);
            $poster = $library?->find($ids['poster']);
            $markup .= '<div data-video-selection>' . VideoPreview::player($video, $poster)
                . '<img class="admin-thumbnail" data-video-poster-preview width="80" height="64" alt=""' . ($poster?->status === 'ready' && $poster->thumbnail?->mime === 'image/webp' ? ' src="' . Html::url('/media/' . $poster->id . '/thumbnail') . '"' : ' hidden') . '>';
            foreach (['video', 'poster'] as $kind) {
                $item = $kind === 'video' ? $video : $poster;
                $name = $prefix . '__' . $kind;
                $reason = $item === null || $kind !== 'video' ? null : self::reason($field, $item, $library, $t);
                $markup .= '<input type="hidden" name="' . Html::attribute($name) . '" value="' . Html::attribute($ids[$kind]) . '" data-video-value="' . $kind . '">'
                    . '<div class="admin-selection"><strong>' . $t->html('video.' . $kind) . '</strong><span data-video-name="' . $kind . '">' . Html::text($item->name ?? ($ids[$kind] === '' ? $t->text('field.none') : $t->text('video.missing'))) . '</span>'
                    . '<a data-video-link="' . $kind . '"' . ($item === null ? ' hidden' : ' href="' . Html::url('/media/' . $item->id) . '"') . '>' . $t->html('media.text.edit') . '</a>'
                    . ($enabled ? '<button type="submit" name="_editor_action" value="' . Html::attribute('clear-' . $kind . ':' . $field->id) . '" formnovalidate data-video-clear="' . $kind . '"' . ($ids[$kind] === '' ? ' hidden' : '') . '>' . $t->html('video.clear') . '</button>' : '') . '</div>';
                if ($reason !== null) {
                    $markup .= '<p role="alert" data-video-limit-error>' . Html::text($reason) . '</p><input type="hidden" name="_retained_' . Html::attribute($name) . '" value="' . Html::attribute($ids[$kind]) . '">';
                }
            }
            $markup .= '</div>';
            if ($enabled) {
                foreach (['video', 'poster'] as $kind) {
                    $open = ($action[1] ?? '') === $field->id && in_array($action[0] ?? '', ['search', 'page'], true);
                    $markup .= '<details class="admin-picker__browse"' . ($open ? ' open' : '') . '><summary>' . $t->html('video.choose_' . $kind) . '</summary><ul class="admin-picker__results">';
                    $found = false;
                    foreach ($results->items ?? [] as $item) {
                        if ($kind === 'video' ? !in_array($item->mime, ['video/mp4', 'video/webm'], true) : !str_starts_with($item->mime, 'image/')) {
                            continue;
                        }
                        $found = true;
                        $reason = $kind === 'video' ? self::reason($field, $item, $library, $t) : ($item->status !== 'ready' || $item->thumbnail?->mime !== 'image/webp' ? ReferenceControls::mediaState($item->status, $t) : null);
                        $markup .= '<li>' . ReferenceControls::thumbnail($item) . '<div><span>' . Html::text($item->name) . '</span><small class="admin-meta">' . Html::text(ReferenceControls::metadata($item, $t)) . '</small>'
                            . ($reason === null ? '' : '<small class="admin-field__error">' . Html::text($reason) . '</small>') . '</div><button type="submit" name="_editor_action" value="' . Html::attribute('select-' . $kind . ':' . $field->id . ':' . $item->id) . '" formnovalidate data-video-choose="' . $kind . '" data-media-id="' . $item->id . '" data-media-name="' . Html::attribute($item->name) . '"'
                            . ' data-media-width="' . ($item->width ?? 0) . '" data-media-height="' . ($item->height ?? 0) . '"'
                            . ($kind === 'video' ? ' data-video-checksum="' . Html::attribute($item->thumbnail->checksum ?? '') . '"' : '')
                            . ($reason === null ? '' : ' disabled') . ' aria-label="' . $t->html('picker.select', ['name' => $item->name]) . '">' . $t->html('picker.choose') . '</button></li>';
                    }
                    $markup .= '</ul>' . ($found ? '' : '<p class="admin-meta">' . $t->html('picker.no_matches') . '</p>') . '</details>';
                }
            }
        }
        if ($enabled) {
            $markup .= '<div class="admin-picker__search"><label for="video-query-' . Html::attribute($field->id) . '">' . $t->html('picker.find') . '</label><input id="video-query-' . Html::attribute($field->id) . '" type="search" maxlength="100" name="_query_' . Html::attribute($field->id) . '" value="' . Html::attribute($query) . '">'
                . '<input type="hidden" name="_page_' . Html::attribute($field->id) . '" value="' . $page . '">'
                . ReferenceControls::button('search:' . $field->id, $t->text('picker.search'))
                . ($page > 1 ? ReferenceControls::button('page:' . $field->id . ':' . ($page - 1), $t->text('records.pages.previous')) : '')
                . ($results?->hasNext === true ? ReferenceControls::button('page:' . $field->id . ':' . ($page + 1), $t->text('records.pages.next')) : '') . '</div>';
        }
        return $markup . $upload . '</fieldset>';
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
