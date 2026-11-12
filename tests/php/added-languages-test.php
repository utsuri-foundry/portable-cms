<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Compiler\FrozenSnapshot;
use Utsuri\PortableCms\Conventional\Content\ContentService;
use Utsuri\PortableCms\Conventional\Content\LanguageCatalog;
use Utsuri\PortableCms\Conventional\Content\LanguageEnvelope;
use Utsuri\PortableCms\Conventional\Content\LanguageText;
use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Content\RecordSnapshot;
use Utsuri\PortableCms\Conventional\Content\SiteLanguages;
use Utsuri\PortableCms\Conventional\Content\SiteLanguagesConflict;
use Utsuri\PortableCms\Conventional\Content\SiteLanguagesHttp;
use Utsuri\PortableCms\Conventional\Content\SiteLanguagesService;
use Utsuri\PortableCms\Conventional\Content\SiteWording;
use Utsuri\PortableCms\Conventional\Content\ValidationFailure;
use Utsuri\PortableCms\Conventional\Export\NormalizedExport;
use Utsuri\PortableCms\Conventional\Http\Request;
use Utsuri\PortableCms\Conventional\Preview\PreviewSnapshot;
use Utsuri\PortableCms\Conventional\Preview\ProfilePreviewCompiler;
use Utsuri\PortableCms\Conventional\Publication\WebsitePublication;
use Utsuri\PortableCms\Conventional\Upgrade\ProfileManifestChanges;
use Utsuri\PortableCms\Conventional\View\AdminChrome;
use Utsuri\PortableCms\Conventional\View\SiteLanguagesPage;

/** Reference Studio's manifest with its language envelope replaced, or removed when null. */
function addedLanguagesManifest(?string $envelope): ProfileManifest
{
    $source = (string) file_get_contents(dirname(__DIR__, 2) . '/profiles/reference-studio/profile.json');
    $start = strpos($source, "\n  \"languageEnvelope\": ");
    $end = strpos($source, "\n  \"recordTypes\": ");
    assertTrueValue(is_int($start) && is_int($end), 'the reference manifest declares its envelope after its languages');
    $path = sys_get_temp_dir() . '/portable-cms-envelope-' . bin2hex(random_bytes(8)) . '.json';
    file_put_contents($path, substr($source, 0, $start) . ($envelope === null ? '' : "\n  \"languageEnvelope\": " . $envelope . ',') . substr($source, $end));
    try {
        return ProfileManifest::load($path);
    } finally {
        unlink($path);
    }
}

function addedLanguagesService(MemorySiteLanguages $repository, ?ProfileManifest $profile = null): SiteLanguagesService
{
    return new SiteLanguagesService($repository, $profile ?? siteLanguagesProfile(), new IdentityTestClock(new DateTimeImmutable('2026-10-19T12:00:00Z')), new IdentityTestRandom());
}

/**
 * The Reference Studio snapshot with Italian beside its languages: every Italian value is the English
 * one, and titles say they are Italian.
 * @return mixed The value with Italian added beside English.
 */
function addedLanguagesItalian(mixed $value, string $key = ''): mixed
{
    if (!is_array($value)) {
        return $value;
    }
    foreach ($value as $index => $item) {
        $value[$index] = addedLanguagesItalian($item, (string) $index);
    }
    if (array_key_exists('en', $value) && array_key_exists('ca', $value)) {
        $value['it'] = $key === 'title' && is_string($value['en']) && $value['en'] !== '' ? 'IT ' . $value['en'] : $value['en'];
    }
    if (array_is_list($value)) {
        foreach ($value as $item) {
            if (is_array($item) && ($item['locale'] ?? null) === 'en') {
                $value[] = [...$item, 'locale' => 'it'];
            }
        }
    }
    return $value;
}

