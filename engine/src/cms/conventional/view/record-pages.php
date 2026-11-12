<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\View;

use Utsuri\PortableCms\Conventional\Content\EditorialControls;
use Utsuri\PortableCms\Conventional\Content\EditorialForm;
use Utsuri\PortableCms\Conventional\Content\FieldDefinition;
use Utsuri\PortableCms\Conventional\Content\LocaleCoverage;
use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Content\RecordLabels;
use Utsuri\PortableCms\Conventional\Content\RecordSnapshot;
use Utsuri\PortableCms\Conventional\Content\RecordTypeDefinition;
use Utsuri\PortableCms\Conventional\Http\Html;
use Utsuri\PortableCms\Conventional\Http\Request;
use Utsuri\PortableCms\Conventional\I18n\Message;
use Utsuri\PortableCms\Conventional\I18n\Translator;

/** One page of a collection list after native search and status filtering. */
final readonly class RecordListing
{
    /**
     * @param list<RecordSnapshot> $records
     * @param array<string, int> $states Totals by workflow state before filtering.
     * @param ?\Closure(RecordSnapshot): string $thumbnail Trusted engine thumbnail markup.
     * @param array<string, int> $usage Incoming references for taxonomy terms.
     */
    public function __construct(
        public array $records,
        public string $query = '',
        public string $state = '',
        public array $states = [],
        public int $page = 1,
        public bool $hasNext = false,
        public ?\Closure $thumbnail = null,
        public array $usage = [],
    ) {}
}

final class RecordPages
{
    public const EDITOR_SCRIPT_VERSION = '18';
    private const MEDIA_UPLOAD_ASSET_VERSION = '7';
    private const LIVE_PREVIEW_STYLE_VERSION = '2';

    public static function groupLabel(RecordTypeDefinition $type, ?Translator $translator = null): string
    {
        return ($translator ?? Translator::english())->text('shell.group.' . match (true) {
            $type->kind === 'taxonomy' => 'library',
            $type->archetype === 'site_settings' => 'website',
            $type->kind === 'singleton' => 'pages',
            default => 'content',
        });
    }

