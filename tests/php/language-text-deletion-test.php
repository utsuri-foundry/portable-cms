<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Content\ContentService;
use Utsuri\PortableCms\Conventional\Content\LanguageText;
use Utsuri\PortableCms\Conventional\Content\LanguageTextDeletion;
use Utsuri\PortableCms\Conventional\Content\LanguageTextDeletionConflict;
use Utsuri\PortableCms\Conventional\Content\LanguageTextDeletionHttp;
use Utsuri\PortableCms\Conventional\Content\LanguageTextDeletionRepository;
use Utsuri\PortableCms\Conventional\Content\LanguageTextDeletionService;
use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Content\RecordConflict;
use Utsuri\PortableCms\Conventional\Content\RecordRepository;
use Utsuri\PortableCms\Conventional\Content\RecordSnapshot;
use Utsuri\PortableCms\Conventional\Content\SiteLanguagesService;
use Utsuri\PortableCms\Conventional\Content\SiteWording;
use Utsuri\PortableCms\Conventional\Http\Request;
use Utsuri\PortableCms\Conventional\I18n\InvalidInput;
use Utsuri\PortableCms\Conventional\Publication\WebsitePublication;
use Utsuri\PortableCms\Conventional\View\AdminChrome;
use Utsuri\PortableCms\Conventional\View\SiteLanguagesPage;

/** Records in memory that can fail on a chosen write, as an interrupted request would. */
final class InterruptibleRecords implements RecordRepository
{
    public ?int $failAt = null;

    public int $writes = 0;

    public function __construct(public MemoryRecordRepository $memory) {}

    public function list(string $typeId, int $limit): array
    {
        return $this->memory->list($typeId, $limit);
    }

    public function find(string $typeId, string $recordId): ?RecordSnapshot
    {
        return $this->memory->find($typeId, $recordId);
    }

    public function create(string $recordId, string $revisionId, string $typeId, string $profileId, string $profileVersion, array $body, array $relationships, string $actorUserId, string $auditId, DateTimeImmutable $now): RecordSnapshot
    {
        return $this->memory->create($recordId, $revisionId, $typeId, $profileId, $profileVersion, $body, $relationships, $actorUserId, $auditId, $now);
    }

    public function update(string $typeId, string $recordId, int $expectedRevisionNumber, string $revisionId, string $profileId, string $profileVersion, array $body, array $relationships, string $actorUserId, string $auditId, DateTimeImmutable $now): RecordSnapshot|RecordConflict
    {
        if ($this->failAt !== null && $this->writes === $this->failAt) {
            $this->failAt = null;
            throw new RuntimeException('The request was interrupted');
        }
        ++$this->writes;
        return $this->memory->update($typeId, $recordId, $expectedRevisionNumber, $revisionId, $profileId, $profileVersion, $body, $relationships, $actorUserId, $auditId, $now);
    }
}

final class MemoryLanguageTextDeletion implements LanguageTextDeletionRepository
{
    /** @var list<LanguageTextDeletion> */
    public array $deletions = [];

    /** @var list<string> */
    public array $events = [];

    public function __construct(private MemoryRecordRepository $records) {}

    public function latest(string $profileId): ?LanguageTextDeletion
    {
        $running = array_values(array_filter($this->deletions, static fn(LanguageTextDeletion $deletion): bool => $deletion->state === 'running'));
        return $running[0] ?? ($this->deletions === [] ? null : $this->deletions[count($this->deletions) - 1]);
    }

    public function start(LanguageTextDeletion $deletion, string $auditId, DateTimeImmutable $now): void
    {
        if ($this->latest($deletion->profileId)?->state === 'running') {
            throw new LanguageTextDeletionConflict();
        }
        $this->deletions[] = $deletion;
        $this->events[] = 'language_text_deletion_started';
    }

    public function advance(LanguageTextDeletion $deletion, DateTimeImmutable $now, ?string $auditId = null): void
    {
        foreach ($this->deletions as $index => $stored) {
            if ($stored->id === $deletion->id) {
                $this->deletions[$index] = $deletion;
            }
        }
        if ($auditId !== null) {
            $this->events[] = 'language_text_deleted';
        }
    }

    public function records(ProfileManifest $profile, string $locale, int $limit): array
    {
        $records = array_values(array_filter($this->records->records, static fn(RecordSnapshot $record): bool => LanguageText::record($profile->type($record->typeId)->fields ?? [], $record->body, $locale)));
        usort($records, static fn(RecordSnapshot $a, RecordSnapshot $b): int => $a->id <=> $b->id);
        return array_slice($records, 0, $limit);
    }

