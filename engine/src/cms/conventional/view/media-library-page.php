<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\View;

use Utsuri\PortableCms\Conventional\Content\ContentService;
use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Content\RecordLabels;
use Utsuri\PortableCms\Conventional\Http\Html;
use Utsuri\PortableCms\Conventional\Media\LibraryMedia;
use Utsuri\PortableCms\Conventional\Media\MediaLibrary;
use Utsuri\PortableCms\Conventional\Media\MediaPage;

final class MediaLibraryPage
{
    public static function listing(MediaPage $page, ProfileManifest $profile, ?AdminChrome $chrome = null, ?ContentService $content = null, string $csrf = '', ?\Utsuri\PortableCms\Conventional\Media\VideoUploadPolicy $policy = null): string
    {
        $policy ??= new \Utsuri\PortableCms\Conventional\Media\VideoUploadPolicy();
        $t = AdminShell::translator($chrome);
        $markup = AdminShell::breadcrumbs([[$t->text('shell.group.library'), null], [$t->text('media.title'), null]], $t)
            . AdminShell::header($t->text('media.title'), '<p class="admin-lede">' . $t->html('media.lede') . '</p>')
            . '<form class="admin-toolbar" method="get" action="/media" role="search"><label class="admin-visually-hidden" for="media-query">' . $t->html('media.find') . '</label>'
            . '<input type="search" id="media-query" name="q" maxlength="100" placeholder="' . $t->html('media.find') . '" value="' . Html::attribute($page->query) . '"><button type="submit">' . $t->html('media.search') . '</button></form>';
        $items = '';
        if ($content !== null) {
            $options = '<option value="website">' . $t->html('website_media.owner') . '</option>';
            foreach ($profile->types as $type) {
                if ($type->kind !== 'collection') {
                    continue;
                }
                foreach ($content->list($type->id) as $record) {
                    $options .= '<option value="' . Html::attribute($type->id . '/' . $record->id . '/' . $record->revisionNumber) . '">' . Html::text($type->singularLabel . ': ' . RecordLabels::label($record, $profile)) . '</option>';
                }
            }
            $markup .= '<details class="admin-section"><summary>' . $t->html('media.upload.title') . '</summary><p class="admin-help">' . (\Utsuri\PortableCms\Conventional\Media\MediaContract::profileVideoEnabled($profile) ? VideoUploadHelp::render($t, $policy, $policy->maximumBytes) : $t->html('media.upload.help')) . '</p>'
                . '<form class="admin-form" method="post" action="/media/upload" enctype="multipart/form-data"><input type="hidden" name="_csrf" value="' . Html::attribute($csrf) . '">'
                . '<label for="media-owner">' . $t->html('media.upload.owner') . '</label><select id="media-owner" name="owner" required><option value=""></option>' . $options . '</select>'
                . '<label for="media-files">' . $t->html(\Utsuri\PortableCms\Conventional\Media\MediaContract::profileVideoEnabled($profile) ? 'video.upload_label' : 'media.upload.files') . '</label><input id="media-files" type="file" name="images[]" accept="' . Html::attribute(implode(',', \Utsuri\PortableCms\Conventional\Media\MediaContract::uploadTypes(\Utsuri\PortableCms\Conventional\Media\MediaContract::profileVideoEnabled($profile)))) . '" multiple required>'
                . '<button type="submit">' . $t->html('media.upload.title') . '</button></form></details>';
        }
        foreach ($page->items as $media) {
            $warning = MediaDescriptionsForm::warning($media, $profile, $t);
            $items .= '<li class="admin-card"><a href="' . Html::url('/media/' . $media->id) . '">' . ReferenceControls::thumbnail($media, true)
                . '<strong>' . Html::text($media->name) . '</strong></a><p class="admin-meta">' . Html::text(ReferenceControls::metadata($media, $t)) . '</p>'
                . ($media->ownerRecordId === null ? '<p class="admin-meta">' . $t->html('website_media.owner') . '</p>' : '')
                . ($warning === '' ? '' : '<p class="admin-field__error">' . Html::text($warning) . '</p>')
                . (str_starts_with($media->mime, 'image/') ? '<label class="admin-checkbox"><input type="checkbox" name="media_ids[]" value="' . $media->id . '"><span>' . $t->html('media.descriptions.choose', ['name' => $media->name]) . '</span></label>' : '')
                . ($media->errorCode === null ? '' : '<p class="admin-field__error">' . $t->html('media.processing_attention') . '</p>') . '</li>';
        }
        $markup .= $items === ''
            ? '<div class="admin-section admin-empty"><h2>' . $t->html('media.empty.title') . '</h2><p class="admin-meta">' . $t->html($page->query === '' ? 'media.empty.help' : 'media.empty.filtered_help') . '</p></div>'
            : '<form method="post" action="/media/descriptions"><input type="hidden" name="_csrf" value="' . Html::attribute($csrf) . '"><ul class="admin-media-grid">' . $items . '</ul><p class="admin-help">' . $t->html('media.descriptions.help') . '</p><button type="submit" name="_mode" value="open">' . $t->html('media.descriptions.title') . '</button></form>';
        $markup .= '<nav class="admin-pagination" aria-label="' . $t->html('media.pages') . '"><span>' . $t->html('records.pages.current', ['page' => (string) $page->page]) . '</span>';
        foreach (['previous' => $page->page - 1, 'next' => $page->page + 1] as $label => $number) {
            if (($label === 'previous' && $number >= 1) || ($label === 'next' && $page->hasNext)) {
                $markup .= '<a class="admin-button admin-button--small" href="' . Html::url('/media?q=' . rawurlencode($page->query) . '&page=' . $number) . '">' . $t->html('records.pages.' . $label) . '</a>';
            }
        }
        return AdminShell::application($t->text('media.title'), TrustedHtml::fromEngineMarkup($markup . '</nav>'), $profile, 'media', 'wide', $chrome);
    }

