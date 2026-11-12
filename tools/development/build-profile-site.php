<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Compiler\CompilationRequest;
use Utsuri\PortableCms\Conventional\Compiler\CompilerSource;
use Utsuri\PortableCms\Conventional\Compiler\FilesystemMediaSource;
use Utsuri\PortableCms\Conventional\Compiler\FrozenSnapshot;
use Utsuri\PortableCms\Conventional\Compiler\ImagickSharingImageProcessor;
use Utsuri\PortableCms\Conventional\Compiler\LanguageLayout;
use Utsuri\PortableCms\Conventional\Compiler\SnapshotLanguages;
use Utsuri\PortableCms\Conventional\Compiler\StaticSiteCompiler;
use Utsuri\PortableCms\Conventional\Content\ProfilePackage;
use Utsuri\PortableCms\Conventional\JsonSchemaValidator;

// Compiles a verified profile package's frozen snapshot into a static artifact with the
// ordinary compiler, so the public site can be audited before publication exists locally.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$repository = dirname(__DIR__, 2);
require_once $repository . '/vendor/autoload.php';
require_once $repository . '/engine/src/cms/conventional/bootstrap.php';

$options = getopt('', ['profile-root:', 'snapshot:', 'media:', 'output:', 'languages:', 'primary:', 'primary-at-root']);
$profileRoot = realpath(is_string($options['profile-root'] ?? null) ? $options['profile-root'] : $repository . '/profiles/reference-studio');
$snapshot = is_string($options['snapshot'] ?? null) ? $options['snapshot'] : ($profileRoot === false ? '' : $profileRoot . '/fixtures/snapshot.json');
$media = is_string($options['media'] ?? null) ? $options['media'] : ($profileRoot === false ? '' : $profileRoot . '/fixtures/media');
$output = is_string($options['output'] ?? null) ? $options['output'] : '';
$languages = is_string($options['languages'] ?? null) ? explode(',', $options['languages']) : null;
if ($profileRoot === false || !is_file($profileRoot . '/profile.json') || !is_file($snapshot) || !is_dir($media) || $output === ''
    || file_exists($output) || !is_dir(dirname($output))) {
    fwrite(STDERR, "Usage: php tools/development/build-profile-site.php --output=<new directory> [--profile-root=<profile>] [--snapshot=<snapshot.json>] [--media=<media root>] [--languages=<public languages> [--primary=<language>]] [--primary-at-root]\n");
    exit(64);
}

$schemas = $repository . '/schemas';
$package = ProfilePackage::load($profileRoot);
if ($languages === null) {
    $frozen = FrozenSnapshot::load($snapshot, $schemas . '/portable-cms-snapshot-v1.schema.json', $package);
} else {
    // The site as published with only these languages public, as an installation may choose in Settings.
    $document = json_decode((string) file_get_contents($snapshot), true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($document)) {
        throw new RuntimeException('The snapshot is not a document');
    }
    $document = (new SnapshotLanguages($languages, is_string($options['primary'] ?? null) ? $options['primary'] : $languages[0]))->document($document, $package->manifest);
    JsonSchemaValidator::validateJson(json_encode($document, JSON_THROW_ON_ERROR), (string) file_get_contents($schemas . '/portable-cms-snapshot-v1.schema.json'));
    $frozen = FrozenSnapshot::fromDocument($document, $package);
}
if (isset($options['primary-at-root'])) {
    // A profile that offers the root layout takes it as the second argument of its compiler factory.
    $factory = require $profileRoot . '/src/compiler.php';
    $builder = $factory($package, LanguageLayout::PrimaryAtRoot);
} else {
    $builder = $package->siteBuilder();
}
$compilation = (new StaticSiteCompiler(
    $schemas . '/portable-cms-artifact-v1.schema.json',
    $schemas . '/portable-cms-search-v1.schema.json',
    new ImagickSharingImageProcessor(),
    new \Utsuri\PortableCms\Conventional\Compiler\ImagickWebsiteIconProcessor(),
))->compile(
    $package,
    $frozen,
    $builder,
    new FilesystemMediaSource($media),
    // The snapshot fixes the identity and time, so the same inputs build the same bytes.
    new CompilationRequest(substr(hash('sha256', $frozen->id . "\0" . $package->checksum), 0, 32), $frozen->createdAt, CompilerSource::identity()),
    $output,
);
if (isset($options['primary-at-root']) && file_exists($output . '/' . $frozen->defaultLocale)) {
    fwrite(STDERR, "The profile does not offer the primary language at the root.\n");
    exit(1);
}
fwrite(STDOUT, sprintf("Built %d files for %s into %s.\n", count($compilation->files), $package->manifest->id, $output));
