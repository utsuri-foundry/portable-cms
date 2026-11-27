<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\View;

use Utsuri\PortableCms\Conventional\Content\ContentService;
use Utsuri\PortableCms\Conventional\Content\EditorialControls;
use Utsuri\PortableCms\Conventional\Content\FieldDefinition;
use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Content\RecordLabels;
use Utsuri\PortableCms\Conventional\Content\RecordReference;
use Utsuri\PortableCms\Conventional\Content\RecordSnapshot;
use Utsuri\PortableCms\Conventional\Http\Html;
use Utsuri\PortableCms\Conventional\Http\Request;
use Utsuri\PortableCms\Conventional\I18n\Message;
use Utsuri\PortableCms\Conventional\I18n\Translator;
use Utsuri\PortableCms\Conventional\Media\LibraryMedia;
use Utsuri\PortableCms\Conventional\Media\MediaLibrary;
use Utsuri\PortableCms\Conventional\Media\MediaPreparation;

final class ReferenceControls
{
    private readonly Translator $t;

    public function __construct(
        private readonly ContentService $content,
        private readonly ProfileManifest $profile,
        private readonly Request $request,
        private readonly ?MediaLibrary $media = null,
        ?Translator $translator = null,
    ) {
        $this->t = $translator ?? Translator::english();
    }

    public function translator(): Translator
    {
        return $this->t;
    }

    public function videoUploads(): \Utsuri\PortableCms\Conventional\Media\VideoUploadPolicy
    {
        return $this->media->videoUploads ?? new \Utsuri\PortableCms\Conventional\Media\VideoUploadPolicy();
    }

    /** @param array<string, mixed> $values */
    /**
     * @param array<string, mixed> $values
     * @param \Closure(FieldDefinition, mixed): string $render
     */
    public function searchSharing(\Utsuri\PortableCms\Conventional\Content\RecordTypeDefinition $type, FieldDefinition $field, array $values, ?string $error, \Closure $render): string
    {
        return SearchSharingEditor::render($type, $field, $values, $error, $this->profile, $this->content, $this->media, $render, $this->request, $this->t);
    }