    public function countRecords(ProfileManifest $profile, string $locale): int
    {
        return count($this->records($profile, $locale, PHP_INT_MAX));
    }

    public function media(string $locale, int $limit): array
    {
        return [];
    }

    public function countMedia(string $locale): int
    {
        return 0;
    }
}

/**
 * Reference Studio with English off, sixty terms written in every language, English wording and an
 * English holding message.
 * @return array{service: LanguageTextDeletionService, records: InterruptibleRecords, deletions: MemoryLanguageTextDeletion, wording: MemorySiteWording, holding: MemoryWebsitePublication, ids: list<string>}
 */
function languageTextFixture(): array
{
    $profile = siteLanguagesProfile();
    $clock = new IdentityTestClock(new DateTimeImmutable('2026-10-19T12:00:00Z'));
    $languages = new SiteLanguagesService(new MemorySiteLanguages(siteLanguagesChoice($profile, ['en' => 'off'])), $profile, $clock, new IdentityTestRandom());
    $memory = new MemoryRecordRepository();
    $records = new InterruptibleRecords($memory);
    $content = new ContentService($records, $profile, $clock, new IdentityTestRandom(), publicLocales: static fn(): array => $languages->load()->public());
    $ids = [];
    for ($index = 0; $index < 60; $index++) {
        $created = $content->create('discipline', ['label' => ['ca' => 'Disciplina ' . $index, 'es' => 'Disciplina ' . $index, 'en' => 'Discipline ' . $index], 'aliases' => ['ca' => '', 'es' => '', 'en' => 'Field ' . $index], 'deprecated' => false], str_repeat('1', 32));
        assertTrueValue($created instanceof RecordSnapshot, 'a term is created');
        $ids[] = $created->id;
    }
    $content->create('discipline', ['label' => ['ca' => 'Sense anglès', 'es' => 'Sin inglés', 'en' => ''], 'aliases' => [], 'deprecated' => false], str_repeat('1', 32));
    $wording = new MemorySiteWording(SiteWording::of($profile, 3, ['page.projects' => ['en' => 'Selected work', 'es' => 'Obras'], 'page.about' => ['en' => 'Studio']]));
    $settings = WebsitePublication::defaults($profile->id, $profile->locales)->document();
    $settings['translations'][2] = ['locale' => 'en', 'message' => 'Opening in spring.', 'contact' => 'hello@example.test'];
    $holding = new MemoryWebsitePublication(WebsitePublication::from($settings));
    $deletions = new MemoryLanguageTextDeletion($memory);
    $service = new LanguageTextDeletionService($deletions, $content, null, $wording, $holding, $languages, $profile, $clock, new IdentityTestRandom());
    return ['service' => $service, 'records' => $records, 'deletions' => $deletions, 'wording' => $wording, 'holding' => $holding, 'ids' => $ids];
}

function languageTextRejects(callable $action, string $key, string $message): void
{
    try {
        $action();
    } catch (InvalidInput $error) {
        assertSameValue($key, $error->text()->key, $message);
        return;
    }
    throw new RuntimeException($message . ': accepted');
}

