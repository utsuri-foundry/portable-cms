<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\View;

use Utsuri\PortableCms\Conventional\Content\ContentService;
use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Content\RecordLabels;
use Utsuri\PortableCms\Conventional\Http\Html;
use Utsuri\PortableCms\Conventional\I18n\Translator;
use Utsuri\PortableCms\Conventional\Media\LibraryMedia;
use Utsuri\PortableCms\Conventional\Media\MediaContract;
use Utsuri\PortableCms\Conventional\Media\MediaLibrary;
use Utsuri\PortableCms\Conventional\Media\MediaPage;
use Utsuri\PortableCms\Conventional\Media\VideoUploadPolicy;

final class MediaLibraryPage
{
    /** @param bool $cronOnly Uploads wait for the scheduled worker, so a pending card says minutes rather than a moment. */
    public static function listing(MediaPage $page, ProfileManifest $profile, ?AdminChrome $chrome = null, ?ContentService $content = null, string $csrf = '', ?VideoUploadPolicy $policy = null, bool $cronOnly = false): string
    {
        $policy ??= new VideoUploadPolicy();
        $t = AdminShell::translator($chrome);
        $video = MediaContract::profileVideoEnabled($profile);
        $markup = AdminShell::breadcrumbs([[$t->text('shell.group.content'), null], [$t->text('media.title'), null]], $t)
            . AdminShell::header($t->text('media.title'), '<p class="admin-lede">' . $t->html('media.lede') . '</p>')
            . '<div class="admin-toolbar admin-media-toolbar">'
            . ($content === null ? '' : self::upload($profile, $content, $csrf, $policy, $video, $t))
            . '<form class="admin-media-toolbar__search" method="get" action="/media" role="search">'
            . ($page->filter === 'all' ? '' : '<input type="hidden" name="show" value="' . Html::attribute($page->filter) . '">')
            . '<label class="admin-visually-hidden" for="media-query">' . $t->html('media.find') . '</label>'
            . '<input type="search" id="media-query" name="q" maxlength="100" placeholder="' . $t->html('media.find') . '" value="' . Html::attribute($page->query) . '"><button type="submit">' . $t->html('media.search') . '</button></form>'
            . self::filters($page, $video, $t) . '</div>';
        $items = '';
        foreach ($page->items as $media) {
            $items .= self::card($media, $profile, $cronOnly, $t);
        }
        $markup .= $items === ''
            ? self::empty($page, $t)
            : '<form method="post" action="/media/descriptions"><input type="hidden" name="_csrf" value="' . Html::attribute($csrf) . '"><ul class="admin-media-grid">' . $items . '</ul>'
                . '<div class="admin-actions admin-media-grid__foot"><button type="submit" name="_mode" value="open">' . $t->html('media.descriptions.selected') . '</button>'
                . FieldHelp::toggle('media-describe-help', $t->text('media.descriptions.selected'), $t) . '</div>' . FieldHelp::text('media-describe-help', $t->html('media.descriptions.help')) . '</form>';
        $parameters = array_filter(['q' => $page->query, 'show' => $page->filter === 'all' ? '' : $page->filter], static fn(string $value): bool => $value !== '');
        $markup .= '<nav class="admin-pagination" aria-label="' . $t->html('media.pages') . '"><span>' . $t->html('records.pages.current', ['page' => (string) $page->page]) . '</span>';
        foreach (['previous' => $page->page - 1, 'next' => $page->page + 1] as $label => $number) {
            if (($label === 'previous' && $number >= 1) || ($label === 'next' && $page->hasNext)) {
                $markup .= '<a class="admin-button admin-button--small" href="' . Html::url('/media?' . http_build_query([...$parameters, 'page' => $number])) . '">' . $t->html('records.pages.' . $label) . '</a>';
            }
        }
        return AdminShell::application($t->text('media.title'), TrustedHtml::fromEngineMarkup($markup . '</nav>'), $profile, 'media', 'wide', $chrome);
    }

