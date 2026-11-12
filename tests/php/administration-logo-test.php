<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Http\Request;
use Utsuri\PortableCms\Conventional\Identity\IdentityService;
use Utsuri\PortableCms\Conventional\IdentityApplication;
use Utsuri\PortableCms\Conventional\Media\AdministrationLogoDelivery;
use Utsuri\PortableCms\Conventional\Media\LibraryMedia;
use Utsuri\PortableCms\Conventional\Media\MediaDerivative;
use Utsuri\PortableCms\Conventional\Publication\AdministrationBranding;
use Utsuri\PortableCms\Conventional\Publication\PublicationFailure;
use Utsuri\PortableCms\Conventional\Publication\WebsitePublication;
use Utsuri\PortableCms\Conventional\Publication\WebsitePublicationService;
use Utsuri\PortableCms\Conventional\View\AdminChrome;
use Utsuri\PortableCms\Conventional\View\AdminShell;

/** @return array{app: IdentityApplication, library: \Utsuri\PortableCms\Conventional\Media\MediaLibrary, repository: MemoryMediaLibrary, root: string, settings: MemoryWebsitePublication, identity: MemoryIdentityRepository} */
function administrationLogoFixture(): array
{
    $fixture = editorialMediaFixture();
    for ($index = 1; $index <= 3; $index++) {
        $id = str_pad((string) $index, 32, '0', STR_PAD_LEFT);
        $size = $index === 3 ? [640, 640] : [320, 96];
        $image = new Imagick();
        try {
            $image->newImage(...[...$size, new ImagickPixel(['#172029', '#f1efe9', '#427c91'][$index - 1])]);
            $image->setImageFormat('webp');
            $bytes = $image->getImageBlob();
        } finally {
            $image->clear();
        }
        $directory = $fixture['root'] . '/derivatives/' . $id;
        mkdir($directory, 0700, true);
        file_put_contents($directory . '/' . $id . '-960-webp.webp', $bytes);
        $face = new MediaDerivative($id, '960-webp', 'derivatives/' . $id . '/' . $id . '-960-webp.webp', $id . '-960-webp.webp', 'image/webp', ...[...$size, strlen($bytes), hash('sha256', $bytes), 'fixture', '1', 1, []]);
        $fixture['repository']->items[$id] = new LibraryMedia($id, str_repeat('1', 32), 'Logo <' . $index . '>', 'ready', 'image/png', 500, ...[...$size, 1, null, $face]);
    }
    $profile = referenceStudioPackage()->manifest;
    $clock = new IdentityTestClock(new DateTimeImmutable('2026-10-22T12:00:00Z'));
    $random = new IdentityTestRandom();
    $identity = new MemoryIdentityRepository();
    $identity->addUser(str_repeat('a', 32), 'admin@example.test', 'Admin', password_hash('correct horse battery staple', PASSWORD_BCRYPT, ['cost' => 4]));
    $settings = new MemoryWebsitePublication(WebsitePublication::from([...WebsitePublication::defaults($profile->id, $profile->locales)->document(), 'iconMediaId' => str_pad('3', 32, '0', STR_PAD_LEFT),
        'administrationBranding' => ['logoMediaId' => str_pad('1', 32, '0', STR_PAD_LEFT), 'darkLogoMediaId' => str_pad('2', 32, '0', STR_PAD_LEFT)]]));
    $app = new IdentityApplication(
        new IdentityService($identity, $clock, $random, str_repeat('t', 32)),
        new \Utsuri\PortableCms\Conventional\Http\OriginPolicy('https://cms.example.test'),
        $clock,
        $random,
        profile: $profile,
        mediaLibrary: $fixture['library'],
        websitePublication: new WebsitePublicationService($settings, $profile, $clock, $random),
    );
    return [...$fixture, 'settings' => $settings, 'identity' => $identity, 'app' => $app];
}