$tests['the language catalog names formats and admits languages only within an envelope the engine supports'] = static function (): void {
    $italian = LanguageCatalog::language('it') ?? throw new RuntimeException('Italian is missing');
    assertSameValue(['Italiano', 'Latn', 'ltr'], [$italian->name, $italian->script, $italian->direction], 'the catalog names a language in itself with its script and direction');
    $date = new DateTimeImmutable('2026-03-07T10:00:00Z');
    assertSameValue(['7/3/2026', '3/7/2026', '07/03/2026', '2026-03-07', '7. 3. 2026'], array_map(static fn(string $id): string => LanguageCatalog::language($id)?->date($date) ?? '', ['it', 'en', 'fr', 'sv', 'cs']), 'dates follow each language');
    assertSameValue(['1.234.567,5', '1,234,567.5', "1\u{202F}234\u{202F}567,5"], array_map(static fn(string $id): string => LanguageCatalog::language($id)?->number(1234567.5, 1) ?? '', ['it', 'en', 'fr']), 'numbers follow each language');
    assertSameValue([null, null], [LanguageCatalog::language('ru'), LanguageCatalog::language('ar')], 'languages in scripts the engine does not support are not in the catalog');
    assertSameValue('unicode_nfkd_accent_fold_lower_words_v1', LanguageCatalog::normalization('it'), 'search normalization is chosen by script');
    foreach ([[['Cyrl'], ['ltr'], []], [['Latn'], ['rtl'], []], [[], ['ltr'], []], [['Latn', 'Latn'], ['ltr'], []], [['Latn'], ['ltr'], ['00ZZ']], [['Latn'], ['ltr'], ['00FF-0000']], [['Latn'], ['ltr'], ['110000']]] as [$scripts, $directions, $characters]) {
        try {
            new LanguageEnvelope($scripts, $directions, $characters);
            throw new RuntimeException('An unsupported envelope was accepted');
        } catch (InvalidArgumentException $error) {
            assertSameValue('Profile language envelope is invalid', $error->getMessage(), 'an envelope outside what the engine supports is refused');
        }
    }

    $profile = siteLanguagesProfile();
    assertSameValue([['Latn'], ['ltr'], ['0000-00FF', '013F-0140', '2010-2027', '20AC']], [$profile->envelope?->scripts, $profile->envelope?->directions, $profile->envelope?->characters], 'Reference Studio declares a Latin, left-to-right envelope with the characters of its fonts');
    $addable = array_map(static fn($language): string => $language->id, $profile->addable());
    assertTrueValue(in_array('it', $addable, true) && in_array('pt', $addable, true) && !in_array('ca', $addable, true), 'catalog languages the profile does not list can be added');
    assertSameValue([], array_values(array_intersect(['fr', 'pl', 'cs', 'tr', 'vi'], $addable)), 'a language needing letters the fonts lack, such as œ, Ÿ or ł, is not offered');
    $french = LanguageCatalog::language('fr') ?? throw new RuntimeException('French is missing');
    assertTrueValue(in_array(0x153, $french->characters(), true) && in_array(0x178, $french->characters(), true), 'a language needs its letters and their capitals');
    $anyLatin = addedLanguagesManifest('{"scripts": ["Latn"], "directions": ["ltr"]}');
    assertTrueValue(in_array('fr', array_map(static fn($language): string => $language->id, $anyLatin->addable()), true) && count($anyLatin->addable()) === 36, 'without a character list the whole script is offered');
    assertTrueValue(in_array('pl', $profile->reserved(), true), 'codes in the scripts of the envelope stay reserved even where the fonts do not reach yet');
    assertTrueValue($profile->carries('it') && !$profile->carries('ru') && in_array('it', $profile->reserved(), true), 'an addable language is carried and its code reserved');
    $closed = addedLanguagesManifest(null);
    assertSameValue([[], false, ['ca', 'es', 'en']], [$closed->addable(), $closed->carries('it'), $closed->reserved()], 'a profile without an envelope offers only its own languages');
    foreach (['{"scripts": ["Arab"], "directions": ["rtl"]}', '{"scripts": ["Latn"]}', '{"scripts": ["Latn"], "directions": ["ltr"], "fonts": []}'] as $invalid) {
        try {
            addedLanguagesManifest($invalid);
            throw new RuntimeException('An invalid envelope declaration was loaded');
        } catch (InvalidArgumentException) {
            // The manifest schema and the engine refuse it.
        }
    }
    $installed = $profile->withLanguages(['it']);
    assertSameValue([['ca', 'es', 'en', 'it'], ['it'], ['ca', 'es', 'en'], 'Italiano'], [$installed->locales, $installed->addedLocales, $installed->listed(), $installed->localeDefinitions[3]->label], 'an installed profile lists the added language after its own');
    assertSameValue(['ca', 'es', 'en', 'it'], $profile->carrying(['ca', 'it'])->locales, 'a snapshot carries its added languages');
    try {
        $profile->withLanguages(['ru']);
        throw new RuntimeException('A language outside the envelope was installed');
    } catch (InvalidArgumentException) {
    }
};

