<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Content\RecordSnapshot;
use Utsuri\PortableCms\Conventional\Http\Request;
use Utsuri\PortableCms\Conventional\I18n\InterfaceLanguage;
use Utsuri\PortableCms\Conventional\I18n\Message;
use Utsuri\PortableCms\Conventional\I18n\Translator;
use Utsuri\PortableCms\Conventional\Identity\IdentityService;
use Utsuri\PortableCms\Conventional\View\AdminChrome;
use Utsuri\PortableCms\Conventional\View\AdminShell;
use Utsuri\PortableCms\Conventional\View\Pages;
use Utsuri\PortableCms\Conventional\View\ThemePreference;

/** @return array<string, string> Same-origin headers for a native form post. */
function interfaceLanguagePostHeaders(string $acceptLanguage = ''): array
{
    return ['origin' => 'https://cms.example.test', 'sec-fetch-site' => 'same-origin'] + ($acceptLanguage === '' ? [] : ['accept-language' => $acceptLanguage]);
}

/**
 * @param array<string, string> $cookies
 * @param array<string, string> $headers
 */
function interfaceLanguageChoose(Utsuri\PortableCms\Conventional\IdentityApplication $app, array $cookies, string $language, array $headers = []): Utsuri\PortableCms\Conventional\Http\Response
{
    return $app->handle(new Request(
        'POST',
        '/settings/language',
        $headers === [] ? interfaceLanguagePostHeaders() : $headers,
        ['_csrf' => $cookies[IdentityService::CSRF_COOKIE] ?? '', 'language' => $language, '_return' => '/records/project'],
        $cookies,
        '192.0.2.1',
    ));
}

$tests['interface language negotiation picks a supported browser language and falls back to English'] = static function (): void {
    foreach ([
        'fr-CH, fr;q=0.9, en;q=0.8, de;q=0.7' => 'fr',
        'it, de;q=0.5' => 'de',
        'it' => 'en',
        '' => 'en',
        'ES' => 'es',
        'en;q=0.5, ca;q=0.9' => 'ca',
        'de, fr' => 'de',
        'fr;q=0.8, de;q=0.8' => 'fr',
        'de;q=0, es;q=0.1' => 'es',
        'de;q=0' => 'en',
        '*' => 'en',
        'ca-ES-valencia' => 'ca',
        '<script>de</script>' => 'en',
        'de;q=2' => 'en',
        'de;q=abc, fr' => 'fr',
        "de\r\nX-Injected: 1" => 'en',
    ] as $header => $expected) {
        assertSameValue($expected, InterfaceLanguage::negotiate($header), 'negotiates ' . json_encode($header));
    }
    assertSameValue('en', InterfaceLanguage::negotiate(null), 'a missing header falls back to English');
    assertSameValue('en', InterfaceLanguage::negotiate(str_repeat('de, ', 200)), 'an oversized header is ignored');
    assertSameValue('en', InterfaceLanguage::negotiate(str_repeat('xx, ', 30) . 'de'), 'only a bounded number of ranges is considered');
    assertSameValue('fr', InterfaceLanguage::resolve('fr', 'de'), 'the account choice wins over the browser');
    assertSameValue('de', InterfaceLanguage::resolve(null, 'de'), 'System follows the browser');
    assertSameValue('de', InterfaceLanguage::resolve('it', 'de'), 'an unsupported stored value follows the browser');
    foreach (['system', 'ca', 'es', 'en', 'fr', 'de'] as $choice) {
        assertTrueValue(InterfaceLanguage::choice($choice), $choice . ' is a valid choice');
    }
    foreach (['', 'it', 'DE', 'de-DE', 'System', ' de', str_repeat('d', 10_000), "de\0"] as $hostile) {
        assertTrueValue(!InterfaceLanguage::choice($hostile), 'rejects choice ' . json_encode(substr($hostile, 0, 12)));
    }
};

