<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Compiler\ProfileBuildFailure;
use Utsuri\PortableCms\Conventional\Compiler\ProfileBuildReason;
use Utsuri\PortableCms\Conventional\Content\AdministrationLabels;
use Utsuri\PortableCms\Conventional\Content\EditorialTerminology;
use Utsuri\PortableCms\Conventional\Content\SiteWording;
use Utsuri\PortableCms\Conventional\Content\SiteWordingHttp;
use Utsuri\PortableCms\Conventional\Http\Request;
use Utsuri\PortableCms\Conventional\I18n\Message;
use Utsuri\PortableCms\Conventional\I18n\Translator;
use Utsuri\PortableCms\Conventional\Identity\IdentityService;
use Utsuri\PortableCms\Conventional\JsonSchemaValidator;
use Utsuri\PortableCms\Conventional\Preview\PreviewDiagnostics;
use Utsuri\PortableCms\Conventional\Preview\PreviewPreflightFailure;
use Utsuri\PortableCms\Conventional\Preview\PreviewSnapshot;
use Utsuri\PortableCms\Conventional\View\AdminChrome;
use Utsuri\PortableCms\Conventional\View\PreviewDiagnosticList;

$tests['wording administration labels cover every reference key and preserve public declarations with fallback'] = static function (): void {
    $package = referenceStudioPackage();
    $profile = $package->manifest;
    $document = json_decode((string) file_get_contents($package->root . '/administration-labels.json'), true, flags: JSON_THROW_ON_ERROR);
    $terminology = new EditorialTerminology($profile->id, 0, []);
    assertSameValue(28, count($profile->wording), 'the reference has 28 wording keys');
    foreach (['ca', 'es', 'en', 'fr', 'de'] as $language) {
        assertSameValue(array_column($profile->wording, 'key'), array_keys($document['languages'][$language]['wording']), 'every wording key has a label and purpose in ' . $language);
        $presented = $terminology->present($profile, $language, $package->administrationLabels);
        foreach ($profile->wording as $definition) {
            $names = $document['languages'][$language]['wording'][$definition->key];
            $translated = $presented->wording($definition->key);
            assertSameValue([$names['label'], $names['purpose']], [$translated?->label, $translated?->purpose], 'both names follow the interface language');
            assertSameValue([$definition->key, $definition->kind, $definition->maximumLength, $definition->defaults, $definition->uniqueIn], [$translated?->key, $translated?->kind, $translated?->maximumLength, $translated?->defaults, $translated?->uniqueIn], 'wording identity, defaults and validation are unchanged');
        }
        assertSameValue(SiteWording::defaults($profile)->entries($profile, $profile->locales), SiteWording::defaults($presented)->entries($presented, $presented->locales), 'administration labels never enter frozen wording');
    }
    $partial = AdministrationLabels::fromDocument(labelsDocument(['ca' => ['wording' => ['page.home' => ['label' => 'Inici', 'purpose' => 'Enllaç a la portada.']]]]), $profile);
    assertSameValue('Inici', $terminology->present($profile, 'ca', $partial)->wording('page.home')?->label, 'a partial translation names its key');
    foreach ([$terminology->present($profile, 'de', $partial), $terminology->present($profile, 'ca', AdministrationLabels::none()), $terminology->present($profile, null, $partial)] as $fallback) {
        assertSameValue($profile->wording, $fallback->wording, 'an absent file, language or language choice retains manifest names and purposes');
    }
    assertSameValue($profile->wording('metadata.year'), $terminology->present($profile, 'ca', $partial)->wording('metadata.year'), 'an untranslated key retains its full declaration');
    assertSameValue('Year label', $profile->wording('metadata.year')?->label, 'the canonical manifest is never mutated');
};

$tests['wording administration labels refuse undeclared identities and invalid bounded shapes'] = static function (): void {
    $profile = referenceStudioPackage()->manifest;
    $valid = ['label' => 'Any', 'purpose' => 'L’any del projecte.'];
    foreach (['missing', 'project.title', 'page.unknown', '../wording'] as $unknown) {
        try {
            AdministrationLabels::fromDocument(labelsDocument(['ca' => ['wording' => [$unknown => $valid]]]), $profile);
            throw new RuntimeException('An undeclared wording key was accepted: ' . $unknown);
        } catch (InvalidArgumentException) {
        }
    }
    foreach ([null, 'Any', ['label' => 'Any'], ['purpose' => 'Any'], [...$valid, 'default' => '2026'],
        ['label' => str_repeat('a', 101), 'purpose' => 'Any'], ['label' => ' ', 'purpose' => 'Any'],
        ['label' => "Any\n", 'purpose' => 'Any'], ['label' => 'Any', 'purpose' => "Any\n"],
        ['label' => 'Any', 'purpose' => str_repeat('à', 301)], ['label' => 'Any', 'purpose' => '   '],
    ] as $invalid) {
        $document = labelsDocument(['ca' => ['wording' => ['metadata.year' => $invalid]]]);
        try {
            AdministrationLabels::fromDocument($document, $profile);
            throw new RuntimeException('Invalid wording labels passed semantic validation');
        } catch (InvalidArgumentException) {
        }
        try {
            JsonSchemaValidator::validateJsonWithSchemaFile(json_encode($document, JSON_THROW_ON_ERROR), dirname(__DIR__, 2) . '/schemas/portable-cms-administration-labels-v1.schema.json');
            throw new RuntimeException('Invalid wording labels passed schema validation');
        } catch (InvalidArgumentException) {
        }
    }
};

