<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Compiler\CompilationRequest;
use Utsuri\PortableCms\Conventional\Compiler\CompilerIdentity;
use Utsuri\PortableCms\Conventional\Compiler\FilesystemMediaSource;
use Utsuri\PortableCms\Conventional\Compiler\FrozenSnapshot;
use Utsuri\PortableCms\Conventional\Compiler\StaticSiteCompiler;
use Utsuri\PortableCms\Conventional\Content\ProfilePackage;
use Utsuri\PortableCms\Conventional\SchemaValidationFailure;
use Utsuri\PortableCms\Profile\ReferenceStudio\ReferenceStudioSiteBuilder;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$options = getopt('', ['output:', 'refresh-fixtures', 'refresh-assets', 'help']);
if (isset($options['help'])) {
    fwrite(STDOUT, <<<'HELP'
Usage:
  php tools/build-reference-studio.php [--output=build/reference-studio]

--refresh-fixtures deliberately regenerates the snapshot package pin, frozen
design defaults and expected artifact/search fixtures from reviewed profile input.
--refresh-assets deliberately updates declared asset checksums after reviewed asset edits.

Builds the deterministic fictional Reference Studio fixture. The output path
must not exist. The command prints the static-server command and local URL.
HELP);
    fwrite(STDOUT, "\n");
    exit(0);
}

$repository = dirname(__DIR__, 2);
$outputOption = is_string($options['output'] ?? null) ? $options['output'] : 'build/reference-studio';
$output = str_starts_with($outputOption, DIRECTORY_SEPARATOR)
    ? rtrim($outputOption, DIRECTORY_SEPARATOR)
    : $repository . DIRECTORY_SEPARATOR . rtrim($outputOption, DIRECTORY_SEPARATOR);
if ($output === '' || basename($output) === '' || file_exists($output) || is_link($output)) {
    fwrite(STDERR, "The Reference Studio output path must be new.\n");
    exit(64);
}
$parent = dirname($output);
if (!is_dir($parent) && $parent === $repository . '/build' && !mkdir($parent, 0755)) {
    fwrite(STDERR, "The local build directory could not be created.\n");
    exit(73);
}

