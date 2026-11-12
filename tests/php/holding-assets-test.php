<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Compiler\HoldingAssets;
use Utsuri\PortableCms\Conventional\Compiler\HoldingPageCompiler;
use Utsuri\PortableCms\Conventional\Compiler\PublicUrlContext;
use Utsuri\PortableCms\Conventional\Content\ProfilePackage;
use Utsuri\PortableCms\Conventional\Preview\PreviewBuild;
use Utsuri\PortableCms\Conventional\Preview\PreviewSnapshot;
use Utsuri\PortableCms\Conventional\Publication\ReleaseArtifact;
use Utsuri\PortableCms\Conventional\Publication\WebsitePublication;

/** @param list<string> $requested */
function holdingAssetTestPackage(string $root, array $requested): ProfilePackage
{
    $source = dirname(__DIR__, 2) . '/profiles/reference-studio';
    mkdir($root . '/package', 0700);
    copy($source . '/profile.json', $root . '/package/profile.json');
    foreach (ProfilePackage::load($source)->manifest->assets as $asset) {
        if (!str_contains($asset->path, '/')) {
            copy($source . '/' . $asset->path, $root . '/package/' . $asset->path);
        }
    }
    foreach (['src', 'assets', 'licenses', 'renderers'] as $directory) {
        mkdir($root . '/package/' . $directory, 0700);
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source . '/' . $directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $entry) {
            $relative = substr($entry->getPathname(), strlen($source) + 1);
            $entry->isDir() ? mkdir($root . '/package/' . $relative, 0700) : copy($entry->getPathname(), $root . '/package/' . $relative);
        }
    }
    $hook = <<<'PHP'
<?php
declare(strict_types=1);
use Utsuri\PortableCms\Conventional\Compiler\HoldingAssets;
use Utsuri\PortableCms\Conventional\Compiler\HoldingPageAssetRenderer;
use Utsuri\PortableCms\Conventional\Compiler\HoldingPageCompiler;
use Utsuri\PortableCms\Conventional\Compiler\HoldingPresentation;
use Utsuri\PortableCms\Conventional\Compiler\WebsiteIcon;
use Utsuri\PortableCms\Conventional\Publication\WebsitePublication;
use Utsuri\PortableCms\Conventional\View\TrustedHtml;
return static fn() => new class implements HoldingPageAssetRenderer {
    public function assets(): array { return __REQUESTED__; }
    public function render(WebsitePublication $settings, ?WebsiteIcon $icon, HoldingAssets $assets): HoldingPresentation {
        $base = HoldingPageCompiler::fallback($settings, $icon);
        $body = $base->body->value;
        $css = $base->css;
        if (in_array('assets/icon.svg', $this->assets(), true)) {
            $body = str_replace('<main>', '<main><img alt="" width="96" height="96" src="' . $assets->url('assets/icon.svg') . '">', $body);
        }
        if (in_array('assets/studio-sans-latin.woff2', $this->assets(), true)) {
            $css .= '@font-face{font-family:"Studio Sans";src:url("' . $assets->cssUrl('assets/studio-sans-latin.woff2') . '") format("woff2")}html{font-family:"Studio Sans"}';
        }
        return new HoldingPresentation(TrustedHtml::fromEngineMarkup($body), $css);
    }
};
PHP;
    file_put_contents($root . '/package/src/holding.php', str_replace('__REQUESTED__', var_export($requested, true), $hook));
    return ProfilePackage::load($root . '/package');
}

function holdingAssetTestSnapshot(ProfilePackage $package): PreviewSnapshot
{
    return new PreviewSnapshot(
        $package->manifest->id,
        $package->manifest->version,
        $package->manifest->locales,
        [],
        new DateTimeImmutable('2026-10-06T12:00:00Z'),
        profileChecksum: $package->checksum,
        publicUrlContext: PublicUrlContext::from(['formatVersion' => 1, 'baseUrl' => 'https://studio.example']),
        holdingPage: WebsitePublication::defaults($package->manifest->id, $package->manifest->locales),
    );
}

