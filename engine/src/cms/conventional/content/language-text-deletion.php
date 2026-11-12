<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Content;

use Utsuri\PortableCms\Conventional\I18n\InvalidInput;
use Utsuri\PortableCms\Conventional\Identity\Clock;
use Utsuri\PortableCms\Conventional\Identity\RandomSource;
use Utsuri\PortableCms\Conventional\Identity\SessionContext;
use Utsuri\PortableCms\Conventional\Media\MediaLibrary;
use Utsuri\PortableCms\Conventional\Media\MediaText;
use Utsuri\PortableCms\Conventional\Publication\WebsitePublication;
use Utsuri\PortableCms\Conventional\Publication\WebsitePublicationRepository;

/** One deletion of a language's current text, which proceeds in bounded batches until nothing in the language remains. */
final readonly class LanguageTextDeletion
{
    public const STATES = ['running', 'completed'];

    public function __construct(
        public string $id,
        public string $profileId,
        public string $locale,
        public string $actorId,
        public string $state,
        /** Records the owner confirmed. */
        public int $records,
        /** Records rewritten so far. */
        public int $recordsDone,
        public int $mediaDone,
        public \DateTimeImmutable $startedAt,
    ) {
        if (preg_match('/\A[a-f0-9]{32}\z/', $id) !== 1 || !ProfileManifest::localeId($locale) || !in_array($state, self::STATES, true)
            || min($records, $recordsDone, $mediaDone) < 0) {
            throw new \InvalidArgumentException('Language text deletion is invalid');
        }
    }

    public function advanced(int $records, int $media, bool $completed): self
    {
        return new self($this->id, $this->profileId, $this->locale, $this->actorId, $completed ? 'completed' : 'running', $this->records, $this->recordsDone + $records, $this->mediaDone + $media, $this->startedAt);
    }
}

interface LanguageTextDeletionRepository
{
    /** The running deletion, or the last one finished. */
    public function latest(string $profileId): ?LanguageTextDeletion;

    /** @throws LanguageTextDeletionConflict when another deletion is running */
    public function start(LanguageTextDeletion $deletion, string $auditId, \DateTimeImmutable $now): void;

    /** Records progress, and with $auditId set, the completion. */
    public function advance(LanguageTextDeletion $deletion, \DateTimeImmutable $now, ?string $auditId = null): void;

    /** @return list<RecordSnapshot> Records whose current revision has text in $locale, in a stable order. */
    public function records(ProfileManifest $profile, string $locale, int $limit): array;

    public function countRecords(ProfileManifest $profile, string $locale): int;

    /** @return list<string> Media whose description has text in $locale. */
    public function media(string $locale, int $limit): array;

    public function countMedia(string $locale): int;
}

final class LanguageTextDeletionConflict extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Another language text deletion is running');
    }
}

/**
 * The owner deletes the current text of a language that is off: every record with text in it gets a
 * new revision without it, and media descriptions, site wording and the holding message lose it too.
 * Earlier revisions keep their history. Each call does one bounded batch; calling again continues
 * with what remains, so an interrupted deletion resumes where it stopped.
 */