$tests['administration logo selection is bounded optional and absent legacy bytes remain unchanged'] = static function (): void {
    $base = WebsitePublication::defaults('test-studio', ['en']);
    assertSameValue($base->document(), WebsitePublication::from($base->document())->document(), 'legacy settings retain their exact shape');
    $ids = ['logoMediaId' => str_repeat('a', 32), 'darkLogoMediaId' => str_repeat('b', 32)];
    $settings = WebsitePublication::from([...$base->document(), 'administrationBranding' => $ids]);
    assertSameValue($ids, $settings->document()['administrationBranding'], 'both selections remain portable');
    assertSameValue($base->document(), $settings->forWebsite()->document(), 'public builds see no administration selections');
    assertSameValue($ids, $settings->within(['en', 'ca'])->administrationBranding->document(), 'language editing retains the selections');
    foreach ([null, [], ['logoMediaId' => '../original', 'darkLogoMediaId' => null], ['logoMediaId' => null, 'darkLogoMediaId' => str_repeat('b', 32)], [...$ids, 'url' => 'https://external.example']] as $bad) {
        try {
            WebsitePublication::from([...$base->document(), 'administrationBranding' => $bad]);
            throw new RuntimeException('Hostile logo selection accepted');
        } catch (PublicationFailure) {
        }
    }
    assertTrueValue(AdministrationBranding::eligible('ready', 'image/png', 960, 960, 1_048_576), 'exact prepared bounds are admitted');
    foreach ([['queued', 'image/png', 20, 20, 50], ['ready', 'image/svg+xml', 20, 20, 50], ['ready', 'video/mp4', 20, 20, 50], ['ready', 'image/png', 961, 20, 50], ['ready', 'image/png', 20, 0, 50], ['ready', 'image/png', 20, 20, 1_048_577]] as $facts) {
        assertSameValue(false, AdministrationBranding::eligible(...$facts), 'unsupported media cannot become branding');
    }
};

$tests['anonymous logo delivery admits only current prepared faces without opening a session'] = static function (): void {
    $f = administrationLogoFixture();
    try {
        $delivery = new AdministrationLogoDelivery($f['library']);
        $pair = $delivery->resolve($f['settings']->settings);
        assertTrueValue($pair !== null && !$pair->icon && $pair->dark->sha256 !== $pair->light->sha256, 'the selected pair wins over the icon');
        foreach ([$pair->light, $pair->dark] as $logo) {
            $response = $f['app']->handle(new Request('GET', $logo->url()));
            assertSameValue(200, $response->status, 'chosen prepared derivative is public');
            assertSameValue($logo->sha256, hash('sha256', $response->body), 'exact verified bytes');
            assertSameValue(['image/webp'], $response->headers()['content-type'], 'the MIME is fixed');
            assertSameValue(['no-store'], $response->headers()['cache-control'], 'revocation is rechecked on every request');
            assertTrueValue(!isset($response->headers()['set-cookie']), 'image delivery creates no session cookies');
            assertTrueValue(!str_contains($response->body . json_encode($response->headers(), JSON_THROW_ON_ERROR), \Utsuri\PortableCms\Conventional\Content\ProfileManifest::ENGINE_VERSION), 'anonymous prepared-logo delivery never discloses the engine version');
        }
        assertSameValue([], $f['identity']->sessions, 'anonymous requests do not create database sessions');
        $unused = $f['library']->find(str_pad('3', 32, '0', STR_PAD_LEFT))->thumbnail->checksum;
        foreach (['/administration-logo/' . $unused . '.webp', '/administration-logo/' . str_repeat('f', 64) . '.webp', '/administration-logo/../originals/source', '/administration-logo/' . $pair->light->sha256 . '.svg'] as $path) {
            assertSameValue(404, $f['app']->handle(new Request('GET', $path))->status, 'unselected bytes, paths and SVG are inaccessible');
        }
        assertSameValue(404, $f['app']->handle(new Request('POST', $pair->light->url()))->status, 'the route is read only');
        assertTrueValue($f['app']->handle(new Request('GET', '/media/' . $pair->light->mediaId . '/thumbnail'))->status !== 200, 'the general media route remains private');
        file_put_contents($f['root'] . '/derivatives/' . $pair->light->mediaId . '/' . $pair->light->mediaId . '-960-webp.webp', 'corrupt');
        assertSameValue(404, $f['app']->handle(new Request('GET', $pair->light->url()))->status, 'corrupt bytes are never served');
        $f['settings']->settings = WebsitePublication::from([...$f['settings']->settings->forWebsite()->document(), 'iconMediaId' => null]);
        assertSameValue(404, $f['app']->handle(new Request('GET', $pair->dark->url()))->status, 'removing a logo immediately revokes its old URL');
        assertSameValue(null, $delivery->resolve($f['settings']->settings), 'without a logo or icon the monogram stays');
    } finally {
        staticCompilerRemoveTree($f['root']);
    }
};

