<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Content;

use Utsuri\PortableCms\Conventional\Http\Request;
use Utsuri\PortableCms\Conventional\I18n\Message;
use Utsuri\PortableCms\Conventional\Identity\RandomSource;
use Utsuri\PortableCms\Conventional\Media\MediaContract;
use Utsuri\PortableCms\Conventional\Media\MediaLibrary;
use Utsuri\PortableCms\Conventional\Media\MediaUploadService;
use Utsuri\PortableCms\Conventional\Media\NativeUploadHttp;
use Utsuri\PortableCms\Conventional\Media\UploadFailure;

/** Transient media intent: upload ownership, library eligibility and field bounds. */
final readonly class EditorialMediaFields
{
    public function __construct(private ProfileManifest $profile, private ?MediaLibrary $library, private ?MediaUploadService $uploads, private RandomSource $random) {}

    /**
     * @param array<string, mixed> $values
     * @return array{values: array<string, mixed>, errors: array<string, Message>, notice: ?Message, descriptions: list<array{media: \Utsuri\PortableCms\Conventional\Media\LibraryMedia, jobId: ?string}>}
     */
    public function apply(Request $request, RecordTypeDefinition $type, ?RecordSnapshot $saved, int $revision, string $actor, array $values, string $action): array
    {
        [$operation, $id] = explode(':', $action, 2);
        $field = null;
        foreach ($type->fields as $candidate) {
            if ($candidate->id === $id && in_array($candidate->type, ['media_references', 'video_reference'], true) && !$candidate->localized) {
                $field = $candidate;
            }
        }
        $nested = $field === null ? EditorialControls::nestedField($type, $this->profile, $values, $id) : null;
        $field ??= $nested['field'] ?? null;
        if (!in_array($operation, ['add-media', 'uploaded-media', 'upload-media'], true) || $field === null || !in_array($field->type, ['media_references', 'video_reference'], true) || $field->localized || $this->library === null || $request->formValue('_editorial_end') !== '1') {
            throw new \InvalidArgumentException('The media field is unavailable.');
        }
        $errors = [];
        $descriptions = [];
        $uploads = $operation !== 'add-media';
        $ids = [];
        if ($uploads) {
            if ($this->uploads === null || $saved === null || $type->kind !== 'collection' || $nested !== null) {
                throw new \InvalidArgumentException('Field uploads need a saved collection record.');
            }
            $sessions = $request->formValues('_completed_uploads');
            if ($operation === 'upload-media') {
                // No bytes are accepted on behalf of an obsolete editing revision.
                if ($saved->revisionNumber !== $revision) {
                    return ['values' => $values, 'errors' => [], 'notice' => null, 'descriptions' => []];
                }
                try {
                    $batch = NativeUploadHttp::receive($request, $type->id, $saved->id, $actor, $this->uploads, $this->random, $this->profile, $revision, imagesOnly: $field->type !== 'video_reference');
                } catch (UploadFailure $error) {
                    return ['values' => $values, 'errors' => [$id => $error->text()], 'notice' => null, 'descriptions' => []];
                }
                $sessions = $batch->sessions;
                if ($batch->failure !== null) {
                    $errors[$id] = $batch->failure->text();
                }
            } elseif ($request->formValue('_upload_failed') === '1') {
                $errors[$id] = new Message('media.field.failed');
            }
            if (count($sessions) > 8) {
                throw new \InvalidArgumentException('The completed upload batch is too large.');
            }
            foreach ($sessions as $sessionId) {
                try {
                    $session = $this->uploads->status($sessionId, $actor);
                    if ($session->ownerRecordId !== $saved->id || $session->status !== 'completed' || $session->mediaId === null
                        || !in_array($session->declaredType, MediaContract::uploadTypes($field->type === 'video_reference'), true)) {
                        throw new \InvalidArgumentException('The completed upload does not belong to this media field.');
                    }
                    if ($field->type === 'video_reference' && str_starts_with($session->declaredType, 'video/')) {
                        try {
                            $this->uploads->assertVideoField($sessionId, $actor, $field);
                        } catch (\InvalidArgumentException $error) {
                            $errors[$id] = Message::fromFailure($error);
                        }
                    }
                    $ids[] = $session->mediaId;
                    $media = $this->library->find($session->mediaId);
                    if ($media !== null) {
                        $descriptions[$media->id] = ['media' => $media, 'jobId' => $session->jobId];
                    }
                } catch (UploadFailure $error) {
                    $errors[$id] = $error->text();
                    break;
                }
            }
        } else {
            $ids = $request->formValues('_pick_' . $id);
            if (count($ids) > 20) {
                throw new \InvalidArgumentException('The library selection exceeds one page.');
            }
        }
        if ($field->type === 'video_reference') {
            return ['values' => $values, 'errors' => $errors, 'notice' => new Message('video.accepted', ['count' => count($ids)]), 'descriptions' => array_values($descriptions)];
        }
        $selected = EditorialControls::selections($nested === null ? ($values[$id] ?? null) : $nested['value']);
        $added = 0;
        $remaining = 0;
        foreach (array_unique($ids) as $mediaId) {
            if (in_array($mediaId, $selected, true)) {
                continue;
            }
            $media = $this->library->find($mediaId);
            if (preg_match('/\A[a-f0-9]{32}\z/', $mediaId) !== 1 || (!$uploads && ($media === null || $media->status !== 'ready' || !str_starts_with($media->mime, 'image/')
                || (($field->settings['purpose'] ?? null) === 'website-icon' && !WebsiteIconSelection::eligible($media->status, $media->mime, $media->width, $media->height))))) {
                $errors[$id] = new Message('media.field.invalid');
                continue;
            }
            $values = EditorialControls::apply(new Request('POST', $request->path, form: ['_editor_action' => 'select:' . $id . ':' . $mediaId]), $type, $this->profile, $values, $this->random);
            $updated = $nested === null ? ($values[$id] ?? null) : (EditorialControls::nestedField($type, $this->profile, $values, $id)['value'] ?? null);
            $selected = EditorialControls::selections($updated);
            if (in_array($mediaId, $selected, true)) {
                $added++;
            } else {
                $remaining++;
            }
        }
        return ['values' => $values, 'errors' => $errors, 'notice' => new Message('media.field.added', ['added' => $added, 'remaining' => $remaining, 'field' => $field->label]), 'descriptions' => array_values($descriptions)];
    }
}