$tests['every interface catalog has every key with identical parameters and plural categories'] = static function (): void {
    $english = Translator::english()->catalog();
    $placeholders = static function (string $pattern): array {
        preg_match_all('/\{([a-z_]+)[,}]/', $pattern, $matches);
        $names = array_values(array_unique($matches[1]));
        sort($names, SORT_STRING);
        return $names;
    };
    $plurals = static function (string $pattern): array {
        preg_match_all('/\b(zero|one|two|few|many|other|=\d+)\s*\{/', $pattern, $matches);
        $names = array_values(array_unique($matches[1]));
        sort($names, SORT_STRING);
        return $names;
    };
    foreach (InterfaceLanguage::SUPPORTED as $language) {
        $catalog = Translator::load($language)->catalog();
        assertSameValue(array_keys($english), array_keys($catalog), $language . ' should have exactly the English keys in order');
        foreach ($english as $key => $pattern) {
            $translated = $catalog[$key];
            if (str_starts_with($key, 'format.')) {
                $formatter = new IntlDateFormatter($language, IntlDateFormatter::NONE, IntlDateFormatter::NONE, 'UTC', IntlDateFormatter::GREGORIAN, $translated);
                assertTrueValue(is_string($formatter->format(new DateTimeImmutable('2026-10-03T09:05:00Z'))), $language . ' date pattern should format: ' . $key);
                continue;
            }
            assertTrueValue(MessageFormatter::create($language, $translated) !== null, $language . ' pattern should compile: ' . $key);
            assertSameValue($placeholders($pattern), $placeholders($translated), $language . ' parameters should match: ' . $key);
            assertSameValue($plurals($pattern), $plurals($translated), $language . ' plural categories should match: ' . $key);
            assertTrueValue(!str_contains($translated, "'"), $language . ' text avoids ICU-quoting apostrophes: ' . $key);
            assertTrueValue(!str_contains($translated, '—') && !str_contains($translated, '–'), $language . ' text avoids long dashes: ' . $key);
            if (str_starts_with($key, 'client.')) {
                assertTrueValue(preg_match('/\{(?![a-z_]+\})/', $translated) !== 1, $language . ' browser patterns use simple placeholders: ' . $key);
            }
        }
    }
};

$tests['dates relative days plurals and numbers follow the interface language'] = static function (): void {
    $instant = new DateTimeImmutable('2026-10-03T09:05:00Z');
    $now = new DateTimeImmutable('2026-10-03T12:00:00Z');
    $english = Translator::english();
    $german = Translator::load('de');
    assertTrueValue(str_contains(AdminShell::time($instant, $now, $german), '>' . $german->text('time.today', ['time' => '09:05']) . '</time>'), 'relative days use the interface language');
    assertTrueValue(AdminShell::time($instant, $now, $german) !== AdminShell::time($instant, $now, $english), 'relative days are translated');
    assertTrueValue(str_contains($german->date($instant->modify('-1 year'), 'format.date', new DateTimeZone('UTC')), 'Okt'), 'German month names');
    assertSameValue('1.5 MB', $english->text('media.size', ['megabytes' => 1.5]), 'English decimal point');
    assertTrueValue(str_contains($german->text('media.size', ['megabytes' => 1.5]), '1,5'), 'German decimal comma');
    assertTrueValue(str_contains(Translator::load('fr')->text('media.size', ['megabytes' => 1234.5]), "1\u{202F}234,5"), 'French digit grouping');
    foreach (InterfaceLanguage::SUPPORTED as $language) {
        $translator = Translator::load($language);
        assertTrueValue($translator->text('preflight.issues', ['count' => 1]) !== $translator->text('preflight.issues', ['count' => 3]), $language . ' plurals distinguish one from many');
        assertTrueValue(str_contains($translator->message(new Message('validation.required', ['field' => '<Títol>'])), '<Títol>'), $language . ' validation keeps the profile field label');
    }
};

