<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Compiler\CompilationRequest;
use Utsuri\PortableCms\Conventional\Compiler\CompiledRedirect;
use Utsuri\PortableCms\Conventional\Compiler\CompiledRoute;
use Utsuri\PortableCms\Conventional\Compiler\CompilerIdentity;
use Utsuri\PortableCms\Conventional\Compiler\FilesystemMediaSource;
use Utsuri\PortableCms\Conventional\Compiler\FrozenSnapshot;
use Utsuri\PortableCms\Conventional\Compiler\ProfileSiteBuild;
use Utsuri\PortableCms\Conventional\Compiler\ProfileSiteBuilder;
use Utsuri\PortableCms\Conventional\Compiler\StaticCompilation;
use Utsuri\PortableCms\Conventional\Compiler\StaticSiteCompiler;
use Utsuri\PortableCms\Conventional\Content\ProfilePackage;
use Utsuri\PortableCms\Profile\ReferenceStudio\ReferenceStudioSiteBuilder;

$staticCompilerRoot = dirname(__DIR__, 2);
$staticCompilerProfile = $staticCompilerRoot . '/profiles/reference-studio';
foreach ([
    'page-view.php',
    'presentation.php',
    'reference-content.php',
    'reference-search.php',
    'site-builder.php',
] as $profileSource) {
    require_once $staticCompilerProfile . '/src/' . $profileSource;
}

/** @return array{package: ProfilePackage, snapshot: FrozenSnapshot, compiler: StaticSiteCompiler, builder: ReferenceStudioSiteBuilder, media: FilesystemMediaSource, request: CompilationRequest} */
function staticCompilerFixture(): array
{
    $root = dirname(__DIR__, 2);
    $profileRoot = $root . '/profiles/reference-studio';
    $package = ProfilePackage::load($profileRoot);
    return [
        'package' => $package,
        'snapshot' => FrozenSnapshot::load(
            $profileRoot . '/fixtures/snapshot.json',
            $root . '/schemas/portable-cms-snapshot-v1.schema.json',
            $package,
        ),
        'compiler' => new StaticSiteCompiler(
            $root . '/schemas/portable-cms-artifact-v1.schema.json',
            $root . '/schemas/portable-cms-search-v1.schema.json',
            new \Utsuri\PortableCms\Conventional\Compiler\ImagickSharingImageProcessor(),
            new \Utsuri\PortableCms\Conventional\Compiler\ImagickWebsiteIconProcessor(),
        ),
        'builder' => new ReferenceStudioSiteBuilder($package),
        'media' => new FilesystemMediaSource($profileRoot . '/fixtures/media'),
        'request' => new CompilationRequest(
            'f1000000000000000000000000000001',
            new DateTimeImmutable('2026-10-03T09:01:00Z'),
            new CompilerIdentity('portable-cms-compiler', '0.1.0', staticCompilerSourceChecksum()),
        ),
    ];
}

function staticCompilerSourceChecksum(): string
{
    $root = dirname(__DIR__, 2) . '/engine/src/cms/conventional/compiler';
    $files = glob($root . '/*.php');
    if (!is_array($files) || $files === []) {
        throw new RuntimeException('Compiler source fixture is unavailable');
    }
    foreach (['registry-document.php', 'font-families.php', 'size-curve.php', 'design-css.php'] as $source) {
        $files[] = dirname($root) . '/design/' . $source;
    }
    foreach (['content/blocks.php', 'content/content-feed.php', 'content/content-service.php', 'content/composition.php', 'content/rich-text.php', 'content/search-sharing.php', 'content/record-reference.php', 'content/web-address.php', 'content/speculation-rules.php', 'view/composition-view.php', 'view/composition-assets.php'] as $source) {
        $files[] = dirname($root) . '/' . $source;
    }
    sort($files, SORT_STRING);
    $input = '';
    foreach ($files as $file) {
        $size = filesize($file);
        $checksum = hash_file('sha256', $file);
        if (!is_int($size) || !is_string($checksum)) {
            throw new RuntimeException('Compiler source fixture could not be verified');
        }
        $input .= basename($file) . "\0" . $size . "\0" . $checksum . "\n";
    }
    return hash('sha256', $input);
}

function staticCompilerTempRoot(): string
{
    $root = sys_get_temp_dir() . '/portable-cms-static-' . bin2hex(random_bytes(8));
    if (!mkdir($root, 0700)) {
        throw new RuntimeException('Could not create static compiler test root');
    }
    return $root;
}

