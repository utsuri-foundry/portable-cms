<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Content\ContentService;
use Utsuri\PortableCms\Conventional\Content\EditorialForm;
use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Content\RecordSnapshot;
use Utsuri\PortableCms\Conventional\Content\SiteLanguages;
use Utsuri\PortableCms\Conventional\Content\SiteLanguagesConflict;
use Utsuri\PortableCms\Conventional\Content\SiteLanguagesRepository;
use Utsuri\PortableCms\Conventional\Content\SiteLanguagesService;
use Utsuri\PortableCms\Conventional\Content\ValidationFailure;
use Utsuri\PortableCms\Conventional\Export\NormalizedExport;
use Utsuri\PortableCms\Conventional\Http\Request;
use Utsuri\PortableCms\Conventional\I18n\InvalidInput;
use Utsuri\PortableCms\Conventional\Identity\AdministrationPermissions;
use Utsuri\PortableCms\Conventional\Identity\SessionContext;
use Utsuri\PortableCms\Conventional\View\AdminChrome;
use Utsuri\PortableCms\Conventional\View\Pages;
use Utsuri\PortableCms\Conventional\View\SiteLanguagesPage;

final class MemorySiteLanguages implements SiteLanguagesRepository
{
    public int $writes = 0;

    public function __construct(public ?SiteLanguages $languages = null) {}

    public function load(ProfileManifest $profile): SiteLanguages
    {
        return $this->languages ?? SiteLanguages::defaults($profile);
    }

    /** @var list<array{string, array<string, mixed>}> */
    public array $events = [];

    /** @var list<string> Languages that have text. */
    public array $written = [];

    public function save(SiteLanguages $next, int $expected, string $actorId, string $auditId, DateTimeImmutable $now, string $event = 'site_languages_saved', array $detail = []): void
    {
        if (($this->languages->revision ?? 0) !== $expected) {
            throw new SiteLanguagesConflict();
        }
        ++$this->writes;
        $this->languages = $next;
        $this->events[] = [$event, $detail];
    }

    public function hasText(ProfileManifest $profile, string $locale): bool
    {
        return in_array($locale, $this->written, true);
    }
}

function siteLanguagesProfile(): ProfileManifest
{
    return ProfileManifest::load(dirname(__DIR__, 2) . '/profiles/reference-studio/profile.json');
}

function siteLanguagesActor(string $role): SessionContext
{
    return new SessionContext('session', 'authenticated', str_repeat('1', 32), 'person@example.test', 'Person', $role, 'token', 'csrf', new DateTimeImmutable('2026-10-15T12:00:00Z'), false);
}

/** @param array<string, string> $states */
function siteLanguagesChoice(ProfileManifest $profile, array $states, string $primary = 'ca', int $revision = 1): SiteLanguages
{
    return SiteLanguages::from(['formatVersion' => 1, 'profileId' => $profile->id, 'revision' => $revision, 'primary' => $primary,
        'languages' => array_map(static fn(string $locale): array => ['locale' => $locale, 'state' => $states[$locale] ?? 'public'], $profile->locales)], $profile);
}

function siteLanguagesRejects(callable $action, string $key, string $message): void
{
    try {
        $action();
    } catch (InvalidInput $error) {
        assertSameValue($key, $error->text()->key, $message);
        return;
    }
    throw new RuntimeException($message . ': accepted');
}

