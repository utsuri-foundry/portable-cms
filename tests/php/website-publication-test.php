<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Compiler\HoldingPageCompiler;
use Utsuri\PortableCms\Conventional\Compiler\PublicUrlContext;
use Utsuri\PortableCms\Conventional\Content\ProfilePackage;
use Utsuri\PortableCms\Conventional\Identity\SessionContext;
use Utsuri\PortableCms\Conventional\Preview\PreviewSnapshot;
use Utsuri\PortableCms\Conventional\Publication\PublicationConflict;
use Utsuri\PortableCms\Conventional\Publication\PublicationFailure;
use Utsuri\PortableCms\Conventional\Publication\ReleaseArtifact;
use Utsuri\PortableCms\Conventional\Publication\WebsitePublication;
use Utsuri\PortableCms\Conventional\Publication\WebsitePublicationRepository;
use Utsuri\PortableCms\Conventional\Publication\WebsitePublicationService;
use Utsuri\PortableCms\Conventional\View\WebsitePublicationPage;

final class MemoryWebsitePublication implements WebsitePublicationRepository
{
    public int $writes = 0;

    public function __construct(public WebsitePublication $settings) {}

    public function load(string $profileId, array $locales): WebsitePublication
    {
        return $this->settings;
    }

    public function save(WebsitePublication $next, int $expected, string $actorId, string $auditId, DateTimeImmutable $now): void
    {
        if ($this->settings->revision() !== $expected) {
            throw new PublicationConflict();
        }
        ++$this->writes;
        $this->settings = $next;
    }
}

function holdingTestActor(): SessionContext
{
    return new SessionContext('session', 'authenticated', str_repeat('a', 32), 'admin@example.test', 'Admin', 'administrator', 'token', 'csrf', new DateTimeImmutable('2026-10-06T12:00:00Z'), false);
}

$tests['holding intent is bounded localized and defaults to coming soon without activation'] = static function (): void {
    $settings = WebsitePublication::defaults('test-studio', ['en', 'ca', 'es', 'ja']);
    assertSameValue('coming_soon', $settings->mode(), 'empty installations start with safe holding intent');
    assertSameValue('live', WebsitePublication::defaults('test-studio', ['en'], true)->mode(), 'upgrades preserve existing live intent');
    assertSameValue(['en', 'ca', 'es', 'ja'], array_column($settings->document()['translations'], 'locale'), 'all profile languages receive editable messages');
    foreach ([
        [...$settings->document(), 'mode' => 'other'],
        [...$settings->document(), 'websiteName' => ''],
        [...$settings->document(), 'websiteName' => str_repeat('a', 181)],
        [...$settings->document(), 'iconMediaId' => '../escape'],
        [...$settings->document(), 'unexpected' => true],
        [...$settings->document(), 'translations' => [$settings->document()['translations'][0], $settings->document()['translations'][0]]],
        [...$settings->document(), 'websiteName' => "Hidden\0value"],
    ] as $invalid) {
        try {
            WebsitePublication::from($invalid);
            throw new RuntimeException('Invalid publication intent accepted');
        } catch (PublicationFailure $error) {
            assertSameValue('website_publication_invalid', $error->codeName, 'invalid input rejected before writes');
        }
    }
    try {
        $settings->assertScope('test-studio', ['en']);
        throw new RuntimeException('Changed language scope accepted');
    } catch (PublicationFailure $error) {
        assertSameValue('website_publication_scope_changed', $error->codeName, 'scope must match the complete profile language registry');
    }
};

$tests['holding settings require confirmation on return to holding and reject stale writers'] = static function (): void {
    $profile = ProfilePackage::load(dirname(__DIR__, 2) . '/profiles/reference-studio')->manifest;
    $repository = new MemoryWebsitePublication(WebsitePublication::defaults($profile->id, $profile->locales));
    $service = new WebsitePublicationService($repository, $profile, new IdentityTestClock(new DateTimeImmutable('2026-10-06T12:00:00Z')), new IdentityTestRandom());
    $live = [...$service->load()->document(), 'revision' => 1, 'mode' => 'live'];
    $service->save($live, 0, holdingTestActor(), false);
    assertSameValue(1, $repository->writes, 'live intent can be saved but does not activate a destination');
    $return = [...$live, 'revision' => 2, 'mode' => 'coming_soon'];
    try {
        $service->save($return, 1, holdingTestActor(), false);
        throw new RuntimeException('Unconfirmed replacement accepted');
    } catch (PublicationFailure $error) {
        assertSameValue('holding_confirmation_required', $error->codeName, 'return to holding names the destructive route consequence');
    }
    $service->save($return, 1, holdingTestActor(), true);
    try {
        $service->save($return, 1, holdingTestActor(), true);
        throw new RuntimeException('Stale settings accepted');
    } catch (PublicationConflict) {
    }
    assertSameValue(2, $repository->writes, 'stale and unconfirmed requests have no writes');
};