function holdingAssetTestCompile(ProfilePackage $package, string $output): PreviewBuild
{
    return (new HoldingPageCompiler(dirname(__DIR__, 2) . '/schemas', dirname($output), $package))->compile(holdingAssetTestSnapshot($package), $output);
}

function holdingAssetTestRefusal(ProfilePackage $package, string $output): void
{
    try {
        holdingAssetTestCompile($package, $output);
    } catch (InvalidArgumentException) {
        assertSameValue(false, file_exists($output), 'a refused holding build writes no directory or file');
        return;
    }
    throw new RuntimeException('Unsafe holding assets were accepted');
}

$tests['holding assets copy exact packaged font and image bytes with correct mounted HTML and CSS URLs'] = static function (): void {
    $root = staticCompilerTempRoot();
    try {
        $package = holdingAssetTestPackage($root, ['assets/studio-sans-latin.woff2', 'assets/icon.svg']);
        $build = holdingAssetTestCompile($package, $root . '/output');
        $snapshot = holdingAssetTestSnapshot($package);
        $artifact = new ReleaseArtifact(file_get_contents($root . '/output/artifact.json'), $build, $snapshot->publicUrlContext, $package->manifest->id, file_get_contents(dirname(__DIR__, 2) . '/schemas/portable-cms-artifact-v1.schema.json'));
        assertSameValue(['/'], $artifact->routes->paths(), 'only the holding route remains public');
        assertSameValue([], $artifact->document['publicMedia'], 'packaged assets never become editor media');
        assertSameValue(8, count($build->artifacts), 'only two requested assets join the six existing holding files');
        $assets = HoldingAssets::load($package, ['assets/studio-sans-latin.woff2', 'assets/icon.svg']);
        foreach ($assets->files as $file) {
            assertSameValue($file['bytes'], file_get_contents($root . '/output/' . $file['path']), 'prepared artifact retains exactly the verified packaged bytes');
            assertTrueValue(str_contains($file['path'], hash('sha256', $file['bytes'])), 'the output path addresses the content checksum');
            $declaration = array_values(array_filter($artifact->files, static fn(array $entry): bool => $entry['path'] === $file['path']))[0];
            assertSameValue($file['mime'], $declaration['mime'], 'inventory retains the declared font/image MIME');
        }
        $html = file_get_contents($root . '/output/index.html');
        assertTrueValue(str_contains($html, $assets->url('assets/icon.svg')), 'markup uses the document-relative URL');
        $css = array_values(array_filter($artifact->files, static fn(array $entry): bool => $entry['mime'] === 'text/css; charset=utf-8'))[0];
        assertTrueValue(str_contains(file_get_contents($root . '/output/' . $css['path']), $assets->cssUrl('assets/studio-sans-latin.woff2')), 'font URL resolves from the generated stylesheet directory');
        assertTrueValue(str_contains($artifact->rules(), "font-src 'self'"), 'public font policy stays restricted to self');
        try {
            $assets->url('assets/studio-serif-latin.woff2');
            throw new RuntimeException('An unrequested URL was supplied');
        } catch (InvalidArgumentException) {
        }
        assertSameValue(false, file_exists($root . '/output/assets/search.js'), 'scripts are not carried into holding');
    } finally {
        staticCompilerRemoveTree($root);
    }
};

$tests['holding assets reject undeclared unsafe repeated script and excessive requests before writing'] = static function (): void {
    foreach ([['assets/missing.png'], ['../profile.json'], ['/etc/passwd'], ['assets/../icon.svg'], ['assets/icon.svg', 'assets/icon.svg'], ['assets/search.js'], ['assets/site.css'], ['named' => 'assets/icon.svg'], [123], array_fill(0, HoldingAssets::MAX_FILES + 1, 'assets/icon.svg')] as $requested) {
        $root = staticCompilerTempRoot();
        try {
            $package = holdingAssetTestPackage($root, $requested);
            holdingAssetTestRefusal($package, $root . '/output');
        } finally {
            staticCompilerRemoveTree($root);
        }
    }
};