$tests['administrators add a catalog language in preparation and remove it only while it has no text'] = static function (): void {
    $profile = siteLanguagesProfile();
    $repository = new MemorySiteLanguages();
    $service = addedLanguagesService($repository);
    siteLanguagesRejects(static fn() => $service->add('it', 0, siteLanguagesActor('editor')), 'languages.not_allowed', 'editors cannot add languages');
    siteLanguagesRejects(static fn() => $service->add('ru', 0, siteLanguagesActor('owner')), 'languages.add_invalid', 'a language outside the envelope cannot be added');
    siteLanguagesRejects(static fn() => $service->add('es', 0, siteLanguagesActor('owner')), 'languages.add_invalid', 'a listed language cannot be added again');
    $added = $service->add('it', 0, siteLanguagesActor('administrator'));
    assertSameValue([1, ['ca' => 'public', 'es' => 'public', 'en' => 'public', 'it' => 'preparation'], ['it']], [$added->revision, $added->states, $added->added($profile)], 'an added language starts in preparation after the listed ones');
    assertSameValue([['site_language_added', ['locale' => 'it']]], $repository->events, 'adding is audited');
    siteLanguagesRejects(static fn() => $service->add('it', 1, siteLanguagesActor('owner')), 'languages.add_invalid', 'a language is added once');
    try {
        $service->add('pt', 0, siteLanguagesActor('owner'));
        throw new RuntimeException('A stale addition was saved');
    } catch (SiteLanguagesConflict) {
    }
    assertTrueValue(!in_array('it', array_map(static fn($language): string => $language->id, $service->addable()), true), 'an added language is no longer offered');

    $installed = $added->installed($profile);
    $editing = $added->editingProfile($installed);
    assertSameValue([['ca', 'es', 'en', 'it'], [false, false, false, true]], [$editing->locales, array_map(static fn($definition): bool => $definition->preparation, $editing->localeDefinitions)], 'editors work in the added language, in preparation');
    $public = $service->save(['ca' => 'public', 'es' => 'public', 'en' => 'off', 'it' => 'public'], 'it', 1, siteLanguagesActor('owner'), true);
    assertSameValue(['it', ['ca', 'es', 'it'], 'site_languages_saved'], [$public->primary, $public->public(), $repository->events[1][0]], 'an added language can become public and primary like any other');
    siteLanguagesRejects(static fn() => $service->remove('it', 2, siteLanguagesActor('owner')), 'languages.remove_primary', 'the primary language cannot be removed');
    $service->save(['ca' => 'public', 'es' => 'public', 'en' => 'public', 'it' => 'off'], 'ca', 2, siteLanguagesActor('owner'), true);
    siteLanguagesRejects(static fn() => $service->remove('es', 3, siteLanguagesActor('owner')), 'languages.remove_invalid', 'a listed language is turned off, never removed');
    siteLanguagesRejects(static fn() => $service->remove('it', 3, siteLanguagesActor('editor')), 'languages.not_allowed', 'editors cannot remove languages');
    $repository->written = ['it'];
    try {
        $service->remove('it', 3, siteLanguagesActor('owner'));
        throw new RuntimeException('A language with text was removed');
    } catch (\Utsuri\PortableCms\Conventional\I18n\InvalidInput $error) {
        assertSameValue(['languages.remove_text', ['language' => 'Italiano']], [$error->text()->key, $error->text()->parameters], 'a language with text is kept and the refusal names it');
    }
    $repository->written = [];
    $removed = $service->remove('it', 3, siteLanguagesActor('owner'));
    assertSameValue([4, ['ca', 'es', 'en'], ['site_language_removed', ['locale' => 'it']]], [$removed->revision, array_keys($removed->states), $repository->events[3]], 'a language without text is removed and the removal audited');

    $closed = addedLanguagesService(new MemorySiteLanguages(), addedLanguagesManifest(null));
    assertSameValue([], $closed->addable(), 'a profile without an envelope offers nothing to add');
    siteLanguagesRejects(static fn() => $closed->add('it', 0, siteLanguagesActor('owner')), 'languages.add_invalid', 'nor accepts one');
    $full = new MemorySiteLanguages(SiteLanguages::from(['formatVersion' => 1, 'profileId' => $profile->id, 'revision' => 1, 'primary' => 'ca',
        'languages' => array_map(static fn(string $locale): array => ['locale' => $locale, 'state' => $locale === 'ca' ? 'public' : 'off'], ['ca', 'es', 'en', 'it', 'pt', 'de', 'nl', 'da', 'sv', 'nb', 'nn', 'is', 'ga', 'gl', 'eu', 'af'])], $profile));
    assertSameValue([], addedLanguagesService($full)->addable(), 'an installation holds at most sixteen languages');
};

