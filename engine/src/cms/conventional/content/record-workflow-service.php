<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Content;

use Utsuri\PortableCms\Conventional\I18n\Message;
use Utsuri\PortableCms\Conventional\Identity\Clock;
use Utsuri\PortableCms\Conventional\Identity\RandomSource;
use Utsuri\PortableCms\Conventional\Identity\SessionContext;

/** Editorial selection only. No public filesystem or release authority exists here. */
final class RecordWorkflowService
{
    public function __construct(
        private readonly RecordRepository $repository,
        private readonly ContentService $content,
        private readonly ProfileManifest $profile,
        private readonly Clock $clock,
        private readonly RandomSource $random,
        private readonly ?\Utsuri\PortableCms\Conventional\Media\MediaLibrary $media,
        private readonly ?\Utsuri\PortableCms\Conventional\Design\RegistryService $design,
    ) {}

    public function transition(string $typeId, string $recordId, int $revision, string $state, SessionContext $actor, ?int $expectedSequence = null): RecordSnapshot|RecordConflict|ValidationFailure
    {
        if (!$actor->administrator() || $actor->userId === null) {
            throw new RecordPermissionDenied();
        }
        if (!in_array($state, ['draft', 'published', 'archived'], true) || $revision < 1) {
            throw new \InvalidArgumentException('Unknown record status or revision');
        }
        $record = $this->content->find($typeId, $recordId);
        if ($record === null) {
            throw new RecordNotFound();
        }
        if ($record->revisionNumber !== $revision || ($expectedSequence !== null && $record->editSequence !== $expectedSequence)) {
            return new RecordConflict($record);
        }
        if ($state === 'published') {
            $errors = $this->validate($record);
            if ($errors !== []) {
                return new ValidationFailure($record->body, $errors);
            }
        }
        if (!$this->repository instanceof RecordWorkflowRepository) {
            throw new \LogicException('Record status capability is unavailable');
        }
        if ($state === $record->workflowState && !($state === 'published' && $record->hasUnpublishedChanges())) {
            return $record;
        }
        return $this->repository->transition($record, $state, bin2hex($this->random->bytes(16)), $this->profile->id, $this->profile->version, $actor->userId, bin2hex($this->random->bytes(16)), $this->clock->now());
    }

    public function discard(string $typeId, string $recordId, int $revision, SessionContext $actor, ?int $expectedSequence = null): RecordSnapshot|RecordConflict
    {
        if (!$actor->administrator() || $actor->userId === null) {
            throw new RecordPermissionDenied();
        }
        $record = $this->content->find($typeId, $recordId) ?? throw new RecordNotFound();
        if ($record->revisionNumber !== $revision || ($expectedSequence !== null && $record->editSequence !== $expectedSequence)) {
            return new RecordConflict($record);
        }
        if (!$this->repository instanceof PublishedRecordRepository || !$record->hasUnpublishedChanges()) {
            throw new \InvalidArgumentException('There are no published changes to discard');
        }
        $published = $this->repository->published($record);
        if ($published === null) {
            // Another status/publication transaction may have advanced the head after find().
            return new RecordConflict($this->repository->find($typeId, $recordId) ?? throw new RecordNotFound());
        }
        $validated = $this->content->validateCanonical($typeId, $published->body, $this->design?->load());
        return $this->repository->discard($record, $published, $validated->relationships, bin2hex($this->random->bytes(16)), $this->profile->id, $this->profile->version, $actor->userId, bin2hex($this->random->bytes(16)), $this->clock->now());
    }

    public function delete(string $typeId, string $recordId, int $revision, SessionContext $actor, ?int $expectedSequence = null): bool|RecordConflict|ValidationFailure
    {
        if (!$actor->administrator() || $actor->userId === null) {
            throw new RecordPermissionDenied();
        }
        $record = $this->content->find($typeId, $recordId) ?? throw new RecordNotFound();
        if ($record->revisionNumber !== $revision || ($expectedSequence !== null && $record->editSequence !== $expectedSequence)) {
            return new RecordConflict($record);
        }
        if ($this->profile->type($typeId)?->kind !== 'collection' || $record->workflowState !== 'archived') {
            return new ValidationFailure($record->body, ['_workflow' => new Message('workflow.delete_blocked')]);
        }
        if (!$this->repository instanceof RecordDeletionRepository) {
            throw new \LogicException('Record deletion is unavailable');
        }
        return $this->repository->deleteArchived($record, $actor->userId, bin2hex($this->random->bytes(16)), $this->clock->now());
    }

    /** @return array<string, Message> */
    private function validate(RecordSnapshot $record): array
    {
        $type = $this->profile->type($record->typeId);
        try {
            $this->content->validateBlocksCanonical($type, $record->body, true, $this->design?->load());
            $validated = $this->content->validateCanonical($record->typeId, $record->body, $this->design?->load());
        } catch (\InvalidArgumentException) {
            return ['_workflow' => new Message('workflow.invalid')];
        }
        $type = $this->profile->type($record->typeId);
        $errors = [];
        $this->fields($type->fields, $record->body, $type, $errors, recordId: $record->id);
        foreach ($validated->relationships as $relationship) {
            $field = null;
            foreach ($type->fields as $candidate) {
                if ($candidate->id === $relationship->fieldId) {
                    $field = $candidate;
                }
            }
            $field ??= EditorialControls::nestedDefinition($type, $this->profile, $record->body, $relationship->fieldId);
            $target = null;
            foreach ($field->targetTypes as $targetType) {
                $target ??= $this->content->find($targetType, $relationship->targetRecordId);
            }
            if ($target === null || ($target->id !== $record->id && $target->workflowState !== 'published')) {
                $errors[$relationship->fieldId] = new Message('workflow.relationship');
            }
        }
        return $errors;
    }

