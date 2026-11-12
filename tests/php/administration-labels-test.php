<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Content\AdministrationLabels;
use Utsuri\PortableCms\Conventional\Content\EditorialTerminology;
use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Content\ProfilePackage;
use Utsuri\PortableCms\Conventional\Content\RecordTypeDefinition;
use Utsuri\PortableCms\Conventional\Http\Request;
use Utsuri\PortableCms\Conventional\I18n\Translator;
use Utsuri\PortableCms\Conventional\Identity\IdentityService;
use Utsuri\PortableCms\Conventional\View\AdminChrome;
use Utsuri\PortableCms\Conventional\View\Pages;
use Utsuri\PortableCms\Conventional\View\ThemePreference;

function referenceStudioPackage(): ProfilePackage
{
    return ProfilePackage::load(dirname(__DIR__, 2) . '/profiles/reference-studio');
}

function presentedType(ProfileManifest $profile, string $id): RecordTypeDefinition
{
    return $profile->type($id) ?? throw new RuntimeException('type missing: ' . $id);
}

function presentedNavigation(ProfileManifest $profile, string $id): string
{
    foreach ($profile->navigation as $item) {
        if ($item->id === $id) {
            return $item->label;
        }
    }
    throw new RuntimeException('navigation missing: ' . $id);
}

/**
 * @param array<string, mixed> $languages
 * @return array<string, mixed>
 */
function labelsDocument(array $languages, string $profileId = 'reference-studio'): array
{
    return ['formatVersion' => 1, 'profileId' => $profileId, 'languages' => $languages];
}

$tests['profile administration labels follow the interface language without changing identities'] = static function (): void {
    $package = referenceStudioPackage();
    $profile = $package->manifest;
    $labels = $package->administrationLabels;
    assertSameValue(['ca', 'es', 'en', 'fr', 'de'], $labels->languages(), 'Reference Studio ships five interface languages');
    $none = new EditorialTerminology($profile->id, 0, []);
    $german = $none->present($profile, 'de', $labels);
    $project = presentedType($german, 'project');
    assertSameValue('Projekte', $project->label, 'type names follow the interface language');
    assertSameValue('Projekt', $project->singularLabel, 'singular names follow the interface language');
    $fields = [];
    foreach ($project->fields as $field) {
        $fields[$field->id] = $field;
    }
    assertSameValue('Titel', $fields['title']->label, 'field labels follow the interface language');
    assertSameValue('Abgeschlossen', $fields['status']->settings['options'][0]['label'], 'enum options follow the interface language');
    assertSameValue('complete', $fields['status']->settings['options'][0]['value'], 'enum values never change');
    assertSameValue('Rolle', $fields['credits']->fields[0]->label, 'nested group fields follow the interface language');
    assertSameValue('Rolle', $fields['credits']->settings['fields'][0]['label'], 'nested field settings follow the interface language');
    assertSameValue('Projekte', presentedNavigation($german, 'projects'), 'navigation follows the interface language');
    assertSameValue('Vorschauen', presentedNavigation($german, 'previews'), 'system navigation follows the interface language');
    foreach ($profile->types as $index => $type) {
        $presented = $german->types[$index];
        assertSameValue([$type->id, $type->kind, $type->archetype, $type->renderer, $type->route], [$presented->id, $presented->kind, $presented->archetype, $presented->renderer, $presented->route], 'type identities never change: ' . $type->id);
        assertSameValue(array_map(static fn($field) => $field->id, $type->fields), array_map(static fn($field) => $field->id, $presented->fields), 'field identities never change: ' . $type->id);
    }
    assertSameValue([$profile->id, $profile->version, $profile->locales, $profile->defaultLocale], [$german->id, $german->version, $german->locales, $german->defaultLocale], 'profile identity never changes');
    assertSameValue('Projects', presentedType($none->present($profile), 'project')->label, 'without a language the profile default remains');
    assertSameValue('Projects', presentedType($none->present($profile, 'de'), 'project')->label, 'without profile labels the default remains');
    assertSameValue('Title', $none->present($profile, 'de')->type('project')?->fields[0]->label, 'fields fall back to the profile default');

    $partial = AdministrationLabels::fromDocument(labelsDocument(['fr' => ['fields' => ['project.title' => 'Intitulé']]]), $profile);
    $french = $none->present($profile, 'fr', $partial);
    assertSameValue('Intitulé', presentedType($french, 'project')->fields[0]->label, 'a partial file overrides only what it names');
    assertSameValue('Projects', presentedType($french, 'project')->label, 'unnamed types keep the profile default');
    assertSameValue('Slug', presentedType($french, 'project')->fields[1]->label, 'unnamed fields keep the profile default');
};

