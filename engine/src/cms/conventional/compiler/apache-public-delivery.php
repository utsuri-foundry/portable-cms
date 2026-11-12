<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Compiler;

/** Apache 2.4 target rules. Installing and verifying them is part of activation. */
final class ApachePublicDelivery
{
    /** The public site policy. Live previews add only framing by the CMS and a sandbox. */
    public const CONTENT_SECURITY_POLICY = "default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self'; font-src 'self'; media-src 'self'; connect-src 'self'; base-uri 'none'; object-src 'none'; frame-ancestors 'none'; form-action 'self'";

    /**
     * @param list<CompiledRedirect> $redirects
     * @param list<string> $routes
     */
    public static function configuration(PublicUrlContext $context, array $redirects, array $routes, ?string $speculationRulesPath = null, bool $vimeo = false): string
    {
        if ($speculationRulesPath !== null && !\Utsuri\PortableCms\Conventional\Content\SpeculationRules::validPath($speculationRulesPath)) {
            throw new \InvalidArgumentException('Public speculation rules path is invalid');
        }
        $seen = [];
        $routeIndex = [];
        foreach ($routes as $path) {
            if (!CompiledRoute::validRoute($path) || isset($routeIndex[$path])) {
                throw new \InvalidArgumentException('Delivery route set is invalid');
            }
            $routeIndex[$path] = true;
        }
        if (count($redirects) > 5000 || count($routes) > 10_000 || $routes === []) {
            throw new \InvalidArgumentException('Delivery rules exceed their bounds');
        }
        foreach ($redirects as $redirect) {
            if (isset($seen[$redirect->from]) || isset($routeIndex[$redirect->from]) || !isset($routeIndex[$redirect->to])) {
                throw new \InvalidArgumentException('Delivery redirects must be unique and resolve directly to public pages');
            }
            $seen[$redirect->from] = true;
        }
        usort($redirects, static fn(CompiledRedirect $a, CompiledRedirect $b): int => $a->from <=> $b->from);
        $basePath = parse_url($context->baseUrl, PHP_URL_PATH) ?: '';
        $lines = ['# Portable CMS Apache 2.4 public delivery, format 1',
            '# Required: mod_alias, mod_headers, mod_dir; allowed FileInfo, Indexes overrides.',
            '# Apply only in the dedicated public site root, never the private CMS mount.',
            'DirectoryIndex index.html',
            'Header always set X-Content-Type-Options "nosniff"',
            'Header always set Referrer-Policy "strict-origin-when-cross-origin"',
            'Header always set X-Frame-Options "DENY"',
            'Header always set Permissions-Policy "camera=(), microphone=(), geolocation=()"',
            'Header always set Strict-Transport-Security "max-age=15552000"',
            'Header always set Content-Security-Policy "' . VimeoPresentation::policy(self::CONTENT_SECURITY_POLICY, $vimeo) . '"',
            'Header always set Cache-Control "public, max-age=0, must-revalidate"',
            '# Stable asset paths revalidate. Do not cache them as immutable across releases.',
        ];
        if ($speculationRulesPath !== null) {
            $lines[] = '<FilesMatch "\\.html$">';
            $lines[] = 'Header always set Speculation-Rules \'"' . $basePath . '/' . $speculationRulesPath . '"\'';
            $lines[] = '</FilesMatch>';
            $lines[] = '<Files "' . $speculationRulesPath . '">';
            $lines[] = 'ForceType ' . \Utsuri\PortableCms\Conventional\Content\SpeculationRules::MIME;
            $lines[] = '</Files>';
        }
        foreach ($redirects as $redirect) {
            // Route/base validation excludes whitespace, quotes, fragments and encoded separators.
            $pattern = str_replace('~', '\\~', preg_quote($basePath . $redirect->from, '#'));
            $lines[] = 'RedirectMatch ' . $redirect->status . ' "^' . $pattern . '$" "' . $context->route($redirect->to) . '"';
        }
        return implode("\n", $lines) . "\n";
    }
}