$tests['documents declare the interface language while content controls keep their own'] = static function (): void {
    $profile = ProfileManifest::load(dirname(__DIR__, 2) . '/profiles/reference-studio/profile.json');
    $project = $profile->type('project');
    assertTrueValue($project !== null, 'Reference Studio project type should exist');
    $record = new RecordSnapshot(str_repeat('1', 32), 'project', 'draft', str_repeat('2', 32), 3, ['title' => ['ca' => 'Arxiu', 'es' => 'Archivo', 'en' => '']], new DateTimeImmutable('2026-10-02T08:00:00Z'));
    $german = Translator::load('de');
    $chrome = new AdminChrome(str_repeat('c', 32), 'Nora', ThemePreference::System, '/records/project', [], new DateTimeImmutable('2026-10-03T12:00:00Z'), [], $german, 'de');
    $html = Pages::recordForm(str_repeat('b', 32), $profile, $project, $record->body, $record, chrome: $chrome);
    assertTrueValue(str_starts_with($html, '<!doctype html><html lang="de">'), 'the document declares German');
    assertTrueValue(str_contains($html, $german->html('shell.skip_link')) && !str_contains($html, '>Skip to content<'), 'the shell is German');
    foreach (['ca', 'es', 'en'] as $locale) {
        assertTrueValue(str_contains($html, 'data-locale-panel="' . $locale . '" lang="' . $locale . '"'), $locale . ' content panels keep their language');
    }
    assertTrueValue(str_contains($html, '<option value="de" lang="de" selected>Deutsch</option>'), 'the account menu shows the current choice');
    assertTrueValue(str_contains($html, '<option value="ca" lang="ca">Català</option>'), 'languages are named in themselves');
    assertTrueValue(str_contains($html, 'action="/settings/language"'), 'the account menu offers a native language form');
    assertTrueValue(str_starts_with(AdminShell::withTheme($html, ThemePreference::Dark), '<!doctype html><html lang="de" data-theme="dark">'), 'themes keep the interface language');
    $bundle = json_decode(html_entity_decode((string) preg_replace('/\A.*?data-messages="([^"]*)".*\z/s', '$1', $html), ENT_QUOTES | ENT_HTML5), true);
    assertTrueValue(is_array($bundle) && $bundle['editor.saving'] === $german->text('client.editor.saving'), 'browser islands receive the interface language');
    $english = Pages::recordForm(str_repeat('b', 32), $profile, $project, $record->body, $record, chrome: new AdminChrome(str_repeat('c', 32), 'Nora'));
    assertTrueValue(str_contains($english, '<option value="system" selected>'), 'System is selected without an account choice');
    $error = Pages::error(404, str_repeat('a', 24), Translator::load('ca'));
    assertTrueValue(str_starts_with($error, '<!doctype html><html lang="ca">'), 'focused pages declare their language');
};

