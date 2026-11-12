<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Ai;

use Utsuri\PortableCms\Conventional\Content\FieldDefinition;
use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Content\RecordSnapshot;
use Utsuri\PortableCms\Conventional\Content\RecordTypeDefinition;
use Utsuri\PortableCms\Conventional\Http\Html;
use Utsuri\PortableCms\Conventional\Http\Request;
use Utsuri\PortableCms\Conventional\I18n\Translator;
use Utsuri\PortableCms\Conventional\Identity\RandomSource;
use Utsuri\PortableCms\Conventional\Media\MediaLibrary;

/** Adapter for native record forms. All mutations return unsaved values, never a record revision. */
final class EditorialAssistance
{
    private readonly Translator $t;

    public function __construct(private readonly AssistanceService $service, private readonly ProfileManifest $profile, private readonly RandomSource $random, private readonly ?MediaLibrary $media, ?Translator $translator = null)
    {
        $this->t = $translator ?? Translator::english();
    }

    /**
     * @param array<string, mixed> $values
     * @return array{values: array<string, mixed>, request: Request, panel: string, failed: bool}
     */
    public function handle(Request $request, RecordTypeDefinition $type, ?RecordSnapshot $record, string $actorId, array $values, int $revision): array
    {
        $job = $record === null ? null : $this->service->find($request->formValue('_ai_job'), $actorId, $record->id);
        $message = '';
        $failed = false;
        $action = $request->formValue('_ai_action');
        try {
            if ($action !== '') {
                if ($record === null || $request->formValue('_editorial_end') !== '1') {
                    throw new AssistanceFailure('invalid_request');
                }
                if ($action === 'request') {
                    $job = $this->service->request(
                        $type->id,
                        $record->id,
                        $revision,
                        $request->formValue('_ai_media'),
                        $request->formValue('_ai_context'),
                        $request->formValue('_ai_client'),
                        $actorId,
                        $request->formValue('_ai_consent') === '1',
                    );
                    $message = $this->t->text('ai.message.requested');
                } elseif ($action === 'cancel') {
                    if ($job === null) {
                        throw new AssistanceFailure('invalid_request');
                    }
                    $this->service->cancel($job->id, $actorId, $record->id);
                    $job = $this->service->find($job->id, $actorId, $record->id);
                    $message = $this->t->text('ai.message.cancelled');
                } elseif (str_starts_with($action, 'apply:')) {
                    if ($job?->candidate === null || $job->request === null || $job->status !== 'completed') {
                        throw new AssistanceFailure('invalid_request');
                    }
                    $this->service->fresh($job->request);
                    if ($revision !== $job->request->frozen['sourceRevision']) {
                        throw new AssistanceFailure('stale');
                    }
                    $parts = explode(':', $action);
                    $purpose = $parts[1] ?? '';
                    $locale = $parts[2] ?? '';
                    $index = $parts[3] ?? '';
                    if (!in_array($purpose, ['description', 'caption', 'alt', 'taxonomy'], true)
                        || !in_array($locale, $this->profile->locales, true) || ($purpose !== 'taxonomy' && count($parts) !== 3)) {
                        throw new AssistanceFailure('invalid_request');
                    }
                    $field = $this->target($type, $request->formValue('_ai_target_' . $purpose), $purpose);
                    $entry = $job->candidate->suggestions[$locale];
                    if ($purpose === 'taxonomy') {
                        if (preg_match('/\A[0-4]\z/', $index) !== 1 || count($parts) !== 4 || !isset($entry['taxonomy'][(int) $index])) {
                            throw new AssistanceFailure('invalid_request');
                        }
                        $form = $request->form;
                        foreach ($job->candidate->suggestions as $language => $suggestion) {
                            $form['_term_' . $field->id . '__' . $language] = $suggestion['taxonomy'][(int) $index] ?? '';
                        }
                        $request = new Request($request->method, $request->path, form: $form, query: $request->query);
                        $message = $this->t->text('ai.message.taxonomy_copied');
                    } else {
                        $text = $entry[$purpose];
                        if (mb_strlen($text) > ($field->maximumLength ?? 2000)) {
                            throw new AssistanceFailure('target_limit');
                        }
                        if ($field->type === 'rich_text') {
                            $text = \Utsuri\PortableCms\Conventional\Content\RichText::from(['formatVersion' => 1, 'nodes' => [['type' => 'paragraph', 'children' => [['text' => $text, 'marks' => []]]]]], $field)->document();
                        }
                        if ($field->localized) {
                            $translations = is_array($values[$field->id] ?? null) ? $values[$field->id] : [];
                            $translations[$locale] = $text;
                            $values[$field->id] = $translations;
                        } else {
                            $values[$field->id] = $text;
                        }
                        $message = $this->t->text('ai.message.inserted');
                    }
                } elseif ($action !== 'check') {
                    throw new AssistanceFailure('invalid_request');
                }
            }
            if ($job?->status === 'completed' && $job->request !== null) {
                $this->service->fresh($job->request);
            }
        } catch (AssistanceFailure $error) {
            $failed = true;
            $message = $this->t->text(in_array($error->safeCode, ['stale', 'quota_exceeded', 'excluded', 'source_unavailable', 'unavailable', 'target_limit'], true) ? 'ai.failure.' . $error->safeCode : 'ai.failure.default');
        }
        return ['values' => $values, 'request' => $request, 'panel' => $this->panel($request, $type, $record, $job, $message, $failed), 'failed' => $failed];
    }