$tests['wording page and validation summary use interface names and escape labels and purposes'] = static function (): void {
    $package = referenceStudioPackage();
    $profile = (new EditorialTerminology($package->manifest->id, 0, []))->present($package->manifest, 'ca', $package->administrationLabels);
    $http = new SiteWordingHttp(siteWordingService(new MemorySiteWording()), $profile, new AdminChrome(str_repeat('c', 32), 'Editor', translator: Translator::load('ca')));
    $page = $http->handle(new Request('GET', '/settings/wording'), siteLanguagesActor('editor'));
    assertTrueValue(str_contains($page->body, '<legend>Etiqueta de l’any</legend>') && str_contains($page->body, 'L’etiqueta de l’any d’un projecte.'), 'both label and purpose use the Catalan interface');
    assertTrueValue(str_contains($page->body, 'lang="en" maxlength="40" value="Year"'), 'English website text stays English in a Catalan interface');
    $invalid = $http->handle(new Request('POST', '/settings/wording', form: ['_revision' => '0', SiteWording::field('address.about', 'ca') => 'Invalid']), siteLanguagesActor('editor'));
    assertSameValue(422, $invalid->status, 'invalid wording is refused');
    assertTrueValue(str_contains($invalid->body, 'href="#wording__address-about__ca">Adreça de l’estudi, Català:'), 'the error summary names the translated key and its site language');

    $hostile = AdministrationLabels::fromDocument(labelsDocument(['ca' => ['wording' => ['address.about' => ['label' => '<b>Estudi</b>', 'purpose' => '<img src=x>']]]]), $package->manifest);
    $presented = (new EditorialTerminology($profile->id, 0, []))->present($package->manifest, 'ca', $hostile);
    $html = \Utsuri\PortableCms\Conventional\View\SiteWordingPage::render($presented, new AdminChrome('csrf', 'Editor'), SiteWording::defaults($profile));
    assertTrueValue(str_contains($html, '&lt;b&gt;Estudi&lt;/b&gt;') && str_contains($html, '&lt;img src=x&gt;') && !str_contains($html, '<img src=x>'), 'profile labels and purposes are escaped text');
};

$tests['saved missing-wording diagnostics resolve names per reader with legacy fallback'] = static function (): void {
    $package = referenceStudioPackage();
    $history = new MemoryPreviewChecks();
    ['app' => $app, 'previewRepository' => $previews] = contentTestApplication(withPreviews: true, profileOverride: $package->manifest, administrationLabels: $package->administrationLabels, previewChecks: $history);
    $snapshot = new PreviewSnapshot($package->manifest->id, $package->manifest->version, $package->manifest->locales, [], new DateTimeImmutable('2026-10-20T09:00:00Z'));
    $issue = PreviewDiagnostics::cause(new ProfileBuildFailure(ProfileBuildReason::MissingWording, fieldId: 'metadata.year', locale: 'es'), $snapshot, $package->manifest);
    assertSameValue('metadata.year', $issue->parameters['keys'], 'durable diagnostics retain the wording identity');
    $previews->preflight = static function () use ($issue): void {
        throw new PreviewPreflightFailure([$issue]);
    };
    $cookies = authenticatedTestCookies($app);
    $response = $app->handle(new Request('POST', '/previews', [...contentMutationHeaders(), 'accept-language' => 'ca'], ['_csrf' => $cookies[IdentityService::CSRF_COOKIE]], $cookies));
    assertSameValue(422, $response->status, 'the missing wording blocks preflight');
    assertTrueValue(str_contains($response->body, 'Etiqueta de l’any (L’etiqueta de l’any d’un projecte.)'), 'the retry page explains the key in the current administration language');
    foreach (['/' => 'Beschriftung des Jahres', '/previews' => 'Beschriftung des Jahres'] as $path => $name) {
        $page = $app->handle(new Request('GET', $path, ['accept-language' => 'de'], cookies: $cookies));
        assertTrueValue(str_contains($page->body, $name) && str_contains($page->body, 'Die Beschriftung des Jahres eines Projekts.'), 'another reader sees the same saved failure in German');
    }
    assertSameValue('metadata.year', $history->check?->issues[0]->parameters['keys'], 'view projection never overwrites the saved identity');
    $diagnostics = PreviewDiagnostics::fromDocument(json_decode(json_encode(new PreviewDiagnostics([$issue]), JSON_THROW_ON_ERROR), true));
    $german = (new EditorialTerminology($package->manifest->id, 0, []))->present($package->manifest, 'de', $package->administrationLabels);
    assertTrueValue(str_contains(PreviewDiagnosticList::render($diagnostics, Translator::load('de'), $german), 'Beschriftung des Jahres'), 'persisted build failures use the same projection');
    $legacy = new Message('preflight.issue.wording', ['language' => 'Español', 'keys' => 'Year label, retired.key']);
    assertSameValue($legacy->parameters, PreviewDiagnostics::present($legacy, $german)->parameters, 'older display-name reports and missing declarations remain readable');
    assertSameValue($issue, PreviewDiagnostics::present($issue, null), 'a missing profile retains a safe stable key');
};
