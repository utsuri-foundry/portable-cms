<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Content;

use Utsuri\PortableCms\Conventional\Http\Request;
use Utsuri\PortableCms\Conventional\Http\Response;
use Utsuri\PortableCms\Conventional\Identity\RandomSource;
use Utsuri\PortableCms\Conventional\Identity\SessionContext;
use Utsuri\PortableCms\Conventional\Media\MediaLibrary;
use Utsuri\PortableCms\Conventional\View\Pages;
use Utsuri\PortableCms\Conventional\View\ReferenceControls;

/** Invoked only after the application has checked editorial, route, origin and CSRF authority. */
final class EditorialRecordHttp
{
    public function __construct(
        private readonly ContentService $content,
        private readonly ProfileManifest $profile,
        private readonly RandomSource $random,
        private readonly ?MediaLibrary $media,
        private readonly bool $uploadsEnabled,
        private readonly bool $technicalData,
        private readonly ?\Utsuri\PortableCms\Conventional\Design\RegistryService $design = null,
        private readonly ?\Utsuri\PortableCms\Conventional\Ai\AssistanceService $assistance = null,
        private readonly ?\Utsuri\PortableCms\Conventional\View\AdminChrome $chrome = null,
        private readonly ?\Utsuri\PortableCms\Conventional\Preview\LivePreviewService $live = null,
        private readonly ?\Utsuri\PortableCms\Conventional\Media\MediaUploadService $uploads = null,
        private readonly ?\Utsuri\PortableCms\Conventional\Publication\PublicationService $publication = null,
        private readonly ?\Utsuri\PortableCms\Conventional\Preview\EditorExactPreview $exact = null,
    ) {}

    /** @param array{kind: 'list'|'new'|'record'|'workflow'|'live', typeId: string, recordId: string|null} $route */
    public function handle(Request $request, array $route, SessionContext $session, string $correlationId): Response
    {
        $response = $this->record($request, $route, $session, $correlationId);
        $type = $this->profile->type($route['typeId']);
        return $type !== null && in_array($route['kind'], ['record', 'new'], true) && VimeoVideo::declared($type->fields)
            ? $response->withVimeoWorkspace() : $response;
    }

