<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Compiler;

use Utsuri\PortableCms\Conventional\Content\LanguageCatalog;
use Utsuri\PortableCms\Conventional\Content\LlmsTxtSettings;

/**
 * The website's llms.txt (https://llmstxt.org): its name, the editors' introduction in the
 * primary language as the summary, then one section per language with that language's
 * introduction and its pages as links, each with the title and description the page itself
 * declares. It lists the pages the sitemap lists, so it never names a page search engines are
 * asked to leave out.
 */
final class LlmsTxt
{
    /**
     * @param list<string> $locales The website's languages, the primary one first.
     * @param array<string, string> $html Compiled page HTML by route path.
     */
    public static function render(string $name, LlmsTxtSettings $settings, array $locales, RouteDiscovery $discovery, array $html): ?string
    {
        $context = $discovery->context;
        if (!$settings->enabled || $context === null || $locales === []) {
            return null;
        }
        $pages = [];
        foreach ($discovery->listed() as $route) {
            $pages[$route->locale][] = $route;
        }
        $primary = $locales[0];
        $text = '# ' . self::line($name) . "\n";
        $summary = $settings->introductions[$primary] ?? null;
        if ($summary !== null) {
            $text .= "\n" . implode("\n", array_map(static fn(string $line): string => rtrim('> ' . $line), explode("\n", $summary))) . "\n";
        }
        $links = 0;
        foreach ($locales as $locale) {
            if (($pages[$locale] ?? []) === []) {
                continue;
            }
            $text .= "\n## " . self::line(LanguageCatalog::language($locale)->name ?? LanguageCatalog::language(explode('-', $locale)[0])->name ?? $locale) . "\n\n";
            if ($locale !== $primary && isset($settings->introductions[$locale])) {
                $text .= $settings->introductions[$locale] . "\n\n";
            }
            foreach ($pages[$locale] as $route) {
                [$title, $description] = self::head($html[$route->path] ?? '');
                $text .= '- [' . self::label($title ?? $route->path) . '](' . $context->route($route->path) . ')'
                    . ($description === null ? '' : ': ' . self::line($description)) . "\n";
                ++$links;
            }
        }
        return $links === 0 ? null : $text;
    }

    /**
     * The page's own title and meta description.
     * @return array{?string, ?string}
     */
    private static function head(string $html): array
    {
        $head = preg_match('#<head\b[^>]*>(.*?)</head>#is', $html, $match) === 1 ? $match[1] : $html;
        $title = preg_match('#<title\b[^>]*>(.*?)</title>#is', $head, $found) === 1 ? self::decode($found[1]) : null;
        $description = null;
        if (preg_match_all('#<meta\b[^>]*>#i', $head, $metas) > 0) {
            foreach ($metas[0] as $meta) {
                if (preg_match('#\bname\s*=\s*["\']description["\']#i', $meta) === 1
                    && preg_match('#\bcontent\s*=\s*"([^"]*)"|\bcontent\s*=\s*\'([^\']*)\'#i', $meta, $content) === 1) {
                    $description = self::decode($content[1] !== '' ? $content[1] : ($content[2] ?? ''));
                    break;
                }
            }
        }
        return [$title === '' ? null : $title, $description === '' ? null : $description];
    }

    private static function decode(string $text): string
    {
        return self::line(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /** One line of plain text: whitespace runs, line breaks included, become one space. */
    private static function line(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /** Link text that cannot close or open a Markdown link. */
    private static function label(string $text): string
    {
        return strtr(self::line($text), ['\\' => '\\\\', '[' => '\\[', ']' => '\\]']);
    }
}