$tests['the languages page offers the catalog languages and removes added ones through its own form'] = static function (): void {
    $profile = siteLanguagesProfile();
    $repository = new MemorySiteLanguages();
    $service = addedLanguagesService($repository);
    $administrator = new AdminChrome(str_repeat('c', 32), 'Admin');
    $http = new SiteLanguagesHttp($service, $profile, $administrator);
    $offered = static fn(string $page): string => preg_match('/<select id="languages-add".*?<\/select>/s', $page, $select) === 1 ? $select[0] : '';
    $before = $http->handle(new Request('GET', '/settings/languages'), siteLanguagesActor('administrator'))->body;
    assertTrueValue(str_contains($offered($before), '<option value="it" lang="it">Italiano</option>') && !str_contains($offered($before), 'value="es"') && !str_contains($before, 'form="languages-remove"'), 'the page offers languages inside the envelope, and nothing to remove yet');
    $added = $http->handle(new Request('POST', '/settings/languages', form: ['_csrf' => 'csrf', '_revision' => '0', '_action' => 'add', 'locale' => 'it']), siteLanguagesActor('administrator'));
    assertSameValue([303, '/settings/languages?notice=added'], [$added->status, $added->headers()['location'][0] ?? null], 'adding redirects to the page with a notice');
    $after = $http->handle(new Request('GET', '/settings/languages', query: ['notice' => 'added']), siteLanguagesActor('administrator'))->body;
    assertTrueValue(str_contains($after, 'Language added.') && str_contains($after, '<legend lang="it">Italiano</legend>') && str_contains($after, 'name="state__it" value="preparation" checked'), 'the added language is in preparation with the others');
    assertTrueValue(str_contains($after, 'form="languages-remove" name="locale" value="it"') && str_contains($after, 'id="languages-remove"') && !str_contains($offered($after), 'value="it"'), 'an added language is removed through a separate form');
    $stale = $http->handle(new Request('POST', '/settings/languages', form: ['_revision' => '0', '_action' => 'add', 'locale' => 'pt']), siteLanguagesActor('administrator'));
    assertSameValue(409, $stale->status, 'a stale addition is refused');
    $repository->written = ['it'];
    $kept = $http->handle(new Request('POST', '/settings/languages', form: ['_revision' => '1', '_action' => 'remove', 'locale' => 'it']), siteLanguagesActor('administrator'));
    assertTrueValue($kept->status === 422 && str_contains($kept->body, 'Italiano already has text'), 'removing a language with text explains why it stays');
    $saved = $http->handle(new Request('POST', '/settings/languages', form: ['_revision' => '1', 'primary' => 'ca', 'state__ca' => 'public', 'state__es' => 'public', 'state__en' => 'public', 'state__it' => 'off', 'confirmed' => '1']), siteLanguagesActor('administrator'));
    assertSameValue([303, 'off'], [$saved->status, $repository->load($profile)->states['it'] ?? null], 'an added language is turned off like any other');

    $editor = SiteLanguagesPage::render($repository->load($profile)->installed($profile), new AdminChrome(str_repeat('c', 32), 'Editor', role: 'editor'), $repository->load($profile));
    assertTrueValue(str_contains($editor, '<th scope="row" lang="it">Italiano</th><td>added · Off') && !str_contains($editor, 'languages-add'), 'editors read the added language without controls');
    $closed = SiteLanguagesPage::render(addedLanguagesManifest(null), $administrator, SiteLanguages::defaults($profile));
    assertTrueValue(str_contains($closed, 'needs a new version of the site profile') && !str_contains($closed, 'id="languages-add"'), 'a profile without an envelope explains how languages are added');
};