    /** The primary action: a disclosure that opens the native upload form in place, with the rule in one line. */
    private static function upload(ProfileManifest $profile, ContentService $content, string $csrf, VideoUploadPolicy $policy, bool $video, Translator $t): string
    {
        $options = '<option value="website">' . $t->html('media.upload.website') . '</option>';
        foreach ($profile->types as $type) {
            if ($type->kind !== 'collection') {
                continue;
            }
            foreach ($content->list($type->id) as $record) {
                $options .= '<option value="' . Html::attribute($type->id . '/' . $record->id . '/' . $record->revisionNumber) . '">' . Html::text($type->singularLabel . ': ' . RecordLabels::label($record, $profile)) . '</option>';
            }
        }
        $limit = (string) (int) round(($video ? min($policy->maximumBytes, MediaContract::MAX_BYTES) : MediaContract::MAX_BYTES) / 1_048_576);
        return '<details class="admin-media-upload" id="media-upload"><summary class="admin-button admin-button--primary">' . $t->html('media.upload.title') . '</summary>'
            . '<form class="admin-form admin-upload" method="post" action="/media/upload" enctype="multipart/form-data"><input type="hidden" name="_csrf" value="' . Html::attribute($csrf) . '">'
            . '<p class="admin-meta">' . $t->html($video ? 'media.upload.rule_video' : 'media.upload.rule', ['limit' => $limit]) . '</p>'
            . '<div class="admin-field"><label for="media-files">' . $t->html('media.upload.files') . '</label><input id="media-files" type="file" name="images[]" accept="' . Html::attribute(implode(',', MediaContract::uploadTypes($video))) . '" multiple required></div>'
            . '<div class="admin-field"><div class="admin-help-row"><label for="media-owner">' . $t->html('media.upload.owner') . '</label>' . FieldHelp::toggle('media-owner-help', $t->text('media.upload.owner'), $t) . '</div>' . FieldHelp::text('media-owner-help', $t->html('media.upload.owner_help'))
            . '<select id="media-owner" name="owner" required>' . $options . '</select></div>'
            . '<div class="admin-actions"><button type="submit" class="admin-button--primary">' . $t->html('media.upload.submit') . '</button></div></form></details>';
    }

    /** All, Images, Videos and the images still to describe; a count of zero shows nothing. */
    private static function filters(MediaPage $page, bool $video, Translator $t): string
    {
        $links = '';
        foreach (['all', 'images', 'videos', 'undescribed'] as $filter) {
            if (($filter === 'videos' && !$video) || ($filter === 'undescribed' && $page->undescribed === 0 && $page->filter !== 'undescribed')) {
                continue;
            }
            $parameters = array_filter(['q' => $page->query, 'show' => $filter === 'all' ? '' : $filter], static fn(string $value): bool => $value !== '');
            $links .= '<a href="' . Html::url('/media' . ($parameters === [] ? '' : '?' . http_build_query($parameters))) . '"' . ($page->filter === $filter ? ' aria-current="page"' : '') . ' data-media-filter="' . $filter . '">'
                . $t->html('media.show.' . $filter) . ($filter === 'undescribed' ? ' <span class="admin-navigation__count">' . $page->undescribed . '</span>' : '') . '</a>';
        }
        return '<nav class="admin-segmented" aria-label="' . $t->html('media.show.label') . '">' . $links . '</nav>';
    }

    /** Thumbnail and name; a line only where the editor has something to do or to wait for. */
    private static function card(LibraryMedia $media, ProfileManifest $profile, bool $cronOnly, Translator $t): string
    {
        $failed = $media->status === 'needs_attention' || $media->errorCode !== null;
        $pending = !$failed && $media->status !== 'ready';
        $warning = $failed || $pending ? '' : MediaDescriptionsForm::warning($media, $profile, $t);
        return '<li class="admin-card admin-media-card" data-media-card="' . ($failed ? 'failed' : ($pending ? 'pending' : 'ready')) . '"><a href="' . Html::url('/media/' . $media->id) . '">' . ReferenceControls::thumbnail($media, true)
            . '<strong>' . Html::text($media->name) . '</strong></a>'
            . ($pending ? '<p class="admin-meta" role="status">' . $t->html($cronOnly ? 'media.pending.minutes' : 'media.pending.moment') . '</p>' : '')
            . ($failed ? '<p class="admin-field__error">' . $t->html('media.processing_attention') . '</p>' : '')
            . ($warning === '' ? '' : '<p class="admin-field__error">' . Html::text($warning) . '</p>')
            . (str_starts_with($media->mime, 'image/') && !$failed ? '<label class="admin-checkbox"><input type="checkbox" name="media_ids[]" value="' . $media->id . '"><span class="admin-visually-hidden">' . $t->html('media.descriptions.choose', ['name' => $media->name]) . '</span></label>' : '')
            . '</li>';
    }