function staticCompilerRemoveTree(string $root): void
{
    if (!is_dir($root) || is_link($root)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $entry) {
        $path = $entry->getPathname();
        $removed = $entry->isDir() && !$entry->isLink() ? rmdir($path) : unlink($path);
        if (!$removed) {
            throw new RuntimeException('Could not remove static compiler test fixture');
        }
    }
    if (!rmdir($root)) {
        throw new RuntimeException('Could not remove static compiler test root');
    }
}

/** @return array<string, string> */
function staticCompilerFileDigests(string $root): array
{
    $digests = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    );
    foreach ($iterator as $entry) {
        if ($entry->isFile()) {
            $path = $entry->getPathname();
            $digest = hash_file('sha256', $path);
            if (!is_string($digest)) {
                throw new RuntimeException('Could not hash static compiler output');
            }
            $digests[str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen($root) + 1))] = $digest;
        }
    }
    ksort($digests, SORT_STRING);
    return $digests;
}

/** @param array{package: ProfilePackage, snapshot: FrozenSnapshot, compiler: StaticSiteCompiler, builder: ReferenceStudioSiteBuilder, media: FilesystemMediaSource, request: CompilationRequest} $fixture */
function staticCompilerBuild(array $fixture, string $output): StaticCompilation
{
    return $fixture['compiler']->compile(
        $fixture['package'],
        $fixture['snapshot'],
        $fixture['builder'],
        $fixture['media'],
        $fixture['request'],
        $output,
    );
}

$tests['static compiler emits the complete multilingual Reference Studio artifact'] = static function (): void {
    $root = staticCompilerTempRoot();
    try {
        $fixture = staticCompilerFixture();
        $result = staticCompilerBuild($fixture, $root . '/artifact');
        assertSameValue(88, count($result->files), 'the frozen profile should emit the complete file set including real italic, responsive widths, collage media, composition CSS, discovery, sharing cards, speculation rules and target delivery rules');
        assertTrueValue(array_sum(array_column($result->files, 'byteSize')) <= 2 * 1024 * 1024, 'the complete fictional reference artifact stays within its 2 MiB budget');
        $rasterFiles = array_values(array_filter($result->files, static fn(array $file): bool => str_starts_with($file['path'], 'media/')));
        assertTrueValue(array_sum(array_column($rasterFiles, 'byteSize')) <= 64 * 1024, 'all fictional raster widths stay within their 64 KiB budget');
        foreach (['ca', 'es', 'en'] as $locale) {
            foreach (['index.html', 'projects/index.html', 'articles/index.html', 'people/index.html', 'about/index.html', 'search/index.html'] as $path) {
                assertTrueValue(is_file($result->outputRoot . '/' . $locale . '/' . $path), "{$locale} route should exist");
            }
        }
        assertTrueValue(
            is_file($result->outputRoot . '/en/projects/archive-of-mist/index.html'),
            'published localized project route should exist',
        );
        assertTrueValue(
            !is_dir($result->outputRoot . '/en/projects/taller-de-llum'),
            'draft routes must not enter a public artifact',
        );
        assertTrueValue(
            is_file($result->outputRoot . '/media/d0000000000000000000000000000001-display_1600_webp.webp'),
            'referenced immutable media should be public',
        );
        assertTrueValue(
            is_file($result->outputRoot . '/media/d0000000000000000000000000000003-display_1600_webp.webp'),
            'an image selected by the published collage is public even when its owner is a draft',
        );
        $english = file_get_contents($result->outputRoot . '/en/projects/archive-of-mist/index.html');
        assertTrueValue(is_string($english), 'compiled project should be readable');
        assertTrueValue(str_contains($english, '<html lang="en">'), 'route should declare its locale');
        assertTrueValue(str_contains($english, '<span class="cms-small-caps">RIBA </span>'), 'frozen v2 mark compiles as original letters and an engine class');
        assertTrueValue(!str_contains($english, 'style="font-variant'), 'mark never emits editor styles');
        assertTrueValue(str_contains($english, '<main id="content"'), 'route should retain semantic landmarks');
        assertTrueValue(str_contains($english, 'aria-current="page"'), 'route should identify current navigation');
        $expected = file_get_contents(dirname(__DIR__, 2) . '/profiles/reference-studio/fixtures/expected/artifact.json');
        $actual = file_get_contents($result->outputRoot . '/artifact.json');
        assertSameValue($expected, $actual, 'complete output manifest should remain pinned');
    } finally {
        staticCompilerRemoveTree($root);
    }
};

