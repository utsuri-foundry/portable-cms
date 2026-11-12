<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Content\ProfilePackage;

/** An optional fictional agent field; ordinary Reference Studio content remains valid. */
function singleLineRichTextPackage(string $root): ProfilePackage
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
    $document->compatibility->engine->minimum = '0.5.0';
    foreach ($document->recordTypes as $type) {
        if ($type->id === 'project') {
            $type->fields[] = (object) ['id' => 'salesAgent', 'label' => 'International sales agent', 'hint' => 'Name, optionally linked.', 'type' => 'rich_text',
                'localized' => false, 'requiredOnDraft' => false, 'requiredOnPublish' => false, 'editorialSection' => 'details',
                'settings' => (object) ['maximumNodes' => 100, 'maximumBlocks' => 1, 'blocks' => ['paragraph'], 'marks' => ['link']]];
        }
    }
    file_put_contents($path, json_encode($document, JSON_THROW_ON_ERROR));
    return ProfilePackage::load($root);
}
