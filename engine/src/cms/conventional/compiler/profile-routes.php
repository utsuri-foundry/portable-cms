<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Compiler;

use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Content\SearchSharing;

/**
 * Manifest routes plus explicitly selected taxonomy paths and profile-owned record segments, placed
 * in each language by the profile's language layout. A page's first address segment can be site
 * wording, so editors name it per language.
 */
final readonly class ProfileRoutes
{
    /**
     * @param array<string, string> $taxonomyPaths Type ID to unlocalized directory path.
     * @param null|\Closure(FrozenRecord, string): string $segment Defaults to the localized slug field.
     * @param array<string, string> $addresses First path segment, as the manifest writes it, to the
     *     address segment wording that names it in each language.
     */
    public function __construct(
        private ProfileManifest $profile,
        private PublishedRecords $records,
        private array $taxonomyPaths = [],
        private ?\Closure $segment = null,
        private LanguageLayout $layout = LanguageLayout::Prefixed,
        private array $addresses = [],
    ) {
        $snapshot = $records->snapshot;
        if ($snapshot->profileId !== $profile->id || $snapshot->profileVersion !== $profile->version || array_values(array_intersect($profile->carrying($snapshot->locales)->supported(), $snapshot->locales)) !== $snapshot->locales) {
            throw new \InvalidArgumentException('Route manifest differs from the frozen profile');
        }
        foreach ($addresses as $segment => $key) {
            if (!is_string($segment) || preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $segment) !== 1 || $profile->wording($key)?->kind !== 'address_segment') {
                throw new \InvalidArgumentException('Address wording mapping is invalid');
            }
        }
        foreach ($taxonomyPaths as $id => $path) {
            if ($profile->type($id)?->kind !== 'taxonomy' || $path === '/' || !CompiledRoute::validRoute($path)) {
                throw new \InvalidArgumentException('Taxonomy route mapping is invalid');
            }
        }
    }

    /**
     * A page's address in a language, from its path without a language prefix, with its first
     * segment in that language's wording. With the primary language at the root, no such path may
     * begin with a language prefix of the profile, so a language added later never takes an
     * address in use.
     */
    public function path(string $path, string $locale): string
    {
        $snapshot = $this->records->snapshot;
        if (!in_array($locale, $snapshot->locales, true)) {
            throw new \InvalidArgumentException('Route locale is not frozen');
        }
        $segments = explode('/', self::checked($path));
        if (isset($this->addresses[$segments[1]])) {
            $segments[1] = $snapshot->wording->text($this->addresses[$segments[1]], $locale);
            $path = implode('/', $segments);
        }
        if ($this->layout === LanguageLayout::PrimaryAtRoot && in_array($segments[1], $this->profile->reserved(), true)) {
            throw new \InvalidArgumentException('Page route collides with a language prefix');
        }
        return self::checked($this->layout->path($path, $locale, $snapshot->defaultLocale));
    }

    public function index(string $typeId, string $locale): string
    {
        $type = $this->profile->type($typeId) ?? throw new \InvalidArgumentException('Route type is unavailable');
        $path = match ($type->kind) {
            'collection' => '/' . $type->route . '/',
            'singleton' => $type->route,
            default => $this->taxonomyPaths[$typeId] ?? throw new \InvalidArgumentException('Taxonomy has no public route'),
        };
        return $this->path($path, $locale);
    }

    public function record(FrozenRecord $record, string $locale): string
    {
        if ($this->records->record($record->id) !== $record) {
            throw new \InvalidArgumentException('Route record is not included in the frozen site');
        }
        $path = $this->index($record->typeId, $locale);
        if ($this->profile->type($record->typeId)?->kind === 'singleton') {
            return $path;
        }
        $segment = $this->segment === null ? ($record->body['slug'][$locale] ?? null) : ($this->segment)($record, $locale);
        if (!is_string($segment) || $segment === '' || str_contains($segment, '/')) {
            throw new ProfileBuildFailure(ProfileBuildReason::InvalidRoute, $record->id, $record->typeId, fieldId: 'slug', locale: $locale);
        }
        return self::checked($path . $segment . '/');
    }

    /**
     * With every language prefixed, the root is an alias of the primary language's home page unless
     * `$rootAlias` is false. With the primary language at the root, the root is that home page itself,
     * and every page names its primary-language address as the default for other languages.
     *
     * @param \Closure(?FrozenRecord, string, string): SearchSharing $metadata Record (null for an index), page group/type ID, locale.
     * @param array<string, string> $pages Additional paths or group overrides for manifest pages.
     */
    public function discovery(\Closure $metadata, array $pages = [], bool $rootAlias = true): RouteDiscovery
    {
        $definitions = [];
        foreach ($this->profile->types as $type) {
            if ($type->kind === 'taxonomy' && !isset($this->taxonomyPaths[$type->id])) {
                continue;
            }
            $path = match ($type->kind) {
                'collection' => '/' . $type->route . '/',
                'singleton' => $type->route,
                default => $this->taxonomyPaths[$type->id],
            };
            if (isset($definitions[$path])) {
                throw new \InvalidArgumentException('Manifest page routes collide');
            }
            $definitions[$path] = $type->id;
        }
        foreach ($pages as $path => $group) {
            if (!CompiledRoute::validRoute($path)) {
                throw new \InvalidArgumentException('Additional page route is invalid');
            }
            $definitions[$path] = $group;
        }
        $snapshot = $this->records->snapshot;
        $atRoot = $this->layout === LanguageLayout::PrimaryAtRoot;
        $homeGroup = $definitions['/'] ?? null;
        if ($rootAlias && !$atRoot && $homeGroup === null) {
            throw new \InvalidArgumentException('A root alias requires a localized home page');
        }
        $pages = [];
        foreach ($snapshot->locales as $locale) {
            foreach ($definitions as $path => $group) {
                $pages[] = [$this->path($path, $locale), $locale, 'page:' . $group, !$metadata($this->pageRecord($group), $group, $locale)->excludeFromSearch];
            }
            foreach ($this->profile->types as $type) {
                if ($type->kind === 'singleton' || ($type->kind === 'taxonomy' && !isset($this->taxonomyPaths[$type->id]))) {
                    continue;
                }
                foreach ($this->records->records($type->id) as $record) {
                    $pages[] = [$this->record($record, $locale), $locale, 'record:' . $record->id, !$metadata($record, $type->id, $locale)->excludeFromSearch];
                }
            }
        }
        // The address for other languages: the root alias of the home page, or with the primary
        // language at the root, each page's primary-language address.
        $defaults = [];
        foreach ($pages as [$path, $locale, $group, $indexable]) {
            if ($locale === $snapshot->defaultLocale && $indexable && ($atRoot || ($rootAlias && $group === 'page:' . $homeGroup))) {
                $defaults[$group] = $atRoot ? $path : '/';
            }
        }
        $routes = array_map(
            static fn(array $page): DiscoveryRoute => new DiscoveryRoute($page[0], $page[1], $page[2], $page[3], fallbackPath: $defaults[$page[2]] ?? null),
            $pages,
        );
        if ($rootAlias && !$atRoot && $homeGroup !== null) {
            $routes[] = new DiscoveryRoute('/', $snapshot->defaultLocale, 'page:' . $homeGroup, !$metadata($this->pageRecord($homeGroup), $homeGroup, $snapshot->defaultLocale)->excludeFromSearch, canonicalPath: '/' . $snapshot->defaultLocale . '/', fallbackPath: $defaults['page:' . $homeGroup] ?? null);
        }
        return new RouteDiscovery($snapshot->publicUrlContext, $routes);
    }

    private function pageRecord(string $group): ?FrozenRecord
    {
        return $this->profile->type($group)?->kind === 'singleton' ? $this->records->singleton($group, required: false) : null;
    }

    private static function checked(string $path): string
    {
        if (!CompiledRoute::validRoute($path)) {
            throw new \InvalidArgumentException('Profile route is invalid');
        }
        return $path;
    }
}
