<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Upgrade;

use Utsuri\PortableCms\Conventional\HostIo;

/**
 * The CMS document root's `.htaccess` has two parts: the host's own lines first, then one
 * marker line, then the engine's rules. Shared hosts select a domain's PHP version by
 * writing a handler block into that file, so the engine keeps every line above the marker
 * whenever it writes the file and owns everything below it. A file written before the marker
 * existed is adopted once: an engine-only file has no host lines, anything else is kept whole
 * as the host's.
 */
final class CmsRootRules
{
    public const MARKER = '# Portable CMS root rules, format 1. Host lines stay above this line; the engine owns the lines below.';
    /** The rules of a freshly installed bundle, whose front controller serves its own assets. */
    public const BUNDLE_RULES = "Options -Indexes\nDirectoryIndex index.php\nFallbackResource /index.php\n";
    /** The rules once the stable front controller routes the CMS assets of the active release. */
    public const LAUNCHER_RULES = "Options -Indexes\nDirectoryIndex index.php\nRewriteEngine On\nRewriteRule ^assets/ index.php [END]\nFallbackResource /index.php\n";
    private const MAXIMUM_HOST_BYTES = 16_384;

    /** The host's lines of an existing file, ending in a newline, or an empty string. */
    public static function hostLines(?string $existing): string
    {
        if ($existing === null || $existing === '') {
            return '';
        }
        $marker = strpos($existing, self::MARKER);
        $lines = $marker === false ? $existing : substr($existing, 0, $marker);
        if ($lines === '' || ($marker === false && in_array($lines, [self::BUNDLE_RULES, self::LAUNCHER_RULES], true))) {
            return '';
        }
        if (strlen($lines) > self::MAXIMUM_HOST_BYTES || str_contains($lines, "\0")) {
            throw new UpgradeFailure('cms_root_host_rules_refused');
        }
        return rtrim($lines, "\r\n") . "\n";
    }

    /** The whole file: the host's lines, the marker, then the given engine rules. */
    public static function compose(string $hostLines, string $rules): string
    {
        return $hostLines . self::MARKER . "\n" . $rules;
    }

    /** The PHP handler the host's lines select, such as application/x-lsphp85, or null. */
    public static function handler(string $hostLines): ?string
    {
        return preg_match('/^[ \t]*(?:Set|Add)Handler[ \t]+(application\/x-[a-z0-9.-]+)/mi', $hostLines, $match) === 1
            ? strtolower($match[1])
            : null;
    }

    /**
     * What the file at a path holds, for dry runs and probes: whether it exists, whether it
     * carries the marker, whether it has host lines, and the handler those lines select.
     *
     * @return array{present: bool, marker: bool, hostLines: bool, handler: string|null}
     */
    public static function describe(string $path): array
    {
        $absent = ['present' => false, 'marker' => false, 'hostLines' => false, 'handler' => null];
        if (is_link($path) || !is_file($path)) {
            return $absent;
        }
        $existing = HostIo::attempt(fn() => file_get_contents($path, false, null, 0, self::MAXIMUM_HOST_BYTES + 4_096))->value;
        if (!is_string($existing)) {
            return $absent;
        }
        $marker = str_contains($existing, self::MARKER);
        try {
            $hostLines = self::hostLines($existing);
        } catch (UpgradeFailure) {
            return ['present' => true, 'marker' => $marker, 'hostLines' => true, 'handler' => null];
        }
        return ['present' => true, 'marker' => $marker, 'hostLines' => $hostLines !== '', 'handler' => self::handler($hostLines)];
    }
}