    private static function empty(MediaPage $page, Translator $t): string
    {
        if ($page->query !== '') {
            return '<div class="admin-section admin-empty"><h2>' . $t->html('media.empty.search') . '</h2><p class="admin-meta">' . $t->html('media.empty.filtered_help') . '</p></div>';
        }
        return match ($page->filter) {
            'undescribed' => '<div class="admin-section admin-empty"><h2>' . $t->html('media.empty.described') . '</h2></div>',
            'images', 'videos' => '<div class="admin-section admin-empty"><h2>' . $t->html('media.empty.' . $page->filter) . '</h2></div>',
            default => '<div class="admin-section admin-empty"><h2>' . $t->html('media.empty.title') . '</h2><p class="admin-meta">' . $t->html('media.empty.help') . '</p></div>',
        };
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
        $image = str_starts_with($media->mime, 'image/');
        $failed = $media->status === 'needs_attention' || $media->errorCode !== null;
        // The picture column: the image with its important point and a square crop beside it, or the video player.
        $picture = match (true) {
            $failed => ReferenceControls::thumbnail($media) . '<p class="admin-field__error">' . $t->html('media.processing_attention') . '</p>',
            $media->status !== 'ready' => ReferenceControls::thumbnail($media) . '<p class="admin-meta" role="status">' . $t->html('media.pending.detail') . '</p>',
            VideoPreview::ready($media) => VideoPreview::player($media, preload: 'metadata'),
            $image => FocalPointForm::render($media, $t, $submittedPoint),
            default => ReferenceControls::thumbnail($media),
        };
        $picture .= '<p class="admin-meta">' . Html::text(ReferenceControls::metadata($media, $t)) . '</p>';
        $keep = $media->ownerRecordId !== null && in_array($chrome?->role, ['owner', 'administrator'], true) && $media->status === 'ready' && $image
            ? '<div class="admin-help-row"><button type="submit" name="_website_owner" value="1" formnovalidate class="admin-button--quiet" aria-describedby="media-keep-help">' . $t->html('website_media.keep') . '</button>' . FieldHelp::toggle('media-keep-help', $t->text('website_media.keep'), $t) . '</div>' . FieldHelp::text('media-keep-help', $t->html('website_media.keep_help'))
            : '';
        $markup = AdminShell::breadcrumbs([[$t->text('shell.group.content'), null], [$t->text('media.title'), '/media'], [$media->name, null]], $t)
            . AdminShell::header($media->name)
            . '<form class="admin-form admin-media-detail" method="post" action="' . Html::url('/media/' . $media->id) . '"><input type="hidden" name="_csrf" value="' . Html::attribute($csrf) . '"><input type="hidden" name="_revision" value="' . $media->metadataRevision . '">'
            . '<div class="admin-media-detail__picture">' . $picture . '</div><div class="admin-media-detail__side">'
            . ($error === null ? '' : '<p role="alert" class="admin-field__error">' . Html::text($error) . '</p>')
            . '<div class="admin-field"><div class="admin-help-row"><label for="media-name">' . $t->html('media.name.label') . '</label>' . FieldHelp::toggle('media-name-help', $t->text('media.name.label'), $t) . '</div>' . FieldHelp::text('media-name-help', $t->html('media.name.help'))
            . '<input type="text" id="media-name" name="name" maxlength="180" required value="' . Html::attribute($submittedName ?? $media->name) . '"></div>'
            . ($image ? MediaTextForm::render($media->text, $profile, $t, $submittedText) : '')
            . '<div class="admin-actions"><button type="submit" class="admin-button--primary">' . $t->html('media.save') . '</button></div>' . $keep
            . '<section class="admin-media-usage" aria-labelledby="media-usage-heading">' . FieldHelp::heading('<h2 id="media-usage-heading">' . $t->html('media.usage.title') . '</h2>', 'media-usage-help', $t->text('media.usage.title'), $t)
            . FieldHelp::text('media-usage-help', $t->html('media.usage.help') . ' ' . $t->html('media.replace_help'))
            . ($usage === '' ? '<p class="admin-meta">' . $t->html('media.usage.empty') . '</p>' : '<ul class="admin-list">' . $usage . '</ul>')
            . '</section></div></form>';
        return AdminShell::application($media->name, TrustedHtml::fromEngineMarkup($markup), $profile, 'media', 'wide', $chrome);
    }
}