$tests['records accept and require text in an added language as in any other'] = static function (): void {
    $profile = siteLanguagesProfile();
    $languages = SiteLanguages::defaults($profile)->changed('it', true);
    $installed = $languages->installed($profile);
    $clock = new IdentityTestClock(new DateTimeImmutable('2026-10-19T12:00:00Z'));
    $preparing = new ContentService(new MemoryRecordRepository(), $installed, $clock, new IdentityTestRandom(), publicLocales: static fn(): array => $languages->public());
    $created = $preparing->create('discipline', ['label' => ['ca' => 'Cinema', 'es' => 'Cine', 'en' => 'Film', 'it' => ''], 'aliases' => [], 'deprecated' => false], str_repeat('1', 32));
    assertTrueValue($created instanceof RecordSnapshot, 'a language in preparation never blocks saving');
    $italian = $preparing->create('discipline', ['label' => ['ca' => 'Arquitectura', 'es' => 'Arquitectura', 'en' => 'Architecture', 'it' => 'Architettura'], 'aliases' => [], 'deprecated' => false], str_repeat('1', 32));
    assertSameValue('Architettura', $italian instanceof RecordSnapshot ? $italian->body['label']['it'] : null, 'text in the added language is stored');
    $public = new ContentService(new MemoryRecordRepository(), $installed, $clock, new IdentityTestRandom());
    assertTrueValue($public->create('discipline', ['label' => ['ca' => 'Cinema', 'es' => 'Cine', 'en' => 'Film', 'it' => ''], 'aliases' => [], 'deprecated' => false], str_repeat('1', 32)) instanceof ValidationFailure, 'a public added language requires its text');
    $listedOnly = new ContentService(new MemoryRecordRepository(), $profile, $clock, new IdentityTestRandom());
    $legacy = $listedOnly->create('discipline', ['label' => ['ca' => 'Cinema', 'es' => 'Cine', 'en' => 'Film'], 'aliases' => ['ca' => 'Cine', 'es' => '', 'en' => ''], 'deprecated' => false], str_repeat('1', 32));
    assertTrueValue($legacy instanceof RecordSnapshot && !array_key_exists('it', $legacy->body['label']), 'a record saved before Italian has no Italian value');
    assertSameValue($legacy->body, $preparing->validateCanonical('discipline', $legacy->body)->body, 'its revisions stay valid once Italian is added, for restore and upgrades');
    try {
        $listedOnly->validateCanonical('discipline', ['label' => ['ca' => 'Cinema', 'es' => 'Cine', 'en' => 'Film', 'it' => 'Cinema'], 'aliases' => ['ca' => '', 'es' => '', 'en' => ''], 'deprecated' => false]);
        throw new RuntimeException('Text in a language that was never added was accepted');
    } catch (InvalidArgumentException) {
        // Text in a language that was never added is refused.
    }

    $project = $profile->type('project') ?? throw new RuntimeException('Missing project');
    $home = $profile->type('home') ?? throw new RuntimeException('Missing home');
    $about = $profile->type('about') ?? throw new RuntimeException('Missing about');
    assertTrueValue(LanguageText::record($project->fields, ['title' => ['ca' => 'Obra', 'it' => 'Opera']], 'it'), 'a localized value has text');
    assertTrueValue(!LanguageText::record($project->fields, ['title' => ['ca' => 'Obra', 'it' => '  '], 'body' => ['it' => ['formatVersion' => 1, 'nodes' => []]]], 'it'), 'blank text and empty documents are not text');
    assertTrueValue(LanguageText::record($about->fields, ['contact' => ['email' => 'a@example.test', 'city' => ['it' => 'Barcellona']]], 'it'), 'a translated child of a group has text');
    assertTrueValue(LanguageText::record($home->fields, ['collage' => ['formatVersion' => 2, 'rows' => [['id' => 'r1', 'columns' => [['content' => ['kind' => 'media', 'id' => str_repeat('c', 32), 'captions' => ['it' => 'Foto']]]]]]]], 'it'), 'a layout caption has text');
    assertTrueValue(LanguageText::media(['translations' => [['locale' => 'it', 'alternativeText' => '', 'caption' => 'Didascalia', 'credit' => '']]], 'it') && !LanguageText::media(['translations' => [['locale' => 'it', 'alternativeText' => '', 'caption' => '', 'credit' => '']]], 'it'), 'media descriptions count only when written');
    assertTrueValue(LanguageText::wording(['values' => [['key' => 'page.home', 'locale' => 'it', 'text' => 'Home']]], 'it'), 'site wording counts');
    assertTrueValue(!LanguageText::holding(['translations' => [['locale' => 'it', 'message' => 'Coming soon.', 'contact' => '']]], 'it') && LanguageText::holding(['translations' => [['locale' => 'it', 'message' => 'Prossimamente.', 'contact' => '']]], 'it'), 'a holding message counts once it differs from the default');

    $holding = WebsitePublication::defaults($profile->id, ['ca', 'es', 'en'])->within(['ca', 'es', 'en', 'it']);
    assertSameValue(['ca', 'es', 'en', 'it'], array_column($holding->document()['translations'], 'locale'), 'the holding page settings follow the installation languages');
    assertSameValue('Coming soon.', $holding->document()['translations'][3]['message'], 'an added language starts with the English message');
};

