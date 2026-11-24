<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Compiler\DiscoveryRoute;
use Utsuri\PortableCms\Conventional\Compiler\LlmsTxt;
use Utsuri\PortableCms\Conventional\Compiler\PublicUrlContext;
use Utsuri\PortableCms\Conventional\Compiler\RouteDiscovery;
use Utsuri\PortableCms\Conventional\Content\LanguageText;
use Utsuri\PortableCms\Conventional\Content\LlmsTxtSettings;
use Utsuri\PortableCms\Conventional\Content\ProfilePackage;
use Utsuri\PortableCms\Conventional\Content\SiteLanguages;
use Utsuri\PortableCms\Conventional\Content\WebsiteIdentity;
use Utsuri\PortableCms\Conventional\Http\Request;
use Utsuri\PortableCms\Conventional\Publication\PublicationFailure;
use Utsuri\PortableCms\Conventional\Publication\WebsitePublication;
use Utsuri\PortableCms\Conventional\Publication\WebsitePublicationHttp;
use Utsuri\PortableCms\Conventional\Publication\WebsitePublicationService;

function llmsTxtDiscovery(?PublicUrlContext $context): RouteDiscovery
{
    return new RouteDiscovery($context, [
        new DiscoveryRoute('/', 'en', 'home'),
        new DiscoveryRoute('/ca/', 'ca', 'home'),
        new DiscoveryRoute('/films/', 'en', 'films'),
        new DiscoveryRoute('/hidden/', 'en', 'hidden', false),
    ]);
}

