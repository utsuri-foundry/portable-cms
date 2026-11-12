<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Compiler\FrozenSnapshot;
use Utsuri\PortableCms\Conventional\Compiler\ProfileBuildFailure;
use Utsuri\PortableCms\Conventional\Compiler\ProfileBuildReason;
use Utsuri\PortableCms\Conventional\Compiler\PublishedSlugHistory;
use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Content\SiteWording;
use Utsuri\PortableCms\Conventional\Content\SiteWordingConflict;
use Utsuri\PortableCms\Conventional\Content\SiteWordingHttp;
use Utsuri\PortableCms\Conventional\Content\SiteWordingRepository;
use Utsuri\PortableCms\Conventional\Content\SiteWordingService;
use Utsuri\PortableCms\Conventional\Content\ValidationFailure;
use Utsuri\PortableCms\Conventional\Export\NormalizedExport;
use Utsuri\PortableCms\Conventional\Http\Request;
use Utsuri\PortableCms\Conventional\Identity\AdministrationPermissions;
use Utsuri\PortableCms\Conventional\Preview\PreviewSnapshot;
use Utsuri\PortableCms\Conventional\Preview\ProfilePreviewCompiler;
use Utsuri\PortableCms\Conventional\Upgrade\ProfileManifestChanges;
use Utsuri\PortableCms\Conventional\View\AdminChrome;
use Utsuri\PortableCms\Conventional\View\SiteWordingPage;
use Utsuri\PortableCms\Profile\ReferenceStudio\ReferenceContent;

final class MemorySiteWording implements SiteWordingRepository
{
    /** @var list<array{revision: int, changed: list<string>}> */
    public array $saves = [];

    public function __construct(public ?SiteWording $wording = null) {}

    public function load(ProfileManifest $profile): SiteWording
    {
        return $this->wording ?? SiteWording::defaults($profile);
    }

    public function save(SiteWording $next, int $expected, array $changed, string $actorId, string $auditId, DateTimeImmutable $now): void
    {
        if (($this->wording->revision ?? 0) !== $expected) {
            throw new SiteWordingConflict();
        }
        $this->saves[] = ['revision' => $next->revision, 'changed' => $changed];
        $this->wording = $next;
    }
}

/** @return array<string, mixed> */
function siteWordingManifestDocument(): array
{
    $document = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/profiles/reference-studio/profile.json'), true, 64, JSON_THROW_ON_ERROR);
    assertTrueValue(is_array($document), 'the reference manifest is a document');
    return $document;
}

/**
 * Loads the Reference Studio manifest with other wording, as a profile release would ship it. Only
 * the wording is rewritten, so the rest of the manifest keeps its exact JSON.
 * @param list<array<string, mixed>>|null $wording
 */