$tests['static compiler excludes draft-owned media when no published page selects it'] = static function (): void {
    $fixture = staticCompilerFixture();
    $document = json_decode((string) file_get_contents($fixture['package']->root . '/fixtures/snapshot.json'), true, flags: JSON_THROW_ON_ERROR);
    foreach ($document['records'] as &$record) {
        if ($record['typeId'] === 'home') {
            unset($record['body']['collage']);
        }
    }
    unset($record);
    $fixture['snapshot'] = FrozenSnapshot::fromDocument($document, $fixture['package']);
    $root = staticCompilerTempRoot();
    try {
        $result = staticCompilerBuild($fixture, $root . '/artifact');
        assertTrueValue(!is_file($result->outputRoot . '/media/d0000000000000000000000000000003-display_1600_webp.webp'), 'unselected draft media stays private');
    } finally {
        staticCompilerRemoveTree($root);
    }
};

$tests['static compiler is byte deterministic across isolated builds'] = static function (): void {
    $root = staticCompilerTempRoot();
    try {
        $fixture = staticCompilerFixture();
        staticCompilerBuild($fixture, $root . '/first');
        staticCompilerBuild($fixture, $root . '/second');
        assertSameValue(
            staticCompilerFileDigests($root . '/first'),
            staticCompilerFileDigests($root . '/second'),
            'identical compiler inputs should produce byte-identical trees',
        );
    } finally {
        staticCompilerRemoveTree($root);
    }
};

$tests['frozen snapshot rejects package drift and broken relationships before compilation'] = static function (): void {
    $root = dirname(__DIR__, 2);
    $profileRoot = $root . '/profiles/reference-studio';
    $package = ProfilePackage::load($profileRoot);
    $document = json_decode(
        (string) file_get_contents($profileRoot . '/fixtures/snapshot.json'),
        true,
        64,
        JSON_THROW_ON_ERROR,
    );
    $document['profile']['packageSha256'] = str_repeat('0', 64);
    try {
        FrozenSnapshot::fromDocument($document, $package);
        throw new RuntimeException('package drift unexpectedly reached the compiler');
    } catch (InvalidArgumentException $error) {
        assertSameValue(
            'Frozen snapshot profile does not match the verified package',
            $error->getMessage(),
            'package drift should fail with a stable reason',
        );
    }
    $document['profile']['packageSha256'] = $package->checksum;
    $document['records'][0]['relationships'][0]['targetRecordId'] = str_repeat('f', 32);
    try {
        FrozenSnapshot::fromDocument($document, $package);
        throw new RuntimeException('broken graph unexpectedly reached the compiler');
    } catch (\Utsuri\PortableCms\Conventional\Compiler\ProfileBuildFailures $error) {
        assertSameValue(\Utsuri\PortableCms\Conventional\Compiler\ProfileBuildReason::Relationship, $error->failures[0]->reason, 'broken graphs should have a stable typed reason');
        assertSameValue($document['records'][0]['id'], $error->failures[0]->recordId, 'broken graphs identify the owner');
    }
};

$tests['reference builder rejects a missing translation required for a published route'] = static function (): void {
    $root = staticCompilerTempRoot();
    try {
        $fixture = staticCompilerFixture();
        $document = json_decode(
            (string) file_get_contents(dirname(__DIR__, 2) . '/profiles/reference-studio/fixtures/snapshot.json'),
            true,
            64,
            JSON_THROW_ON_ERROR,
        );
        foreach ($document['records'] as &$record) {
            if (($record['id'] ?? null) === '10000000000000000000000000000001') {
                $record['body']['title']['en'] = '';
            }
        }
        unset($record);
        $snapshot = FrozenSnapshot::fromDocument($document, $fixture['package']);
        try {
            $fixture['compiler']->compile(
                $fixture['package'],
                $snapshot,
                $fixture['builder'],
                $fixture['media'],
                $fixture['request'],
                $root . '/artifact',
            );
            throw new RuntimeException('missing route translation unexpectedly compiled');
        } catch (\Utsuri\PortableCms\Conventional\Compiler\ProfileBuildFailure $error) {
            assertSameValue(\Utsuri\PortableCms\Conventional\Compiler\ProfileBuildReason::MissingTranslation, $error->reason, 'required route translations should have a stable typed reason');
            assertSameValue('en', $error->locale, 'the unavailable translation is identified');
        }
        assertTrueValue(!file_exists($root . '/artifact'), 'missing translations must not leave an artifact');
    } finally {
        staticCompilerRemoveTree($root);
    }
};