$tests['holding presentation bounds reject output after asset validation without writing files'] = static function (): void {
    foreach (['body', 'css', 'url'] as $fault) {
        $root = staticCompilerTempRoot();
        try {
            holdingAssetTestPackage($root, ['assets/icon.svg']);
            $hookPath = $root . '/package/src/holding.php';
            $source = file_get_contents($hookPath);
            $source = match ($fault) {
                'body' => str_replace('$body = $base->body->value;', '$body = str_repeat("x", 1024 * 1024 + 1);', $source),
                'css' => str_replace('$css = $base->css;', '$css = str_repeat("x", 128 * 1024 + 1);', $source),
                'url' => str_replace('$css = $base->css;', '$css = $assets->cssUrl("assets/studio-serif-latin.woff2");', $source),
            };
            file_put_contents($hookPath, $source);
            holdingAssetTestRefusal(ProfilePackage::load($root . '/package'), $root . '/output');
        } finally {
            staticCompilerRemoveTree($root);
        }
    }
};

$tests['holding assets accept exact byte bounds and deduplicate identical declared bytes'] = static function (): void {
    $root = staticCompilerTempRoot();
    try {
        $requested = array_map(static fn(int $i): string => 'assets/exact-' . $i . '.svg', range(0, 3));
        holdingAssetTestPackage($root, $requested);
        $document = json_decode(file_get_contents($root . '/package/profile.json'));
        $head = '<svg xmlns="http://www.w3.org/2000/svg"><!--';
        $tail = '--></svg>';
        $bytes = $head . str_repeat('x', HoldingAssets::MAX_FILE_BYTES - strlen($head) - strlen($tail)) . $tail;
        foreach ($requested as $path) {
            file_put_contents($root . '/package/' . $path, $bytes);
            $document->assets[] = (object) ['path' => $path, 'role' => 'image', 'mime' => 'image/svg+xml', 'byteSize' => strlen($bytes), 'sha256' => hash('sha256', $bytes)];
        }
        file_put_contents($root . '/package/profile.json', json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $package = ProfilePackage::load($root . '/package');
        $build = holdingAssetTestCompile($package, $root . '/output');
        assertSameValue(7, count($build->artifacts), 'four identical declarations at the total byte boundary copy one immutable file');
        $assets = HoldingAssets::load($package, $requested);
        foreach ($requested as $path) {
            assertSameValue($assets->url($requested[0]), $assets->url($path), 'each requested alias receives the same content-addressed URL');
        }
        assertSameValue($bytes, file_get_contents($root . '/output/' . $assets->files[0]['path']), 'the exact per-file boundary retains every byte');
    } finally {
        staticCompilerRemoveTree($root);
    }
};

$tests['holding assets reverify tampered truncated grown and symlinked files after package load'] = static function (): void {
    foreach (['tampered', 'truncated', 'grown', 'link', 'directory-link'] as $fault) {
        $root = staticCompilerTempRoot();
        try {
            $package = holdingAssetTestPackage($root, ['assets/icon.svg']);
            $path = $package->assetPath('assets/icon.svg');
            $bytes = file_get_contents($path);
            match ($fault) {
                'tampered' => file_put_contents($path, str_replace('svg', 'sVg', $bytes)),
                'truncated' => file_put_contents($path, substr($bytes, 0, -1)),
                'grown' => file_put_contents($path, $bytes . str_repeat('x', HoldingAssets::MAX_FILE_BYTES)),
                'link' => (static function () use ($path, $root, $bytes): void {
                    file_put_contents($root . '/outside.svg', $bytes);
                    unlink($path);
                    symlink($root . '/outside.svg', $path);
                })(),
                'directory-link' => (static function () use ($root): void {
                    rename($root . '/package/assets', $root . '/replaced-assets');
                    symlink($root . '/replaced-assets', $root . '/package/assets');
                })(),
            };
            holdingAssetTestRefusal($package, $root . '/output');
        } finally {
            staticCompilerRemoveTree($root);
        }
    }
};

$tests['holding assets reject declared per-file and aggregate byte limits before writing'] = static function (): void {
    foreach (['file', 'total'] as $fault) {
        $root = staticCompilerTempRoot();
        try {
            $requested = $fault === 'file' ? ['assets/bounded-0.svg'] : array_map(static fn(int $i): string => 'assets/bounded-' . $i . '.svg', range(0, 4));
            holdingAssetTestPackage($root, $requested);
            $document = json_decode(file_get_contents($root . '/package/profile.json'));
            foreach ($requested as $path) {
                $bytes = '<svg xmlns="http://www.w3.org/2000/svg"><!--' . str_repeat('x', HoldingAssets::MAX_FILE_BYTES - 64 + ($fault === 'file' ? 128 : 0)) . '--></svg>';
                file_put_contents($root . '/package/' . $path, $bytes);
                $document->assets[] = (object) ['path' => $path, 'role' => 'image', 'mime' => 'image/svg+xml', 'byteSize' => strlen($bytes), 'sha256' => hash('sha256', $bytes)];
            }
            file_put_contents($root . '/package/profile.json', json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            holdingAssetTestRefusal(ProfilePackage::load($root . '/package'), $root . '/output');
        } finally {
            staticCompilerRemoveTree($root);
        }
    }
};

$tests['holding hooks with no asset requests keep fallback files byte identical'] = static function (): void {
    $root = staticCompilerTempRoot();
    try {
        $package = holdingAssetTestPackage($root, []);
        $assetBuild = holdingAssetTestCompile($package, $root . '/assets-none');
        file_put_contents($root . '/package/src/holding.php', <<<'PHP'
<?php
declare(strict_types=1);
return static fn() => new class implements \Utsuri\PortableCms\Conventional\Compiler\HoldingPageRenderer {
    public function render(\Utsuri\PortableCms\Conventional\Publication\WebsitePublication $settings, ?\Utsuri\PortableCms\Conventional\Compiler\WebsiteIcon $icon): \Utsuri\PortableCms\Conventional\Compiler\HoldingPresentation {
        return \Utsuri\PortableCms\Conventional\Compiler\HoldingPageCompiler::fallback($settings, $icon);
    }
};
PHP);
        $legacy = ProfilePackage::load($root . '/package');
        $legacyBuild = holdingAssetTestCompile($legacy, $root . '/legacy');
        unlink($root . '/package/src/holding.php');
        $bare = ProfilePackage::load($root . '/package');
        $bareBuild = holdingAssetTestCompile($bare, $root . '/bare');
        assertSameValue(6, count($assetBuild->artifacts), 'empty requests introduce no new artifact');
        $facts = static fn(PreviewBuild $build): array => array_values(array_map(
            static fn($entry): array => [$entry->path, $entry->mime, $entry->byteSize, $entry->checksum],
            array_filter($build->artifacts, static fn($entry): bool => $entry->path !== 'artifact.json'),
        ));
        foreach ([$legacyBuild, $bareBuild] as $build) {
            assertSameValue($facts($assetBuild), $facts($build), 'only package provenance changes when the hook source changes');
        }
        foreach ($assetBuild->artifacts as $entry) {
            if ($entry->path === 'artifact.json') {
                continue;
            }
            assertSameValue(file_get_contents($root . '/assets-none/' . $entry->path), file_get_contents($root . '/legacy/' . $entry->path), 'the legacy hook renders identical public bytes');
            assertSameValue(file_get_contents($root . '/assets-none/' . $entry->path), file_get_contents($root . '/bare/' . $entry->path), 'a package without a hook renders identical public bytes');
        }
    } finally {
        staticCompilerRemoveTree($root);
    }
};