/** @return array<string, string> */
function llmsTxtPages(): array
{
    $page = static fn(string $title, string $description): string => '<!doctype html><html><head><title>' . $title . '</title><meta name="description" content="' . $description . '"></head><body></body></html>';
    return [
        '/' => $page('Studio [home]', 'Films &amp; series
from Barcelona.'),
        '/ca/' => $page('Estudi', 'Pel·lícules i sèries de Barcelona.'),
        '/films/' => '<!doctype html><html><head><title>Films</title></head><body></body></html>',
        '/hidden/' => $page('Hidden', 'Not listed.'),
    ];
}

$tests['llms.txt settings keep plain introductions per language and only the languages asked for'] = static function (): void {
    $settings = LlmsTxtSettings::from(['enabled' => true, 'introductions' => [['locale' => 'en', 'text' => "One line.\nAnother.\tTabbed."], ['locale' => 'ca', 'text' => 'Una línia.']]]);
    assertSameValue(['enabled' => true, 'introductions' => [['locale' => 'en', 'text' => "One line.\nAnother.\tTabbed."], ['locale' => 'ca', 'text' => 'Una línia.']]], $settings->document(), 'the document round-trips in order');
    assertSameValue(['ca' => 'Una línia.'], $settings->only(['ca', 'es'])->introductions, 'only keeps the languages asked for, in their order');
    assertSameValue('First line.' . "\n" . 'Second.', LlmsTxtSettings::normalize("  First line.\r\nSecond.\r\n  "), 'line breaks are unified and outer space removed');
    assertTrueValue((new LlmsTxtSettings())->isDefault() && !(new LlmsTxtSettings(false))->isDefault(), 'on without introductions is the default');
    foreach ([str_repeat('a', LlmsTxtSettings::INTRODUCTION_LENGTH + 1), "Bell\x07", ''] as $text) {
        try {
            new LlmsTxtSettings(true, ['en' => $text]);
            throw new RuntimeException('Invalid introduction accepted');
        } catch (InvalidArgumentException) {
        }
    }
    try {
        LlmsTxtSettings::from(['enabled' => 'yes', 'introductions' => []]);
        throw new RuntimeException('Invalid switch accepted');
    } catch (InvalidArgumentException) {
    }
};

$tests['Website settings carry the llms.txt choice into the website identity and drop introductions with their language'] = static function (): void {
    $defaults = WebsitePublication::defaults('test-studio', ['en', 'ca']);
    assertTrueValue(!array_key_exists('llmsTxt', $defaults->document()), 'a document that never chose keeps its old shape');
    assertTrueValue($defaults->llmsTxt()->enabled && $defaults->identity()->llmsTxt?->enabled === true, 'llms.txt is on unless turned off, and builds receive it');
    $document = [...$defaults->document(), 'llmsTxt' => ['enabled' => false, 'introductions' => [['locale' => 'en', 'text' => 'Hello.'], ['locale' => 'de', 'text' => 'Hallo.']]]];
    $saved = WebsitePublication::from($document);
    assertSameValue(['enabled' => false, 'introductions' => [['locale' => 'en', 'text' => 'Hello.']]], $saved->document()['llmsTxt'], 'an introduction for a language the website does not have is dropped');
    assertSameValue(['enabled' => false, 'introductions' => [['locale' => 'en', 'text' => 'Hello.']]], WebsiteIdentity::from($saved->identity()->document())->llmsTxt?->document(), 'the frozen identity round-trips');
    assertSameValue([], $saved->within(['ca'])->llmsTxt()->introductions, 'a language leaving the installation takes its introduction');
    assertSameValue(false, WebsitePublication::from([...$defaults->document(), 'llmsTxt' => ['enabled' => true, 'introductions' => []]])->document()['llmsTxt'] ?? false, 'choosing the default stores nothing extra');
    try {
        WebsitePublication::from([...$defaults->document(), 'llmsTxt' => ['enabled' => true, 'introductions' => [['locale' => 'en', 'text' => str_repeat('x', 1501)]]]]);
        throw new RuntimeException('Long introduction accepted');
    } catch (PublicationFailure $error) {
        assertSameValue('website_publication_invalid', $error->codeName, 'an overlong introduction is refused as invalid settings');
    }
};

$tests['llms.txt names the website, summarises it and lists the sitemap pages per language with their own titles'] = static function (): void {
    $context = PublicUrlContext::from(['formatVersion' => 1, 'baseUrl' => 'https://studio.example']);
    $settings = new LlmsTxtSettings(true, ['en' => "We make films.\nIn Barcelona.", 'ca' => 'Fem pel·lícules.']);
    $text = LlmsTxt::render('Studio', $settings, ['en', 'ca'], llmsTxtDiscovery($context), llmsTxtPages());
    assertSameValue(<<<'TXT'
        # Studio

        > We make films.
        > In Barcelona.

        ## English

        - [Studio \[home\]](https://studio.example/): Films & series from Barcelona.
        - [Films](https://studio.example/films/)

        ## Català

        Fem pel·lícules.

        - [Estudi](https://studio.example/ca/): Pel·lícules i sèries de Barcelona.

        TXT, $text, 'the primary language leads, introductions frame their pages and link text cannot break a link');
    assertTrueValue(!str_contains((string) $text, 'hidden'), 'a page search engines are asked to leave out is not listed');
    $plain = LlmsTxt::render('Studio', new LlmsTxtSettings(), ['ca', 'en'], llmsTxtDiscovery($context), llmsTxtPages());
    assertTrueValue(str_starts_with((string) $plain, "# Studio\n\n## Català\n"), 'without introductions the file starts with the pages of the primary language');
    assertSameValue(null, LlmsTxt::render('Studio', new LlmsTxtSettings(false), ['en'], llmsTxtDiscovery($context), llmsTxtPages()), 'turned off, no file is written');
    assertSameValue(null, LlmsTxt::render('Studio', new LlmsTxtSettings(), ['en'], llmsTxtDiscovery(null), llmsTxtPages()), 'without a public address there are no links to give');
};

$tests['the reference website publishes its llms.txt with the files it already had'] = static function (): void {
    $artifact = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/profiles/reference-studio/fixtures/expected/artifact.json'), true, 64, JSON_THROW_ON_ERROR);
    $files = array_column($artifact['files'], null, 'path');
    assertSameValue('text/plain; charset=utf-8', $files['llms.txt']['mime'] ?? null, 'llms.txt is served as plain text beside robots.txt');
    assertSameValue('route', $files['llms.txt']['cachePolicy'], 'it is revalidated like the sitemap');
};

$tests['Website settings save with a language off and keep that language\'s texts and introduction'] = static function (): void {
    $profile = ProfilePackage::load(dirname(__DIR__, 2) . '/profiles/reference-studio')->manifest;
    $languages = SiteLanguages::from(['formatVersion' => 1, 'profileId' => $profile->id, 'revision' => 1, 'primary' => 'ca',
        'languages' => [['locale' => 'ca', 'state' => 'public'], ['locale' => 'es', 'state' => 'off'], ['locale' => 'en', 'state' => 'public']]], $profile);
    $initial = WebsitePublication::from([...WebsitePublication::defaults($profile->id, $profile->locales)->document(),
        'llmsTxt' => ['enabled' => true, 'introductions' => [['locale' => 'es', 'text' => 'Texto guardado.']]]]);
    $repository = new MemoryWebsitePublication($initial);
    $service = new WebsitePublicationService($repository, $profile, new IdentityTestClock(new DateTimeImmutable('2026-11-24T10:00:00Z')), new IdentityTestRandom());
    $chrome = new \Utsuri\PortableCms\Conventional\View\AdminChrome('csrf', 'Admin');
    $http = new WebsitePublicationHttp($service, $languages->editingProfile($profile), $chrome, publicLocales: $languages->public());
    $page = $http->handle(new Request('GET', '/settings/publication'), holdingTestActor())->body;
    assertTrueValue(str_contains($page, 'name="llms_es" value="Texto guardado."') && !str_contains($page, 'id="llms-es"'), 'the language that is off keeps its introduction in a hidden field');
    assertTrueValue(str_contains($page, 'id="llms-ca"') && str_contains($page, 'name="llmsTxt" value="1" checked'), 'public languages get a field and the switch starts on');
    $form = ['_revision' => '0', '_complete' => '1', 'mode' => 'live', 'websiteName' => 'Reference Studio', 'iconMediaId' => '', 'llms_ca' => "  Estudi de Barcelona.\r\n", 'llms_en' => ''];
    foreach (['ca', 'es', 'en'] as $locale) {
        $form['message_' . $locale] = WebsitePublication::defaultMessage($locale);
        $form['contact_' . $locale] = '';
    }
    $response = $http->handle(new Request('POST', '/settings/publication', form: $form), holdingTestActor());
    assertSameValue(303, $response->status, 'saving works while a language is off');
    $saved = $repository->settings;
    assertSameValue('live', $saved->mode(), 'the launch choice is saved');
    assertSameValue(['ca', 'es', 'en'], array_column($saved->document()['translations'], 'locale'), 'every installed language keeps its holding texts');
    assertSameValue(['enabled' => false, 'introductions' => [['locale' => 'ca', 'text' => 'Estudi de Barcelona.'], ['locale' => 'es', 'text' => 'Texto guardado.']]], $saved->document()['llmsTxt'], 'an unticked switch turns llms.txt off, a cleared introduction is removed and the language that is off keeps its own');
};

$tests['deleting a language\'s text also deletes its introduction for AI assistants'] = static function (): void {
    $document = [...WebsitePublication::defaults('test-studio', ['en', 'ca'])->document(), 'llmsTxt' => ['enabled' => true, 'introductions' => [['locale' => 'en', 'text' => 'Hello.'], ['locale' => 'ca', 'text' => 'Hola.']]]];
    assertTrueValue(LanguageText::holding($document, 'ca'), 'an introduction counts as the language\'s own text');
    $without = LanguageText::withoutHolding($document, 'ca');
    assertSameValue([['locale' => 'en', 'text' => 'Hello.']], $without['llmsTxt']['introductions'], 'the language\'s introduction goes with its other texts');
    assertTrueValue(!LanguageText::holding($without, 'ca'), 'nothing of the language remains');
};