    /** @param string $tile Trusted engine markup placed right after the selections, such as the upload tile. */
    public function field(FieldDefinition $field, mixed $value, ?string $error, string $tile = ''): string
    {
        $t = $this->t;
        if ($field->type === 'vimeo_video') {
            return VimeoVideoControl::field($field, $value, $error, $this->profile, $this->request, $this->media, $t);
        }
        if ($field->type === 'video_reference') {
            return VideoReferenceControl::field($field, $value, $error, $this->profile, $this->request, $this->media, $t, $tile);
        }
        $selected = EditorialControls::selections($value, $field);
        $query = mb_substr($this->request->formValue('_query_' . $field->id), 0, 100);
        $page = filter_var($this->request->formValue('_page_' . $field->id), FILTER_VALIDATE_INT);
        $page = is_int($page) ? max(1, min(1000, $page)) : 1;
        $pageAction = explode(':', $this->request->formValue('_editor_action'));
        if (($pageAction[0] ?? '') === 'page' && ($pageAction[1] ?? '') === $field->id) {
            $page = max(1, min(1000, (int) ($pageAction[2] ?? 1)));
        }
        if ($pageAction[0] === 'search' && ($pageAction[1] ?? '') === $field->id) {
            $page = 1;
        }
        $mediaField = $field->type === 'media_references';
        $filter = $field->typedReferences() ? $this->request->formValue('_type_' . $field->id) : '';
        if (!in_array($filter, $field->targetTypes, true)) {
            $filter = '';
        }
        $results = $mediaField ? $this->media?->search($query, $page) : $this->content->searchReferences($field, $query, $page, $filter);
        $items = $mediaField ? ($results->items ?? []) : ($results->records ?? []);
        // Images and records are ordered rows; taxonomy terms stay chips.
        $recordRows = in_array($field->type, ['record_reference', 'record_references'], true);
        $markup = '<fieldset class="admin-field admin-picker' . ($mediaField ? ' admin-picker--media admin-picker--rows' : ($recordRows ? ' admin-picker--records admin-picker--rows' : '')) . '" id="field-' . Html::attribute($field->id) . '" data-reference-control' . $this->focusAttributes($field->id) . '><legend>'
            . Html::text($field->label) . ($field->required ? FieldHelp::required($t) : '') . '</legend>'
            . '<input type="hidden" name="_present_' . Html::attribute($field->id) . '" value="1">';
        if ($error !== null) {
            $markup .= '<p class="admin-field__error" role="alert">' . Html::text($error) . '</p>';
        }
        $markup .= '<ol class="admin-selections" aria-label="' . $t->html('picker.selected', ['field' => $field->label]) . '">';
        $replaceOptions = '';
        foreach ($selected as $index => $id) {
            $record = !$mediaField ? $this->selectedRecord($field, $id) : null;
            $media = $mediaField ? $this->media?->find($id) : null;
            $descriptionWarning = $media === null || MediaPreparation::failed($media) ? '' : MediaDescriptionsForm::warning($media, $this->profile, $t);
            $name = $mediaField ? ($media->name ?? $t->text('picker.unavailable_image')) : ($record === null ? $t->text('picker.unavailable_record') : RecordLabels::label($record, $this->profile));
            // An image is one row: thumbnail, name, the way to its details, then the remove control.
            // A record row shows the picture its type shares, when it has one.
            $cover = $recordRows && $record !== null ? $this->coverMedia($record) : null;
            // Until an image is ready its row says when it will be; the page asks again until then.
            $waiting = $media !== null && $media->status !== 'ready';
            $markup .= '<li class="admin-selection"' . ($waiting && !MediaPreparation::failed($media) ? ' data-media-pending="' . Html::attribute($media->id) . '"' : '') . '>' . ($media === null ? ($cover === null ? '' : self::thumbnail($cover)) : self::thumbnail($media))
                . '<span>' . Html::text($name) . ($waiting ? $this->preparation($media) : '') . $this->referenceStatus($field, $id, $record) . ($record !== null && ($record->body['deprecated'] ?? false) === true ? ' <small>' . $t->html('picker.deprecated') . '</small>' : '')
                . ($descriptionWarning === '' ? '' : '<small class="admin-field__error" data-description-warning="' . Html::attribute($id) . '">' . Html::text($descriptionWarning) . '</small>')
                . ($media === null ? '' : '<a class="admin-selection__link" href="' . Html::url('/media/' . $media->id) . '">' . $t->html('picker.image_details') . '</a>') . '</span>'
                . '<input type="hidden" name="' . Html::attribute($field->id . ($field->type === 'record_reference' ? '' : '[]')) . '" value="' . Html::attribute($id) . '">'
                . '<span class="admin-selection__actions">'
                . self::button('remove:' . $field->id . ':' . $id, '×', $t->text('picker.remove', ['name' => $name]))
                . ($index > 0 ? self::button('up:' . $field->id . ':' . $id, '↑', $t->text('picker.move_earlier', ['name' => $name])) : '')
                . ($index + 1 < count($selected) ? self::button('down:' . $field->id . ':' . $id, '↓', $t->text('picker.move_later', ['name' => $name])) : '')
                . '</span></li>';
            $replaceOptions .= '<option value="' . $index . '">' . Html::text($name) . '</option>';
        }
        $markup .= '</ol>';
        // Search opens on demand; it stays open while the editor is searching, paging or picking in this field.
        $browsing = $query !== '' || $filter !== '' || ($this->request->method === 'POST' && ($pageAction[1] ?? '') === $field->id && in_array($pageAction[0], ['page', 'search', 'select', 'add-media', 'replace', 'term'], true));
        // Choosing an image puts it in the field and closes the chooser; removing the row is the undo.
        if ($mediaField && $this->request->method === 'POST' && ($pageAction[1] ?? '') === $field->id && in_array($pageAction[0], ['select', 'replace'], true)) {
            $browsing = false;
        }
        $uploading = $tile !== '' && ($this->request->formValue('_upload_field') === $field->id || ($pageAction[0] === 'uploaded-media' && ($pageAction[1] ?? '') === $field->id));
        // A full image field offers nothing to add; removing a row is the way to change it. An upload
        // that has just completed keeps its panel, so its images can still be described.
        if ($mediaField && !$uploading && $field->maximumItems !== null && count($selected) >= $field->maximumItems) {
            return $markup . '</fieldset>';
        }
        if ($tile !== '') {
            // Uploading and the library are the field's two ways to add, side by side. Scripts turn the
            // upload choice into one button that opens the file chooser and uploads what is chosen.
            $markup .= '<div class="admin-picker__add"><details class="admin-picker__upload"' . ($uploading ? ' open' : '') . '><summary>' . $t->html($field->maximumItems === 1 ? 'picker.upload_image' : 'picker.upload_images') . '</summary><div class="admin-upload-tile">' . $tile . '</div></details>'
                . '<details class="admin-picker__library"' . ($browsing ? ' open' : '') . '><summary>' . $t->html('picker.choose_library') . '</summary>';
        } else {
            $markup .= '<details class="admin-picker__browse"' . ($browsing ? ' open' : '') . '><summary>'
                . ($mediaField ? $t->html($field->maximumItems === 1 ? 'picker.choose_image' : 'picker.add_media') : $t->html($field->type === 'record_reference' ? 'picker.choose_field' : 'picker.add_field', ['field' => $t->inlineLabel($field->label)])) . '</summary>';
        }
        $markup .= '<div class="admin-picker__search"><label for="query-' . Html::attribute($field->id) . '">' . $t->html('picker.find') . '</label>'
            . '<input type="search" id="query-' . Html::attribute($field->id) . '" name="_query_' . Html::attribute($field->id) . '" maxlength="100" value="' . Html::attribute($query) . '">'
            . $this->typeFilter($field, $filter)
            . '<input type="hidden" name="_page_' . Html::attribute($field->id) . '" value="' . $page . '">'
            . self::button('search:' . $field->id, $t->text('picker.search')) . '</div>';
        if ($mediaField && $selected !== []) {
            $markup .= '<label for="replace-' . Html::attribute($field->id) . '">' . $t->html('picker.replace_target') . '</label><select id="replace-' . Html::attribute($field->id) . '" name="_replace_' . Html::attribute($field->id) . '">' . $replaceOptions . '</select>';
        }
        $markup .= '<ul class="admin-picker__results">';
        foreach ($items as $item) {
            $id = $field->typedReferences() && $item instanceof RecordSnapshot ? (new RecordReference($item->typeId, $item->id))->token() : $item->id;
            $label = $item instanceof LibraryMedia ? $item->name : RecordLabels::label($item, $this->profile);
            $invalidIcon = $item instanceof LibraryMedia && ($field->settings['purpose'] ?? null) === 'website-icon'
                && !\Utsuri\PortableCms\Conventional\Content\WebsiteIconSelection::eligible($item->status, $item->mime, $item->width, $item->height);
            $disabled = $item instanceof LibraryMedia ? $item->status !== 'ready' || !str_starts_with($item->mime, 'image/') || $invalidIcon : ($item->body['deprecated'] ?? false) === true;
            if ($item instanceof LibraryMedia && $mediaField && !$disabled && !in_array($id, $selected, true)) {
                // The whole image is one button: activating it adds the image at once, with or without scripts.
                $markup .= '<li><button type="submit" name="_editor_action" value="' . Html::attribute('select:' . $field->id . ':' . $id) . '" formnovalidate class="admin-picker__pick" aria-label="' . $t->html('picker.select', ['name' => $label]) . '">'
                    . self::thumbnail($item) . '<span><span>' . Html::text($label) . '</span><small class="admin-meta">' . Html::text(self::metadata($item, $t)) . '</small></span></button>'
                    . ($selected !== [] ? self::button('replace:' . $field->id . ':' . $id, $t->text('picker.replace'), $t->text('picker.replace_label', ['name' => $label])) : '') . '</li>';
                continue;
            }
            $markup .= '<li>' . ($item instanceof LibraryMedia ? self::thumbnail($item) : '') . '<div><span>' . Html::text($label) . '</span>'
                . ($item instanceof RecordSnapshot ? $this->referenceStatus($field, $id, $item) : '')
                . ($item instanceof LibraryMedia ? '<small class="admin-meta">' . Html::text(self::metadata($item, $t)) . '</small>' : self::taxonomyMetadata($item->body)) . '</div>';
            if (in_array($id, $selected, true)) {
                $markup .= '<span class="admin-meta">' . $t->html('picker.selected_state') . '</span>';
            } elseif ($disabled && $invalidIcon) {
                $markup .= '<span class="admin-meta">' . $t->html('picker.icon_requirements') . '</span>';
            } elseif ($disabled && !$item instanceof LibraryMedia) {
                $markup .= '<span class="admin-meta">' . $t->html('picker.deprecated') . '</span>';
            } elseif (!$disabled) {
                $markup .= self::button('select:' . $field->id . ':' . $id, $t->text($field->type === 'record_reference' ? 'picker.choose' : 'picker.add'), $t->text('picker.select', ['name' => $label]));
            }
            $markup .= '</li>';
        }
        $markup .= '</ul>' . ($items === [] ? '<p class="admin-meta">' . $t->html('picker.no_matches') . '</p>' : '')
            . '<div class="admin-picker__pagination"><span>' . $t->html('records.pages.current', ['page' => (string) $page]) . '</span>'
            . ($page > 1 ? self::button('page:' . $field->id . ':' . ($page - 1), $t->text('records.pages.previous')) : '')
            . ($results?->hasNext === true ? self::button('page:' . $field->id . ':' . ($page + 1), $t->text('records.pages.next')) : '') . '</div>'
            . ($field->type === 'taxonomy_references' ? $this->inlineTerm($field) : '') . '</details>' . ($tile === '' ? '' : '</div>');
        return $markup . '</fieldset>';
    }