$tests['holding compiler escapes hostile plain text and emits one noindex page without sitemap or website content'] = static function (): void {
    $repository = dirname(__DIR__, 2);
    $package = ProfilePackage::load($repository . '/profiles/reference-studio');
    $value = WebsitePublication::defaults($package->manifest->id, $package->manifest->locales)->document();
    $value['websiteName'] = '<script>alert("unsafe")</script>';
    $value['translations'][0]['contact'] = 'javascript:alert(1) <img src=x onerror=alert(1)>';
    $settings = WebsitePublication::from($value);
    $context = PublicUrlContext::from(['formatVersion' => 1, 'baseUrl' => 'https://studio.example']);
    $snapshot = new PreviewSnapshot($package->manifest->id, $package->manifest->version, $package->manifest->locales, [], new DateTimeImmutable('2026-10-06T12:00:00.123456Z'), profileChecksum: $package->checksum, publicUrlContext: $context, holdingPage: $settings);
    $root = sys_get_temp_dir() . '/portable-holding-test-' . bin2hex(random_bytes(8));
    mkdir($root, 0700);
    try {
        $build = (new HoldingPageCompiler($repository . '/schemas', $root, $package))->compile($snapshot, $root . '/artifact');
        $html = file_get_contents($root . '/artifact/index.html');
        assertTrueValue(str_contains($html, '&lt;script&gt;'), 'hostile name remains visible plain text');
        assertTrueValue(!str_contains($html, '<script') && !str_contains($html, '<img src=x'), 'editor cannot inject executable markup');
        assertTrueValue(str_contains($html, 'content="noindex,follow"'), 'holding page has explicit page-level noindex');
        assertTrueValue(str_contains($html, '<link rel="icon"'), 'an installation without a selected image still has a verified favicon');
        assertTrueValue(str_contains(HoldingPageCompiler::fallback($settings, null)->css, 'h1{max-width:100%;overflow-wrap:anywhere;'), 'long website names wrap on narrow screens');
        foreach ($package->manifest->locales as $locale) {
            assertTrueValue(str_contains($html, 'lang="' . $locale . '"'), 'all languages remain available without visitor JavaScript');
        }
        $artifact = new ReleaseArtifact(file_get_contents($root . '/artifact/artifact.json'), $build, $context, $package->manifest->id, file_get_contents($repository . '/schemas/portable-cms-artifact-v1.schema.json'));
        assertSameValue(true, $artifact->holding(), 'producer marks this as an internal holding publication');
        assertSameValue(['/'], $artifact->routes->paths(), 'only the root page is public');
        assertTrueValue(!file_exists($root . '/artifact/sitemap.xml'), 'there is no fabricated live sitemap');
        assertSameValue([], $artifact->document['publicMedia'], 'website originals and media cannot leak through the holding build');
        assertSameValue([], $artifact->document['redirects'], 'old routes never redirect to the holding page');
    } finally {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($root);
    }
};

$tests['holding settings native form escapes content and explains that saving is not publication'] = static function (): void {
    $profile = ProfilePackage::load(dirname(__DIR__, 2) . '/profiles/reference-studio')->manifest;
    $chrome = new \Utsuri\PortableCms\Conventional\View\AdminChrome('csrf', 'Admin');
    $settings = WebsitePublication::defaults($profile->id, $profile->locales);
    $html = WebsitePublicationPage::render($profile, $chrome, $settings, ['websiteName' => '" onfocus="alert(1)']);
    assertTrueValue(str_contains($html, '&quot; onfocus=&quot;'), 'attribute context is escaped');
    assertTrueValue(str_contains($html, 'Saving does not change the public website'), 'intent and activation remain distinct');
    assertTrueValue(str_contains($html, 'name="_complete"'), 'truncated native forms cannot save partially');
};