    private function target(RecordTypeDefinition $type, string $id, string $purpose): FieldDefinition
    {
        foreach ($type->fields as $field) {
            if ($field->id === $id && self::targetAllowed($field, $purpose)) {
                return $field;
            }
        }
        throw new AssistanceFailure('invalid_request');
    }

    private static function targetAllowed(FieldDefinition $field, string $purpose): bool
    {
        return $purpose === 'taxonomy' ? $field->type === 'taxonomy_references'
            : in_array($field->type, ['localized_short_text', 'multiline_text', 'short_text'], true)
                || ($field->type === 'rich_text' && in_array('paragraph', $field->settings['blocks'] ?? [], true));
    }

    private function panel(Request $request, RecordTypeDefinition $type, ?RecordSnapshot $record, ?AssistanceJob $job, string $message, bool $failed): string
    {
        $t = $this->t;
        $markup = '<section class="admin-section" id="ai-assistance" aria-labelledby="ai-heading"><h2 id="ai-heading">' . $t->html('ai.title') . '</h2>';
        if ($this->service->policy === null || !in_array('ai_drafts', [...$this->profile->capabilities->optional, ...$this->profile->capabilities->required], true)) {
            // An optional capability the installation has not enabled leaves the page entirely.
            return '';
        }
        if ($record === null) {
            return $markup . '<p class="admin-help">' . $t->html('ai.save_first') . '</p></section>';
        }
        if ($message !== '') {
            $markup .= '<p role="' . ($failed ? 'alert' : 'status') . '" tabindex="-1" data-ai-message>' . Html::text($message) . '</p>';
        }
        $policy = $this->service->policy;
        $markup .= '<p class="admin-help">' . $t->html('ai.policy', [
            'region' => $policy->residency,
            'hours' => (string) $policy->retentionHours,
            'cost' => 2 * $policy->attemptCostMicros / 1000000,
        ]) . '</p>';
        $options = '';
        foreach ($type->fields as $field) {
            if ($field->type !== 'media_references') {
                continue;
            }
            foreach (is_array($record->body[$field->id] ?? null) ? $record->body[$field->id] : [] as $id) {
                if (!is_string($id) || !$policy->permits($type->id, $id)) {
                    continue;
                }
                $media = $this->media?->find($id);
                if ($media?->status === 'ready' && $media->thumbnail !== null && $media->thumbnail->byteSize <= 4194304) {
                    $options .= '<option value="' . Html::attribute($id) . '"' . ($request->formValue('_ai_media') === $id ? ' selected' : '') . '>' . Html::text($media->name) . '</option>';
                }
            }
        }
        $markup .= '<input type="hidden" name="_ai_client" value="' . bin2hex($this->random->bytes(16)) . '">';
        if ($options !== '') {
            $markup .= '<label for="ai-media">' . $t->html('ai.image') . '</label><select id="ai-media" name="_ai_media">' . $options . '</select>'
                . '<label for="ai-context">' . $t->html('ai.context') . '</label><textarea id="ai-context" name="_ai_context" maxlength="1000">' . Html::text($request->formValue('_ai_context')) . '</textarea>'
                . '<label><input type="checkbox" name="_ai_consent" value="1"> ' . $t->html('ai.consent') . '</label>'
                . self::button('request', $t->text('ai.request'));
        } else {
            $markup .= '<p class="admin-help">' . $t->html('ai.no_image') . '</p>';
        }
        if ($job !== null) {
            $markup .= '<input type="hidden" name="_ai_job" value="' . Html::attribute($job->id) . '"><p role="status" data-ai-status>'
                . $t->html('ai.status', ['status' => $job->status, 'attempts' => (string) $job->attempts]) . '</p>';
            if ($job->errorCode !== null) {
                $diagnostic = match ($job->errorCode) {
                    'stale', 'unavailable', 'invalid_candidate' => 'ai.job.' . $job->errorCode,
                    'provider_retry' => $job->status === 'retry' ? 'ai.job.provider_retry' : 'ai.job.provider_unavailable',
                    default => 'ai.job.default',
                };
                $markup .= '<p class="admin-help">' . $t->html($diagnostic) . '</p>';
            }
            if (in_array($job->status, ['queued', 'retry', 'running', 'completed'], true)) {
                $markup .= self::button('check', $t->text('ai.check')) . self::button('cancel', $t->text('ai.cancel'));
            }
            if ($job->candidate !== null && !$failed && $job->status === 'completed') {
                foreach (['description', 'caption', 'alt', 'taxonomy'] as $purpose) {
                    $markup .= '<fieldset data-ai-purpose="' . $purpose . '"><legend>' . $t->html('ai.purpose.' . $purpose) . '</legend><label for="ai-target-' . $purpose . '">' . $t->html('ai.insert_into')
                        . '</label><select id="ai-target-' . $purpose . '" name="_ai_target_' . $purpose . '"><option value="">' . $t->html('ai.choose_field') . '</option>';
                    foreach ($type->fields as $field) {
                        if (self::targetAllowed($field, $purpose)) {
                            $markup .= '<option value="' . Html::attribute($field->id) . '">' . Html::text($field->label) . '</option>';
                        }
                    }
                    $markup .= '</select>';
                    foreach ($job->candidate->suggestions as $locale => $entry) {
                        $markup .= '<h3>' . Html::text($locale) . '</h3>';
                        if ($purpose === 'taxonomy') {
                            foreach ($entry['taxonomy'] as $index => $term) {
                                $markup .= '<p>' . Html::text($term) . '</p>' . self::button('apply:taxonomy:' . $locale . ':' . $index, $t->text('ai.use_label', ['number' => (string) ($index + 1), 'locale' => $locale]));
                            }
                        } else {
                            $markup .= '<p>' . Html::text($entry[$purpose]) . '</p>' . self::button('apply:' . $purpose . ':' . $locale, $t->text('ai.use.' . $purpose, ['locale' => $locale]));
                        }
                    }
                    $markup .= '</fieldset>';
                }
            }
        }
        return $markup . '</section>';
    }

    private static function button(string $action, string $label): string
    {
        return '<button type="submit" name="_ai_action" value="' . Html::attribute($action) . '" formnovalidate>' . Html::text($label) . '</button>';
    }
}