$tests['site languages start public with the profile default primary and reject an incomplete choice'] = static function (): void {
    $profile = siteLanguagesProfile();
    $defaults = SiteLanguages::defaults($profile);
    assertSameValue(['ca' => 'public', 'es' => 'public', 'en' => 'public'], $defaults->states, 'existing sites keep every language public');
    assertSameValue(['ca', 0], [$defaults->primary, $defaults->revision], 'the default language is primary before any choice');
    assertSameValue($profile, $defaults->editingProfile($profile), 'with every language public, editing sees the installed profile');
    $document = siteLanguagesChoice($profile, ['en' => 'off'])->document();
    assertSameValue($document, SiteLanguages::from($document, $profile)->document(), 'the choice round-trips through its portable document');
    foreach ([
        [[...$document, 'primary' => 'en'], 'languages.primary_public'],
        [[...$document, 'primary' => 'fr'], 'languages.primary_public'],
        [[...$document, 'languages' => array_reverse($document['languages'])], 'languages.invalid'],
        [[...$document, 'languages' => array_slice($document['languages'], 0, 2)], 'languages.invalid'],
        [[...$document, 'languages' => [...array_slice($document['languages'], 0, 2), ['locale' => 'en', 'state' => 'hidden']]], 'languages.invalid'],
        [[...$document, 'profileId' => 'other-profile'], 'languages.invalid'],
        [[...$document, 'extra' => true], 'languages.invalid'],
    ] as [$invalid, $key]) {
        siteLanguagesRejects(static fn() => SiteLanguages::from($invalid, $profile), $key, 'an invalid choice is refused');
    }
    $adapted = SiteLanguages::stored(['formatVersion' => 1, 'profileId' => $profile->id, 'revision' => 4, 'primary' => 'xx',
        'languages' => [['locale' => 'xx', 'state' => 'public'], ['locale' => 'es', 'state' => 'off'], ['locale' => 'en', 'state' => 'preparation']]], $profile);
    assertSameValue([4, 'ca', ['ca' => 'public', 'es' => 'off', 'en' => 'preparation']], [$adapted->revision, $adapted->primary, $adapted->states], 'a profile release that changes languages keeps the remaining choices');
    $kept = SiteLanguages::stored(['formatVersion' => 1, 'profileId' => $profile->id, 'revision' => 4, 'primary' => 'pt',
        'languages' => [['locale' => 'pt', 'state' => 'public'], ['locale' => 'en', 'state' => 'off']]], $profile);
    assertSameValue(['pt', ['ca' => 'public', 'es' => 'public', 'en' => 'off', 'pt' => 'public'], ['pt']], [$kept->primary, $kept->states, $kept->added($profile)], 'a language the profile no longer lists stays as an added one while its envelope admits it');
};

$tests['site languages are changed by administrators with confirmation before a language is turned off'] = static function (): void {
    $profile = siteLanguagesProfile();
    $repository = new MemorySiteLanguages();
    $service = new SiteLanguagesService($repository, $profile, new IdentityTestClock(new DateTimeImmutable('2026-10-15T12:00:00Z')), new IdentityTestRandom());
    siteLanguagesRejects(static fn() => $service->save(['ca' => 'public', 'es' => 'public', 'en' => 'public'], 'ca', 0, siteLanguagesActor('editor'), false), 'languages.not_allowed', 'editors can only read the choice');
    siteLanguagesRejects(static fn() => $service->save(['ca' => 'public', 'es' => 'off', 'en' => 'public'], 'ca', 0, siteLanguagesActor('administrator'), false), 'languages.confirm_off', 'turning a language off needs confirmation');
    siteLanguagesRejects(static fn() => $service->save(['ca' => 'off', 'es' => 'public', 'en' => 'public'], 'ca', 0, siteLanguagesActor('owner'), true), 'languages.primary_public', 'the primary language stays public');
    assertSameValue(0, $repository->writes, 'refused choices are never stored');
    $saved = $service->save(['ca' => 'preparation', 'es' => 'off', 'en' => 'public'], 'en', 0, siteLanguagesActor('owner'), true);
    assertSameValue([1, 'en', ['en']], [$saved->revision, $saved->primary, $saved->public()], 'the owner saves a new revision');
    $restored = $service->save(['ca' => 'public', 'es' => 'public', 'en' => 'public'], 'en', 1, siteLanguagesActor('administrator'), false);
    assertSameValue(2, $restored->revision, 'turning languages on again needs no confirmation');
    try {
        $service->save(['ca' => 'public', 'es' => 'public', 'en' => 'public'], 'ca', 1, siteLanguagesActor('administrator'), false);
        throw new RuntimeException('A stale choice was saved');
    } catch (SiteLanguagesConflict) {
    }
    assertSameValue(2, $repository->writes, 'a stale revision never overwrites a newer choice');
};