$tests['an added public language is published with its own addresses search and wording'] = static function (): void {
    $root = staticCompilerTempRoot();
    try {
        $fixture = staticCompilerFixture();
        $package = $fixture['package'];
        $document = addedLanguagesItalian(siteLanguageSnapshotDocument());
        $document['profile']['locales'] = ['ca', 'es', 'en', 'it'];
        $carried = $package->manifest->carrying(['ca', 'es', 'en', 'it']);
        foreach ($document['records'] as &$record) {
            $record['relationships'] = \Utsuri\PortableCms\Conventional\Content\Blocks::relationships($carried->type($record['typeId'])->fields, $record['body'], $record['body'], $carried->locales, $carried->locales, $record['relationships']);
        }
        unset($record);
        $italian = [];
        foreach ($carried->wording as $definition) {
            $italian[$definition->key] = ['it' => $definition->kind === 'address_segment' ? $definition->defaults['en'] : 'IT ' . $definition->defaults['en']];
        }
        $italian['address.projects'] = ['it' => 'opere'];
        $document['wording'] = SiteWording::of($carried, 1, $italian)->entries($carried, ['ca', 'es', 'en', 'it']);
        $fixture['snapshot'] = FrozenSnapshot::fromDocument($document, $package);
        $result = staticCompilerBuild($fixture, $root . '/artifact');
        $paths = array_column($result->files, 'path');
        assertTrueValue(in_array('it/index.html', $paths, true) && in_array('search/it.json', $paths, true) && in_array('it/opere/archive-of-mist/index.html', $paths, true), 'the added language has its pages, addresses and search index');
        $page = (string) file_get_contents($result->outputRoot . '/it/opere/archive-of-mist/index.html');
        assertTrueValue(str_contains($page, '<html lang="it"') && str_contains($page, 'IT Archive of Mist') && str_contains($page, '>IT Year<'), 'the page is in the added language with its wording');
        $landing = (string) file_get_contents($result->outputRoot . '/it/landing/index.html');
        assertTrueValue(str_contains($landing, 'Spaces find meaning when we share them.') && str_contains($landing, '../../it/opere/archive-of-mist/'), 'typed blocks render their text and resolve relationships in the added language');
        $projected = (new \Utsuri\PortableCms\Conventional\Compiler\SnapshotLanguages(['ca', 'it'], 'ca'))->document($document, $package->manifest);
        assertSameValue(['ca', 'it'], FrozenSnapshot::fromDocument($projected, $package)->locales, 'language projection retains added-language block relationships');
        $preview = profilePreviewFixture();
        try {
            $bodies = array_column($document['records'], 'body', 'id');
            $records = array_map(static fn($record) => new \Utsuri\PortableCms\Conventional\Preview\PreviewRecord($record->id, $record->typeId, $record->revisionId, $record->revisionNumber, $bodies[$record->id], $record->relationships, $record->media, $record->workflowState), $preview['snapshot']->records);
            $frozen = $preview['compiler']->freeze(new PreviewSnapshot($package->manifest->id, $package->manifest->version, $carried->locales, $records, $preview['snapshot']->createdAt, $preview['snapshot']->designRegistries, $package->checksum, defaultLocale: 'ca', wording: $document['wording']));
            assertSameValue(['ca', 'es', 'en', 'it'], $frozen->locales, 'exact previews project typed block relationships in an added language');
            $hidden = $preview['compiler']->freeze(new PreviewSnapshot($package->manifest->id, $package->manifest->version, ['ca', 'es', 'en'], $records, $preview['snapshot']->createdAt, $preview['snapshot']->designRegistries, $package->checksum, defaultLocale: 'ca'));
            $hiddenLanding = array_values(array_filter($hidden->records, static fn($record): bool => $record->typeId === 'landing'))[0];
            assertSameValue(['ca', 'es', 'en'], array_keys($hiddenLanding->body['blocks']), 'turning an added language off removes its block values and relationships from exact previews');
        } finally {
            staticCompilerRemoveTree($preview['root']);
        }
        assertTrueValue(str_contains((string) file_get_contents($result->outputRoot . '/ca/index.html'), 'hreflang="it"'), 'the other languages link to it');
        $graph = array_values(array_unique(array_column($result->manifest['routeIdentities'], 'locale')));
        sort($graph);
        assertSameValue(['ca', 'en', 'es', 'it'], $graph, 'the route graph treats it like a listed language');
        $search = json_decode((string) file_get_contents($result->outputRoot . '/search/it.json'), true, 64, JSON_THROW_ON_ERROR);
        assertSameValue(['it', 'unicode_nfkd_accent_fold_lower_words_v1'], [$search['locale'], $search['normalization']], 'its search index uses the normalization of its script');

        $outside = $document;
        $outside['profile']['locales'] = ['ca', 'es', 'en', 'ru'];
        siteLanguageRejected(static fn() => FrozenSnapshot::fromDocument($outside, $package), 'a snapshot in a language outside the envelope is refused');
        $snapshot = static fn(array $wording, array $preparation = []): PreviewSnapshot => new PreviewSnapshot('reference-studio', '1.0.0', ['ca', 'es', 'en', 'it'], [], new DateTimeImmutable('2026-10-19T09:00:00Z'), profileChecksum: $package->checksum, defaultLocale: 'ca', preparationLocales: $preparation, wording: $wording);
        $missing = ProfilePreviewCompiler::missingWording($carried, $snapshot([]));
        assertSameValue(['preflight.issue.wording', 'Italiano'], [$missing[0]->key ?? null, $missing[0]->parameters['language'] ?? null], 'a public added language needs its complete wording');
        assertSameValue([], ProfilePreviewCompiler::missingWording($carried, $snapshot([], ['it'])), 'in preparation it never blocks publication');
        assertSameValue([], ProfilePreviewCompiler::missingWording($carried, $snapshot($document['wording'])), 'written wording completes it');
    } finally {
        staticCompilerRemoveTree($root);
    }
};