    /** When a file that is not ready will be, or that it failed and what to do. */
    private function preparation(LibraryMedia $media): string
    {
        // In a field the failed row stays until it is removed, so the line says to remove it too.
        $message = MediaPreparation::failed($media) ? new Message('picker.preparation_failed') : ($this->media->preparation ?? new MediaPreparation())->message($media);
        return '<small class="' . (MediaPreparation::failed($media) ? 'admin-field__error' : 'admin-meta') . '" data-media-state>' . Html::text($this->t->message($message)) . '</small>';
    }

    public function summary(FieldDefinition $field, mixed $value): string
    {
        if ($field->type === 'video_reference') {
            $value = EditorialControls::structured($value);
            $markup = '';
            foreach ($field->localized ? $this->profile->locales : [''] as $locale) {
                $selection = $field->localized && is_array($value) ? ($value[$locale] ?? null) : $value;
                foreach (is_array($selection) ? $selection : [] as $id) {
                    $item = is_string($id) ? $this->media?->find($id) : null;
                    $markup .= '<p>' . Html::text($item->name ?? $this->t->text('video.missing')) . '</p>';
                }
            }
            return $markup;
        }
        $markup = '<ol class="admin-selections">';
        foreach (EditorialControls::selections($value, $field) as $id) {
            if ($field->type === 'media_references') {
                $media = $this->media?->find($id);
                $markup .= '<li>' . ($media === null ? $this->t->html('picker.unavailable_image') : self::thumbnail($media) . Html::text($media->name)) . '</li>';
            } else {
                $record = $this->selectedRecord($field, $id);
                $markup .= '<li>' . Html::text($record === null ? $this->t->text('picker.unavailable_record') : RecordLabels::label($record, $this->profile)) . $this->referenceStatus($field, $id, $record) . '</li>';
            }
        }
        return $markup . '</ol>';
    }

