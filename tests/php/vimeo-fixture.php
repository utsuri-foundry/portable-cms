<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Content\ProfilePackage;

/** Fictional acceptance profile; the canonical Reference Studio package keeps its exact bytes. */
function vimeoPackage(string $root, bool $localized = false): ProfilePackage
{
    $source = dirname(__DIR__, 2) . '/profiles/reference-studio';
    mkdir($root, 0700, true);
    $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($entries as $entry) {
        $target = $root . '/' . substr($entry->getPathname(), strlen($source) + 1);
        if ($entry->isDir()) {
            mkdir($target, 0700);
        } else {
            copy($entry->getPathname(), $target);
        }
    }
    $path = $root . '/profile.json';
    $document = json_decode((string) file_get_contents($path), flags: JSON_THROW_ON_ERROR);
    $document->compatibility->engine->minimum = '0.6.0';
    foreach ($document->recordTypes as $type) {
        if ($type->id === 'project') {
            $type->fields[] = (object) ['id' => 'film', 'label' => 'Reference film', 'type' => 'vimeo_video', 'localized' => $localized, 'requiredOnDraft' => false, 'requiredOnPublish' => false, 'settings' => (object) []];
        }
    }
    file_put_contents($path, json_encode($document, JSON_THROW_ON_ERROR));
    $builder = var_export(__DIR__ . '/vimeo-fixture-builder.php', true);
    $factory = var_export($source . '/src/compiler.php', true);
    file_put_contents($root . '/src/compiler.php', '<?php require_once ' . $builder . '; $factory = require ' . $factory . '; return static fn($package, $layout = \\Utsuri\\PortableCms\\Conventional\\Compiler\\LanguageLayout::Prefixed) => new VimeoFixtureBuilder($factory($package, $layout));');
    return ProfilePackage::load($root);
}

/** @return array{formatVersion: int, videoId: string, privacyHash: ?string, poster: string, title: string} */
function vimeoValue(?string $hash = 'abcdef1234', string $poster = 'd0000000000000000000000000000003'): array
{
    return ['formatVersion' => 1, 'videoId' => '123456789', 'privacyHash' => $hash, 'poster' => $poster, 'title' => 'Evening study <film>'];
}
