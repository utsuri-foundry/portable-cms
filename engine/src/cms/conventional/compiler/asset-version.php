<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Compiler;

/**
 * One version per website build, added as `?v=` to every reference the build makes to its own
 * stylesheets, scripts, fonts, icons and other files with stable names, in pages, in stylesheets
 * and in module imports. A host that caches static files for a long time, whatever the release's
 * rules say, then still gives a returning visitor the files that belong to the pages it serves.
 * Pages, the files written for crawlers and content-named media keep their addresses. Every
 * reference in one build carries the same version, so a module loaded by a page and imported by
 * another module stays one module.
 */
final class AssetVersion
{
    private const UNVERSIONED = ['robots.txt', 'sitemap.xml', 'llms.txt', 'artifact.json'];

    /**
     * The version of a build: its frozen content, its profile package and its compiler. Two builds of
     * equal content, such as before and after a backup is restored, get the same version.
     */
    public static function of(string $contentDigest, string $packageChecksum, CompilerIdentity $compiler): string
    {
        return substr(hash('sha256', implode("\n", ['portable-cms-asset-version-1', $contentDigest, $packageChecksum, $compiler->checksum])), 0, 12);
    }

    /**
     * A digest of a snapshot's frozen content without its own id and creation time, so a
     * snapshot taken again of equal content, such as after a backup is restored, digests equally.
     */
    public static function content(FrozenSnapshot $snapshot): string
    {
        $content = get_object_vars($snapshot);
        unset($content['id'], $content['createdAt']);
        return hash('sha256', serialize($content));
    }

    /** Whether a path from the site root names a file whose address the version keeps current. */
    public static function versioned(string $path): bool
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return $extension !== '' && $extension !== 'html' && !str_starts_with($path, 'media/') && !in_array($path, self::UNVERSIONED, true);
    }

    /**
     * The reference with the version added when it names a versioned file of the site; any other
     * reference, including one to another site or one that already has a query, is returned as is.
     * @param string $base The directory of the referencing file from the site root, '' or ending in '/'.
     */
    public static function reference(string $url, string $base, string $version): string
    {
        if ($url === '' || preg_match('#\A(?:[a-z][a-z0-9+.-]*:|//|\#)#i', $url) === 1 || str_contains($url, '?')) {
            return $url;
        }
        $hash = strpos($url, '#');
        $path = $hash === false ? $url : substr($url, 0, $hash);
        $fragment = $hash === false ? '' : substr($url, $hash);
        $resolved = self::resolve(str_starts_with($path, '/') ? ltrim($path, '/') : $base . $path);
        return $resolved !== null && self::versioned($resolved) ? $path . '?v=' . $version . $fragment : $url;
    }

    /** A page's references to versioned files, in every tag's href, src, poster and srcset. */
    public static function html(string $html, string $route, string $version): string
    {
        $base = ltrim($route, '/');
        $base = $base === '' || str_ends_with($base, '/') ? $base : (str_contains($base, '/') ? substr($base, 0, (int) strrpos($base, '/') + 1) : '');
        return preg_replace_callback('#<[a-z][^>]*>#i', static fn(array $tag): string => preg_replace_callback(
            '#(\s(href|src|poster|srcset)\s*=\s*)(["\'])(.*?)\3#is',
            static function (array $attribute) use ($base, $version): string {
                $value = strtolower($attribute[2]) === 'srcset'
                    ? implode(', ', array_map(static function (string $candidate) use ($base, $version): string {
                        $parts = preg_split('/\s+/', trim($candidate), 2) ?: [''];
                        return self::reference($parts[0], $base, $version) . (isset($parts[1]) ? ' ' . $parts[1] : '');
                    }, explode(',', $attribute[4])))
                    : self::reference($attribute[4], $base, $version);
                return $attribute[1] . $attribute[3] . $value . $attribute[3];
            },
            $tag[0],
        ) ?? throw new \RuntimeException('Asset references could not be versioned'), $html) ?? throw new \RuntimeException('Asset references could not be versioned');
    }

    /** A stylesheet's url() and @import references to versioned files. */
    public static function css(string $css, string $path, string $version): string
    {
        $base = self::directory($path);
        $css = preg_replace_callback('#url\(\s*(["\']?)([^"\')]*)\1\s*\)#i', static fn(array $match): string => 'url(' . $match[1] . self::reference($match[2], $base, $version) . $match[1] . ')', $css)
            ?? throw new \RuntimeException('Stylesheet references could not be versioned');
        return preg_replace_callback('#(@import\s+)(["\'])([^"\']+)\2#i', static fn(array $match): string => $match[1] . $match[2] . self::reference($match[3], $base, $version) . $match[2], $css)
            ?? throw new \RuntimeException('Stylesheet references could not be versioned');
    }

    /** A module's relative static and dynamic import specifiers. */
    public static function js(string $js, string $path, string $version): string
    {
        $base = self::directory($path);
        return preg_replace_callback('#(\bfrom\s*|\bimport\s*\(\s*|\bimport\s+)(["\'])(\.{1,2}/[^"\'\s]+)\2#', static fn(array $match): string => $match[1] . $match[2] . self::reference($match[3], $base, $version) . $match[2], $js)
            ?? throw new \RuntimeException('Module imports could not be versioned');
    }

    private static function directory(string $path): string
    {
        $slash = strrpos($path, '/');
        return $slash === false ? '' : substr($path, 0, $slash + 1);
    }

    /** A path from the site root with its dot segments resolved, or null when it climbs out of the site. */
    private static function resolve(string $path): ?string
    {
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '..') {
                if ($segments === []) {
                    return null;
                }
                array_pop($segments);
            } elseif ($segment !== '.' && $segment !== '') {
                $segments[] = $segment;
            }
        }
        return implode('/', $segments);
    }
}