function siteWordingManifest(?array $wording): ProfileManifest
{
    $source = (string) file_get_contents(dirname(__DIR__, 2) . '/profiles/reference-studio/profile.json');
    $start = strpos($source, "\n  \"wording\": ");
    $end = strpos($source, "\n  \"designRegistries\": ");
    assertTrueValue(is_int($start) && is_int($end), 'the reference manifest declares its wording before its design');
    $path = sys_get_temp_dir() . '/portable-cms-wording-' . bin2hex(random_bytes(8)) . '.json';
    file_put_contents($path, substr($source, 0, $start) . ($wording === null ? '' : "\n  \"wording\": " . json_encode($wording, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . ',') . substr($source, $end));
    try {
        return ProfileManifest::load($path);
    } finally {
        unlink($path);
    }
}
/** @param list<string> $languages */
function siteWordingService(MemorySiteWording $repository, array $languages = ['ca', 'es', 'en']): SiteWordingService
{
    return new SiteWordingService($repository, siteLanguagesProfile(), new IdentityTestClock(new DateTimeImmutable('2026-10-16T12:00:00Z')), new IdentityTestRandom(), static fn(): array => $languages);
}

/**
 * The Reference Studio snapshot with the installation's wording in every language.
 * @param array<string, array<string, string>> $values
 */
function siteWordingSnapshot(array $values): FrozenSnapshot
{
    $package = staticCompilerFixture()['package'];
    $document = siteLanguageSnapshotDocument();
    $document['wording'] = SiteWording::of($package->manifest, 1, $values)->entries($package->manifest, ['ca', 'es', 'en']);
    return FrozenSnapshot::fromDocument($document, $package);
}

$tests['profiles declare their site wording with defaults for their languages and refuse unsafe declarations'] = static function (): void {
    $profile = siteLanguagesProfile();
    assertSameValue(['ca' => 'Projectes', 'es' => 'Proyectos', 'en' => 'Work'], $profile->wording('page.projects')?->defaults, 'the manifest carries the default wording per language');
    assertSameValue(['address_segment', 'pages', 'projects'], [$profile->wording('address.projects')?->kind, $profile->wording('address.projects')?->uniqueIn, $profile->wording('address.projects')?->defaults['es']], 'address segments are declared with their group');
    assertSameValue(null, $profile->wording('page.unknown'), 'an undeclared key has no wording');
    $base = siteWordingManifestDocument()['wording'];
    assertSameValue(count($profile->wording), count(siteWordingManifest($base)->wording), 'the declared wording loads unchanged');
    $entry = static fn(array $wording): int => array_search('page.projects', array_column($wording, 'key'), true);
    $segment = static fn(array $wording): int => array_search('address.projects', array_column($wording, 'key'), true);
    $variants = [
        'an unknown property' => static function (array $wording) use ($entry): array {
            $wording[$entry($wording)]['tone'] = 'formal';
            return $wording;
        },
        'a duplicate key' => static function (array $wording) use ($entry): array {
            $wording[] = $wording[$entry($wording)];
            return $wording;
        },
        'an unsafe key' => static function (array $wording) use ($entry): array {
            $wording[$entry($wording)]['key'] = '../projects';
            return $wording;
        },
        'an unknown kind' => static function (array $wording) use ($entry): array {
            $wording[$entry($wording)]['kind'] = 'html';
            return $wording;
        },
        'a default missing in the default language' => static function (array $wording) use ($entry): array {
            unset($wording[$entry($wording)]['defaults']['ca']);
            return $wording;
        },
        'a default in a language the profile does not support' => static function (array $wording) use ($entry): array {
            $wording[$entry($wording)]['defaults']['fr'] = 'Projets';
            return $wording;
        },
        'a default on several lines' => static function (array $wording) use ($entry): array {
            $wording[$entry($wording)]['defaults']['en'] = "Selected\nwork";
            return $wording;
        },
        'a default longer than its limit' => static function (array $wording) use ($entry): array {
            $wording[$entry($wording)]['maximumLength'] = 3;
            return $wording;
        },
        'an unsafe address segment' => static function (array $wording) use ($segment): array {
            $wording[$segment($wording)]['defaults']['en'] = '../work';
            return $wording;
        },
        'an address segment that is a language prefix' => static function (array $wording) use ($segment): array {
            $wording[$segment($wording)]['defaults']['en'] = 'es';
            return $wording;
        },
        'two pages with one address' => static function (array $wording) use ($segment): array {
            $wording[$segment($wording)]['defaults']['en'] = 'articles';
            return $wording;
        },
        'a unique group on a text' => static function (array $wording) use ($entry): array {
            $wording[$entry($wording)]['uniqueIn'] = 'pages';
            return $wording;
        },
    ];
    foreach ($variants as $name => $variant) {
        try {
            siteWordingManifest($variant($base));
            throw new RuntimeException('The manifest accepted ' . $name);
        } catch (InvalidArgumentException|\Utsuri\PortableCms\Conventional\SchemaValidationFailure) {
            // Refused as it should be.
        }
    }
    assertSameValue([], siteWordingManifest(null)->wording, 'a profile may declare no wording');
    $partial = $base;
    unset($partial[$entry($partial)]['defaults']['es']);
    assertSameValue(['ca', 'en'], array_keys(siteWordingManifest($partial)->wording('page.projects')->defaults ?? []), 'another language may leave its text to the editors');
};

$tests['editors write the site wording per language and reset it while texts of languages that are off stay'] = static function (): void {
    $profile = siteLanguagesProfile();
    $repository = new MemorySiteWording(SiteWording::of($profile, 3, ['page.projects' => ['en' => 'Selected work']]));
    // English is off: the page shows Catalan and Spanish only.
    $service = siteWordingService($repository, ['ca', 'es']);
    $editor = siteLanguagesActor('editor');
    $saved = $service->save(3, ['page.projects' => ['ca' => 'Projectes', 'es' => ' Obras '], 'page.home' => ['ca' => '']], [], $editor);
    assertTrueValue($saved instanceof SiteWording, 'an editor saves the site wording');
    assertSameValue(['page.projects' => ['es' => 'Obras', 'en' => 'Selected work']], $saved->values, 'only texts that differ from the defaults are stored, and the off language keeps its own');
    assertSameValue(['Projectes', 'Obras', 'Selected work', 'Inici'], [$saved->text($profile, 'page.projects', 'ca'), $saved->text($profile, 'page.projects', 'es'), $saved->text($profile, 'page.projects', 'en'), $saved->text($profile, 'page.home', 'ca')], 'every language reads its text or its default');
    assertSameValue([4, ['page.projects']], [$repository->saves[0]['revision'], $repository->saves[0]['changed']], 'the save is a new revision naming the changed keys');

    $reset = $service->save(4, [], ['page.projects'], siteLanguagesActor('administrator'));
    assertTrueValue($reset instanceof SiteWording, 'an administrator resets a key');
    assertSameValue(['page.projects' => ['en' => 'Selected work']], $reset->values, 'a reset key follows the defaults in the languages shown, and the off language keeps its text');

    $invalid = $service->save(5, [
        'address.projects' => ['ca' => 'Projectes', 'es' => 'es'],
        'address.articles' => ['ca' => 'people'],
        'page.home' => ['ca' => "Inici\nara", 'es' => str_repeat('a', 41)],
    ], [], siteLanguagesActor('owner'));
    assertTrueValue($invalid instanceof ValidationFailure, 'invalid wording is refused');
    assertSameValue([
        'wording__page-home__ca' => 'wording.text_invalid',
        'wording__page-home__es' => 'wording.too_long',
        'wording__address-projects__ca' => 'wording.segment_invalid',
        'wording__address-projects__es' => 'wording.segment_language',
        'wording__address-articles__ca' => 'wording.segment_taken',
        'wording__address-people__ca' => 'wording.segment_taken',
    ], array_map(static fn($message): string => $message->key, $invalid->errors), 'each field names its problem, and two pages may not share an address');
    assertSameValue([2, 5], [count($repository->saves), $repository->load($profile)->revision], 'a refused save changes nothing');

    try {
        $service->save(4, ['page.home' => ['ca' => 'Portada']], [], $editor);
        throw new RuntimeException('A stale revision was saved');
    } catch (SiteWordingConflict) {
        // The page re-renders with the latest revision.
    }
    foreach ([siteLanguagesActor('reviewer'), new \Utsuri\PortableCms\Conventional\Identity\SessionContext('session', 'authenticated', str_repeat('1', 32), 'person@example.test', 'Person', 'editor', 'token', 'csrf', new DateTimeImmutable('2026-10-15T12:00:00Z'), false, mustChangePassword: true)] as $actor) {
        siteLanguagesRejects(static fn() => $service->save(5, [], [], $actor), 'wording.not_allowed', 'only the owner, administrators and editors write the wording');
    }
};

$tests['the site wording page lists every key per language and saves through native forms'] = static function (): void {
    $profile = siteLanguagesProfile();
    $editing = siteLanguagesChoice($profile, ['es' => 'preparation', 'en' => 'off'])->editingProfile($profile);
    $repository = new MemorySiteWording(SiteWording::of($profile, 2, ['page.projects' => ['es' => 'Obras', 'en' => 'Selected work']]));
    $service = siteWordingService($repository, ['ca', 'es']);
    $chrome = new AdminChrome(str_repeat('c', 32), 'Editor', role: 'editor');
    $page = SiteWordingPage::render($editing, $chrome, $repository->load($profile));
    foreach ($profile->wording as $definition) {
        assertTrueValue(str_contains($page, 'name="' . SiteWording::field($definition->key, 'ca') . '"') && str_contains($page, 'name="' . SiteWording::field($definition->key, 'es') . '"'), 'every key has a field per language shown: ' . $definition->key);
        assertTrueValue(!str_contains($page, 'name="' . SiteWording::field($definition->key, 'en') . '"'), 'a language that is off has no field: ' . $definition->key);
    }
    assertTrueValue(str_contains($page, 'value="Obras"') && str_contains($page, 'Profile text: Proyectos') && str_contains($page, 'Español, in preparation'), 'a changed text shows the profile text beside it and the language in preparation is marked');
    assertTrueValue(substr_count($page, 'name="_reset"') === 1 && str_contains($page, 'form="wording-reset" name="_reset" value="page.projects"'), 'only a changed key offers its reset, on its own form');
    assertTrueValue(str_contains($page, 'name="_revision" value="2"') && str_contains($page, 'action="/settings/wording"') && str_contains($page, 'pattern="[a-z0-9]+(-[a-z0-9]+)*"'), 'the form carries its revision and address segments their pattern');
    assertTrueValue(str_contains($page, 'href="/settings/wording"') && str_contains($page, 'href="/settings/languages"'), 'editors find the wording in the navigation');

    $editor = siteLanguagesActor('editor');
    foreach (['GET', 'POST'] as $method) {
        assertTrueValue(AdministrationPermissions::allows($editor, new Request($method, '/settings/wording'), $profile), 'editors may ' . $method . ' the site wording');
    }
    assertTrueValue(!AdministrationPermissions::allows($editor, new Request('POST', '/settings/design'), $profile), 'other settings stay closed to editors');

    $http = new SiteWordingHttp($service, $editing, $chrome);
    $form = ['_csrf' => 'csrf', '_revision' => '2', SiteWording::field('page.home', 'ca') => 'Portada'];
    $saved = $http->handle(new Request('POST', '/settings/wording', form: $form), $editor);
    assertSameValue([303, ['page.home' => ['ca' => 'Portada'], 'page.projects' => ['es' => 'Obras', 'en' => 'Selected work']]], [$saved->status, $repository->load($profile)->values], 'a saved text redirects and fields the form did not send keep their text');
    $stale = $http->handle(new Request('POST', '/settings/wording', form: $form + [SiteWording::field('page.about', 'ca') => 'Taller']), $editor);
    assertTrueValue($stale->status === 409 && str_contains($stale->body, 'value="Taller"') && str_contains($stale->body, 'name="_revision" value="3"'), 'a stale save keeps the typed text and shows the latest revision');
    $invalid = $http->handle(new Request('POST', '/settings/wording', form: ['_revision' => '3', SiteWording::field('address.about', 'ca') => 'Estudi']), $editor);
    assertTrueValue($invalid->status === 422 && str_contains($invalid->body, 'aria-invalid="true"') && str_contains($invalid->body, 'href="#wording__address-about__ca">Studio address, Català: Use lowercase letters'), 'an invalid address segment is named and linked');
    $reset = $http->handle(new Request('POST', '/settings/wording', form: ['_revision' => '3', '_reset' => 'page.projects']), $editor);
    assertSameValue([303, ['page.home' => ['ca' => 'Portada'], 'page.projects' => ['en' => 'Selected work']]], [$reset->status, $repository->load($profile)->values], 'reset follows the profile text in the languages shown');
    assertSameValue(422, $http->handle(new Request('POST', '/settings/wording', form: ['_revision' => '4', '_reset' => 'page.unknown']), $editor)->status, 'an unknown key cannot be reset');
    assertTrueValue(str_contains($http->handle(new Request('GET', '/settings/wording', query: ['saved' => '1']), $editor)->body, 'Site wording saved.'), 'the saved notice follows the redirect');
};

$tests['snapshots carry the wording of their languages and profile code reads every fixed text from it'] = static function (): void {
    $fixture = staticCompilerFixture();
    $profile = $fixture['package']->manifest;
    assertSameValue(['Work', 'Projectes'], [$fixture['snapshot']->wording->text('page.projects', 'en'), $fixture['snapshot']->wording->text('page.projects', 'ca')], 'a snapshot without wording draws the profile defaults');
    $snapshot = siteWordingSnapshot(['page.projects' => ['es' => 'Obras'], 'address.projects' => ['es' => 'obras']]);
    assertSameValue(['Obras', 'obras'], [$snapshot->wording->text('page.projects', 'es'), $snapshot->wording->text('address.projects', 'es')], 'the snapshot carries the installation wording');
    try {
        $snapshot->wording->text('page.unknown', 'es');
        throw new RuntimeException('Profile code read undeclared wording');
    } catch (InvalidArgumentException $error) {
        assertTrueValue(!$error instanceof ProfileBuildFailure, 'an undeclared key is a profile error, not missing wording');
    }
    $document = siteLanguageSnapshotDocument();
    foreach ([[['key' => 'page.unknown', 'locale' => 'es', 'text' => 'X']], [['key' => 'page.home', 'locale' => 'fr', 'text' => 'Accueil']], [['key' => 'address.projects', 'locale' => 'es', 'text' => 'Obras']]] as $wording) {
        siteLanguageRejected(static fn() => FrozenSnapshot::fromDocument([...$document, 'wording' => $wording], $fixture['package']), 'a snapshot refuses wording it cannot carry: ' . $wording[0]['key']);
    }
    $partial = FrozenSnapshot::fromDocument([...$document, 'wording' => [['key' => 'page.home', 'locale' => 'ca', 'text' => 'Inici']]], $fixture['package']);
    try {
        $partial->wording->text('page.projects', 'ca');
        throw new RuntimeException('Missing wording was drawn');
    } catch (ProfileBuildFailure $failure) {
        assertSameValue([ProfileBuildReason::MissingWording, 'page.projects', 'ca'], [$failure->reason, $failure->fieldId, $failure->locale], 'a missing text fails the build naming the key and language');
    }

    // Previews carry the installation's own texts; the build fills in the profile's defaults.
    $compiler = new ProfilePreviewCompiler($fixture['package'], sys_get_temp_dir(), dirname(__DIR__, 2) . '/schemas');
    $frozen = $compiler->freeze(new PreviewSnapshot('reference-studio', $fixture['package']->manifest->version, ['ca', 'es', 'en'], [], new DateTimeImmutable('2026-10-16T09:00:00Z'), profileChecksum: $fixture['package']->checksum, defaultLocale: 'ca', preparationLocales: ['en'], wording: [['key' => 'page.home', 'locale' => 'es', 'text' => 'Portada']]));
    assertSameValue(['Inici', 'Portada', 'Home'], [$frozen->wording->text('page.home', 'ca'), $frozen->wording->text('page.home', 'es'), $frozen->wording->text('page.home', 'en')], 'the frozen snapshot carries every text of its languages');
    $public = $compiler->freeze(new PreviewSnapshot('reference-studio', $fixture['package']->manifest->version, ['ca', 'es'], [], new DateTimeImmutable('2026-10-16T09:00:00Z'), profileChecksum: $fixture['package']->checksum, defaultLocale: 'es'));
    siteLanguageRejected(static fn() => $public->wording->text('page.home', 'en'), 'a language the snapshot does not draw has no wording');
    // Languages in preparation draw the primary language's wording where theirs is missing.
    $texts = [['key' => 'page.home', 'locale' => 'ca', 'text' => 'Inici'], ['key' => 'page.home', 'locale' => 'es', 'text' => 'Portada'], ['key' => 'page.home', 'locale' => 'fr', 'text' => 'Accueil']];
    assertSameValue([...array_slice($texts, 0, 2), ['key' => 'page.home', 'locale' => 'en', 'text' => 'Inici']], (new \Utsuri\PortableCms\Conventional\Compiler\SnapshotLanguages(['ca', 'es', 'en'], 'ca', ['en']))->wording($texts), 'a language in preparation shows the primary language where its wording is missing, and undrawn languages leave');
    assertSameValue(array_slice($texts, 0, 2), (new \Utsuri\PortableCms\Conventional\Compiler\SnapshotLanguages(['ca', 'es', 'en'], 'ca'))->wording($texts), 'a public language never borrows another language\'s wording');
};

$tests['Reference Studio draws its pages and addresses from the site wording'] = static function (): void {
    $root = staticCompilerTempRoot();
    try {
        $fixture = staticCompilerFixture();
        $fixture['snapshot'] = siteWordingSnapshot(['page.projects' => ['es' => 'Obras'], 'address.projects' => ['es' => 'obras'], 'metadata.year' => ['ca' => 'Any de l’obra'], 'status.complete' => ['ca' => 'Acabat']]);
        $result = staticCompilerBuild($fixture, $root . '/artifact');
        $paths = array_column($result->files, 'path');
        assertTrueValue(in_array('es/obras/index.html', $paths, true) && in_array('es/obras/archivo-de-la-niebla/index.html', $paths, true) && !in_array('es/projects/index.html', $paths, true), 'the Spanish projects move to their written address');
        assertTrueValue(in_array('ca/projects/index.html', $paths, true) && in_array('en/projects/index.html', $paths, true), 'other languages keep theirs');
        $index = (string) file_get_contents($result->outputRoot . '/es/obras/index.html');
        assertTrueValue(str_contains($index, '>Obras</h1>') && str_contains($index, '>Obras</a>'), 'the page title and navigation use the written text');
        $project = (string) file_get_contents($result->outputRoot . '/ca/projects/arxiu-de-la-boira/index.html');
        assertTrueValue(str_contains($project, '<dt>Any de l’obra</dt>') && str_contains($project, 'Acabat'), 'record labels and status names are wording too');
        assertTrueValue(str_contains($project, 'hreflang="es" href="https://reference-studio.example/es/obras/archivo-de-la-niebla/"'), 'alternates follow the written address');
    } finally {
        staticCompilerRemoveTree($root);
    }
};

$tests['changing an address segment redirects the published addresses of its pages'] = static function (): void {
    $fixture = staticCompilerFixture();
    $published = (new ReferenceContent($fixture['snapshot'], $fixture['package']->manifest))->discovery();
    $history = PublishedSlugHistory::afterVerifiedActivation($published, 'reference-studio', publicationReceiptFixture());
    $renamed = (new ReferenceContent(siteWordingSnapshot(['address.projects' => ['es' => 'obras']]), $fixture['package']->manifest))->discovery();
    $redirects = array_map(static fn($redirect): array => [$redirect->from, $redirect->to], $history->redirectsFor($renamed, 'reference-studio', 'ca'));
    assertTrueValue(in_array(['/es/projects/', '/es/obras/'], $redirects, true) && in_array(['/es/projects/archivo-de-la-niebla/', '/es/obras/archivo-de-la-niebla/'], $redirects, true), 'the Spanish project pages redirect to their new address');
    assertSameValue([], array_values(array_filter($redirects, static fn(array $redirect): bool => !str_starts_with($redirect[0], '/es/projects/'))), 'nothing else moves');
};

$tests['publication preflight refuses a public language whose wording is incomplete and names the missing keys'] = static function (): void {
    $package = staticCompilerFixture()['package'];
    // A profile release whose Spanish leaves two texts to the editors.
    $wording = siteWordingManifestDocument()['wording'];
    foreach ($wording as $index => $entry) {
        if (in_array($entry['key'], ['page.home', 'address.search'], true)) {
            unset($wording[$index]['defaults']['es']);
        }
    }
    $release = siteWordingManifest($wording);
    $snapshot = static fn(array $preparation, array $texts = []): PreviewSnapshot => new PreviewSnapshot('reference-studio', $package->manifest->version, ['ca', 'es', 'en'], [], new DateTimeImmutable('2026-10-16T09:00:00Z'), profileChecksum: $package->checksum, defaultLocale: 'ca', preparationLocales: $preparation, wording: $texts);
    $issues = ProfilePreviewCompiler::missingWording($release, $snapshot([]));
    assertSameValue([['preflight.issue.wording', ['language' => 'Español', 'keys' => 'page.home, address.search']]], array_map(static fn($issue): array => [$issue->key, $issue->parameters], $issues), 'one issue retains the incomplete language and every missing text identity');
    $presented = \Utsuri\PortableCms\Conventional\Preview\PreviewDiagnostics::present($issues[0], $release);
    assertSameValue('Site wording in Español is incomplete: Home link (The navigation link to the home page.), Search address (The part of the address of the search pages after the language.).', \Utsuri\PortableCms\Conventional\I18n\Translator::english()->message($presented), 'editors read the missing keys and their purposes');
    assertSameValue('address.search', ProfilePreviewCompiler::missingWording($release, $snapshot([], [['key' => 'page.home', 'locale' => 'es', 'text' => 'Inicio']]))[0]->parameters['keys'] ?? null, 'a text the editors wrote completes its key');
    assertSameValue([], ProfilePreviewCompiler::missingWording($release, $snapshot(['es'])), 'a language in preparation never blocks publication');

    // The installed profile is complete in every language, so its previews pass the check.
    (new ProfilePreviewCompiler($package, sys_get_temp_dir(), dirname(__DIR__, 2) . '/schemas'))->checkRequired($snapshot([]));
};

$tests['site wording travels in the normalized export and is read strictly on restore'] = static function (): void {
    $profile = siteLanguagesProfile();
    $wording = SiteWording::of($profile, 4, ['page.projects' => ['es' => 'Obras', 'en' => 'Selected work'], 'address.about' => ['ca' => 'estudi']]);
    assertSameValue(['formatVersion' => 1, 'profileId' => 'reference-studio', 'revision' => 4, 'values' => [
        ['key' => 'page.projects', 'locale' => 'es', 'text' => 'Obras'],
        ['key' => 'page.projects', 'locale' => 'en', 'text' => 'Selected work'],
        ['key' => 'address.about', 'locale' => 'ca', 'text' => 'estudi'],
    ]], $wording->document(), 'the portable document lists texts in profile order');
    assertSameValue($wording->document(), SiteWording::from($wording->document(), $profile)->document(), 'the document round-trips');
    $export = ['formatVersion' => 1, 'exportId' => str_repeat('a', 32), 'createdAt' => '2026-10-16T12:00:00Z',
        'profile' => ['id' => $profile->id, 'version' => $profile->version, 'locales' => $profile->locales],
        'records' => [], 'revisions' => [], 'relationships' => [], 'media' => [], 'siteWording' => $wording->document()];
    assertSameValue('Obras', (new NormalizedExport($export))->document['siteWording']['values'][0]['text'], 'the wording is part of the export');
    foreach ([['profileId' => 'other-profile'], ['values' => [['key' => 'Page', 'locale' => 'es', 'text' => 'X']]], ['values' => [['key' => 'page.home', 'locale' => 'es', 'text' => '']]]] as $change) {
        siteLanguageRejected(static fn() => new NormalizedExport([...$export, 'siteWording' => [...$wording->document(), ...$change]]), 'an export refuses malformed wording');
    }
    foreach ([['key' => 'page.unknown', 'locale' => 'es', 'text' => 'X'], ['key' => 'page.home', 'locale' => 'fr', 'text' => 'Accueil'], ['key' => 'address.about', 'locale' => 'ca', 'text' => 'Estudi']] as $entry) {
        siteLanguageRejected(static fn() => SiteWording::from([...$wording->document(), 'values' => [$entry]], $profile), 'restore refuses wording the profile does not declare or accept: ' . $entry['key']);
        assertSameValue([], SiteWording::stored([...$wording->document(), 'values' => [$entry]], $profile)->values, 'a stored document keeps only what the installed profile accepts');
    }
};

$tests['profile releases that change wording declarations or defaults are presentation changes'] = static function (): void {
    $old = siteWordingManifestDocument();
    $new = $old;
    $index = array_search('page.projects', array_column($new['wording'], 'key'), true);
    $new['wording'][$index]['defaults']['en'] = 'Projects';
    $new['wording'][$index]['label'] = 'Projects page title';
    $new['wording'][] = ['key' => 'page.contact', 'label' => 'Contact title', 'purpose' => 'The title of the contact page.', 'kind' => 'text', 'maximumLength' => 40, 'defaults' => ['ca' => 'Contacte', 'es' => 'Contacto', 'en' => 'Contact']];
    $changes = ProfileManifestChanges::classify($old, $new);
    assertSameValue([['wording'], ['wording'], []], [$changes->manifestKeys, $changes->neutral, $changes->affecting], 'wording needs no content migration');
    $new['wording'][$index]['secret'] = true;
    assertSameValue('wording', ProfileManifestChanges::classify($old, $new)->affecting[0]['path'] ?? null, 'an unknown wording property fails closed');
};