$tests['administration leads with accessible logos and retains named icon and initials fallbacks'] = static function (): void {
    $f = administrationLogoFixture();
    try {
        $profile = referenceStudioPackage()->manifest;
        $delivery = new AdministrationLogoDelivery($f['library']);
        $identity = $f['settings']->settings->identity();
        $pair = $delivery->resolve($f['settings']->settings);
        $html = AdminShell::brand($profile, new AdminChrome('csrf', 'Admin', websiteIdentity: $identity, logos: $pair));
        assertTrueValue(str_contains($html, 'admin-logo__dark') && str_contains($html, $pair->light->url()), 'the shell has both reviewed theme images');
        assertTrueValue(str_contains($html, 'alt="Reference Studio"') && !str_contains($html, 'aria-hidden'), 'the visible logo names the link');
        assertTrueValue(!str_contains($html, 'admin-brand__text') && !str_contains($html, $profile->version), 'the logo replaces name and version');
        $hostile = new \Utsuri\PortableCms\Conventional\Content\WebsiteIdentity('Studio "<Nord>" & Co');
        $escaped = AdminShell::brand($profile, new AdminChrome('csrf', 'Admin', websiteIdentity: $hostile, logos: $pair));
        assertSameValue(2, substr_count($escaped, 'alt="Studio &quot;&lt;Nord&gt;&quot; &amp; Co"'), 'each theme alternative escapes the saved name');
        $icon = $delivery->resolve($f['settings']->settings->forWebsite());
        assertTrueValue($icon?->icon === true, 'the website icon is the first fallback');
        $login = $f['app']->handle(new Request('GET', '/login'));
        assertTrueValue(str_contains($login->body, $pair->light->url()) && !str_contains($login->body, '<strong>Reference Studio</strong>'), 'sign-in shows only the named logo');
        assertTrueValue(str_contains($login->body, '<p class="admin-auth__footer">Portable CMS · Site ' . $profile->version . '</p>'), 'logo-led sign-in has one concise product/site line');
        assertTrueValue(!str_contains($login->body, 'Private editing area') && !str_contains($login->body, \Utsuri\PortableCms\Conventional\Content\ProfileManifest::ENGINE_VERSION), 'logo-led sign-in has no repeated notice or engine version');
        foreach ([$icon, null] as $fallback) {
            $brand = AdminShell::brand($profile, new AdminChrome('csrf', 'Admin', websiteIdentity: $identity, logos: $fallback));
            assertTrueValue(str_contains($brand, '>Reference Studio</span>') && str_contains($brand, 'Site ' . $profile->version), 'fallback shell retains the visible name and site version');
            $fallbackLogin = \Utsuri\PortableCms\Conventional\View\Pages::login('csrf', profile: $profile, identity: $identity, logos: $fallback);
            assertTrueValue(str_contains($fallbackLogin, '<strong>Reference Studio</strong><small>Private editing area</small>'), 'fallback sign-in retains the adjacent name and private-area notice');
            if ($fallback !== null) {
                assertTrueValue(str_contains($brand, 'aria-hidden="true"') && str_contains($brand, 'alt=""'), 'fallback icon is decorative beside the name');
            }
        }
        $generic = AdminShell::brand(null, new AdminChrome('csrf', 'Admin', websiteIdentity: $identity, logos: $pair));
        assertTrueValue(!str_contains($generic, 'admin-logo') && str_contains($generic, '>Portable CMS</span>'), 'generic pages ignore supplied website branding');
        $cookies = authenticatedTestCookies($f['app']);
        $settings = $f['app']->handle(new Request('GET', '/settings/publication', cookies: $cookies));
        assertTrueValue(str_contains($settings->body, 'name="adminLogoMediaId"') && str_contains($settings->body, 'name="darkAdminLogoMediaId"'), 'native controls expose both choices');
        assertTrueValue(!str_contains($settings->body, 'Logo <1>') && str_contains($settings->body, 'Logo &lt;1&gt;'), 'library names cannot become markup');
        $input = ['_csrf' => $cookies[IdentityService::CSRF_COOKIE], '_revision' => '0', 'mode' => 'coming_soon', 'websiteName' => 'Unsaved logo studio', 'iconMediaId' => '', 'adminLogoMediaId' => $pair->light->mediaId, 'darkAdminLogoMediaId' => $pair->dark->mediaId, '_darkAdminLogoMediaId_action' => 'search', '_darkAdminLogoMediaId_query' => 'Image 25'];
        $browsed = $f['app']->handle(new Request('POST', '/settings/publication', contentMutationHeaders(), $input, $cookies));
        assertSameValue(200, $browsed->status, 'native browsing does not save');
        assertSameValue(0, $f['settings']->writes, 'browsing leaves the revision alone');
        assertTrueValue(str_contains($browsed->body, 'Unsaved logo studio') && str_contains($browsed->body, 'Image 25'), 'search keeps other unsaved settings');
        assertTrueValue(str_contains($browsed->body, '<details open><summary>Administration logo</summary>'), 'native search results remain disclosed after navigation');
    } finally {
        staticCompilerRemoveTree($f['root']);
    }
};

