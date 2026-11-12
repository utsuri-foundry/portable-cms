<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Compiler;

use Utsuri\PortableCms\Conventional\Http\Html;

final readonly class DiscoveryRoute
{
    public string $canonicalPath;

    public function __construct(
        public string $path,
        public string $locale,
        public string $translationGroup,
        public bool $indexable = true,
        ?string $canonicalPath = null,
        public ?string $fallbackPath = null,
    ) {
        $this->canonicalPath = $canonicalPath ?? $path;
        if (!CompiledRoute::validRoute($path) || !CompiledRoute::validRoute($this->canonicalPath)
            || ($fallbackPath !== null && !CompiledRoute::validRoute($fallbackPath))
            || preg_match('/\A[a-z]{2,3}(?:-[A-Z]{2})?\z/', $locale) !== 1
            || preg_match('/\A[a-z0-9][a-z0-9:_-]{0,159}\z/', $translationGroup) !== 1) {
            throw new \InvalidArgumentException('Discovery route identity is invalid');
        }
    }
}

/** Immutable, verified actual route graph, not guessed localized URL prefixes. */
final readonly class RouteDiscovery
{
    /** @var array<string, DiscoveryRoute> */
    private array $routes;

    /** @var array<string, array<string, DiscoveryRoute>> */
    private array $groups;

    /** @param list<DiscoveryRoute> $routes */
    public function __construct(public ?PublicUrlContext $context, array $routes)
    {
        if ($routes === [] || count($routes) > 10_000) {
            throw new \InvalidArgumentException('Discovery route graph is invalid');
        }
        $index = [];
        $groups = [];
        foreach ($routes as $route) {
            if (isset($index[$route->path])) {
                throw new ProfileBuildFailure(ProfileBuildReason::DuplicateRoute, route: $route->path);
            }
            $index[$route->path] = $route;
            if ($route->indexable && $route->path === $route->canonicalPath) {
                if (isset($groups[$route->translationGroup][$route->locale])) {
                    throw new ProfileBuildFailure(ProfileBuildReason::DuplicateRoute, route: $route->path);
                }
                $groups[$route->translationGroup][$route->locale] = $route;
            }
        }
        foreach ($routes as $route) {
            $canonical = $index[$route->canonicalPath] ?? null;
            $fallback = $route->fallbackPath === null ? null : ($index[$route->fallbackPath] ?? null);
            if ($canonical === null || $canonical->canonicalPath !== $canonical->path
                || $canonical->translationGroup !== $route->translationGroup
                || $canonical->locale !== $route->locale || $canonical->indexable !== $route->indexable
                || ($route->fallbackPath !== null && ($fallback === null || !$fallback->indexable
                    || $fallback->translationGroup !== $route->translationGroup))) {
                throw new \InvalidArgumentException('Canonical or fallback route is unavailable or contradictory');
            }
            foreach ($groups[$route->translationGroup] ?? [] as $counterpart) {
                if ($route->indexable && $counterpart->fallbackPath !== $route->fallbackPath) {
                    throw new \InvalidArgumentException('Translation fallbacks must be reciprocal');
                }
            }
        }
        ksort($index, SORT_STRING);
        foreach ($groups as &$group) {
            ksort($group, SORT_STRING);
        }
        unset($group);
        $this->routes = $index;
        $this->groups = $groups;
    }

    /** @return list<string> */
    public function paths(): array
    {
        return array_keys($this->routes);
    }

    public function route(string $path): DiscoveryRoute
    {
        return $this->routes[$path] ?? throw new \InvalidArgumentException('Discovery route is unavailable');
    }

    /** @return list<array{path: string, locale: string, translationGroup: string}> */
    public function publicationRoutes(): array
    {
        $routes = [];
        foreach ($this->routes as $route) {
            if ($route->path === $route->canonicalPath) {
                $routes[] = ['path' => $route->path, 'locale' => $route->locale, 'translationGroup' => $route->translationGroup];
            }
        }
        return $routes;
    }

    /** @param list<CompiledRoute> $routes */
    public function assertRoutes(array $routes): void
    {
        $paths = array_map(static fn(CompiledRoute $route): string => $route->route, $routes);
        sort($paths, SORT_STRING);
        if ($paths !== array_keys($this->routes)) {
            throw new \InvalidArgumentException('Discovery graph differs from the compiled route set');
        }
    }

    public function head(string $path): string
    {
        $route = $this->routes[$path] ?? throw new \InvalidArgumentException('Discovery route is unavailable');
        $head = $route->indexable ? '' : '<meta name="robots" content="noindex,follow">';
        if ($this->context === null) {
            return $head;
        }
        $canonical = Html::attribute($this->context->route($route->canonicalPath));
        $head .= '<link rel="canonical" href="' . $canonical . '"><meta property="og:url" content="' . $canonical . '">';
        if (!$route->indexable) {
            return $head;
        }
        foreach ($this->groups[$route->translationGroup] ?? [] as $locale => $counterpart) {
            $head .= '<link rel="alternate" hreflang="' . Html::attribute($locale)
                . '" href="' . Html::attribute($this->context->route($counterpart->path)) . '">';
        }
        if ($route->fallbackPath !== null) {
            $head .= '<link rel="alternate" hreflang="x-default" href="'
                . Html::attribute($this->context->route($route->fallbackPath)) . '">';
        }
        return $head;
    }

    public function canonicalPath(string $path): string
    {
        return ($this->routes[$path] ?? throw new \InvalidArgumentException('Discovery route is unavailable'))->canonicalPath;
    }

    public function sitemap(): string
    {
        if ($this->context === null) {
            throw new \InvalidArgumentException('A sitemap requires an operator-owned public URL context');
        }
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        foreach ($this->routes as $route) {
            if ($route->indexable && $route->path === $route->canonicalPath) {
                $xml .= '<url><loc>' . htmlspecialchars($this->context->route($route->path), ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</loc></url>';
            }
        }
        return $xml . '</urlset>' . "\n";
    }

    public function robots(): string
    {
        // No blanket disallow: crawlers must be able to read page-level noindex.
        return "User-agent: *\nAllow: /\n"
            . ($this->context === null ? '' : 'Sitemap: ' . $this->context->baseUrl . "/sitemap.xml\n");
    }
}