$tests['removing a language from stored documents leaves every other value and the history structure intact'] = static function (): void {
    $profile = siteLanguagesProfile();
    $project = $profile->type('project') ?? throw new RuntimeException('Missing project');
    $about = $profile->type('about') ?? throw new RuntimeException('Missing about');
    $home = $profile->type('home') ?? throw new RuntimeException('Missing home');
    $body = ['title' => ['ca' => 'Obra', 'es' => 'Obra', 'en' => 'Work'], 'year' => 2024];
    assertSameValue(['title' => ['ca' => 'Obra', 'es' => 'Obra'], 'year' => 2024], LanguageText::withoutRecord($project->fields, $body, 'en'), 'a localized value loses only the language');
    $contact = ['contact' => ['email' => 'a@example.test', 'city' => ['ca' => 'Barcelona', 'en' => 'Barcelona city']]];
    assertSameValue(['contact' => ['email' => 'a@example.test', 'city' => ['ca' => 'Barcelona']]], LanguageText::withoutRecord($about->fields, $contact, 'en'), 'a translated child of a group loses the language');
    $collage = ['collage' => ['formatVersion' => 2, 'rows' => [['id' => 'r1', 'columns' => [
        ['content' => ['kind' => 'media', 'id' => str_repeat('c', 32), 'captions' => ['ca' => 'Foto', 'en' => 'Photo']]],
        ['content' => ['kind' => 'media', 'id' => str_repeat('d', 32), 'captions' => ['en' => 'Only English']]],
    ]]]]];
    $without = LanguageText::withoutRecord($home->fields, $collage, 'en');
    assertSameValue([['ca' => 'Foto'], false], [$without['collage']['rows'][0]['columns'][0]['content']['captions'], array_key_exists('captions', $without['collage']['rows'][0]['columns'][1]['content'])], 'layout captions lose the language and an empty caption list disappears');
    assertTrueValue(!LanguageText::record($home->fields, $without, 'en') && LanguageText::record($home->fields, $without, 'ca'), 'nothing in the language remains');
    $quote = static fn(bool $localized): array => ['id' => 'quote', 'label' => 'Quote', 'type' => 'short_text', 'localized' => $localized, 'requiredOnDraft' => false, 'requiredOnPublish' => false, 'settings' => ['maximumLength' => 200]];
    $blocks = static fn(string $id, bool $localized): array => ['id' => $id, 'label' => 'Blocks', 'type' => 'blocks', 'localized' => $localized, 'requiredOnDraft' => false, 'requiredOnPublish' => false, 'settings' => ['minimumItems' => 0, 'maximumItems' => 8, 'blockTypes' => [['id' => 'quote', 'label' => 'Quote', 'fields' => [$quote(!$localized)]]]]];
    $fields = ProfileManifest::parseFields([$blocks('page', true), $blocks('shared', false)]);
    $item = static fn(string $id, mixed $text): array => ['_itemId' => str_repeat($id, 32), 'typeId' => 'quote', 'values' => ['quote' => $text]];
    $page = ['page' => ['ca' => [$item('a', 'Cita')], 'es' => [], 'en' => [$item('b', 'Quote')]], 'shared' => [$item('c', ['ca' => 'Cita', 'en' => 'Quote'])]];
    $without = LanguageText::withoutRecord($fields, $page, 'en');
    assertSameValue(['page' => ['ca' => [$item('a', 'Cita')], 'es' => [], 'en' => []], 'shared' => [$item('c', ['ca' => 'Cita'])]], $without, 'blocks in the language are emptied and translated block values lose it');
    assertTrueValue(LanguageText::record($fields, ['page' => $page['page']], 'en') && LanguageText::record($fields, ['shared' => $page['shared']], 'en'), 'text in either kind of blocks is found');
    assertTrueValue(!LanguageText::record($fields, $without, 'en') && LanguageText::record($fields, $without, 'ca') && !LanguageText::record($fields, ['page' => ['en' => [$item('d', '')]]], 'en'), 'empty blocks hold no text');
    assertSameValue(['translations' => [['locale' => 'ca', 'alternativeText' => 'Foto', 'caption' => '', 'credit' => '']]], LanguageText::withoutMedia(['translations' => [['locale' => 'ca', 'alternativeText' => 'Foto', 'caption' => '', 'credit' => ''], ['locale' => 'en', 'alternativeText' => 'Photo', 'caption' => '', 'credit' => '']]], 'en'), 'a media description loses its translation');
    assertSameValue(['page.projects' => ['es' => 'Obras']], LanguageText::withoutWording(['page.projects' => ['en' => 'Work', 'es' => 'Obras'], 'page.about' => ['en' => 'Studio']], 'en'), 'wording loses the language and keys left empty');
    $holding = LanguageText::withoutHolding(['translations' => [['locale' => 'en', 'message' => 'Opening soon.', 'contact' => 'a@example.test']]], 'en');
    assertSameValue([['locale' => 'en', 'message' => 'Coming soon.', 'contact' => '']], $holding['translations'], 'the holding message returns to the default');
};