    public static function listing(ProfileManifest $profile, RecordTypeDefinition $type, RecordListing $listing, ?AdminChrome $chrome): string
    {
        $t = AdminShell::translator($chrome);
        $taxonomy = $type->kind === 'taxonomy';
        $languages = !$taxonomy && count($profile->locales) > 1 && LocaleCoverage::localized($type);
        $base = '/records/' . $type->id;
        $rows = '';
        foreach ($listing->records as $record) {
            $url = $base . '/' . $record->id;
            $thumbnail = $listing->thumbnail === null ? '' : ($listing->thumbnail)($record);
            $rows .= '<tr><td data-column="title"><div class="admin-table__record">' . $thumbnail . '<span><a class="admin-table__title" href="' . Html::url($url) . '">'
                . Html::text(RecordLabels::label($record, $profile)) . '</a><span class="admin-table__sub">' . Html::text(self::subtitle($record, $profile, $t)) . '</span></span></div></td>';
            if ($languages) {
                $rows .= '<td data-column="languages">' . self::coverage($type, $record->body, $profile, $t) . '</td>';
            }
            if ($taxonomy) {
                $rows .= '<td data-column="used_by">' . $t->html('records.used_by_count', ['count' => $listing->usage[$record->id] ?? 0]) . '</td>';
            }
            $rows .= '<td data-column="status">' . AdminShell::status($record->hasUnpublishedChanges() ? 'published_changes' : $record->workflowState, null, $t) . '</td>'
                . '<td data-column="updated">' . AdminShell::time($record->updatedAt, $chrome?->now, $t) . '</td>'
                . '<td class="admin-table__chevron" aria-hidden="true">›</td></tr>';
        }
        $singular = $t->inlineLabel($type->singularLabel);
        $plural = $t->inlineLabel($type->label);
        $filtered = $listing->query !== '' || $listing->state !== '';
        $records = $rows === ''
            ? '<div class="admin-section admin-empty"><h2>' . $t->html($filtered ? 'records.empty.filtered' : 'records.empty.title', ['plural' => $plural]) . '</h2>'
                . '<p class="admin-meta">' . ($filtered ? $t->html('records.empty.filtered_help') : $t->html('records.empty.help', ['singular' => $singular])) . '</p>'
                . ($filtered ? '<a class="admin-button" href="' . Html::url($base) . '">' . $t->html('records.clear_filters') . '</a>' : '') . '</div>'
            : '<div class="admin-table-wrap"><table class="admin-table"><thead><tr><th scope="col">' . $t->html('records.column.title') . '</th>'
                . ($languages ? '<th scope="col">' . $t->html('records.column.languages') . '</th>' : '') . ($taxonomy ? '<th scope="col">' . $t->html('records.column.used_by') . '</th>' : '')
                . '<th scope="col">' . $t->html('records.column.status') . '</th><th scope="col">' . $t->html('records.column.updated') . '</th><th scope="col"><span class="admin-visually-hidden">' . $t->html('records.column.open') . '</span></th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
        $total = array_sum(array_intersect_key($listing->states, array_flip(['draft', 'published', 'archived'])));
        $toolbar = '<form class="admin-toolbar" method="get" action="' . Html::url($base) . '" role="search">'
            . '<label class="admin-visually-hidden" for="record-query">' . $t->html('records.search.label', ['plural' => $plural]) . '</label>'
            . '<input type="search" id="record-query" name="q" maxlength="100" placeholder="' . $t->html('records.search.label', ['plural' => $plural]) . '" value="' . Html::attribute($listing->query) . '">'
            . ($listing->state === '' ? '' : '<input type="hidden" name="status" value="' . Html::attribute($listing->state) . '">')
            . '<button type="submit">' . $t->html('records.search.submit') . '</button>';
        if (count($listing->states) > 1) {
            $toolbar .= '<span class="admin-toolbar__spacer"></span><nav class="admin-segmented" aria-label="' . $t->html('records.filter.label') . '">'
                . self::filterLink($base, $listing->query, '', $t->text('records.filter.all'), $total, $listing->state === '');
            foreach ($listing->states as $state => $count) {
                $toolbar .= self::filterLink($base, $listing->query, $state, AdminShell::stateLabel($state, $t), $count, $listing->state === $state);
            }
            $toolbar .= '</nav>';
        }
        $toolbar .= '</form>';
        $pagination = $listing->page > 1 || $listing->hasNext
            ? '<nav class="admin-pagination" aria-label="' . $t->html('records.pages.label') . '"><span>' . $t->html('records.pages.current', ['page' => (string) $listing->page]) . '</span>'
                . ($listing->page > 1 ? '<a class="admin-button admin-button--small" href="' . Html::url($base . '?' . http_build_query(['q' => $listing->query, 'status' => $listing->state, 'page' => $listing->page - 1])) . '">' . $t->html('records.pages.previous') . '</a>' : '')
                . ($listing->hasNext ? '<a class="admin-button admin-button--small" href="' . Html::url($base . '?' . http_build_query(['q' => $listing->query, 'status' => $listing->state, 'page' => $listing->page + 1])) . '">' . $t->html('records.pages.next') . '</a>' : '') . '</nav>'
            : '';
        $intro = '<p class="admin-meta">' . $t->html('records.total', ['count' => (string) $total, 'noun' => $total === 1 ? $singular : $plural]) . '</p>';
        $body = AdminShell::breadcrumbs([[self::groupLabel($type, $t), null], [$type->label, null]], $t)
            . AdminShell::header($type->label, $intro, '<a class="admin-button admin-button--primary" href="' . Html::url($base . '/new') . '">' . $t->html('records.new', ['singular' => $singular]) . '</a>')
            . $toolbar . $records . $pagination;
        return AdminShell::application($type->label, TrustedHtml::fromEngineMarkup($body), $profile, $type->id, 'wide', $chrome);
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, Message> $errors
     * @param array<string, list<RecordSnapshot>> $choices
     * @param array<string, list<array{media: \Utsuri\PortableCms\Conventional\Media\LibraryMedia, jobId: ?string}>> $uploadDescriptions
     */
    public static function form(
        string $csrfToken,
        ProfileManifest $profile,
        RecordTypeDefinition $type,
        array $values,
        ?RecordSnapshot $record,
        array $errors,
        ?RecordSnapshot $conflict,
        ?int $submittedRevision,
        array $choices,
        bool $mediaUploadEnabled,
        bool $technicalData,
        ?ReferenceControls $referenceControls,
        bool $pendingControls,
        bool $structureChanged,
        ?CompositionEditor $compositionEditor,
        ?AdminChrome $chrome,
        string $assistancePanel = '',
        ?Message $mediaNotice = null,
        array $uploadDescriptions = [],
        string $scheduleNotice = '',
        ?int $submittedEditSequence = null,
        ?bool $exactHasPage = null,
    ): string {
        $t = AdminShell::translator($chrome);
        $editing = $record !== null;
        $singular = $t->inlineLabel($type->singularLabel);
        $label = $editing ? RecordLabels::label($record, $profile) : $t->text('records.new', ['singular' => $singular]);
        if ($editing && $type->kind === 'singleton') {
            $label = $type->label;
        }
        $action = '/records/' . $type->id . ($editing ? '/' . $record->id : '');
        $summary = $scheduleNotice;
        if ($errors !== []) {
            $items = '';
            foreach ($errors as $fieldId => $message) {
                $field = null;
                foreach ($type->fields as $candidate) {
                    $field = $candidate->id === $fieldId ? $candidate : $field;
                }
                $field ??= EditorialControls::nestedDefinition($type, $profile, $values, $fieldId);
                $items .= '<li><a href="#field-' . Html::attribute($fieldId) . '">' . Html::text(EditorialRecordLayout::fieldMessage($message, $field, $t)) . '</a></li>';
            }
            $summary .= '<section class="admin-notice admin-notice--danger" role="alert" tabindex="-1" data-validation-summary aria-labelledby="validation-heading">'
                . '<h2 id="validation-heading">' . $t->html('editor.validation.title') . '</h2><ul>' . $items . '</ul></section>';
        }
        $conflictMarkup = $conflict === null ? '' : self::conflict($profile, $type, $record, $conflict, $submittedRevision, $referenceControls, $t);
        $compositionEditor ??= new CompositionEditor($profile, $type, new Request('GET', $action), null, $profile->designRegistries, translator: $t);
        $uploads = $editing && $mediaUploadEnabled;
        // Each image field owns its upload intent; unbound library/video uploads keep their panel.
        $tileFields = [];
        foreach ($uploads && $referenceControls !== null ? $type->fields : [] as $candidate) {
            if ($candidate->type === 'media_references' && !$candidate->localized) {
                $tileFields[] = $candidate->id;
            }
        }
        $unbound = $uploads && ($tileFields === [] || \Utsuri\PortableCms\Conventional\Media\MediaContract::videoFieldMaximum($type->fields) > 0);
        $parts = EditorialRecordLayout::parts(
            $type,
            $values,
            $errors,
            static fn(FieldDefinition $field, mixed $value, ?string $error): string => match (true) {
                $uploads && $field->type === 'video_reference' && $referenceControls !== null && $record !== null => FieldHint::render($referenceControls->field($field, $value, $error, self::uploadPanel($csrfToken, $type, $record, $t, $profile, $referenceControls->videoUploads(), $field->id, $submittedRevision)), $field),
                in_array($field->id, $tileFields, true) && $referenceControls !== null && $record !== null => FieldHint::render($referenceControls->field($field, $value, $error, self::uploadPanel($csrfToken, $type, $record, $t, $profile, $referenceControls->videoUploads(), $field->id, $submittedRevision, $uploadDescriptions[$field->id] ?? [])), $field),
                $field->type === 'group' && isset($field->settings['searchSharing']) && $referenceControls !== null => FieldHint::render($referenceControls->searchSharing($type, $field, $values, $error, static fn(FieldDefinition $child, mixed $childValue): string => self::field($child, $childValue, null, $profile, [], $referenceControls, null, $t)), $field),
                default => self::field($field, $value, $error, $profile, $choices[$field->id] ?? [], $referenceControls, $compositionEditor, $t, $field->id === 'slug' && $type->route !== null ? '…/' . $type->route . '/' : '', $errors),
            },
            $technicalData,
            $errors !== [] || $conflict !== null ? ($record === null ? [] : $record->body) : null,
            [...($unbound ? ['media' => self::uploadPanel($csrfToken, $type, $record, $t, $profile, $referenceControls?->videoUploads())] : [])],
            $t,
        );
        $revision = $editing
            ? '<input type="hidden" name="_revision" value="' . Html::attribute((string) ($conflict === null ? ($submittedRevision ?? $record->revisionNumber) : $record->revisionNumber)) . '">'
            : '';
        $revision .= '<input type="hidden" name="_edit_sequence" value="' . ($record->editSequence ?? 0) . '">';
        if ($conflict !== null) {
            $revision .= '<input type="hidden" name="_editor_base_revision" value="' . Html::attribute((string) ($submittedRevision ?? $record->revisionNumber)) . '">';
            $revision .= '<input type="hidden" name="_editor_base_sequence" value="' . ($submittedEditSequence ?? $record->editSequence) . '">';
        }
        $status = $errors !== [] ? $t->text('editor.status.invalid')
            : ($conflict !== null ? $t->text('editor.status.conflict')
            : ($structureChanged ? $t->text('client.editor.unsaved')
            : ($pendingControls ? $t->text('editor.status.pending')
            : ($editing ? $t->text('editor.status.saved', ['revision' => (string) $record->revisionNumber]) : $t->text('editor.status.new')))));
        $facts = $editing
            ? '<dl class="admin-facts"><dt>' . $t->html('editor.facts.status') . '</dt><dd data-record-state>' . Html::text(AdminShell::stateLabel($record->hasUnpublishedChanges() ? 'published_changes' : $record->workflowState, $t)) . '</dd>'
                . '<dt>' . $t->html('editor.facts.revision') . '</dt><dd data-record-revision-number>' . $record->revisionNumber . '</dd><dt>' . $t->html('editor.facts.saved') . '</dt><dd>' . AdminShell::time($record->updatedAt, $chrome?->now, $t) . '</dd></dl>'
            : '';
        if ($record?->publishedRevisionNumber !== null) {
            $facts .= '<p class="admin-meta" data-published-revision="' . $record->publishedRevisionNumber . '">' . $t->html('workflow.published_revision', ['revision' => (string) $record->publishedRevisionNumber]) . ' ' . AdminShell::time($record->publishedAt, $chrome?->now, $t) . '</p>';
            if ($record->hasUnpublishedChanges()) {
                $facts .= '<p class="admin-notice" data-unpublished-changes>' . $t->html('workflow.pending') . ' ' . $t->html('workflow.changed_at') . ' ' . AdminShell::time($record->updatedAt, $chrome?->now, $t) . '</p>';
            }
        }
        $localized = count($profile->locales) > 1 && LocaleCoverage::localized($type);
        $live = $chrome !== null && !in_array('live-preview', $chrome->unavailable, true);
        $liveEndpoint = '/records/' . $type->id . '/' . ($editing ? $record->id : 'new') . '/live-preview';
        // Without scripts the fallback opens the same render in a new tab; the island swaps it for the workspace toggle.
        $liveControls = $live ? '<button type="button" data-live-toggle data-script-control="editor" aria-pressed="false" aria-controls="live-preview" aria-describedby="record-live-help" hidden>' . $t->html('live.toggle') . '</button>'
            . '<button type="submit" class="admin-button--quiet" data-live-fallback data-script-fallback="editor" formaction="' . Html::url($liveEndpoint) . '" formtarget="_blank" formnovalidate aria-describedby="record-live-help">' . $t->html('live.fallback') . '</button><span id="record-live-help" class="admin-visually-hidden">' . $t->html('exact.live_help') . '</span>' : '';
        $exact = $chrome !== null && !in_array('previews', $chrome->unavailable, true);
        $exactControl = $exact ? '<button type="submit" data-exact-preview form="record-exact-preview-form" formnovalidate aria-describedby="record-exact-help record-save-status">'
            . $t->html($exactHasPage === false ? 'exact.open_home' : 'exact.open') . '</button><span id="record-exact-help" class="admin-visually-hidden">' . $t->html('exact.description') . '</span>' : '';
        $previewControls = $liveControls === '' && $exactControl === '' ? '' : '<div class="admin-preview-actions">' . $liveControls . $exactControl . '</div>';
        if ($exact) {
            $previewControls .= '<span class="admin-help" role="status" aria-live="polite" data-exact-status></span><p class="admin-help" data-exact-save-first' . ($conflict === null ? ' data-script-fallback="editor"' : '') . '>' . $t->html('exact.save_first') . '</p>';
        }
        $scriptStatus = $record !== null && $errors === [] && $conflict === null && !$structureChanged && !$pendingControls
            ? $t->text('client.keep.saved', ['time' => $t->date($record->updatedAt, 'format.time', $chrome?->now?->getTimezone() ?? $record->updatedAt->getTimezone())]) : null;
        $statusMarkup = $scriptStatus === null ? Html::text($status)
            : '<span data-script-fallback="editor">' . Html::text($status) . '</span><span data-script-control="editor" hidden>' . Html::text($scriptStatus) . '</span>';
        $rail = '<aside class="admin-editor__rail" aria-label="' . $t->html('editor.rail') . '"><div class="admin-save-bar"><p id="record-save-status" tabindex="-1" class="admin-save-bar__status" role="status" data-save-status>' . $statusMarkup . '</p>'
            . '<button type="submit" class="admin-button--primary" data-record-save' . ($conflict === null ? ' data-script-fallback="editor"' : '') . '>' . ($conflict === null ? $t->html($record !== null && $record->workflowState !== 'draft' ? 'workflow.save' : 'editor.save') : $t->html('editor.save_over', ['revision' => (string) $conflict->revisionNumber])) . '</button>'
            . '<button type="button" data-keep-retry hidden>' . $t->html('client.keep.retry') . '</button><span class="admin-visually-hidden" role="status" aria-live="polite" data-keep-announcement></span>'
            . $previewControls . $facts . '</div>' . ($record === null || $chrome?->role === 'editor' ? '' : RecordWorkflowPage::controls($record, $t, allowDelete: $type->kind === 'collection')) . $parts['properties']
            . '<nav class="admin-outline admin-rail-card" aria-label="' . $t->html('editor.sections') . '"><h2>' . $t->html('editor.outline') . '</h2><ul>' . $parts['outline'] . '</ul></nav>'
            . ($localized ? self::coverageCard($type, $values, $profile, $t) : '') . '</aside>';
        $switcher = '';
        if ($localized) {
            foreach ($profile->localeDefinitions as $locale) {
                $switcher .= '<button type="button" data-locale-tab="' . Html::attribute($locale->id) . '" aria-pressed="' . ($locale->id === $profile->locales[0] ? 'true' : 'false') . '" tabindex="' . ($locale->id === $profile->locales[0] ? '0' : '-1') . '">'
                    . '<span class="admin-dot" data-locale-dot aria-hidden="true"></span>' . Html::text($locale->label) . '</button>';
            }
            $switcher = '<div class="admin-segmented admin-record-locales" role="group" aria-label="' . $t->html('editor.language') . '" data-record-locale-switcher data-script-control="editor" hidden>' . $switcher . '</div>';
        }
        $meta = $editing
            ? '<p class="admin-meta-line">' . AdminShell::status($record->hasUnpublishedChanges() ? 'published_changes' : $record->workflowState, null, $t) . '<span>' . $t->html('editor.meta.revision', ['revision' => (string) $record->revisionNumber]) . '</span><span aria-hidden="true">·</span><span>'
                . $t->markup('editor.meta.saved', [], ['time' => AdminShell::time($record->updatedAt, $chrome?->now, $t)]) . '</span></p>'
            : '<p class="admin-meta-line">' . $t->html('editor.meta.unsaved', ['type' => $type->singularLabel]) . '</p>';
        $trail = [[self::groupLabel($type, $t), null]];
        if ($type->kind !== 'singleton') {
            $trail[] = [$type->label, '/records/' . $type->id];
        }
        $trail[] = [$label, null];
        $upload = $unbound ? '<form id="media-upload-form" method="post" enctype="multipart/form-data" action="' . Html::url('/records/' . $type->id . '/' . $record->id . '/upload') . '">'
            . '<input type="hidden" name="_csrf" value="' . Html::attribute($csrfToken) . '"><input type="hidden" name="_revision" value="' . $record->revisionNumber . '"></form>' : '';
        if ($unbound && !in_array('media', $parts['placed'], true)) {
            $upload .= '<section class="admin-section" aria-label="' . $t->html('editor.upload.section') . '">' . self::uploadPanel($csrfToken, $type, $record, $t, $profile, $referenceControls?->videoUploads()) . '</section>';
        }
        if ($uploads) {
            $upload .= '<details class="admin-section"><summary>' . $t->html('media.descriptions.title') . '</summary><p class="admin-help">'
                . $t->html('media.text.help', ['locale' => $profile->defaultLocale]) . '</p><noscript><p class="admin-help">' . $t->html('media.descriptions.keep_record') . '</p></noscript></details>';
        }
        $body = AdminShell::breadcrumbs($trail, $t) . AdminShell::header($label, $meta, $switcher) . $summary . $conflictMarkup
            . ($mediaNotice === null ? '' : '<p class="admin-notice" role="status" data-media-upload-result>' . Html::text($t->message($mediaNotice)) . '</p>')
            . '<form class="admin-form" data-editorial-form data-autosave="true" data-saved-at="' . ($record === null ? '' : Html::attribute($record->updatedAt->format(DATE_ATOM))) . '" data-messages="' . $t->bundleAttribute('client') . '" data-pending-input="' . ($errors !== [] || $conflict !== null || $pendingControls || $structureChanged ? 'true' : 'false') . '" method="post" action="' . Html::url($action) . '">'
            . '<input type="hidden" name="_csrf" value="' . Html::attribute($csrfToken) . '">' . $revision
            . '<div class="admin-editor"><div class="admin-editor__main">' . $parts['sections'] . $assistancePanel . '</div>' . $rail . '</div>'
            . '<input type="hidden" name="_editorial_end" value="1"></form>'
            . ($exact ? '<form id="record-exact-preview-form" method="post" action="' . Html::url('/records/' . $type->id . '/' . ($record->id ?? 'new') . '/exact-preview') . '" target="_blank" rel="noopener"><input type="hidden" name="_csrf" value="' . Html::attribute($csrfToken) . '"><input type="hidden" name="_exact_locale" value="' . Html::attribute($profile->defaultLocale) . '"></form>' : '')
            . ($live ? self::livePane($liveEndpoint, $profile, $t) : '') . ($record === null || $chrome?->role === 'editor' ? '' : RecordWorkflowPage::form($record, $csrfToken)) . $upload
            . implode('', array_map(static fn(string $suffix): string => '<form id="media-descriptions-form' . Html::attribute($suffix) . '" method="post" action="/media/descriptions" target="_blank" rel="noopener"><input type="hidden" name="_csrf" value="' . Html::attribute($csrfToken) . '"><input type="hidden" name="_descriptions_end" value="1"></form>', [...($unbound ? [''] : []), ...array_map(static fn(string $id): string => '-' . $id, $tileFields)]))
            . '<script type="module" data-admin-enhancement="editor" src="' . Html::url('/assets/editorial-form.js?v=' . self::EDITOR_SCRIPT_VERSION) . '"></script>'
            . ($uploads ? '<script type="module" src="' . Html::url('/assets/resumable-upload.js?v=' . self::MEDIA_UPLOAD_ASSET_VERSION) . '"></script>' : '');
        return AdminShell::application($label, TrustedHtml::fromEngineMarkup(self::languageVisibility($body, $profile, $errors === [])), $profile, $type->id, 'editor', $chrome);
    }

    /**
     * The live preview workspace, inert until the island opens it. Frames run the site's own
     * scripts in an opaque origin; the token documents also carry a sandbox policy of their own.
     */
    private static function livePane(string $endpoint, ProfileManifest $profile, Translator $t): string
    {
        $frames = '';
        foreach (['a', 'b'] as $index => $name) {
            $frames .= '<iframe name="live-preview-' . $name . '" title="' . $t->html('live.frame') . '" sandbox="allow-scripts allow-popups allow-popups-to-escape-sandbox"'
                . ' referrerpolicy="no-referrer" data-live-frame' . ($index === 0 ? '' : ' hidden') . '></iframe>';
        }
        $screens = '';
        foreach (['desktop' => 1440, 'tablet' => 768, 'phone' => 390] as $screen => $width) {
            $screens .= '<button type="button" data-live-screen="' . $width . '" aria-pressed="' . ($screen === 'desktop' ? 'true' : 'false') . '">' . $t->html('live.screen.' . $screen) . '</button>';
        }
        return '<link rel="stylesheet" href="' . Html::url('/assets/live-preview.css?v=' . self::LIVE_PREVIEW_STYLE_VERSION) . '"><section class="admin-live" id="live-preview" data-live-preview data-endpoint="' . Html::url($endpoint) . '" data-default-locale="' . Html::attribute($profile->defaultLocale) . '"'
            . ' aria-labelledby="live-preview-heading" hidden><h2 class="admin-visually-hidden" id="live-preview-heading">' . $t->html('live.region') . '</h2>'
            . '<div class="admin-live__bar">'
            . '<div class="admin-segmented admin-live__modes" role="group" aria-label="' . $t->html('live.mode.label') . '">'
            . '<button type="button" data-live-mode="edit" aria-pressed="false">' . $t->html('live.mode.edit') . '</button>'
            . '<button type="button" data-live-mode="preview" aria-pressed="true">' . $t->html('live.mode.preview') . '</button></div>'
            . '<label class="admin-live__page"><span>' . $t->html('live.page.label') . '</span><select data-live-page>'
            . '<option value="record">' . $t->html('live.page.record') . '</option><option value="home">' . $t->html('live.page.home') . '</option></select></label>'
            . '<div class="admin-segmented admin-live__screens" role="group" aria-label="' . $t->html('live.screen.label') . '">' . $screens . '</div>'
            . '<span class="admin-live__actions"><a class="admin-button admin-button--small" data-live-open target="_blank" rel="noopener" aria-disabled="true">' . $t->html('live.open') . '</a>'
            . '<button type="button" class="admin-button--small admin-button--quiet" data-live-close>' . $t->html('live.close') . '</button></span></div>'
            . '<div class="admin-live__state"><p class="admin-live__status" role="status" data-live-status></p>'
            . '<button type="button" class="admin-button--small" data-live-update hidden>' . $t->html('live.update') . '</button></div>'
            . '<div class="admin-live__stage" data-live-stage><div class="admin-live__box" data-live-box>' . $frames . '</div></div></section>'
            . '<div class="admin-live-separator" role="separator" aria-orientation="vertical" tabindex="0" aria-label="' . $t->html('live.separator') . '"'
            . ' aria-controls="live-preview" aria-valuemin="20" aria-valuemax="60" aria-valuenow="30" data-live-separator hidden></div>';
    }

    /** @param list<RecordSnapshot> $choices
     * @param array<string, Message> $errors
     */
    public static function field(
        FieldDefinition $field,
        mixed $value,
        ?string $error,
        ProfileManifest $profile,
        array $choices = [],
        ?ReferenceControls $referenceControls = null,
        ?CompositionEditor $compositionEditor = null,
        ?Translator $translator = null,
        string $addressPrefix = '',
        array $errors = [],
    ): string {
        return self::languageVisibility(FieldHint::render(self::control($field, $value, $error, $profile, $choices, $referenceControls, $compositionEditor, $translator, $addressPrefix, $errors), $field), $profile);
    }

    /** @param list<RecordSnapshot> $choices
     * @param array<string, Message> $errors
     */
    private static function control(FieldDefinition $field, mixed $value, ?string $error, ProfileManifest $profile, array $choices, ?ReferenceControls $referenceControls, ?CompositionEditor $compositionEditor, ?Translator $translator, string $addressPrefix, array $errors): string
    {
        $t = $translator ?? Translator::english();
        if ($field->type === 'blocks') {
            return BlocksControls::field(
                $field,
                $value,
                $profile,
                static fn(FieldDefinition $child, mixed $childValue, ?string $childError, FieldDefinition $group): string => self::field($child, $childValue, $childError, $profile, [], $referenceControls, $compositionEditor?->within($group->fields), $t),
                $error,
                $errors,
                $t,
            );
        }
        if ($field->type === 'composition' && $compositionEditor !== null) {
            return $compositionEditor->field($field, $value, $error);
        }
        if (in_array($field->type, ['group', 'repeatable_group'], true)) {
            return GroupControls::field(
                $field,
                $value,
                $profile,
                static fn(FieldDefinition $child, mixed $childValue): string => self::field($child, $childValue, null, $profile, [], $referenceControls, null, $t),
                $error,
                $referenceControls?->focusAttributes($field->id) ?? '',
                $t,
            );
        }
        if ($referenceControls !== null && in_array($field->type, ['record_reference', 'record_references', 'taxonomy_references', 'media_references', 'video_reference', 'vimeo_video'], true)) {
            return $referenceControls->field($field, $value, $error);
        }
        if ($field->type === 'video_reference') {
            return VideoReferenceControl::field($field, $value, $error, $profile, new Request('GET', '/'), null, $t);
        }
        if ($field->type === 'vimeo_video') {
            return VimeoVideoControl::field($field, $value, $error, $profile, new Request('GET', '/'), null, $t);
        }
        if ($field->type === 'web_address') {
            return WebAddressControl::field($field, $value, $error, $profile, $t);
        }
        if ($field->type === 'rich_text') {
            return RichTextEditor::field($field, $value, $error, $profile, $t);
        }
        $fieldId = 'field-' . $field->id;
        $required = $field->required ? ' required' : '';
        $invalid = $error !== null ? ' aria-invalid="true" aria-describedby="error-' . Html::attribute($field->id) . '"' : '';
        $errorMarkup = $error !== null ? '<p class="admin-field__error" id="error-' . Html::attribute($field->id) . '">' . Html::text($error) . '</p>' : '';
        $label = Html::text($field->label) . ($field->required ? ' <span class="admin-field__required">' . $t->html('field.required') . '</span>' : '');

        if ($field->localized && in_array($field->type, ['localized_short_text', 'multiline_text'], true)) {
            $localized = is_array($value) ? $value : [];
            $controls = '';
            foreach ($profile->localeDefinitions as $localeDefinition) {
                $locale = $localeDefinition->id;
                $localeValue = is_string($localized[$locale] ?? null) ? $localized[$locale] : '';
                $controlId = $fieldId . '-' . $locale;
                $control = $field->type === 'multiline_text'
                    ? '<textarea id="' . Html::attribute($controlId) . '" name="' . Html::attribute($field->id . '__' . $locale) . '" maxlength="'
                        . $field->maximumLength . '" rows="5"' . ($localeDefinition->preparation ? '' : $required) . $invalid . '>' . Html::text($localeValue) . '</textarea>'
                    : '<input id="' . Html::attribute($controlId) . '" name="' . Html::attribute($field->id . '__' . $locale) . '" type="text" maxlength="'
                        . $field->maximumLength . '" value="' . Html::attribute($localeValue) . '"' . ($localeDefinition->preparation ? '' : $required) . $invalid . '>';
                if ($addressPrefix !== '') {
                    // The page address is edited inside the route prefix it produces.
                    $control = '<div class="admin-input-group admin-address"><span class="admin-input-unit" aria-hidden="true">' . Html::text($addressPrefix) . '</span>' . $control . '</div>';
                }
                $controls .= '<div class="admin-locale" data-locale-panel="' . Html::attribute($locale) . '" lang="' . Html::attribute($locale) . '"><label for="'
                    . Html::attribute($controlId) . '">' . Html::text($localeDefinition->label) . '</label>' . $control . '</div>';
            }
            return '<fieldset class="admin-field" id="' . Html::attribute($fieldId) . '"><legend>' . $label . self::localeTag() . '</legend>'
                . '<div class="admin-locales">' . $controls . '</div>' . $errorMarkup . '</fieldset>';
        }

        if (in_array($field->type, ['record_reference', 'record_references', 'taxonomy_references'], true)) {
            $multiple = in_array($field->type, ['record_references', 'taxonomy_references'], true);
            $selected = $multiple ? array_fill_keys(is_array($value) ? array_values($value) : [], true) : [is_string($value) ? $value : '' => true];
            $options = $multiple ? '' : '<option value="">' . $t->html('field.none') . '</option>';
            foreach ($choices as $choice) {
                $options .= '<option value="' . Html::attribute($choice->id) . '"' . (isset($selected[$choice->id]) ? ' selected' : '') . '>'
                    . Html::text(RecordLabels::label($choice, $profile)) . '</option>';
            }
            return '<div class="admin-field"><label for="' . Html::attribute($fieldId) . '">' . $label . '</label>'
                . ($multiple ? '<span class="admin-field__hint">' . $t->html('field.select_many') . '</span>' : '')
                . '<select id="' . Html::attribute($fieldId) . '" name="' . Html::attribute($field->id . ($multiple ? '[]' : '')) . '"'
                . ($multiple ? ' multiple size="' . max(2, min(8, count($choices))) . '"' : '') . $required . $invalid . '>'
                . $options . '</select>' . $errorMarkup . '</div>';
        }

        if ($field->type === 'boolean') {
            $checked = $value === true || $value === '1' ? ' checked' : '';
            return '<div class="admin-field admin-field--short" id="' . Html::attribute($fieldId) . '"><label class="admin-checkbox admin-switch"><input name="'
                . Html::attribute($field->id) . '" type="checkbox" role="switch" value="1"' . $checked . $invalid . '> '
                . '<span>' . $label . '</span></label>' . $errorMarkup . '</div>';
        }

        if ($field->type === 'enum') {
            $options = $required ? '' : '<option value="">' . $t->html('field.none') . '</option>';
            foreach ($field->settings['options'] ?? [] as $option) {
                if (!is_array($option) || !is_string($option['value'] ?? null) || !is_string($option['label'] ?? null)) {
                    continue;
                }
                $options .= '<option value="' . Html::attribute($option['value']) . '"' . ($value === $option['value'] ? ' selected' : '') . '>'
                    . Html::text($option['label']) . '</option>';
            }
            return '<div class="admin-field admin-field--short"><label for="' . Html::attribute($fieldId) . '">' . $label . '</label>'
                . '<select id="' . Html::attribute($fieldId) . '" name="' . Html::attribute($field->id) . '"'
                . $required . $invalid . '>' . $options . '</select>' . $errorMarkup . '</div>';
        }

        if (in_array($field->type, ['integer', 'decimal', 'date'], true)) {
            $text = is_scalar($value) ? (string) $value : '';
            $bounds = '';
            foreach (['minimum' => 'min', 'maximum' => 'max'] as $setting => $attribute) {
                $bound = $field->settings[$setting] ?? null;
                if (is_int($bound) || is_float($bound)) {
                    $bounds .= ' ' . $attribute . '="' . Html::attribute((string) $bound) . '"';
                }
            }
            if ($field->type === 'decimal') {
                $bounds .= ' step="any"';
            }
            return '<div class="admin-field admin-field--short"><label for="' . Html::attribute($fieldId) . '">' . $label . '</label>'
                . '<input id="' . Html::attribute($fieldId) . '" name="' . Html::attribute($field->id)
                . '" type="' . ($field->type === 'date' ? 'date' : 'number') . '" value="' . Html::attribute($text) . '"' . $bounds
                . $required . $invalid . '>' . $errorMarkup . '</div>';
        }

        $text = is_string($value) ? $value : '';
        $multiline = $field->type === 'multiline_text';
        $control = $multiline
            ? '<textarea id="' . Html::attribute($fieldId) . '" name="' . Html::attribute($field->id) . '" maxlength="' . $field->maximumLength . '" rows="6"' . $required . $invalid . '>'
                . Html::text($text) . '</textarea>'
            : '<input id="' . Html::attribute($fieldId) . '" name="' . Html::attribute($field->id) . '" type="text" maxlength="' . $field->maximumLength . '" value="'
                . Html::attribute($text) . '"' . $required . $invalid . '>';
        return '<div class="admin-field' . ($multiline || $field->maximumLength > 160 ? '' : ' admin-field--short') . '"><label for="' . Html::attribute($fieldId) . '">' . $label . '</label>'
            . $control . $errorMarkup . '</div>';
    }

    /** Filled by the editorial island with the active language; absent without script. */
    public static function localeTag(): string
    {
        return ' <span class="admin-locale-tag" data-locale-tag hidden></span>';
    }

    private static function languageVisibility(string $markup, ProfileManifest $profile, bool $primeLocale = false): string
    {
        if (count($profile->locales) > 1) {
            return $primeLocale ? str_replace('data-locale-panel="' . Html::attribute($profile->locales[0]) . '"', 'data-script-initial-locale data-locale-panel="' . Html::attribute($profile->locales[0]) . '"', $markup) : $markup;
        }
        return str_replace([self::localeTag(), 'class="admin-locale"', 'class="admin-group-cell__locale"'], ['', 'class="admin-locale admin-locale--single"', 'class="admin-group-cell__locale admin-locale--single"'], $markup);
    }

    /** @param array<string, mixed> $body */
    public static function coverage(RecordTypeDefinition $type, array $body, ProfileManifest $profile, ?Translator $translator = null): string
    {
        $t = $translator ?? Translator::english();
        $markup = '<span class="admin-coverage">';
        foreach (LocaleCoverage::missing($type, $body, $profile) as $locale => $fields) {
            $list = implode(', ', $fields);
            $markup .= '<span title="' . ($fields === [] ? $t->html('coverage.complete') : $t->html('coverage.missing_title', ['fields' => $list])) . '"><span class="admin-dot'
                . ($fields === [] ? '' : ' admin-dot--missing') . '" aria-hidden="true"></span>' . Html::text(mb_strtoupper($locale))
                . '<span class="admin-visually-hidden"> ' . ($fields === [] ? $t->html('coverage.complete_spoken') : $t->html('coverage.missing_spoken', ['fields' => $list])) . '</span></span>';
        }
        return $markup . '</span>';
    }

    /** @param array<string, mixed> $values */
    private static function coverageCard(RecordTypeDefinition $type, array $values, ProfileManifest $profile, Translator $t): string
    {
        $missing = LocaleCoverage::missing($type, $values, $profile);
        $rows = '';
        foreach ($profile->localeDefinitions as $locale) {
            $fields = $missing[$locale->id] ?? [];
            $rows .= '<dt>' . Html::text($locale->label) . '</dt><dd data-locale-coverage="' . Html::attribute($locale->id) . '"' . ($fields === [] ? '' : ' class="admin-facts__missing"') . '>'
                . ($fields === [] ? '<span class="admin-dot" aria-hidden="true"></span>' . $t->html('coverage.complete') : $t->html('client.editor.missing', ['fields' => implode(', ', $fields)])) . '</dd>';
        }
        return '<section class="admin-rail-card admin-coverage-card" aria-labelledby="coverage-heading"><h2 id="coverage-heading">' . $t->html('editor.languages') . '</h2><dl class="admin-facts">' . $rows . '</dl></section>';
    }

    private static function subtitle(RecordSnapshot $record, ProfileManifest $profile, Translator $t): string
    {
        $slug = $record->body['slug'] ?? null;
        if (is_array($slug)) {
            $slug = $slug[$profile->defaultLocale] ?? null;
        }
        return is_string($slug) && $slug !== '' ? '/' . $slug : $t->text('editor.meta.revision', ['revision' => (string) $record->revisionNumber]);
    }

    private static function filterLink(string $base, string $query, string $state, string $label, int $count, bool $current): string
    {
        $parameters = array_filter(['q' => $query, 'status' => $state], static fn(string $value): bool => $value !== '');
        return '<a href="' . Html::url($base . ($parameters === [] ? '' : '?' . http_build_query($parameters))) . '"' . ($current ? ' aria-current="page"' : '') . '>'
            . Html::text($label) . ' <span class="admin-navigation__count">' . $count . '</span></a>';
    }

    private static function conflict(ProfileManifest $profile, RecordTypeDefinition $type, ?RecordSnapshot $record, RecordSnapshot $conflict, ?int $submittedRevision, ?ReferenceControls $referenceControls, Translator $t): string
    {
        $current = '';
        foreach ($type->fields as $field) {
            if (!EditorialForm::supported($field)) {
                continue;
            }
            $current .= '<dt>' . Html::text($field->label) . '</dt><dd>'
                . ($field->type === 'rich_text' ? RichTextEditor::saved($field, $conflict->body[$field->id] ?? null, $profile, $t)
                    : EditorialValueSummary::field($field, $conflict->body[$field->id] ?? null, $profile, $referenceControls, $t)) . '</dd>';
        }
        return '<section class="admin-notice admin-notice--warning" role="alert" tabindex="-1" data-conflict-summary aria-labelledby="conflict-heading">'
            . '<h2 id="conflict-heading">' . $t->html('editor.conflict.title') . '</h2>'
            . '<p>' . $t->html('editor.conflict.detail', ['submitted' => (string) ($submittedRevision ?? $record?->revisionNumber), 'current' => (string) $conflict->revisionNumber]) . '</p>'
            . '<details><summary>' . $t->html('editor.conflict.current') . '</summary><dl>' . $current . '</dl></details>'
            . '<p>' . $t->html('editor.conflict.next') . '</p></section>';
    }

    /** @param list<array{media: \Utsuri\PortableCms\Conventional\Media\LibraryMedia, jobId: ?string}> $descriptions */
    private static function uploadPanel(string $csrfToken, RecordTypeDefinition $type, RecordSnapshot $record, Translator $t, ProfileManifest $profile, ?\Utsuri\PortableCms\Conventional\Media\VideoUploadPolicy $policy = null, ?string $field = null, ?int $revision = null, array $descriptions = []): string
    {
        $policy ??= new \Utsuri\PortableCms\Conventional\Media\VideoUploadPolicy();
        $maximum = min($policy->maximumBytes, \Utsuri\PortableCms\Conventional\Media\MediaContract::videoFieldMaximum($type->fields));
        $videoField = false;
        foreach ($type->fields as $candidate) {
            if ($candidate->id === $field && $candidate->type === 'video_reference') {
                $videoField = true;
                $maximum = min($policy->maximumBytes, \Utsuri\PortableCms\Conventional\Media\MediaContract::videoFieldMaximum([$candidate]));
            }
        }
        $video = ($field === null || $videoField) && \Utsuri\PortableCms\Conventional\Media\MediaContract::profileVideoEnabled($profile) && $maximum > 0;
        $suffix = $field === null ? '' : '-' . $field;
        $form = $field === null ? ' form="media-upload-form"' : '';
        $native = $field === null ? $form : ' name="_upload_field" value="' . Html::attribute($field) . '" formaction="' . Html::url('/records/' . $type->id . '/' . $record->id . '/upload') . '" formenctype="multipart/form-data" formnovalidate';
        $types = implode(',', \Utsuri\PortableCms\Conventional\Media\MediaContract::uploadTypes($video));
        return ($field === null ? '<details class="admin-library-upload"><summary>' . $t->html('editor.upload.section') . '</summary>' : '') . '<div class="admin-upload" data-media-upload data-upload-field="' . Html::attribute($field ?? '') . '" data-messages="' . $t->bundleAttribute('client') . '" data-record-type="' . Html::attribute($type->id) . '" data-record-id="' . Html::attribute($record->id) . '"'
            . ' data-image-max-bytes="41943040" data-video-max-bytes="' . $maximum . '" data-record-revision="' . Html::attribute((string) ($revision ?? $record->revisionNumber)) . '" data-csrf-token="' . Html::attribute($csrfToken) . '">'
            . '<label class="admin-upload__label' . ($field !== null && !$videoField ? ' admin-visually-hidden' : '') . '" for="media-upload-files' . Html::attribute($suffix) . '">' . $t->html($video ? 'video.upload_label' : 'editor.upload.label') . '</label>'
            . '<div class="admin-upload__controls"><input id="media-upload-files' . Html::attribute($suffix) . '" name="' . Html::attribute($field === null ? 'images' : 'images_' . $field) . '[]" type="file" accept="' . Html::attribute($types) . '" multiple' . $form . ' data-media-upload-files aria-describedby="media-upload-help' . Html::attribute($suffix) . '">'
            . '<button type="button" data-media-upload-start hidden>' . $t->html($video ? 'video.upload_submit' : 'editor.upload.submit') . '</button>'
            . '<button type="submit"' . $native . ' data-native-upload-start>' . $t->html($video ? 'video.upload_submit' : 'editor.upload.submit') . '</button></div>'
            . '<small class="admin-upload__limits" id="media-upload-help' . Html::attribute($suffix) . '">' . $t->html('editor.upload.limits') . ($video ? ' ' . VideoUploadHelp::limits($t, $policy, $maximum) : '') . '</small>'
            . '<progress class="admin-progress" max="1" value="0" hidden data-media-upload-progress></progress>'
            . '<p class="admin-meta" role="status" aria-live="polite" data-media-upload-status></p>' . MediaDescriptionsForm::template($profile, $t, $descriptions, 'media-descriptions-form' . $suffix, instructions: false) . '</div>' . ($field === null ? '</details>' : '');
    }
}