    /** @param array{kind: 'list'|'new'|'record'|'workflow'|'live', typeId: string, recordId: string|null} $route */
    private function record(Request $request, array $route, SessionContext $session, string $correlationId): Response
    {
        $type = $this->profile->type($route['typeId']);
        if ($type === null || $session->userId === null) {
            return new Response(404, Pages::error(404, $correlationId, $this->chrome?->translator()));
        }
        $saved = $route['recordId'] !== null ? $this->content->find($type->id, $route['recordId']) : null;
        if ($route['recordId'] !== null && $saved === null) {
            return new Response(404, Pages::error(404, $correlationId, $this->chrome?->translator()));
        }
        if ($request->method === 'GET' && $request->queryValue('_keep_context') === '1') {
            return Response::json(['csrf' => $session->csrfToken]);
        }
        if ($route['kind'] === 'live') {
            return $this->live($request, $type, $saved, $session, $correlationId);
        }
        if ($route['kind'] === 'workflow' && $saved !== null) {
            if ($request->method !== 'POST') {
                return new Response(405, Pages::error(405, $correlationId, $this->chrome?->translator()));
            }
            $revision = filter_var($request->formValue('_revision'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $editSequence = $this->editSequence($request, $saved);
            if (!is_int($revision) || $request->formValue('_confirmed') !== '1') {
                throw new \InvalidArgumentException('Status confirmation and revision are required');
            }
            if (!in_array($request->formValue('_workflow_action'), ['', 'delete', 'discard'], true)) {
                throw new \InvalidArgumentException('Unknown workflow action');
            }
            $result = $request->formValue('_workflow_action') === 'delete'
                ? $this->content->workflow()->delete($type->id, $saved->id, $revision, $session, $editSequence)
                : ($request->formValue('_workflow_action') === 'discard'
                    ? $this->content->workflow()->discard($type->id, $saved->id, $revision, $session, $editSequence)
                    : $this->content->workflow()->transition($type->id, $saved->id, $revision, $request->formValue('_workflow_state'), $session, $editSequence));
            if ($result === true) {
                return Response::redirect('/records/' . $type->id);
            }
            if ($result instanceof ValidationFailure || $result instanceof RecordConflict) {
                return new Response($result instanceof ValidationFailure ? 422 : 409, \Utsuri\PortableCms\Conventional\View\RecordWorkflowPage::failure($this->profile, $type, $result instanceof RecordConflict ? $result->current : $saved, $session->csrfToken, $request->formValue('_workflow_state'), $result instanceof ValidationFailure ? $result->errors : [], $result instanceof RecordConflict, $this->chrome));
            }
            return Response::redirect('/records/' . $type->id . '/' . $saved->id . '?status_changed=1');
        }
        if ($request->method === 'GET' && $route['kind'] === 'list') {
            return $this->listing($request, $type, $session);
        }
        $aiEditor = $this->assistance === null ? null : new \Utsuri\PortableCms\Conventional\Ai\EditorialAssistance($this->assistance, $this->profile, $this->random, $this->media, $this->chrome?->translator());
        $aiPanel = $aiEditor?->handle(new Request('GET', $request->path, form: array_replace($request->form, ['_ai_action' => ''])), $type, $saved, $session->userId, $saved->body ?? [], $saved->revisionNumber ?? 0)['panel'] ?? '';
        $controls = new ReferenceControls($this->content, $this->profile, $request, $this->media, $this->chrome?->translator());
        $scheduleNotice = $saved === null || $this->publication === null ? '' : \Utsuri\PortableCms\Conventional\View\ReleaseScheduleView::notice($this->publication->scheduledForRecord($type->id, $saved->id), $this->publication, $this->chrome?->translator() ?? \Utsuri\PortableCms\Conventional\I18n\Translator::english());
        $registry = $this->design?->load() ?? $this->profile->designRegistries;
        $compositionEditor = new \Utsuri\PortableCms\Conventional\View\CompositionEditor($this->profile, $type, $request, $this->media, $registry, translator: $this->chrome?->translator(), content: $this->content);
        $exactHasPage = $saved === null || $request->formValue('_keep') === '1' ? null : $this->exact?->hasPage($type->id, $saved->id, $this->profile->defaultLocale);
        $submittedEditSequence = null;
        $editingConflict = false;
        if ($request->method === 'GET') {
            return new Response(200, Pages::recordForm(
                $session->csrfToken,
                $this->profile,
                $type,
                $saved->body ?? [],
                $saved,
                mediaUploadEnabled: $this->uploadsEnabled && $type->kind === 'collection',
                technicalData: $this->technicalData,
                chrome: $this->chrome,
                referenceControls: $controls,
                assistancePanel: $aiPanel,
                scheduleNotice: $scheduleNotice,
                submittedEditSequence: $submittedEditSequence,
                compositionEditor: $compositionEditor,
                exactHasPage: $exactHasPage,
            ));
        }
        $revision = filter_var($request->formValue('_revision'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($saved !== null && !is_int($revision)) {
            return new Response(400, Pages::error(400, $correlationId, $this->chrome?->translator()));
        }
        $values = EditorialForm::input($request, $type, $this->profile, $saved);
        if ($request->formValue('_keep') === '1') {
            return $this->keep($request, $type, $saved, $values, $session);
        }
        if ($saved !== null) {
            $sequence = $this->editSequence($request, $saved);
            $transient = false;
            foreach (['_editor_action', '_rich_action', '_ai_action', '_composition_action', '_composition_preview', '_upload_field'] as $name) {
                $transient = $transient || $request->formValue($name) !== '';
            }
            $baseSequence = filter_var($request->formValue('_editor_base_sequence'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            $baseRevision = filter_var($request->formValue('_editor_base_revision'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $sequence = $transient && is_int($baseSequence) ? $baseSequence : $sequence;
            $expectedRevision = $transient && is_int($baseRevision) ? $baseRevision : $revision;
            $submittedEditSequence = $sequence;
            $editingConflict = $sequence !== $saved->editSequence || $expectedRevision !== $saved->revisionNumber;
            $safeTransient = $transient && $request->formValue('_ai_action') === '' && !str_starts_with($request->formValue('_editor_action'), 'term:');
            if ($request->formValue('_keep_conflict') === '1' || ($editingConflict && !$safeTransient)) {
                return new Response(409, Pages::recordForm($session->csrfToken, $this->profile, $type, $values, $saved, conflict: $saved, submittedRevision: is_int($expectedRevision) ? $expectedRevision : null, chrome: $this->chrome, referenceControls: $controls, compositionEditor: $compositionEditor, submittedEditSequence: $sequence, exactHasPage: $exactHasPage));
            }
        }
        if ($request->formValue('_ai_action') !== '' && $aiEditor !== null) {
            $baseRevision = filter_var($request->formValue('_editor_base_revision'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (is_int($baseRevision)) {
                $revision = $baseRevision;
            }
            $outcome = $aiEditor->handle($request, $type, $saved, $session->userId, $values, is_int($revision) ? $revision : 0);
            $controls = new ReferenceControls($this->content, $this->profile, $outcome['request'], $this->media, $this->chrome?->translator());
            $conflict = $saved !== null && ($editingConflict || $saved->revisionNumber !== $revision) ? $saved : null;
            return new Response($conflict !== null ? 409 : ($outcome['failed'] ? 422 : 200), Pages::recordForm(
                $session->csrfToken,
                $this->profile,
                $type,
                $outcome['values'],
                $saved,
                conflict: $conflict,
                submittedRevision: is_int($revision) ? $revision : null,
                mediaUploadEnabled: $this->uploadsEnabled && $type->kind === 'collection',
                technicalData: $this->technicalData,
                referenceControls: $controls,
                compositionEditor: $compositionEditor,
                pendingControls: true,
                assistancePanel: $outcome['panel'],
                scheduleNotice: $scheduleNotice,
                submittedEditSequence: $submittedEditSequence,
                exactHasPage: $exactHasPage,
            ));
        }
        if ($request->formValue('_ai_action') !== '') {
            throw new \InvalidArgumentException('AI assistance is unavailable.');
        }
        $compositionId = $request->formValue('_composition_preview') ?: (explode(':', $request->formValue('_composition_action'))[1] ?? '');
        $blockComposition = $compositionId === '' ? null : BlocksForm::find($type, $this->profile, $values, $compositionId);
        $compositionType = $blockComposition['type'] ?? $type;
        $compositionFields = $blockComposition === null ? $type->fields : [$blockComposition['field']];
        $compositionValues = $blockComposition === null ? $values : [...$blockComposition['values'], $compositionId => $blockComposition['value']];
        if ($request->formValue('_composition_preview') !== '') {
            foreach ($compositionFields as $field) {
                if ($field->type === 'composition' && !$field->localized && $field->id === $request->formValue('_composition_preview')) {
                    return CompositionPreview::response($request, $field, $compositionType, $this->profile, $compositionValues, $registry, $this->media, $this->chrome?->translator(), $this->content);
                }
            }
            throw new \InvalidArgumentException('Unknown preview field');
        }
        $compositionAction = $request->formValue('_composition_action');
        if ($compositionAction !== '') {
            $parts = explode(':', $compositionAction);
            $errors = [];
            $matched = false;
            foreach ($compositionFields as $field) {
                if ($field->type !== 'composition' || $field->localized || $field->id !== ($parts[1] ?? '')) {
                    continue;
                }
                $matched = true;
                $undo = null;
                try {
                    $previous = Composition::from($compositionValues[$field->id] ?? null, $field, $compositionType, $registry, $this->profile->supported());
                    if ($parts[0] === 'undo') {
                        $rawUndo = $request->formValue($field->id . '__undo');
                        if (strlen($rawUndo) > 1_048_576) {
                            throw new \InvalidArgumentException('Undo exceeds the layout limit');
                        }
                        $compositionValues[$field->id] = Composition::from(json_decode($rawUndo, true, 16, JSON_THROW_ON_ERROR), $field, $compositionType, $registry, $this->profile->supported())->document();
                    } elseif (!in_array($parts[0], ['edit-row', 'media-page', 'link-page'], true)) {
                        $compositionValues[$field->id] = CompositionForm::apply($parts[0], $parts[2] ?? '', $previous, $field, $this->random);
                        $undo = json_encode($previous->compact(), JSON_THROW_ON_ERROR);
                    }
                } catch (\JsonException) {
                    $errors[$field->id] = new \Utsuri\PortableCms\Conventional\I18n\Message('validation.layout.undo_invalid');
                } catch (\InvalidArgumentException $error) {
                    $errors[$field->id] = \Utsuri\PortableCms\Conventional\I18n\Message::fromFailure($error);
                }
                $compositionEditor = new \Utsuri\PortableCms\Conventional\View\CompositionEditor($this->profile, $type, $request, $this->media, $registry, $undo === null ? [] : [$field->id => $undo], $this->chrome?->translator(), $this->content);
            }
            if (!$matched) {
                throw new \InvalidArgumentException('Unknown composition field');
            }
            $values = $blockComposition === null ? $compositionValues : BlocksForm::replace($values, $blockComposition, $compositionValues[$blockComposition['field']->id]);
            $base = filter_var($request->formValue('_editor_base_revision'), FILTER_VALIDATE_INT);
            if (is_int($base) && $base > 0) {
                $revision = $base;
            }
            $conflict = $saved !== null && ($editingConflict || $saved->revisionNumber !== $revision) ? $saved : null;
            return new Response($conflict === null ? ($errors === [] ? 200 : 422) : 409, Pages::recordForm(
                $session->csrfToken,
                $this->profile,
                $type,
                $values,
                $saved,
                $errors,
                $conflict,
                is_int($revision) ? $revision : null,
                mediaUploadEnabled: $this->uploadsEnabled && $type->kind === 'collection',
                technicalData: $this->technicalData,
                chrome: $this->chrome,
                referenceControls: $controls,
                assistancePanel: $aiPanel,
                pendingControls: true,
                scheduleNotice: $scheduleNotice,
                submittedEditSequence: $submittedEditSequence,
                compositionEditor: $compositionEditor,
                exactHasPage: $exactHasPage,
            ));
        }
        $action = $request->formValue('_editor_action');
        if ($request->formValue('_upload_field') !== '') {
            $action = 'upload-media:' . $request->formValue('_upload_field');
        }
        $richAction = $request->formValue('_rich_action');
        $errors = [];
        $mediaNotice = null;
        $uploadDescriptions = [];
        if ($action !== '' || $richAction !== '') {
            $baseRevision = filter_var($request->formValue('_editor_base_revision'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (is_int($baseRevision)) {
                $revision = $baseRevision;
            }
            if (str_starts_with($action, 'add-media:') || str_starts_with($action, 'uploaded-media:') || str_starts_with($action, 'upload-media:')) {
                $outcome = (new EditorialMediaFields($this->profile, $this->media, $this->uploads, $this->random))->apply($request, $type, $saved, $editingConflict && str_starts_with($action, 'upload-media:') ? 0 : (int) $revision, $session->userId, $values, $action);
                $values = $outcome['values'];
                $errors = $outcome['errors'];
                $mediaNotice = $outcome['notice'];
                $uploadDescriptions[explode(':', $action, 2)[1]] = $outcome['descriptions'];
            } elseif (str_starts_with($action, 'term:')) {
                $fieldId = substr($action, 5);
                $field = null;
                foreach ($type->fields as $candidate) {
                    if ($candidate->id === $fieldId && $candidate->type === 'taxonomy_references') {
                        $field = $candidate;
                    }
                }
                $field ??= EditorialControls::nestedDefinition($type, $this->profile, $values, $fieldId);
                if ($field === null || $field->type !== 'taxonomy_references') {
                    throw new \InvalidArgumentException('The term target is invalid.');
                }
                $label = [];
                foreach ($this->profile->locales as $locale) {
                    $label[$locale] = $request->formValue('_term_' . $fieldId . '__' . $locale);
                }
                $selected = $request->formValues($fieldId);
                if (count($selected) >= (int) $field->maximumItems) {
                    $errors[$fieldId] = new \Utsuri\PortableCms\Conventional\I18n\Message('validation.term.limit');
                } else {
                    $term = $this->content->create((string) $field->targetType, ['label' => $label], $session->userId);
                    if ($term instanceof ValidationFailure) {
                        $errors[$fieldId] = new \Utsuri\PortableCms\Conventional\I18n\Message('validation.term.label');
                    } else {
                        $values = EditorialControls::apply(new Request('POST', $request->path, form: ['_editor_action' => 'select:' . $fieldId . ':' . $term->id]), $type, $this->profile, $values, $this->random);
                    }
                }
            } elseif ($action !== '' && !str_starts_with($action, 'search:') && !str_starts_with($action, 'page:')) {
                $values = EditorialControls::apply($request, $type, $this->profile, $values, $this->random);
            }
            // A transient search or reordering preserves the submitted base revision, never grants a stale overwrite.
            $conflict = $saved !== null && ($editingConflict || $saved->revisionNumber !== $revision) ? $saved : null;
            return new Response(
                $conflict === null ? ($errors === [] ? 200 : 422) : 409,
                Pages::recordForm(
                    $session->csrfToken,
                    $this->profile,
                    $type,
                    $values,
                    $saved,
                    $errors,
                    $conflict,
                    is_int($revision) ? $revision : null,
                    mediaUploadEnabled: $this->uploadsEnabled && $type->kind === 'collection',
                    technicalData: $this->technicalData,
                    chrome: $this->chrome,
                    referenceControls: $controls,
                    assistancePanel: $aiPanel,
                    pendingControls: $saved !== null || $richAction !== '' || !(str_starts_with($action, 'search:') || str_starts_with($action, 'page:')),
                    scheduleNotice: $scheduleNotice,
                    submittedEditSequence: $submittedEditSequence,
                    structureChanged: $richAction !== '',
                    compositionEditor: $compositionEditor,
                    mediaNotice: $mediaNotice,
                    uploadDescriptions: $uploadDescriptions,
                    exactHasPage: $exactHasPage,
                ),
            );
        }
        $result = $saved === null ? $this->content->create($type->id, $values, $session->userId)
            : $this->content->update($type->id, $saved->id, (int) $revision, $values, $session->userId, $this->editSequence($request, $saved));
        if ($result instanceof ValidationFailure || $result instanceof RecordConflict) {
            return new Response(
                $result instanceof ValidationFailure ? 422 : 409,
                Pages::recordForm(
                    $session->csrfToken,
                    $this->profile,
                    $type,
                    $values,
                    $result instanceof RecordConflict ? $result->current : $saved,
                    $result instanceof ValidationFailure ? $result->errors : [],
                    $result instanceof RecordConflict ? $result->current : null,
                    is_int($revision) ? $revision : null,
                    mediaUploadEnabled: $this->uploadsEnabled && $type->kind === 'collection',
                    technicalData: $this->technicalData,
                    chrome: $this->chrome,
                    referenceControls: $controls,
                    assistancePanel: $aiPanel,
                    compositionEditor: $compositionEditor,
                    scheduleNotice: $scheduleNotice,
                    submittedEditSequence: $submittedEditSequence,
                    exactHasPage: $exactHasPage,
                ),
            );
        }
        return Response::redirect('/records/' . $type->id . '/' . $result->id);
    }

    private function editSequence(Request $request, RecordSnapshot $record): int
    {
        $sequence = filter_var($request->formValue('_edit_sequence'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if (!is_int($sequence) && $record->editSequence !== 0) {
            throw new \InvalidArgumentException('Record editing sequence is required');
        }
        return is_int($sequence) ? $sequence : 0;
    }

    /** @param array<string, mixed> $values */
    private function keep(Request $request, RecordTypeDefinition $type, ?RecordSnapshot $saved, array $values, SessionContext $session): Response
    {
        $page = $request->formValue('_editing_session');
        $sequence = filter_var($request->formValue('_edit_sequence'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $id = $saved->id ?? $request->formValue('_new_record_id');
        if (preg_match('/\A[a-f0-9]{32}\z/', $page) !== 1 || !is_int($sequence)) {
            throw new \InvalidArgumentException('Record editing context is invalid');
        }
        $result = $this->content->keep($type->id, $id, $values, (string) $session->userId, new RecordEditIntent($sequence, hash('sha256', $session->userId . ':' . $page), $request->formValue('_editing_request')));
        if ($result instanceof RecordConflict) {
            return Response::json(['conflict' => true, 'path' => '/records/' . $type->id . '/' . $result->current->id], 409);
        }
        $t = $this->chrome?->translator() ?? \Utsuri\PortableCms\Conventional\I18n\Translator::english();
        $errors = [];
        foreach ($result->errors as $fieldId => $message) {
            $field = EditorialControls::nestedDefinition($type, $this->profile, $values, $fieldId);
            foreach ($type->fields as $candidate) {
                $field = $candidate->id === $fieldId ? $candidate : $field;
            }
            $errors[$fieldId] = \Utsuri\PortableCms\Conventional\View\EditorialRecordLayout::fieldMessage($message, $field, $t);
        }
        return Response::json(['id' => $result->record->id, 'path' => '/records/' . $type->id . '/' . $result->record->id, 'revision' => $result->record->revisionNumber, 'sequence' => $result->record->editSequence, 'savedAt' => $result->record->updatedAt->format(DATE_ATOM), 'state' => $result->record->workflowState, 'pending' => $result->record->hasUnpublishedChanges(), 'statusLabel' => \Utsuri\PortableCms\Conventional\View\AdminShell::stateLabel($result->record->hasUnpublishedChanges() ? 'published_changes' : $result->record->workflowState, $t), 'errors' => $errors, 'retryable' => $result->retryable]);
    }

    /** Renders the profile's page from unsaved values. It validates like Save and never saves. */
    private function live(Request $request, RecordTypeDefinition $type, ?RecordSnapshot $saved, SessionContext $session, string $correlationId): Response
    {
        $t = $this->chrome?->translator() ?? \Utsuri\PortableCms\Conventional\I18n\Translator::english();
        if ($this->live === null || $request->method !== 'POST') {
            return new Response($this->live === null ? 404 : 405, Pages::error($this->live === null ? 404 : 405, $correlationId, $t));
        }
        $json = str_contains($request->header('accept') ?? '', 'application/json');
        $sequence = filter_var($request->formValue('_live_sequence'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 1_000_000_000]]);
        $sequence = is_int($sequence) ? $sequence : 0;
        $failure = static fn(string $reason): Response => $json
            ? Response::json(['sequence' => $sequence, 'reason' => $reason], 422)
            : new Response(422, \Utsuri\PortableCms\Conventional\View\AdminShell::focused($t->text('live.failed.title'), \Utsuri\PortableCms\Conventional\View\TrustedHtml::fromEngineMarkup(
                '<h1>' . $t->html('live.failed.title') . '</h1><p class="admin-help">' . \Utsuri\PortableCms\Conventional\Http\Html::text($reason) . '</p>',
            ), $t));
        $locale = in_array($request->formValue('_live_locale'), $this->profile->locales, true) ? $request->formValue('_live_locale') : $this->profile->defaultLocale;
        $page = $request->formValue('_live_page') === 'home' ? 'home' : 'record';
        $validated = $this->content->unsaved($type->id, EditorialForm::input($request, $type, $this->profile, $saved), $saved);
        if ($validated instanceof ValidationFailure) {
            $fieldId = (string) array_key_first($validated->errors);
            $field = null;
            foreach ($type->fields as $candidate) {
                $field = $candidate->id === $fieldId ? $candidate : $field;
            }
            return $failure(\Utsuri\PortableCms\Conventional\View\EditorialRecordLayout::fieldMessage($validated->errors[$fieldId], $field, $t));
        }
        $projections = $validated->relationships;
        usort($projections, static fn(RelationshipProjection $a, RelationshipProjection $b): int => [$a->fieldId, $a->position] <=> [$b->fieldId, $b->position]);
        $relationships = [];
        foreach ($projections as $projection) {
            $relationships[$projection->fieldId][] = $projection->targetRecordId;
        }
        try {
            $result = $this->live->render(new \Utsuri\PortableCms\Conventional\Preview\LivePreviewRequest($type->id, $saved?->id, $validated->body, $relationships, $page, $locale), $session->id, $request->formValue('_live_token'));
        } catch (\Utsuri\PortableCms\Conventional\Preview\LivePreviewFailure $error) {
            return $failure($t->message(\Utsuri\PortableCms\Conventional\Preview\PreviewDiagnostics::present($error->reason, $this->profile)));
        }
        if (!$json) {
            return Response::redirect($result->url());
        }
        return Response::json([
            'sequence' => $sequence,
            'url' => $result->url(),
            'page' => $result->page,
            'pages' => $result->pages,
            'locale' => $locale,
            'elapsedMilliseconds' => $result->elapsedMilliseconds,
            'slow' => $result->slow,
            'drafts' => $result->drafts,
        ]);
    }

    private function listing(Request $request, RecordTypeDefinition $type, SessionContext $session): Response
    {
        if ($type->kind === 'singleton') {
            $single = $this->content->list($type->id)[0] ?? null;
            return Response::redirect('/records/' . $type->id . '/' . ($single === null ? 'new' : $single->id));
        }
        $query = mb_substr(trim($request->queryValue('q')), 0, 100);
        $page = filter_var($request->queryValue('page'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
        $page = is_int($page) ? $page : 1;
        $states = $this->content->stateCounts($type->id);
        $state = array_key_exists($request->queryValue('status'), $states) ? $request->queryValue('status') : '';
        $result = $this->content->search($type->id, $query, $page, $state);
        [$records, $hasNext] = [$result->records, $result->hasNext];
        $listing = new \Utsuri\PortableCms\Conventional\View\RecordListing(
            $records,
            $query,
            $state,
            $states,
            $page,
            $hasNext,
            $this->thumbnail($type),
            $type->kind === 'taxonomy' ? $this->content->incomingReferences(array_map(static fn(RecordSnapshot $record): string => $record->id, $records)) : [],
        );
        return new Response(200, Pages::recordList($session->csrfToken, $this->profile, $type, $records, $this->chrome, $listing));
    }

    /** @return ?\Closure(RecordSnapshot): string */
    private function thumbnail(RecordTypeDefinition $type): ?\Closure
    {
        $field = null;
        foreach ($type->fields as $candidate) {
            if ($candidate->type === 'media_references' && !$candidate->localized) {
                $field = $candidate->id;
                break;
            }
        }
        if ($field === null || $this->media === null) {
            return null;
        }
        $media = $this->media;
        return static function (RecordSnapshot $record) use ($field, $media): string {
            $selected = EditorialControls::selections($record->body[$field] ?? null);
            $item = $selected === [] ? null : $media->find($selected[0]);
            return $item === null
                ? '<span class="admin-thumbnail admin-thumbnail--small admin-thumbnail--pending" aria-hidden="true"></span>'
                : str_replace('class="admin-thumbnail', 'class="admin-thumbnail--small admin-thumbnail', \Utsuri\PortableCms\Conventional\View\ReferenceControls::thumbnail($item));
        };
    }
}