try {
    require $repository . '/engine/src/cms/conventional/bootstrap.php';
    $profileRoot = $repository . '/profiles/reference-studio';
    if (isset($options['refresh-assets'])) {
        $manifestPath = $profileRoot . '/profile.json';
        $manifest = \Utsuri\PortableCms\Conventional\Content\ProfileManifest::load($manifestPath);
        $source = (string) file_get_contents($manifestPath);
        foreach ($manifest->assets as $asset) {
            $file = $profileRoot . '/' . $asset->path;
            if (is_link($file) || realpath($file) !== $file) {
                throw new RuntimeException('Asset refresh refuses symbolic links');
            }
            $size = filesize($file);
            $checksum = hash_file('sha256', $file);
            if (!is_int($size) || !is_string($checksum)) {
                throw new RuntimeException('Reviewed asset could not be read');
            }
            $pattern = '/("path": "' . preg_quote($asset->path, '/') . '"[^}]*"byteSize": )\d+(,\s*"sha256": ")[a-f0-9]{64}(")/';
            $updated = preg_replace_callback($pattern, static fn(array $match): string => $match[1] . $size . $match[2] . $checksum . $match[3], $source, 1, $count);
            if (!is_string($updated) || $count !== 1) {
                throw new RuntimeException('Asset manifest cannot be safely refreshed');
            }
            $source = $updated;
        }
        if (file_put_contents($manifestPath, $source) === false) {
            throw new RuntimeException('Asset manifest refresh failed');
        }
    }
    foreach ([
        'page-view.php',
        'presentation.php',
        'reference-content.php',
        'reference-search.php',
        'site-builder.php',
    ] as $source) {
        require_once $profileRoot . '/src/' . $source;
    }
    $package = ProfilePackage::load($profileRoot);
    if (isset($options['refresh-fixtures'])) {
        $snapshotPath = $profileRoot . '/fixtures/snapshot.json';
        $fixtureSource = (string) file_get_contents($snapshotPath);
        $fixtureDocument = json_decode($fixtureSource, true, 64, JSON_THROW_ON_ERROR);
        if ($package->manifest->designRegistries === null) {
            throw new RuntimeException('Reference design defaults are unavailable');
        }
        $fixtureDocument['profile']['packageSha256'] = $package->checksum;
        $fixtureDocument['designRegistries'] = $package->manifest->designRegistries->document();
        $fixtureSource = preg_replace_callback('/("packageSha256": ")[a-f0-9]{64}(")/', static fn(array $match): string => $match[1] . $package->checksum . $match[2], $fixtureSource);
        if (!is_string($fixtureSource)) {
            throw new RuntimeException('Snapshot package pin could not be refreshed');
        }
        $marker = strpos($fixtureSource, "\n" . '  "designRegistries":');
        if ($marker !== false) {
            $fixtureSource = rtrim(substr($fixtureSource, 0, $marker), ",\n ");
        } else {
            $fixtureSource = rtrim(substr(rtrim($fixtureSource), 0, -1));
        }
        $designJson = preg_replace_callback('/^ +/m', static fn(array $match): string => str_repeat(' ', intdiv(strlen($match[0]), 2)), StaticSiteCompiler::json($package->manifest->designRegistries->document()));
        if (!is_string($designJson)) {
            throw new RuntimeException('Snapshot design defaults could not be formatted');
        }
        $lines = explode("\n", rtrim($designJson));
        $fixtureSource .= ",\n" . '  "designRegistries": ' . array_shift($lines) . "\n" . implode("\n", array_map(static fn(string $line): string => '  ' . $line, $lines)) . "\n}\n";
        $serializedDocument = json_decode(json_encode($fixtureDocument, JSON_THROW_ON_ERROR), true, 64, JSON_THROW_ON_ERROR);
        if (json_decode($fixtureSource, true, 64, JSON_THROW_ON_ERROR) !== $serializedDocument
            || file_put_contents($snapshotPath, $fixtureSource) === false) {
            throw new RuntimeException('Snapshot fixture could not be refreshed without changing content');
        }
    }
    $snapshot = FrozenSnapshot::load(
        $profileRoot . '/fixtures/snapshot.json',
        $repository . '/schemas/portable-cms-snapshot-v1.schema.json',
        $package,
    );
    $compiler = new StaticSiteCompiler(
        $repository . '/schemas/portable-cms-artifact-v1.schema.json',
        $repository . '/schemas/portable-cms-search-v1.schema.json',
        new \Utsuri\PortableCms\Conventional\Compiler\ImagickSharingImageProcessor(),
        new \Utsuri\PortableCms\Conventional\Compiler\ImagickWebsiteIconProcessor(),
    );
    $identity = new CompilerIdentity(
        'portable-cms-compiler',
        '0.1.0',
        compilerSourceChecksum($repository . '/engine/src/cms/conventional/compiler'),
    );
    $result = $compiler->compile(
        $package,
        $snapshot,
        new ReferenceStudioSiteBuilder($package),
        new FilesystemMediaSource($profileRoot . '/fixtures/media'),
        new CompilationRequest(
            'f1000000000000000000000000000001',
            new DateTimeImmutable('2026-10-03T09:01:00Z'),
            $identity,
        ),
        $output,
    );
    if (isset($options['refresh-fixtures'])) {
        if (!copy($result->outputRoot . '/artifact.json', $profileRoot . '/fixtures/expected/artifact.json')) {
            throw new RuntimeException('Artifact fixture could not be refreshed');
        }
        foreach (['ca', 'es', 'en'] as $locale) {
            if (!copy($result->outputRoot . '/search/' . $locale . '.json', $profileRoot . '/fixtures/expected/search.' . $locale . '.json')) {
                throw new RuntimeException('Search fixture could not be refreshed');
            }
        }
    }
    fwrite(STDOUT, sprintf("Built %d verified files in %s.\n", count($result->files), $result->outputRoot));
    fwrite(STDOUT, 'Serve: php -S 127.0.0.1:4173 -t ' . escapeshellarg($result->outputRoot) . "\n");
    fwrite(STDOUT, "Open:  http://127.0.0.1:4173/\n");
} catch (Throwable $error) {
    fwrite(STDERR, 'Reference Studio build failed (' . $error::class . '): ' . $error->getMessage() . "\n");
    if ($error instanceof SchemaValidationFailure) {
        foreach ($error->issues as $issue) {
            fwrite(STDERR, sprintf("- %s [%s] %s\n", $issue['path'], $issue['keyword'], $issue['message']));
        }
    }
    exit(1);
}

function compilerSourceChecksum(string $root): string
{
    $files = glob($root . '/*.php');
    if (!is_array($files) || $files === []) {
        throw new RuntimeException('Compiler source is unavailable');
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
            throw new RuntimeException('Compiler source could not be verified');
        }
        $input .= basename($file) . "\0" . $size . "\0" . $checksum . "\n";
    }
    return hash('sha256', $input);
}
