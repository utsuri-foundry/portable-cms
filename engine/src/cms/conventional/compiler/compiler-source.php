<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Compiler;

/** One identity for the installed compiler and shared rendering dependencies. */
final class CompilerSource
{
    public static function identity(): CompilerIdentity
    {
        $files = glob(__DIR__ . '/*.php');
        if (!is_array($files) || $files === []) {
            throw new \RuntimeException('Compiler source is unavailable');
        }
        foreach (['design/registry-document.php', 'design/font-families.php', 'design/size-curve.php', 'design/design-css.php', 'content/blocks.php', 'content/content-feed.php', 'content/content-service.php', 'content/video-limits.php', 'content/video-reference.php', 'media/video-metadata.php', 'content/composition.php', 'content/record-reference.php', 'content/rich-text.php', 'content/search-sharing.php', 'content/web-address.php', 'content/speculation-rules.php', 'content/website-identity.php', 'view/composition-view.php', 'view/composition-assets.php'] as $source) {
            $files[] = dirname(__DIR__) . '/' . $source;
        }
        foreach (['content/vimeo-video.php', 'client/vimeo-player.js', 'client/vimeo-player.css'] as $source) {
            $files[] = dirname(__DIR__) . '/' . $source;
        }
        sort($files, SORT_STRING);
        $input = '';
        foreach ($files as $file) {
            $size = filesize($file);
            $checksum = hash_file('sha256', $file);
            if (!is_int($size) || !is_string($checksum)) {
                throw new \RuntimeException('Compiler source could not be verified');
            }
            $input .= basename($file) . "\0" . $size . "\0" . $checksum . "\n";
        }
        return new CompilerIdentity('portable-cms-compiler', '0.1.0', hash('sha256', $input));
    }
}