$tests['logo references survive normalized export planning and missing or oversized derivatives are refused'] = static function (): void {
    $add = static function (array &$document): void {
        $media = &$document['media'][0];
        $media['derivatives'][0]['width'] = 640;
        $media['derivatives'][0]['height'] = 480;
        $document['websitePublication'] = [...WebsitePublication::defaults($document['profile']['id'], $document['profile']['locales'])->document(),
            'administrationBranding' => ['logoMediaId' => $media['id'], 'darkLogoMediaId' => $media['id']]];
    };
    $plan = restoreFixturePlan($add);
    $branding = restoreFixtureExport($plan)->document['websitePublication']['administrationBranding'];
    assertSameValue($branding['logoMediaId'], $branding['darkLogoMediaId'], 'both portable selectors survive independently');
    foreach (['missing', 'large', 'unready'] as $case) {
        try {
            restoreFixturePlan(static function (array &$document) use ($add, $case): void {
                $add($document);
                if ($case === 'missing') {
                    $document['websitePublication']['administrationBranding']['darkLogoMediaId'] = str_repeat('f', 32);
                } elseif ($case === 'large') {
                    $document['media'][0]['derivatives'][0]['byteSize'] = AdministrationBranding::MAX_BYTES + 1;
                    $document['media'][0]['derivatives'][0]['sha256'] = str_repeat('e', 64);
                } else {
                    $document['media'][0]['status'] = 'needs_attention';
                }
            });
            throw new RuntimeException('An unavailable logo was restored');
        } catch (\Utsuri\PortableCms\Conventional\Restore\RestoreRejected $error) {
            assertSameValue('administration_logo_not_ready', $error->reason, 'restore shares the logo admission policy');
        }
    }
};

$tests['administration logos do not change any public holding file or enter the public renderer settings'] = static function (): void {
    $package = referenceStudioPackage();
    $base = WebsitePublication::defaults($package->manifest->id, $package->manifest->locales);
    $branded = WebsitePublication::from([...$base->document(), 'administrationBranding' => ['logoMediaId' => str_repeat('a', 32), 'darkLogoMediaId' => str_repeat('b', 32)]]);
    $root = staticCompilerTempRoot();
    try {
        $builds = [];
        $clock = new IdentityTestClock(new DateTimeImmutable('2026-10-22T12:00:00Z'));
        $random = new IdentityTestRandom();
        $repository = new MemoryWebsitePublication($branded);
        $previews = new \Utsuri\PortableCms\Conventional\Preview\PreviewService(new MemoryPreviewRepository(), $package->manifest, $clock, $random);
        $holding = new \Utsuri\PortableCms\Conventional\Publication\HoldingPublication(new WebsitePublicationService($repository, $package->manifest, $clock, $random), $previews);
        foreach (['plain' => $base, 'branded' => $branded] as $name => $settings) {
            $snapshot = new \Utsuri\PortableCms\Conventional\Preview\PreviewSnapshot(
                $package->manifest->id,
                $package->manifest->version,
                $package->manifest->locales,
                [],
                new DateTimeImmutable('2026-10-22T12:00:00Z'),
                profileChecksum: $package->checksum,
                publicUrlContext: \Utsuri\PortableCms\Conventional\Compiler\PublicUrlContext::from(['formatVersion' => 1, 'baseUrl' => 'https://studio.example']),
                holdingPage: $settings,
            );
            $build = (new \Utsuri\PortableCms\Conventional\Compiler\HoldingPageCompiler(dirname(__DIR__, 2) . '/schemas', $root, $package))->compile($snapshot, $root . '/' . $name);
            $builds[] = $build->manifest();
            $artifact = new \Utsuri\PortableCms\Conventional\Publication\ReleaseArtifact(file_get_contents($root . '/' . $name . '/artifact.json'), $build, $snapshot->publicUrlContext, $package->manifest->id, file_get_contents(dirname(__DIR__, 2) . '/schemas/portable-cms-artifact-v1.schema.json'));
            $holding->assertApproval($artifact);
            (new \Utsuri\PortableCms\Conventional\Publication\HoldingPublication($holding->settings, $previews, static fn(): array => $package->manifest->locales))->assertApproval($artifact);
            $repository->settings = WebsitePublication::from([...$branded->document(), 'websiteName' => 'Changed public name']);
            try {
                $holding->assertApproval($artifact);
                throw new RuntimeException('Changed public settings approved');
            } catch (PublicationFailure $error) {
                assertSameValue('new_holding_build_required', $error->codeName, 'public changes still require a fresh build');
            }
            $repository->settings = $branded;
        }
        assertSameValue($builds[0], $builds[1], 'administration-only settings leave every public file and manifest byte unchanged');
    } finally {
        staticCompilerRemoveTree($root);
    }
};
