<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Content\WebsiteIdentity;
use Utsuri\PortableCms\Conventional\Http\Request;
use Utsuri\PortableCms\Conventional\I18n\Translator;
use Utsuri\PortableCms\Conventional\IdentityApplication;
use Utsuri\PortableCms\Conventional\Publication\WebsitePublication;
use Utsuri\PortableCms\Conventional\Publication\WebsitePublicationService;
use Utsuri\PortableCms\Conventional\View\AdminChrome;
use Utsuri\PortableCms\Conventional\View\AdminShell;
use Utsuri\PortableCms\Conventional\View\InstallationPage;
use Utsuri\PortableCms\Conventional\View\Pages;

/** @return array{IdentityApplication, MemoryWebsitePublication} */
function installationBrandApplication(string $role): array
{
    $profile = referenceStudioPackage()->manifest;
    $identity = new MemoryIdentityRepository();
    $identity->addUser(str_repeat('a', 32), 'admin@example.test', 'Admin', password_hash('correct horse battery staple', PASSWORD_BCRYPT, ['cost' => 4]), $role);
    $settings = new MemoryWebsitePublication(WebsitePublication::from([...WebsitePublication::defaults($profile->id, $profile->locales)->document(), 'websiteName' => 'Authored <studio>']));
    $clock = new IdentityTestClock(new DateTimeImmutable('2026-10-22T12:00:00Z'));
    $random = new IdentityTestRandom();
    return [new IdentityApplication(
        new \Utsuri\PortableCms\Conventional\Identity\IdentityService($identity, $clock, $random, str_repeat('s', 32)),
        new \Utsuri\PortableCms\Conventional\Http\OriginPolicy('https://cms.example.test'),
        $clock,
        $random,
        profile: $profile,
        websitePublication: new WebsitePublicationService($settings, $profile, $clock, $random),
        installedReleaseId: str_repeat('e', 64),
    ), $settings];
}

$tests['administration uses saved identity with profile fallback and keeps engine failures generic'] = static function (): void {
    $profile = referenceStudioPackage()->manifest;
    $identity = new WebsiteIdentity('Élan & Atelier <Nord>');
    assertSameValue('Reference Studio', AdminShell::installationName($profile), 'before settings the name comes from the profile');
    assertSameValue($identity->name, AdminShell::installationName($profile, $identity), 'the saved name wins');
    assertSameValue('Portable CMS', AdminShell::installationName(null, $identity), 'an unreadable installation keeps the generic identity');
    $chrome = new AdminChrome('csrf', 'Admin', websiteIdentity: $identity);
    $page = Pages::dashboard('csrf', 'Admin', $profile, chrome: $chrome);
    assertSameValue(2, substr_count($page, '>Élan &amp; Atelier &lt;Nord&gt;</span>'), 'both shell brands use the escaped saved name');
    assertSameValue(2, substr_count($page, '>É&amp;</span>'), 'both monograms come from the same saved name');
    assertTrueValue(!str_contains($page, 'Portable CMS · ' . $profile->version), 'a site version is never attributed to the engine');
    $login = Pages::login('csrf', profile: $profile, identity: $identity);
    assertTrueValue(str_contains($login, '<strong>Élan &amp; Atelier &lt;Nord&gt;</strong>'), 'login uses the saved name too');
    assertTrueValue(!str_contains($login, 'Élan & Atelier <Nord>'), 'names cannot become markup');
    $error = Pages::error(503, 'reference');
    assertTrueValue(str_contains($error, '<strong>Portable CMS</strong>') && !str_contains($error, 'Élan'), 'safe errors do not depend on configuration');
};