$tests['the owner deletes the current text of a language that is off in bounded resumable batches'] = static function (): void {
    $fixture = languageTextFixture();
    $service = $fixture['service'];
    assertSameValue(['records' => 60, 'media' => 0], $service->affected('en'), 'the confirmation counts the records with text in the language');
    languageTextRejects(static fn() => $service->start('en', 60, true, siteLanguagesActor('administrator')), 'language_text.not_allowed', 'administrators cannot delete text');
    languageTextRejects(static fn() => $service->start('en', 60, true, siteLanguagesActor('editor')), 'language_text.not_allowed', 'editors cannot delete text');
    languageTextRejects(static fn() => $service->start('es', 60, true, siteLanguagesActor('owner')), 'language_text.not_off', 'a public language keeps its text');
    languageTextRejects(static fn() => $service->start('en', 60, false, siteLanguagesActor('owner')), 'language_text.confirm', 'deletion needs confirmation');
    languageTextRejects(static fn() => $service->start('en', 59, true, siteLanguagesActor('owner')), 'language_text.changed', 'a stale count is confirmed again');
    assertSameValue([0, []], [$fixture['records']->writes, $fixture['deletions']->events], 'refused deletions write nothing');

    $first = $service->start('en', 60, true, siteLanguagesActor('owner'));
    assertSameValue(['running', LanguageTextDeletionService::RECORDS_PER_BATCH, 60], [$first->state, $first->recordsDone, $first->records], 'the first batch is bounded');
    languageTextRejects(static fn() => $service->start('en', 10, true, siteLanguagesActor('owner')), 'language_text.busy', 'only one deletion runs at a time');
    $fixture['records']->failAt = $fixture['records']->writes + 4;
    try {
        $service->continue(siteLanguagesActor('owner'));
        throw new RuntimeException('The interruption did not happen');
    } catch (RuntimeException $error) {
        assertSameValue('The request was interrupted', $error->getMessage(), 'a batch can stop part way');
    }
    assertSameValue(['running', 6], [$service->latest()?->state, $service->affected('en')['records']], 'records written before the interruption stay written');
    $second = $service->continue(siteLanguagesActor('owner'));
    assertSameValue(['running', 56], [$second->state, $second->recordsDone], 'continuing resumes with what remains');
    $done = $service->continue(siteLanguagesActor('owner'));
    assertSameValue(['completed', 56, 0], [$done->state, $done->recordsDone, $service->affected('en')['records']], 'the deletion completes once nothing is left');
    assertSameValue(['language_text_deletion_started', 'language_text_deleted'], $fixture['deletions']->events, 'starting and completing are audited');
    languageTextRejects(static fn() => $service->continue(siteLanguagesActor('owner')), 'language_text.none', 'a finished deletion has nothing to continue');

    $records = $fixture['records']->memory;
    foreach ($fixture['ids'] as $id) {
        $record = $records->find('discipline', $id) ?? throw new RuntimeException('A term is missing');
        assertTrueValue(!LanguageText::record(siteLanguagesProfile()->type('discipline')->fields ?? [], $record->body, 'en') && ($record->body['label']['en'] ?? '') === '', 'no current value is left in the language, which is stored empty as when an editor clears it');
        assertSameValue(2, $record->revisionNumber, 'each record gets exactly one new revision');
        assertTrueValue(str_starts_with($record->body['label']['ca'], 'Disciplina'), 'other languages are untouched');
    }
    assertSameValue(['page.projects' => ['es' => 'Obras']], $fixture['wording']->wording?->values, 'the site wording loses the language');
    assertSameValue(['Coming soon.', ''], [$fixture['holding']->settings->document()['translations'][2]['message'], $fixture['holding']->settings->document()['translations'][2]['contact']], 'the holding message returns to the default');
};

$tests['deleting the text of a language stops when the language is turned on again'] = static function (): void {
    $profile = siteLanguagesProfile();
    $clock = new IdentityTestClock(new DateTimeImmutable('2026-10-19T12:00:00Z'));
    $choice = new MemorySiteLanguages(siteLanguagesChoice($profile, ['en' => 'off']));
    $languages = new SiteLanguagesService($choice, $profile, $clock, new IdentityTestRandom());
    $memory = new MemoryRecordRepository();
    $content = new ContentService($memory, $profile, $clock, new IdentityTestRandom(), publicLocales: static fn(): array => $languages->load()->public());
    for ($index = 0; $index < 51; $index++) {
        $content->create('discipline', ['label' => ['ca' => 'Disciplina ' . $index, 'es' => 'Disciplina ' . $index, 'en' => 'Discipline ' . $index], 'aliases' => [], 'deprecated' => false], str_repeat('1', 32));
    }
    $service = new LanguageTextDeletionService(new MemoryLanguageTextDeletion($memory), $content, null, new MemorySiteWording(), new MemoryWebsitePublication(WebsitePublication::defaults($profile->id, $profile->locales)), $languages, $profile, $clock, new IdentityTestRandom());
    $service->start('en', 51, true, siteLanguagesActor('owner'));
    $languages->save(['ca' => 'public', 'es' => 'public', 'en' => 'public'], 'ca', 1, siteLanguagesActor('owner'), false);
    languageTextRejects(static fn() => $service->continue(siteLanguagesActor('owner')), 'language_text.not_off', 'a language turned on again is not deleted further');
    assertSameValue(1, $service->affected('en')['records'], 'the remaining record keeps its text');
};