$tests['record editing hides off languages and never requires text in languages in preparation'] = static function (): void {
    $profile = siteLanguagesProfile();
    $languages = siteLanguagesChoice($profile, ['ca' => 'public', 'es' => 'preparation', 'en' => 'off'], 'ca');
    $editing = $languages->editingProfile($profile);
    assertSameValue([['ca', 'es'], 'ca', ['ca', 'es', 'en']], [$editing->locales, $editing->defaultLocale, $editing->supported()], 'editing shows public and in-preparation languages');
    assertSameValue([false, true], array_map(static fn($definition): bool => $definition->preparation, $editing->localeDefinitions), 'languages in preparation are marked');
    $article = $profile->type('article') ?? throw new RuntimeException('Missing article');
    $html = Pages::recordForm(str_repeat('b', 32), $editing, $article, ['title' => ['ca' => 'Títol', 'es' => '', 'en' => 'Title']], null, chrome: adminTestChrome());
    assertTrueValue(!str_contains($html, 'data-locale-tab="en"') && !str_contains($html, 'name="title__en"'), 'an off language has no tab and no control');
    assertTrueValue(preg_match('/name="title__ca"[^>]* required/', $html) === 1, 'a public language keeps its required control');
    assertTrueValue(preg_match('/name="title__es"[^>]* required/', $html) === 0 && str_contains($html, 'name="title__es"'), 'a language in preparation is edited without being required');

    $repository = new MemoryRecordRepository();
    $clock = new IdentityTestClock(new DateTimeImmutable('2026-10-15T12:00:00Z'));
    $content = new ContentService($repository, $profile, $clock, new IdentityTestRandom(), publicLocales: static fn(): array => $languages->public());
    $created = $content->create('discipline', ['label' => ['ca' => 'Cinema', 'es' => '', 'en' => 'Film'], 'aliases' => ['ca' => '', 'es' => '', 'en' => 'Movies'], 'deprecated' => false], str_repeat('1', 32));
    assertTrueValue($created instanceof RecordSnapshot, 'a required text empty in a language in preparation does not block saving');
    $everyLanguage = new ContentService($repository, $profile, $clock, new IdentityTestRandom());
    assertTrueValue($everyLanguage->create('discipline', ['label' => ['ca' => 'Cinema', 'es' => '', 'en' => 'Film'], 'aliases' => [], 'deprecated' => false], str_repeat('1', 32)) instanceof ValidationFailure, 'a public language still requires its text');

    // The form shows only Catalan and Spanish; saving must keep the English text unchanged.
    $type = $profile->type('discipline') ?? throw new RuntimeException('Missing discipline');
    $values = EditorialForm::input(new Request('POST', '/', form: ['label__ca' => 'Cinema nou', 'label__es' => 'Cine', 'aliases__ca' => '', 'aliases__es' => '', 'deprecated' => '0']), $type, $editing, $created);
    $updated = $content->update('discipline', $created->id, $created->revisionNumber, $values, str_repeat('1', 32));
    assertTrueValue($updated instanceof RecordSnapshot, 'the edited record saves');
    assertSameValue(['ca' => 'Cinema nou', 'es' => 'Cine', 'en' => 'Film'], $updated->body['label'], 'the off language keeps its text');
    assertSameValue('Movies', $updated->body['aliases']['en'], 'every off text field is kept');
};