$tests['static compiler rejects symlink media and removes an interrupted build'] = static function (): void {
    $root = staticCompilerTempRoot();
    try {
        $fixture = staticCompilerFixture();
        $mediaRoot = $root . '/media';
        if (!mkdir($mediaRoot, 0700)) {
            throw new RuntimeException('Could not create hostile media fixture');
        }
        $profileMedia = dirname(__DIR__, 2) . '/profiles/reference-studio/fixtures/media';
        foreach (glob($profileMedia . '/*.webp') ?: [] as $source) {
            if (!symlink($source, $mediaRoot . '/' . basename($source))) {
                throw new RuntimeException('Could not create hostile media symlink');
            }
        }
        try {
            $fixture['compiler']->compile(
                $fixture['package'],
                $fixture['snapshot'],
                $fixture['builder'],
                new FilesystemMediaSource($mediaRoot),
                $fixture['request'],
                $root . '/artifact',
            );
            throw new RuntimeException('symlink media unexpectedly compiled');
        } catch (RuntimeException $error) {
            assertSameValue(
                'Compiler media source contains a symbolic link',
                $error->getMessage(),
                'media traversal should fail with a stable reason',
            );
        }
        assertTrueValue(!file_exists($root . '/artifact'), 'failed build must not commit an artifact');
        assertTrueValue(
            !file_exists($root . '/artifact.working-f1000000000000000000000000000001'),
            'failed build must remove its private working tree',
        );
    } finally {
        staticCompilerRemoveTree($root);
    }
};

$tests['profile site contract rejects duplicate routes before filesystem work'] = static function (): void {
    try {
        new ProfileSiteBuild(
            [
                new CompiledRoute('/', '<!doctype html><title>One</title>'),
                new CompiledRoute('/', '<!doctype html><title>Two</title>'),
            ],
            [],
            [],
            [],
        );
        throw new RuntimeException('duplicate routes unexpectedly entered a site build');
    } catch (\Utsuri\PortableCms\Conventional\Compiler\ProfileBuildFailure $error) {
        assertSameValue(\Utsuri\PortableCms\Conventional\Compiler\ProfileBuildReason::DuplicateRoute, $error->reason, 'duplicate routes should have a stable typed reason');
        assertSameValue('/', $error->route, 'the duplicate page address is identified');
    }
};

$tests['static compiler rejects redirect cycles and removes the working tree'] = static function (): void {
    $root = staticCompilerTempRoot();
    try {
        $fixture = staticCompilerFixture();
        $builder = new class implements ProfileSiteBuilder {
            public function build(FrozenSnapshot $snapshot): ProfileSiteBuild
            {
                return new ProfileSiteBuild(
                    [new CompiledRoute('/', '<!doctype html><title>Home</title>')],
                    [],
                    [
                        new CompiledRedirect('/first/', '/second/'),
                        new CompiledRedirect('/second/', '/first/'),
                    ],
                    [],
                    new \Utsuri\PortableCms\Conventional\Compiler\RouteDiscovery($snapshot->publicUrlContext, [new \Utsuri\PortableCms\Conventional\Compiler\DiscoveryRoute('/', $snapshot->defaultLocale, 'home')]),
                );
            }
        };
        try {
            $fixture['compiler']->compile(
                $fixture['package'],
                $fixture['snapshot'],
                $builder,
                $fixture['media'],
                $fixture['request'],
                $root . '/artifact',
            );
            throw new RuntimeException('redirect cycle unexpectedly compiled');
        } catch (InvalidArgumentException $error) {
            assertSameValue(
                'Compiler redirect graph contains a cycle',
                $error->getMessage(),
                'redirect cycles should fail with a stable reason',
            );
        }
        assertTrueValue(!file_exists($root . '/artifact'), 'redirect cycles must not leave an artifact');
        assertTrueValue(
            !file_exists($root . '/artifact.working-' . $fixture['request']->releaseId),
            'redirect cycles must remove the working tree',
        );
    } finally {
        staticCompilerRemoveTree($root);
    }
};

$tests['static compiler cleans up when a profile renderer fails'] = static function (): void {
    $root = staticCompilerTempRoot();
    try {
        $fixture = staticCompilerFixture();
        $failing = new class implements ProfileSiteBuilder {
            public function build(FrozenSnapshot $snapshot): ProfileSiteBuild
            {
                throw new RuntimeException('synthetic renderer failure');
            }
        };
        try {
            $fixture['compiler']->compile(
                $fixture['package'],
                $fixture['snapshot'],
                $failing,
                $fixture['media'],
                $fixture['request'],
                $root . '/artifact',
            );
            throw new RuntimeException('renderer failure unexpectedly compiled');
        } catch (RuntimeException $error) {
            assertSameValue('synthetic renderer failure', $error->getMessage(), 'renderer error should remain causal');
        }
        assertTrueValue(!file_exists($root . '/artifact'), 'renderer failure must not commit an artifact');
        assertTrueValue(
            !file_exists($root . '/artifact.working-f1000000000000000000000000000001'),
            'renderer failure must remove its private working tree',
        );
    } finally {
        staticCompilerRemoveTree($root);
    }
};