    /**
     * @param list<FieldDefinition> $fields
     * @param array<string, mixed> $body
     * @param array<string, Message> $errors
     */
    private function fields(array $fields, array $body, RecordTypeDefinition $type, array &$errors, string $prefix = '', ?string $recordId = null): void
    {
        [$fields, $body] = \Utsuri\PortableCms\Conventional\Content\Blocks::expand($fields, $body, $this->profile->locales, true, $this->content->requiredLocales());
        foreach ($fields as $field) {
            $id = $prefix === '' ? $field->id : $prefix;
            $raw = $body[$field->id] ?? null;
            // Publication requirements apply to public languages; languages in preparation never block it.
            foreach ($field->localized ? $this->content->requiredLocales() : [''] as $locale) {
                $value = $field->localized && is_array($raw) ? ($raw[$locale] ?? null) : $raw;
                $empty = $value === null || $value === [] || (is_string($value) && trim($value) === '');
                $mediaIds = [];
                if ($field->type === 'rich_text' && is_array($value)) {
                    $empty = !RichText::from($value, $field)->hasText();
                }
                if ($field->type === 'composition') {
                    $layout = Composition::from($value, $field, $type, $this->design?->load() ?? $this->profile->designRegistries, $this->profile->locales);
                    $empty = $layout->document()['rows'] === [];
                    $mediaIds = $layout->mediaIds();
                    foreach ($layout->links() as $link) {
                        $target = $this->content->find($link->typeId, $link->recordId);
                        if ($target === null || ($target->id !== $recordId && $target->workflowState !== 'published')) {
                            $errors[$id] = new Message('composition.link_unavailable');
                        }
                    }
                    foreach ($layout->linkedImages() as $source) {
                        $mediaText = $this->media?->find($source['id'] ?? '')->text ?? \Utsuri\PortableCms\Conventional\Media\MediaText::from();
                        foreach ($this->content->requiredLocales() as $language) {
                            $text = $mediaText->localized($language, $this->profile->defaultLocale);
                            if (trim($text['alternativeText']) === '' && trim(Composition::caption($source, $language, $this->profile->defaultLocale, $text['caption'])) === '') {
                                $errors[$id] = new Message('composition.link_name_required');
                            }
                        }
                    }
                }
                if ($field->requiredOnPublish && $empty) {
                    $errors[$id] = new Message('workflow.required', ['field' => $field->label]);
                }
                if ($field->type === 'media_references' && is_array($value)) {
                    $mediaIds = $value;
                }
                if ($field->type === 'vimeo_video' && !$empty) {
                    try {
                        $mediaIds = [VimeoVideo::from($value)->poster];
                        $poster = $this->media?->find($mediaIds[0]);
                        if ($poster?->status !== 'ready' || $poster->thumbnail?->mime !== 'image/webp' || !str_starts_with($poster->mime, 'image/')) {
                            $errors[$id] = new Message('vimeo.poster_invalid');
                        }
                    } catch (\InvalidArgumentException $error) {
                        $errors[$id] = Message::fromFailure($error);
                    }
                }
                if ($field->type === 'video_reference' && !$empty) {
                    $reference = VideoReference::from($value);
                    $mediaIds = $reference === null ? [] : [$reference['mediaId'], $reference['posterId']];
                    $video = $reference === null ? null : $this->media?->find($reference['mediaId']);
                    if ($video?->thumbnail?->variant !== 'prepared-video') {
                        $errors[$id] = new Message('workflow.media');
                    } else {
                        try {
                            VideoLimits::assert($field, $video->byteSize, $this->media->videoFacts($video));
                        } catch (\InvalidArgumentException $error) {
                            $errors[$id] = Message::fromFailure($error);
                        }
                    }
                }
                foreach ($mediaIds as $mediaId) {
                    $media = is_string($mediaId) ? $this->media?->find($mediaId) : null;
                    if ($media === null || $media->status !== 'ready' || $media->thumbnail === null) {
                        $errors[$id] = new Message('workflow.media');
                    } elseif (str_starts_with($media->mime, 'image/') && ($field->settings['purpose'] ?? null) !== 'website-icon') {
                        foreach ($locale === '' ? $this->content->requiredLocales() : [$locale] as $language) {
                            if (!($media->text ?? \Utsuri\PortableCms\Conventional\Media\MediaText::from())->publishable($language, $this->profile->defaultLocale)) {
                                $errors[$id] = new Message('workflow.media_text', ['field' => $field->label, 'locale' => $language]);
                                break;
                            }
                        }
                    }
                }
                if (in_array($field->type, ['group', 'repeatable_group'], true) && is_array($value)) {
                    foreach ($field->type === 'group' ? [$value] : $value as $item) {
                        if (is_array($item)) {
                            $nested = new RecordTypeDefinition($type->id, $type->label, $type->singularLabel, $field->fields, $type->kind, $type->archetype, $type->renderer, $type->route);
                            $this->fields($field->fields, $item, $nested, $errors, $id, $recordId);
                        }
                    }
                }
            }
        }
    }
}