$tests['language codes the envelope could add are reserved from addresses before they are added'] = static function (): void {
    $profile = siteLanguagesProfile();
    $repository = new MemorySiteWording();
    $saved = siteWordingService($repository)->save(0, ['address.about' => ['ca' => 'it']], [], siteLanguagesActor('editor'));
    assertTrueValue($saved instanceof ValidationFailure && ($saved->errors[SiteWording::field('address.about', 'ca')] ?? null)?->key === 'wording.segment_language', 'an address cannot take the code of a language the site could add');
    assertTrueValue(in_array('pt', $profile->reserved(), true) && !in_array('ru', $profile->reserved(), true), 'only codes inside the envelope are reserved');
};

$tests['exports carry added languages and upgrades classify envelope changes'] = static function (): void {
    $profile = siteLanguagesProfile();
    $choice = SiteLanguages::defaults($profile)->changed('it', true);
    $document = ['formatVersion' => 1, 'exportId' => str_repeat('a', 32), 'createdAt' => '2026-10-19T12:00:00Z',
        'profile' => ['id' => $profile->id, 'version' => $profile->version, 'locales' => ['ca', 'es', 'en', 'it']],
        'records' => [], 'revisions' => [], 'relationships' => [], 'media' => [], 'siteLanguages' => $choice->document()];
    assertSameValue('it', (new NormalizedExport($document))->document['siteLanguages']['languages'][3]['locale'], 'the export carries the added language with the others');
    assertSameValue(['it'], SiteLanguages::from($choice->document(), $profile)->added($profile), 'restore reads it back as an added language');
    $document['profile']['locales'] = ['ca', 'es', 'en'];
    try {
        new NormalizedExport($document);
        throw new RuntimeException('An export whose languages disagree was accepted');
    } catch (InvalidArgumentException) {
    }
    siteLanguagesRejects(static fn() => SiteLanguages::from([...$choice->document(), 'languages' => [...$choice->document()['languages'], ['locale' => 'ru', 'state' => 'off']]], $profile), 'languages.invalid', 'a language outside the envelope is refused');
    siteLanguagesRejects(static fn() => SiteLanguages::from($choice->document(), addedLanguagesManifest(null)), 'languages.invalid', 'an added language needs an envelope');
    assertSameValue(['ca' => 'public', 'es' => 'public', 'en' => 'public'], SiteLanguages::stored($choice->document(), addedLanguagesManifest(null))->states, 'a profile release without the envelope no longer offers the added language');

    $manifest = siteWordingManifestDocument();
    $closed = $manifest;
    unset($closed['languageEnvelope']);
    $classify = static fn(array $old, array $new): array => [ProfileManifestChanges::classify($old, $new)->neutral, array_column(ProfileManifestChanges::classify($old, $new)->affecting, 'path')];
    $fonts = $manifest;
    $fonts['languageEnvelope']['characters'][] = '0152-0153';
    assertSameValue([['languageEnvelope'], []], $classify($manifest, $fonts), 'fonts that draw more characters only offer more languages');
    assertSameValue([[], ['languageEnvelope']], $classify($fonts, $manifest), 'fonts that draw fewer recheck the text of added languages');
    assertSameValue([['languageEnvelope'], []], $classify($closed, $manifest), 'declaring an envelope only offers more languages');
    assertSameValue([[], ['languageEnvelope']], $classify($manifest, $closed), 'removing it rechecks the text of added languages');
};