    private function selectedRecord(FieldDefinition $field, string $token): ?RecordSnapshot
    {
        if (!$field->typedReferences()) {
            return $this->content->find((string) $field->targetType, $token);
        }
        $reference = RecordReference::fromToken($token);
        return in_array($reference->typeId, $field->targetTypes, true) ? $this->content->find($reference->typeId, $reference->recordId) : null;
    }

    /** The image a record's type declares for search and sharing, when the record has one in the library. */
    private function coverMedia(RecordSnapshot $record): ?LibraryMedia
    {
        $type = $this->profile->type($record->typeId);
        if ($type === null || $this->media === null) {
            return null;
        }
        foreach ($type->fields as $field) {
            $mapping = $field->settings['searchSharing'] ?? null;
            if (!is_array($mapping)) {
                continue;
            }
            foreach (['coverImageField', 'coverFallbackField'] as $key) {
                if (!is_string($mapping[$key] ?? null)) {
                    continue;
                }
                try {
                    $id = SearchSharingFeedback::cover($type, $record->body, $mapping[$key], $this->profile);
                } catch (\InvalidArgumentException) {
                    continue;
                }
                if ($id !== null) {
                    return $this->media->find($id);
                }
            }
            return null;
        }
        return null;
    }

    private function referenceStatus(FieldDefinition $field, string $token, ?RecordSnapshot $record): string
    {
        if (!$field->typedReferences()) {
            return '';
        }
        $reference = RecordReference::fromToken($token);
        $type = $this->profile->type($reference->typeId);
        $status = ' <small class="admin-meta"> · ' . Html::text($type->singularLabel ?? $reference->typeId) . '</small>';
        if (!in_array($reference->typeId, $field->targetTypes, true)) {
            $status .= ' <small class="admin-field__error">' . $this->t->html('picker.type_removed') . '</small>';
        } elseif ($record === null) {
            $status .= ' <small class="admin-field__error">' . $this->t->html('picker.target_missing') . '</small>';
        } elseif ($record->workflowState !== 'published') {
            $status .= ' <small class="admin-meta">' . $this->t->html('picker.target_unpublished') . '</small>';
        }
        return $status;
    }