$tests['holding profile hook is optional checksummed reviewed code and cannot change the engine route contract'] = static function (): void {
    $repository = dirname(__DIR__, 2);
    $original = ProfilePackage::load($repository . '/profiles/reference-studio');
    $root = staticCompilerTempRoot();
    mkdir($root . '/profile', 0700);
    try {
        copy($original->root . '/profile.json', $root . '/profile/profile.json');
        foreach ($original->manifest->assets as $asset) {
            if (!str_contains($asset->path, '/')) {
                copy($original->root . '/' . $asset->path, $root . '/profile/' . $asset->path);
            }
        }
        foreach (['src', 'assets', 'licenses', 'renderers'] as $directory) {
            mkdir($root . '/profile/' . $directory, 0700);
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($original->root . '/' . $directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
            foreach ($iterator as $entry) {
                $relative = substr($entry->getPathname(), strlen($original->root) + 1);
                $entry->isDir() ? mkdir($root . '/profile/' . $relative, 0700) : copy($entry->getPathname(), $root . '/profile/' . $relative);
            }
        }
        file_put_contents($root . '/profile/src/holding.php', <<<'PHP'
<?php
declare(strict_types=1);
return static fn() => new class implements \Utsuri\PortableCms\Conventional\Compiler\HoldingPageRenderer {
    public function render(\Utsuri\PortableCms\Conventional\Publication\WebsitePublication $settings, ?\Utsuri\PortableCms\Conventional\Compiler\WebsiteIcon $icon): \Utsuri\PortableCms\Conventional\Compiler\HoldingPresentation
    {
        return new \Utsuri\PortableCms\Conventional\Compiler\HoldingPresentation(
            \Utsuri\PortableCms\Conventional\View\TrustedHtml::fromEngineMarkup('<main><h1>' . \Utsuri\PortableCms\Conventional\Http\Html::text($settings->document()['websiteName']) . '</h1><p>Reviewed custom holding design</p></main>'),
            'body{color:#171717;background:white;font-family:system-ui;margin:2rem}',
        );
    }
};
PHP);
        $package = ProfilePackage::load($root . '/profile');
        assertTrueValue($package->checksum !== $original->checksum, 'optional renderer enters reviewed source identity');
        $settings = WebsitePublication::defaults($package->manifest->id, $package->manifest->locales);
        $snapshot = new PreviewSnapshot($package->manifest->id, $package->manifest->version, $package->manifest->locales, [], new DateTimeImmutable('2026-10-06T12:00:00Z'), profileChecksum: $package->checksum, publicUrlContext: PublicUrlContext::from(['formatVersion' => 1, 'baseUrl' => 'https://studio.example']), holdingPage: $settings);
        (new HoldingPageCompiler($repository . '/schemas', $root, $package))->compile($snapshot, $root . '/custom');
        $html = file_get_contents($root . '/custom/index.html');
        assertTrueValue(str_contains($html, 'Reviewed custom holding design') && str_contains($html, 'content="noindex,follow"'), 'profile body is used while the engine still owns noindex');
        file_put_contents($root . '/profile/src/holding.php', '<?php return static fn() => "invalid";');
        try {
            ProfilePackage::load($root . '/profile')->holdingRenderer();
            throw new RuntimeException('Malformed profile hook accepted');
        } catch (InvalidArgumentException $error) {
            assertSameValue('Profile holding renderer is invalid', $error->getMessage(), 'broken reviewed hooks fail closed instead of silently falling back');
        }
        $bareSettings = WebsitePublication::defaults('unfinished-studio', ['en', 'ca']);
        $bareSnapshot = new PreviewSnapshot('unfinished-studio', '0.1.0', ['en', 'ca'], [], new DateTimeImmutable('2026-10-06T12:00:00Z'), publicUrlContext: $snapshot->publicUrlContext, holdingPage: $bareSettings);
        (new HoldingPageCompiler($repository . '/schemas', $root))->compile($bareSnapshot, $root . '/unfinished');
        assertTrueValue(str_contains(file_get_contents($root . '/unfinished/index.html'), 'Unfinished Studio'), 'holding works before any profile renderer exists');
    } finally {
        staticCompilerRemoveTree($root);
    }
};