$tests['the languages page offers the owner the deletion of an off language and the confirmation names it'] = static function (): void {
    $fixture = languageTextFixture();
    $profile = siteLanguagesProfile();
    $choice = siteLanguagesChoice($profile, ['en' => 'off']);
    $owner = new AdminChrome(str_repeat('c', 32), 'Owner', role: 'owner');
    $page = SiteLanguagesPage::render($profile, $owner, $choice);
    assertTrueValue(str_contains($page, 'href="/settings/languages/text?locale=en"') && !str_contains($page, 'locale=es"'), 'the owner deletes only the text of a language that is off');
    assertTrueValue(!str_contains(SiteLanguagesPage::render($profile, new AdminChrome(str_repeat('c', 32), 'Admin'), $choice), '/settings/languages/text'), 'administrators are not offered the deletion');

    $languages = new SiteLanguagesService(new MemorySiteLanguages($choice), $profile, new IdentityTestClock(new DateTimeImmutable('2026-10-19T12:00:00Z')), new IdentityTestRandom());
    $http = new LanguageTextDeletionHttp($fixture['service'], $languages, $profile, $owner);
    $confirmation = $http->handle(new Request('GET', '/settings/languages/text', query: ['locale' => 'en']), siteLanguagesActor('owner'))->body;
    assertTrueValue(str_contains($confirmation, 'Permanently delete the English text of 60 records') && str_contains($confirmation, 'name="records" value="60"') && str_contains($confirmation, 'href="/exports"') && str_contains($confirmation, 'Earlier revisions keep their text'), 'the confirmation names the language, the records, the history and a backup');
    $refused = $http->handle(new Request('POST', '/settings/languages/text', form: ['_action' => 'start', 'locale' => 'en', 'records' => '60']), siteLanguagesActor('owner'));
    assertTrueValue($refused->status === 422 && str_contains($refused->body, 'needs confirmation'), 'an unconfirmed deletion is refused');
    $started = $http->handle(new Request('POST', '/settings/languages/text', form: ['_action' => 'start', 'locale' => 'en', 'records' => '60', 'confirmed' => '1']), siteLanguagesActor('owner'));
    assertSameValue([303, '/settings/languages/text?locale=en'], [$started->status, $started->headers()['location'][0] ?? null], 'a started deletion shows its progress');
    $progress = $http->handle(new Request('GET', '/settings/languages/text'), siteLanguagesActor('owner'))->body;
    assertTrueValue(str_contains($progress, '50 of 60 records rewritten') && str_contains($progress, 'value="continue"'), 'the progress page continues the deletion');
    assertTrueValue(str_contains(SiteLanguagesPage::render($profile, $owner, $choice, deletion: $fixture['service']->latest()), 'The English text is being deleted.'), 'the languages page says a deletion is running');
    $finished = $http->handle(new Request('POST', '/settings/languages/text', form: ['_action' => 'continue']), siteLanguagesActor('owner'));
    $finished = $finished->headers()['location'][0] === '/settings/languages/text?locale=en' ? $http->handle(new Request('POST', '/settings/languages/text', form: ['_action' => 'continue']), siteLanguagesActor('owner')) : $finished;
    assertSameValue('/settings/languages/text?locale=en&done=1', $finished->headers()['location'][0] ?? null, 'a completed deletion says so');
    $done = $http->handle(new Request('GET', '/settings/languages/text', query: ['locale' => 'en', 'done' => '1']), siteLanguagesActor('owner'))->body;
    assertTrueValue(str_contains($done, 'The English text was deleted from 60 records'), 'the owner sees what was deleted');
    $administrator = $http->handle(new Request('GET', '/settings/languages/text', query: ['locale' => 'en']), siteLanguagesActor('administrator'))->body;
    assertTrueValue(str_contains($administrator, 'Only the owner can delete') && !str_contains($administrator, 'name="confirmed"'), 'administrators read why they cannot delete');
};