$tests['interface language is negotiated before sign-in and stored per account after'] = static function (): void {
    ['app' => $app, 'repository' => $repository, 'diagnostics' => $diagnostics] = identityTestApplication();
    $login = $app->handle(new Request('GET', '/login', ['accept-language' => 'es-ES,es;q=0.9']));
    assertTrueValue(str_starts_with($login->body, '<!doctype html><html lang="es">'), 'the login page follows the browser');
    assertTrueValue(str_contains($login->body, Translator::load('es')->html('login.submit')), 'the login form is Spanish');
    $missing = $app->handle(new Request('GET', '/unknown', ['accept-language' => 'de']));
    assertTrueValue(str_starts_with($missing->body, '<!doctype html><html lang="de">'), 'safe errors follow the browser before sign-in');
    $fallback = $app->handle(new Request('GET', '/login', ['accept-language' => 'it-IT']));
    assertTrueValue(str_starts_with($fallback->body, '<!doctype html><html lang="en">'), 'unsupported browsers get English');

    $cookies = authenticatedTestCookies($app);
    $overview = $app->handle(new Request('GET', '/', ['accept-language' => 'de'], cookies: $cookies));
    assertTrueValue(str_starts_with($overview->body, '<!doctype html><html lang="de">'), 'System follows the browser after sign-in');

    $anonymous = $app->handle(new Request('POST', '/settings/language', interfaceLanguagePostHeaders(), ['language' => 'fr']));
    assertSameValue(403, $anonymous->status, 'anonymous submissions are rejected');
    $preAuth = responseCookies($app->handle(new Request('GET', '/login')));
    assertSameValue(403, interfaceLanguageChoose($app, $preAuth, 'fr')->status, 'pre-auth sessions cannot choose an account language');
    $before = count($diagnostics->rejections);
    $crossOrigin = interfaceLanguageChoose($app, $cookies, 'fr', ['origin' => 'https://evil.example', 'sec-fetch-site' => 'cross-site']);
    assertSameValue(403, $crossOrigin->status, 'cross-origin submissions are rejected');
    assertTrueValue(count($diagnostics->rejections) > $before, 'cross-origin rejection is observed');
    $forged = $app->handle(new Request('POST', '/settings/language', interfaceLanguagePostHeaders(), ['_csrf' => str_repeat('0', 43), 'language' => 'fr'], $cookies, '192.0.2.1'));
    assertSameValue(403, $forged->status, 'a mismatched CSRF token is rejected');
    foreach (['it', 'DE', 'de-DE', '', str_repeat('d', 10_000), '<script>'] as $hostile) {
        assertSameValue(400, interfaceLanguageChoose($app, $cookies, $hostile)->status, 'rejects language ' . json_encode(substr($hostile, 0, 12)));
    }
    assertSameValue(null, $repository->users['11111111111111111111111111111111']['interfaceLanguage'] ?? null, 'rejected submissions store nothing');

    $chosen = interfaceLanguageChoose($app, $cookies, 'fr');
    assertSameValue(303, $chosen->status, 'a valid choice redirects');
    assertSameValue('/records/project', $chosen->headers()['location'][0], 'the choice returns to the current page');
    assertSameValue('fr', $repository->users['11111111111111111111111111111111']['interfaceLanguage'], 'the choice is stored on the account');
    assertSameValue('interface_language_changed', end($repository->audit)['eventType'], 'the change is audited');
    $french = $app->handle(new Request('GET', '/', ['accept-language' => 'de'], cookies: $cookies));
    assertTrueValue(str_starts_with($french->body, '<!doctype html><html lang="fr">'), 'the account choice wins over the browser');
    assertTrueValue(str_contains($french->body, '<option value="fr" lang="fr" selected>Français</option>'), 'the menu reflects the stored choice');
    $themed = $app->handle(new Request('GET', '/', [], cookies: $cookies + [ThemePreference::COOKIE => 'dark']));
    assertTrueValue(str_starts_with($themed->body, '<!doctype html><html lang="fr" data-theme="dark">'), 'theme and language combine');
    $missingSignedIn = $app->handle(new Request('GET', '/unknown', ['accept-language' => 'de'], cookies: $cookies));
    assertSameValue(404, $missingSignedIn->status, 'unknown routes stay unknown');

    $hostileReturn = $app->handle(new Request('POST', '/settings/language', interfaceLanguagePostHeaders(), ['_csrf' => $cookies[IdentityService::CSRF_COOKIE], 'language' => 'system', '_return' => '//evil.example'], $cookies, '192.0.2.1'));
    assertSameValue('/', $hostileReturn->headers()['location'][0], 'returns stay same-origin');
    assertSameValue(null, $repository->users['11111111111111111111111111111111']['interfaceLanguage'], 'System clears the stored choice');
    $system = $app->handle(new Request('GET', '/', ['accept-language' => 'ca'], cookies: $cookies));
    assertTrueValue(str_starts_with($system->body, '<!doctype html><html lang="ca">'), 'System returns to browser negotiation');
};