$tests['saving keeps off languages inside groups rich text and layout captions'] = static function (): void {
    $profile = siteLanguagesProfile();
    $off = ['en'];
    $project = $profile->type('project') ?? throw new RuntimeException('Missing project');
    $rich = static fn(string $text): array => ['formatVersion' => 1, 'nodes' => [['type' => 'paragraph', 'children' => [['text' => $text]]]]];
    $saved = [
        'body' => ['ca' => $rich('Cos'), 'es' => $rich('Cuerpo'), 'en' => $rich('Body')],
        'credits' => ['ca' => [['_itemId' => str_repeat('a', 32), 'role' => 'Direcció', 'name' => 'Ada']], 'es' => [], 'en' => [['_itemId' => str_repeat('b', 32), 'role' => 'Director', 'name' => 'Ada']]],
    ];
    $kept = SiteLanguages::keep($project, [
        'body' => ['ca' => $rich('Cos nou'), 'es' => $rich('Cuerpo')],
        'credits' => json_encode(['ca' => [['_itemId' => str_repeat('a', 32), 'role' => 'Direcció', 'name' => 'Ada Lovelace']], 'es' => []], JSON_THROW_ON_ERROR),
    ], $saved, $off);
    assertSameValue($rich('Body'), $kept['body']['en'], 'translated rich text keeps the off language');
    assertSameValue($rich('Cos nou'), $kept['body']['ca'], 'edited languages are saved as typed');
    assertSameValue($saved['credits']['en'], json_decode($kept['credits'], true)['en'], 'a translated group keeps its off-language items');

    $about = $profile->type('about') ?? throw new RuntimeException('Missing about');
    $contact = SiteLanguages::keep(
        $about,
        ['contact' => json_encode(['email' => 'new@example.test', 'city' => ['ca' => 'Barcelona', 'es' => 'Barcelona']], JSON_THROW_ON_ERROR)],
        ['contact' => ['email' => 'old@example.test', 'city' => ['ca' => 'Barcelona', 'es' => 'Barcelona', 'en' => 'Barcelona city']]],
        $off,
    );
    assertSameValue(['email' => 'new@example.test', 'city' => ['ca' => 'Barcelona', 'es' => 'Barcelona', 'en' => 'Barcelona city']], json_decode($contact['contact'], true), 'a translated child of an untranslated group keeps its off language');

    $home = $profile->type('home') ?? throw new RuntimeException('Missing home');
    $column = static fn(array $captions): array => ['content' => ['kind' => 'media', 'id' => str_repeat('c', 32), 'captions' => $captions]];
    $layout = SiteLanguages::keep(
        $home,
        ['collage' => ['formatVersion' => 2, 'rows' => [['id' => 'r1', 'columns' => [$column(['ca' => 'Foto'])]]]]],
        ['collage' => ['formatVersion' => 2, 'rows' => [['id' => 'r1', 'columns' => [$column(['ca' => 'Foto', 'en' => 'Photo'])]]]]],
        $off,
    );
    assertSameValue(['ca' => 'Foto', 'en' => 'Photo'], $layout['collage']['rows'][0]['columns'][0]['content']['captions'], 'an image caption keeps its off language');
    assertSameValue('', SiteLanguages::keep($project, ['trailer' => ''], ['trailer' => null], $off)['trailer'], 'an empty untranslated field stays empty, never an empty JSON string');
    $new = SiteLanguages::keep($project, ['body' => ['ca' => $rich('Nou')]], null, $off);
    assertSameValue(['ca' => $rich('Nou')], $new['body'], 'a new record has no off-language text to keep');
};

$tests['site languages page lets editors read and administrators change the choice'] = static function (): void {
    $profile = siteLanguagesProfile();
    $languages = siteLanguagesChoice($profile, ['es' => 'preparation', 'en' => 'off']);
    $editor = new AdminChrome(str_repeat('c', 32), 'Editor', role: 'editor');
    $read = SiteLanguagesPage::render($profile, $editor, $languages);
    assertTrueValue(!str_contains($read, '<form method="post" action="/settings/languages"') && str_contains($read, 'In preparation') && str_contains($read, 'primary language'), 'editors see the states without a form');
    assertTrueValue(str_contains($read, 'href="/settings/languages"'), 'editors find the page in the navigation');
    $administrator = new AdminChrome(str_repeat('c', 32), 'Admin');
    $write = SiteLanguagesPage::render($profile, $administrator, $languages);
    assertTrueValue(str_contains($write, 'name="state__en" value="off" checked') && str_contains($write, 'name="confirmed"') && str_contains($write, 'name="_revision" value="1"'), 'administrators change states with the expected revision');
    $editorSession = siteLanguagesActor('editor');
    assertTrueValue(AdministrationPermissions::allows($editorSession, new Request('GET', '/settings/languages'), $profile), 'editors may read the languages');
    assertTrueValue(!AdministrationPermissions::allows($editorSession, new Request('POST', '/settings/languages'), $profile), 'editors may not change them');
};

$tests['normalized export carries the language choice and rejects one that does not fit the profile'] = static function (): void {
    $profile = siteLanguagesProfile();
    $document = ['formatVersion' => 1, 'exportId' => str_repeat('a', 32), 'createdAt' => '2026-10-15T12:00:00Z',
        'profile' => ['id' => $profile->id, 'version' => $profile->version, 'locales' => $profile->locales],
        'records' => [], 'revisions' => [], 'relationships' => [], 'media' => [],
        'siteLanguages' => siteLanguagesChoice($profile, ['en' => 'off'])->document()];
    assertSameValue('off', (new NormalizedExport($document))->document['siteLanguages']['languages'][2]['state'], 'the choice is part of the export');
    $document['siteLanguages']['primary'] = 'en';
    try {
        new NormalizedExport($document);
        throw new RuntimeException('An invalid language choice was exported');
    } catch (InvalidArgumentException) {
        // An export whose primary language is off is refused.
    }
};