$tests['installation names win over profile labels in the documented order'] = static function (): void {
    $package = referenceStudioPackage();
    $profile = $package->manifest;
    $labels = $package->administrationLabels;
    $defaults = [];
    foreach ($profile->types as $type) {
        $defaults[] = ['typeId' => $type->id, 'singular' => $type->singularLabel, 'plural' => $type->label];
    }
    $unchanged = new EditorialTerminology($profile->id, 1, $defaults);
    assertSameValue('Projekte', presentedType($unchanged->present($profile, 'de', $labels), 'project')->label, 'saved defaults are not a rename and keep the language label');
    $renamed = $defaults;
    $renamed[0] = ['typeId' => 'project', 'singular' => 'Work', 'plural' => 'Works'];
    $installation = new EditorialTerminology($profile->id, 2, $renamed);
    $german = $installation->present($profile, 'de', $labels);
    assertSameValue('Works', presentedType($german, 'project')->label, 'an installation rename wins over profile language labels');
    assertSameValue('Works', presentedNavigation($german, 'projects'), 'navigation shows the installation rename');
    assertSameValue('Artikel', presentedType($german, 'article')->label, 'other types keep their language label');
    $renamed[0]['languages'] = ['de' => ['singular' => 'Werk', 'plural' => 'Werke']];
    $perLanguage = new EditorialTerminology($profile->id, 3, $renamed);
    assertSameValue('Werke', presentedType($perLanguage->present($profile, 'de', $labels), 'project')->label, 'a per-language installation name wins');
    assertSameValue('Werke', presentedNavigation($perLanguage->present($profile, 'de', $labels), 'projects'), 'navigation shows the per-language name');
    assertSameValue('Works', presentedType($perLanguage->present($profile, 'ca', $labels), 'project')->label, 'other languages keep the installation rename');
    assertSameValue('Works', presentedType($perLanguage->present($profile), 'project')->label, 'documents without a language keep the base name');
    assertSameValue(['typeId', 'singular', 'plural'], array_keys($installation->document()['labels'][0]), 'documents without per-language names keep their exact shape');
    assertSameValue($perLanguage->document(), EditorialTerminology::fromDocument(json_decode(json_encode($perLanguage->document(), JSON_THROW_ON_ERROR), true))->document(), 'per-language names round-trip through the export document');
    foreach ([
        ['languages' => []],
        ['languages' => ['it' => ['singular' => 'Opera', 'plural' => 'Opere']]],
        ['languages' => ['de' => ['singular' => '', 'plural' => 'Werke']]],
        ['languages' => ['de' => ['singular' => "Werk\u{0007}", 'plural' => 'Werke']]],
        ['languages' => ['de' => ['singular' => str_repeat('W', 101), 'plural' => 'Werke']]],
        ['languages' => ['de' => ['plural' => 'Werke', 'singular' => 'Werk']]],
    ] as $index => $hostile) {
        try {
            new EditorialTerminology($profile->id, 1, [['typeId' => 'project', 'singular' => 'Work', 'plural' => 'Works'] + $hostile]);
            throw new RuntimeException('hostile per-language names were accepted: ' . $index);
        } catch (InvalidArgumentException) {
        }
    }
};