    /** @param ?array<string, mixed> $submittedText
     * @param ?array{x: string, y: string, reset: bool} $submittedPoint
     */
    public static function detail(LibraryMedia $media, ProfileManifest $profile, ContentService $content, MediaLibrary $library, string $csrf, ?string $error = null, ?string $submittedName = null, ?AdminChrome $chrome = null, ?array $submittedText = null, ?array $submittedPoint = null): string
    {
        $t = AdminShell::translator($chrome);
        $usage = '';
        foreach ($library->usage($media->id) as $item) {
            $type = $profile->type($item['typeId']);
            $record = $type === null ? null : $content->find($type->id, $item['recordId']);
            if ($record === null) {
                continue;
            }
            $fieldLabel = $item['fieldId'];
            foreach ($type->fields as $field) {
                if ($field->id === $item['fieldId']) {
                    $fieldLabel = $field->label;
                }
            }
            $usage .= '<li class="admin-list__item"><a href="' . Html::url('/records/' . $record->typeId . '/' . $record->id) . '">' . Html::text(RecordLabels::label($record, $profile)) . '</a>'
                . '<span class="admin-meta">' . $t->html('media.usage.item', ['type' => $type->singularLabel, 'field' => $fieldLabel]) . '</span></li>';
        }
        $video = VideoPreview::ready($media);
        $codec = $media->thumbnail?->parameters['codec'] ?? null;
        $codec = is_string($codec) ? (['h264' => 'H.264', 'vp8' => 'VP8', 'vp9' => 'VP9'][$codec] ?? $t->text('video.codec_unknown')) : $t->text('video.codec_unknown');
        $markup = AdminShell::breadcrumbs([[$t->text('shell.group.library'), null], [$t->text('media.title'), '/media'], [$media->name, null]], $t)
            . AdminShell::header($media->name, '<p class="admin-meta">' . Html::text(ReferenceControls::metadata($media, $t)) . '</p>')
            . '<div class="admin-media-detail"><div class="admin-section">' . ($video ? VideoPreview::player($media, preload: 'metadata') . '<p class="admin-meta">' . $t->html('video.codec', ['codec' => $codec]) . '</p>' : ReferenceControls::thumbnail($media)) . '</div><div class="admin-media-detail__side">'
            . '<section class="admin-section" aria-labelledby="media-name-heading"><h2 id="media-name-heading">' . $t->html('media.name.title') . '</h2>'
            . ($error === null ? '' : '<p role="alert" class="admin-field__error">' . Html::text($error) . '</p>')
            . '<form class="admin-form" method="post" action="' . Html::url('/media/' . $media->id) . '"><input type="hidden" name="_csrf" value="' . Html::attribute($csrf) . '"><input type="hidden" name="_revision" value="' . $media->metadataRevision . '">'
            . '<div class="admin-field"><label for="media-name">' . $t->html('media.name.label') . '</label><input type="text" id="media-name" name="name" maxlength="180" required value="' . Html::attribute($submittedName ?? $media->name) . '"></div>'
            . MediaTextForm::render($media->text, $profile, $t, $submittedText)
            . FocalPointForm::render($media, $t, $submittedPoint)
            . '<button type="submit" class="admin-button--primary">' . $t->html('media.text.save') . '</button>'
            . ($media->ownerRecordId === null ? '<p class="admin-help">' . $t->html('website_media.owner') . '</p>'
                : (in_array($chrome?->role, ['owner', 'administrator'], true) && $media->status === 'ready' && str_starts_with($media->mime, 'image/')
                    ? '<p class="admin-help">' . $t->html('website_media.keep_help') . '</p><button type="submit" name="_website_owner" value="1" formnovalidate>' . $t->html('website_media.keep') . '</button>' : ''))
            . '</form></section>'
            . '<section class="admin-section" aria-labelledby="media-usage-heading"><h2 id="media-usage-heading">' . $t->html('media.usage.title') . '</h2><p class="admin-help">' . $t->html('media.usage.help') . '</p>'
            . ($usage === '' ? '<p class="admin-meta">' . $t->html('media.usage.empty') . '</p>' : '<ul class="admin-list">' . $usage . '</ul>')
            . '<p class="admin-help">' . $t->html('media.replace_help') . '</p></section></div></div>';
        return AdminShell::application($media->name, TrustedHtml::fromEngineMarkup($markup), $profile, 'media', 'wide', $chrome);
    }
}