    private function typeFilter(FieldDefinition $field, string $filter): string
    {
        if (!$field->typedReferences()) {
            return '';
        }
        $id = Html::attribute($field->id);
        $markup = '<label for="type-' . $id . '">' . $this->t->html('picker.type_filter') . '</label><select id="type-' . $id . '" name="_type_' . $id . '"><option value="">' . $this->t->html('picker.all_types') . '</option>';
        foreach ($field->targetTypes as $typeId) {
            $markup .= '<option value="' . Html::attribute($typeId) . '"' . ($filter === $typeId ? ' selected' : '') . '>' . Html::text($this->profile->type($typeId)->label ?? $typeId) . '</option>';
        }
        return $markup . '</select>';
    }

    public function focusAttributes(string $fieldId): string
    {
        $action = explode(':', $this->request->formValue('_editor_action'));
        return ' tabindex="-1"' . ($this->request->method === 'POST' && ($action[1] ?? '') === $fieldId ? ' autofocus' : '');
    }

    private function inlineTerm(FieldDefinition $field): string
    {
        $target = $this->profile->type((string) $field->targetType);
        if ($target === null || $target->kind !== 'taxonomy') {
            return '';
        }
        $markup = '<details><summary>' . $this->t->html('picker.term.create', ['singular' => $this->t->inlineLabel($target->singularLabel)]) . '</summary><p class="admin-help">' . $this->t->html('picker.term.help') . '</p>';
        foreach ($this->profile->localeDefinitions as $locale) {
            $name = '_term_' . $field->id . '__' . $locale->id;
            $markup .= '<label for="' . Html::attribute($name) . '">' . Html::text($locale->label) . '</label><input type="text" id="' . Html::attribute($name) . '" name="' . Html::attribute($name) . '" maxlength="100" value="' . Html::attribute($this->request->formValue($name)) . '">';
        }
        return $markup . self::button('term:' . $field->id, $this->t->text('picker.term.submit')) . '</details>';
    }

    public static function button(string $action, string $label, ?string $accessibleLabel = null, string $class = ''): string
    {
        return '<button type="submit" name="_editor_action" value="' . Html::attribute($action) . '" formnovalidate' . ($class === '' ? '' : ' class="' . Html::attribute($class) . '"')
            . ($accessibleLabel === null ? '' : ' aria-label="' . Html::attribute($accessibleLabel) . '"') . '>' . Html::text($label) . '</button>';
    }

    public static function thumbnail(LibraryMedia $media, bool $captureVideo = false): string
    {
        if ($media->status === 'ready' && in_array($media->mime, ['video/mp4', 'video/webm'], true)) {
            return VideoPreview::thumbnail($media, $captureVideo);
        }
        return $media->status === 'ready' && $media->thumbnail !== null && $media->thumbnail->mime === 'image/webp'
            ? '<img class="admin-thumbnail" src="' . Html::url('/media/' . $media->id . '/thumbnail') . '" width="80" height="64" alt="" loading="lazy">'
            : '<span class="admin-thumbnail admin-thumbnail--pending" aria-hidden="true">' . ($media->status === 'needs_attention' ? '!' : '…') . '</span>';
    }

    public static function metadata(LibraryMedia $media, ?Translator $translator = null): string
    {
        $t = $translator ?? Translator::english();
        // A ready file needs no state word; only waiting and failed states are said.
        $parts = str_starts_with($media->mime, 'video/') ? [$t->text('video.kind'), VideoPreview::duration($media, $t)] : [];
        if ($media->width !== null) {
            $parts[] = $media->width . ' × ' . $media->height;
        }
        $parts[] = $media->byteSize < 1_048_576
            ? $t->text('media.size_small', ['kilobytes' => (string) (int) round($media->byteSize / 1024)])
            : $t->text('media.size', ['megabytes' => $media->byteSize / 1_048_576]);
        if ($media->status !== 'ready') {
            $parts[] = self::mediaState($media->status, $t);
        }
        return implode(' · ', $parts);
    }

    /** Lower-case processing state for inline metadata; unknown states stay diagnostic. */
    public static function mediaState(string $status, ?Translator $translator = null): string
    {
        $t = $translator ?? Translator::english();
        return preg_match('/\A[a-z_]{1,40}\z/', $status) === 1 && $t->has('media.state.' . $status) ? $t->text('media.state.' . $status) : $status;
    }

    /** @param array<string, mixed> $body */
    private static function taxonomyMetadata(array $body): string
    {
        $labels = is_array($body['label'] ?? null) ? $body['label'] : [];
        $aliases = is_array($body['aliases'] ?? null) ? $body['aliases'] : [];
        $text = implode(' · ', array_filter([...array_values($labels), ...array_values($aliases)], 'is_string'));
        return $text === '' ? '' : '<small class="admin-meta">' . Html::text($text) . '</small>';
    }
}