$tests['profile administration labels reject hostile values and stay outside the package identity'] = static function (): void {
    $package = referenceStudioPackage();
    $profile = $package->manifest;
    $expected = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/profiles/reference-studio/fixtures/expected/artifact.json'), true, 32, JSON_THROW_ON_ERROR);
    assertSameValue($expected['profile']['sha256'], $package->checksum, 'administration labels never change the package checksum or compiler provenance');
    foreach ([
        labelsDocument(['de' => ['fields' => ['project.title' => 'Titel']]], 'another-profile'),
        labelsDocument(['it' => ['fields' => ['project.title' => 'Titolo']]]),
        labelsDocument(['de' => ['fields' => ['project.secret' => 'Geheim']]]),
        labelsDocument(['de' => ['fields' => ['project.title.extra' => 'Titel']]]),
        labelsDocument(['de' => ['options' => ['project.status.unknown' => 'Unbekannt']]]),
        labelsDocument(['de' => ['options' => ['project.title.value' => 'Wert']]]),
        labelsDocument(['de' => ['navigation' => ['unknown' => 'Unbekannt']]]),
        labelsDocument(['de' => ['types' => ['unknown' => ['label' => 'X', 'singularLabel' => 'X']]]]),
        labelsDocument(['de' => ['types' => ['project' => ['label' => 'Projekte']]]]),
        labelsDocument(['de' => ['fields' => ['project.title' => "Titel\u{0000}"]]]),
        labelsDocument(['de' => ['fields' => ['project.title' => "Titel\n"]]]),
        labelsDocument(['de' => ['fields' => ['project.title' => str_repeat('T', 101)]]]),
        labelsDocument(['de' => ['fields' => ['project.title' => '   ']]]),
        labelsDocument(['de' => ['fields' => ['project.title' => ['Titel']]]]),
        ['formatVersion' => 2, 'profileId' => 'reference-studio', 'languages' => []],
    ] as $index => $hostile) {
        try {
            AdministrationLabels::fromDocument($hostile, $profile);
            throw new RuntimeException('hostile administration labels were accepted: ' . $index);
        } catch (InvalidArgumentException) {
        }
    }
    $root = sys_get_temp_dir() . '/portable-cms-labels-' . bin2hex(random_bytes(6));
    mkdir($root, 0700);
    try {
        assertSameValue([], AdministrationLabels::load($root, $profile)->languages(), 'a profile without labels keeps its defaults');
        file_put_contents($root . '/' . AdministrationLabels::FILE, str_repeat(' ', 262_145));
        try {
            AdministrationLabels::load($root, $profile);
            throw new RuntimeException('an oversized labels file was accepted');
        } catch (InvalidArgumentException) {
        }
        unlink($root . '/' . AdministrationLabels::FILE);
        file_put_contents($root . '/target.json', json_encode(labelsDocument(['de' => ['fields' => ['project.title' => 'Titel']]])));
        symlink($root . '/target.json', $root . '/' . AdministrationLabels::FILE);
        try {
            AdministrationLabels::load($root, $profile);
            throw new RuntimeException('a symlinked labels file was accepted');
        } catch (InvalidArgumentException) {
        }
        unlink($root . '/' . AdministrationLabels::FILE);
        file_put_contents($root . '/' . AdministrationLabels::FILE, '{"formatVersion":1,"profileId":"reference-studio","languages":{"de":{"script":"x"}}}');
        try {
            AdministrationLabels::load($root, $profile);
            throw new RuntimeException('an unknown labels property was accepted');
        } catch (Throwable $error) {
            assertTrueValue(!$error instanceof RuntimeException || $error->getMessage() !== 'an unknown labels property was accepted', 'unknown properties fail closed');
        }
    } finally {
        foreach (glob($root . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($root);
    }

    $hostile = AdministrationLabels::fromDocument(labelsDocument(['de' => [
        'types' => ['project' => ['label' => '<script>alert(1)</script>', 'singularLabel' => '<b>Projekt</b>']],
        'fields' => ['project.title' => '"><img src=x>'],
        'navigation' => ['projects' => '<script>nav</script>'],
    ]]), $profile);
    $presented = (new EditorialTerminology($profile->id, 0, []))->present($profile, 'de', $hostile);
    $chrome = new AdminChrome(str_repeat('c', 32), 'Nora', ThemePreference::System, '/records/project', [], new DateTimeImmutable('2026-10-03T12:00:00Z'), [], Translator::load('de'), 'de');
    $project = presentedType($presented, 'project');
    $html = Pages::recordForm(str_repeat('b', 32), $presented, $project, [], null, chrome: $chrome);
    assertTrueValue(!str_contains($html, '<script>alert(1)</script>') && !str_contains($html, '<script>nav</script>') && !str_contains($html, '<img src=x>'), 'profile labels are never markup');
    assertTrueValue(str_contains($html, '&lt;script&gt;nav&lt;/script&gt;') && str_contains($html, '&quot;&gt;&lt;img src=x&gt;'), 'profile labels are escaped text');
};

$tests['content names accept per-language overrides with revision conflicts'] = static function (): void {
    $package = referenceStudioPackage();
    $profile = $package->manifest;
    $repository = new MemoryTerminologyRepository();
    $app = contentTestApplication(terminologyRepository: $repository, profileOverride: $profile, administrationLabels: $package->administrationLabels)['app'];
    $cookies = authenticatedTestCookies($app);
    $page = $app->handle(new Request('GET', '/settings/terminology', ['accept-language' => 'de'], cookies: $cookies));
    assertTrueValue(str_starts_with($page->body, '<!doctype html><html lang="de">'), 'Content names follows the interface language');
    assertTrueValue(str_contains($page->body, 'name="project__plural" required maxlength="100" value="Projects"'), 'base names edit the profile defaults');
    assertTrueValue(str_contains($page->body, 'name="project__plural__de" lang="de" maxlength="100" value="" placeholder="Projekte"'), 'per-language inputs show the profile label as a hint');
    assertTrueValue(str_contains($page->body, '<legend lang="ca">Català</legend>'), 'languages are named in themselves');
    assertTrueValue(str_contains($page->body, '>Projekte</span>'), 'the shell navigation follows the interface language');
    $form = ['_csrf' => $cookies[IdentityService::CSRF_COOKIE], '_revision' => '0'];
    foreach ($profile->types as $type) {
        $form[$type->id . '__singular'] = $type->singularLabel;
        $form[$type->id . '__plural'] = $type->label;
    }
    $half = $app->handle(new Request('POST', '/settings/terminology', contentMutationHeaders(), [...$form, 'project__plural__de' => '<Werke>'], $cookies));
    assertSameValue(422, $half->status, 'a single per-language name is rejected');
    assertTrueValue(str_contains($half->body, 'value="&lt;Werke&gt;"') && str_contains($half->body, 'id="error-project__singular__de"'), 'the form keeps and escapes the input and names the missing value');
    assertTrueValue(str_contains($half->body, '<details class="admin-names__languages" open>'), 'per-language names open when they need attention');
    $hostile = $app->handle(new Request('POST', '/settings/terminology', contentMutationHeaders(), [...$form, 'project__singular__de' => "Werk\u{0007}", 'project__plural__de' => 'Werke'], $cookies));
    assertSameValue(422, $hostile->status, 'control characters are rejected');
    assertSameValue(null, $repository->current, 'rejected names are not saved');
    $saved = $app->handle(new Request('POST', '/settings/terminology', contentMutationHeaders(), [...$form, 'project__singular__de' => 'Werk', 'project__plural__de' => 'Werke', 'project__singular__it' => 'Opera'], $cookies));
    assertSameValue(303, $saved->status, 'valid per-language names save');
    assertSameValue(['de' => ['singular' => 'Werk', 'plural' => 'Werke']], $repository->current?->labels[0]['languages'] ?? null, 'only supported languages are stored');
    $german = $app->handle(new Request('GET', '/', ['accept-language' => 'de'], cookies: $cookies));
    assertTrueValue(str_contains($german->body, '>Werke</span>'), 'the per-language name reaches the navigation');
    $spanish = $app->handle(new Request('GET', '/', ['accept-language' => 'es'], cookies: $cookies));
    assertTrueValue(str_contains($spanish->body, '>Proyectos</span>'), 'other languages keep the profile label');
    $conflict = $app->handle(new Request('POST', '/settings/terminology', contentMutationHeaders(), [...$form, 'project__singular__fr' => 'Œuvre', 'project__plural__fr' => '<Œuvres>'], $cookies));
    assertSameValue(409, $conflict->status, 'stale per-language names never overwrite newer names');
    assertTrueValue(str_contains($conflict->body, 'value="&lt;Œuvres&gt;"'), 'conflicts preserve and escape per-language input');
    assertSameValue(1, $repository->current?->revision, 'conflicts leave the saved revision alone');
};