$tests['site and engine versions have distinct translated labels without public login diagnostics'] = static function (): void {
    $profile = referenceStudioPackage()->manifest;
    foreach (['en' => 'Site', 'ca' => 'Web', 'es' => 'Web', 'fr' => 'Site', 'de' => 'Website'] as $language => $label) {
        $t = Translator::load($language);
        $chrome = new AdminChrome('csrf', 'Admin', translator: $t, websiteIdentity: new WebsiteIdentity('Named studio'));
        $brand = AdminShell::brand($profile, $chrome);
        assertTrueValue(str_contains($brand, '>' . $label . ' ' . $profile->version . '</span>'), 'site version is labelled in ' . $language);
        $page = InstallationPage::render($profile, $chrome, str_repeat('f', 64));
        foreach (['installation.website_name', 'installation.profile_id', 'installation.profile_version', 'installation.engine_version', 'installation.release_id'] as $key) {
            assertTrueValue(str_contains($page, '<dt>' . $t->html($key) . '</dt>'), 'separate translated detail: ' . $key);
        }
        foreach (['Named studio', $profile->id, $profile->version, ProfileManifest::ENGINE_VERSION, str_repeat('f', 64)] as $fact) {
            assertTrueValue(str_contains($page, '<dd>' . $fact . '</dd>'), 'exact installed fact is shown');
        }
        $login = Pages::login('csrf', profile: $profile, translator: $t, identity: $chrome->websiteIdentity);
        assertTrueValue(str_contains($login, '<p class="admin-auth__footer">Portable CMS · ' . $label . ' ' . $profile->version . '</p>'), 'login has only the product and translated site version in small print');
        assertSameValue(1, substr_count($login, '<h1'), 'sign-in has one level-one heading');
        assertTrueValue(str_contains($login, '<h1 class="admin-visually-hidden">' . $t->html('login.title') . '</h1>'), 'the heading remains available to assistive technology');
        assertTrueValue(str_contains($login, '>' . $t->html('login.submit') . '</button>'), 'the native submit button keeps its name');
        assertTrueValue(!str_contains($login, ProfileManifest::ENGINE_VERSION), 'the actual engine version stays off anonymous sign-in');
        assertSameValue(0, preg_match('/\b[a-f0-9]{40}\b/', $login), 'anonymous sign-in contains no source revision');
        assertTrueValue(!str_contains($login, str_repeat('f', 64)) && !str_contains($login, $t->html('installation.engine_version')), 'operational details remain private');
        assertTrueValue(!$t->has('login.footer'), 'retired account help is removed from every catalog');
        $withoutProfile = Pages::login('csrf', translator: $t);
        assertTrueValue(str_contains($withoutProfile, '<p class="admin-auth__footer">Portable CMS</p>'), 'a profile-free sign-in has only the product name in small print');
        assertTrueValue(!str_contains($withoutProfile, ProfileManifest::ENGINE_VERSION), 'profile-free sign-in never shows the engine version');
        $overview = Pages::dashboard('csrf', 'Admin', $profile, chrome: $chrome);
        assertTrueValue(str_contains($overview, '<p class="admin-account__versions">Portable CMS ' . ProfileManifest::ENGINE_VERSION . ' · ' . $label . ' ' . $profile->version . '</p>'), 'the account menu has translated, separately attributed versions');
        assertTrueValue(str_contains(InstallationPage::render($profile, $chrome, null), $t->html('installation.development')), 'development does not invent an installed release');
    }
    assertTrueValue(str_contains(InstallationPage::render($profile, new AdminChrome('csrf', 'Admin'), '<hostile>'), '&lt;hostile&gt;'), 'release identities remain escaped');
};

$tests['installation settings are private read only and owner administrator only while branding follows saved changes'] = static function (): void {
    foreach (['owner', 'administrator', 'editor'] as $role) {
        [$app, $settings] = installationBrandApplication($role);
        $guest = $app->handle(new Request('GET', '/settings/installation'));
        assertSameValue(303, $guest->status, 'guests must sign in');
        assertTrueValue(!str_contains($guest->body, str_repeat('e', 64)), 'guests cannot see the release');
        $login = $app->handle(new Request('GET', '/login'));
        assertTrueValue(str_contains($login->body, '<strong>Authored &lt;studio&gt;</strong>'), 'the actual login route reads Website settings');
        foreach ([$guest, $login, $app->handle(new Request('GET', '/missing'))] as $anonymous) {
            $public = $anonymous->body . json_encode($anonymous->headers(), JSON_THROW_ON_ERROR);
            assertTrueValue(!str_contains($public, ProfileManifest::ENGINE_VERSION) && !str_contains($public, str_repeat('e', 64)), 'anonymous pages and headers never disclose engine or release facts');
            assertTrueValue(!str_contains($public, 'admin-account__versions'), 'anonymous requests receive no account version markup');
        }
        $cookies = authenticatedTestCookies($app);
        $page = $app->handle(new Request('GET', '/settings/installation', cookies: $cookies));
        assertSameValue($role === 'editor' ? 403 : 200, $page->status, 'route enforces role authority');
        assertTrueValue(($page->headers()['cache-control'][0] ?? '') === 'no-store', 'installation details cannot be cached');
        $overview = $app->handle(new Request('GET', '/', cookies: $cookies));
        assertSameValue($role !== 'editor', str_contains($overview->body, 'href="/settings/installation"'), 'navigation matches server permissions');
        assertTrueValue(str_contains($overview->body, 'Authored &lt;studio&gt;'), 'the authenticated application reads the saved name');
        assertTrueValue(str_contains($overview->body, '<p class="admin-account__versions">Portable CMS ' . ProfileManifest::ENGINE_VERSION . ' · Site ' . referenceStudioPackage()->manifest->version . '</p>'), 'every signed-in role receives the account menu versions');
        $settings->settings = WebsitePublication::from([...$settings->settings->document(), 'websiteName' => 'Renamed studio', 'revision' => 1]);
        assertTrueValue(str_contains($app->handle(new Request('GET', '/', cookies: $cookies))->body, '>Renamed studio</span>'), 'a later request never retains stale branding');
        assertSameValue(404, $app->handle(new Request('POST', '/settings/installation', cookies: $cookies))->status, 'there is no mutable settings endpoint');
    }
};

$tests['unreadable website settings return a generic safe error without branding or configuration'] = static function (): void {
    [$app, $settings] = installationBrandApplication('owner');
    $settings->settings = WebsitePublication::defaults('wrong-profile', ['en']);
    $response = $app->handle(new Request('GET', '/login'));
    assertSameValue(500, $response->status, 'a broken installation is not silently given invented settings');
    assertTrueValue(str_contains($response->body, '<strong>Portable CMS</strong>'), 'the error mark does not need the installation');
    foreach (['wrong-profile', 'Authored', str_repeat('e', 64), ProfileManifest::ENGINE_VERSION] as $private) {
        assertTrueValue(!str_contains($response->body, $private), 'errors keep private installation details out');
    }
};