final readonly class LanguageTextDeletionService
{
    public const RECORDS_PER_BATCH = 50;

    public const MEDIA_PER_BATCH = 100;

    public function __construct(
        private LanguageTextDeletionRepository $repository,
        private ContentService $content,
        private ?MediaLibrary $media,
        private SiteWordingRepository $wording,
        private WebsitePublicationRepository $holding,
        private SiteLanguagesService $languages,
        /** The installed profile, with every language whose text may be deleted. */
        private ProfileManifest $profile,
        private Clock $clock,
        private RandomSource $random,
    ) {}

    public function latest(): ?LanguageTextDeletion
    {
        return $this->repository->latest($this->profile->id);
    }

    /** @return array{records: int, media: int} What deleting the language's text would change. */
    public function affected(string $locale): array
    {
        return ['records' => $this->repository->countRecords($this->profile, $locale), 'media' => $this->media === null ? 0 : $this->repository->countMedia($locale)];
    }

    /**
     * Starts deleting after the owner confirmed the language and the number of records it changes,
     * and runs the first batch.
     * @throws InvalidInput
     * @throws \RuntimeException when storage interrupts a batch; already committed work remains resumable
     */
    public function start(string $locale, int $records, bool $confirmed, SessionContext $actor): LanguageTextDeletion
    {
        $owner = $this->owner($actor);
        $this->off($locale);
        if (!$confirmed) {
            throw new InvalidInput('language_text.confirm');
        }
        if ($records !== $this->affected($locale)['records']) {
            throw new InvalidInput('language_text.changed');
        }
        $deletion = new LanguageTextDeletion(bin2hex($this->random->bytes(16)), $this->profile->id, $locale, $owner, 'running', $records, 0, 0, $this->clock->now());
        try {
            $this->repository->start($deletion, bin2hex($this->random->bytes(16)), $this->clock->now());
        } catch (LanguageTextDeletionConflict) {
            throw new InvalidInput('language_text.busy');
        }
        return $this->batch($deletion, $owner);
    }

    /**
     * Continues the running deletion with its next batch.
     * @throws InvalidInput
     * @throws \RuntimeException when storage interrupts a batch; already committed work remains resumable
     */
    public function continue(SessionContext $actor): LanguageTextDeletion
    {
        $owner = $this->owner($actor);
        $deletion = $this->latest();
        if ($deletion === null || $deletion->state !== 'running') {
            throw new InvalidInput('language_text.none');
        }
        $this->off($deletion->locale);
        return $this->batch($deletion, $owner);
    }

    private function batch(LanguageTextDeletion $deletion, string $owner): LanguageTextDeletion
    {
        $locale = $deletion->locale;
        $records = 0;
        foreach ($this->repository->records($this->profile, $locale, self::RECORDS_PER_BATCH) as $record) {
            $type = $this->profile->type($record->typeId) ?? throw new \RuntimeException('Stored record has an unknown type');
            try {
                $written = $this->content->rewrite($record->typeId, $record->id, $record->revisionNumber, LanguageText::withoutRecord($type->fields, $record->body, $locale), $owner, $record->editSequence);
            } catch (\InvalidArgumentException $error) {
                // Stored content the installed profile no longer accepts needs an editor before deletion can finish.
                throw new InvalidInput('language_text.record_invalid', ['type' => $type->singularLabel, 'id' => $record->id], $error);
            }
            // A record saved meanwhile keeps its text and is rewritten by the next batch.
            if ($written instanceof RecordSnapshot) {
                $records++;
            }
        }
        $media = 0;
        if ($records === 0 && $this->media !== null) {
            foreach ($this->repository->media($locale, self::MEDIA_PER_BATCH) as $id) {
                $item = $this->media->find($id);
                if ($item?->text !== null && $this->media->rename($id, $item->metadataRevision, $item->name, $owner, MediaText::from(LanguageText::withoutMedia($item->text->document(), $locale)))) {
                    $media++;
                }
            }
        }
        $finished = $records === 0 && $media === 0 && $this->repository->countRecords($this->profile, $locale) === 0
            && ($this->media === null || $this->repository->countMedia($locale) === 0);
        if ($finished) {
            $this->settings($locale, $owner);
        }
        $next = $deletion->advanced($records, $media, $finished);
        $this->repository->advance($next, $this->clock->now(), $finished ? bin2hex($this->random->bytes(16)) : null);
        return $next;
    }

    /** Site wording and the holding message are small single documents, written once at the end. */
    private function settings(string $locale, string $owner): void
    {
        $wording = $this->wording->load($this->profile);
        $changed = array_keys(array_filter($wording->values, static fn(array $texts): bool => isset($texts[$locale])));
        if ($changed !== []) {
            $this->wording->save(SiteWording::of($this->profile, $wording->revision + 1, LanguageText::withoutWording($wording->values, $locale)), $wording->revision, $changed, $owner, bin2hex($this->random->bytes(16)), $this->clock->now());
        }
        $holding = $this->holding->load($this->profile->id, $this->profile->locales);
        if (LanguageText::holding($holding->document(), $locale)) {
            $next = WebsitePublication::from([...LanguageText::withoutHolding($holding->document(), $locale), 'revision' => $holding->revision() + 1]);
            $this->holding->save($next, $holding->revision(), $owner, bin2hex($this->random->bytes(16)), $this->clock->now());
        }
    }

    /** @throws InvalidInput */
    private function owner(SessionContext $actor): string
    {
        if (!$actor->editorial() || $actor->role !== 'owner' || $actor->userId === null) {
            throw new InvalidInput('language_text.not_allowed');
        }
        return $actor->userId;
    }

    /** @throws InvalidInput */
    private function off(string $locale): void
    {
        if (($this->languages->load()->states[$locale] ?? null) !== 'off') {
            throw new InvalidInput('language_text.not_off', ['language' => $this->name($locale)]);
        }
    }

    public function name(string $locale): string
    {
        foreach ($this->profile->localeDefinitions as $definition) {
            if ($definition->id === $locale) {
                return $definition->label;
            }
        }
        return $locale;
    }
}
