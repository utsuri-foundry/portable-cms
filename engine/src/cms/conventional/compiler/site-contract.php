<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Compiler;

final readonly class CompiledRoute
{
    public function __construct(public string $route, public string $html)
    {
        if (
            !self::validRoute($route)
            || $html === ''
            || strlen($html) > 10 * 1024 * 1024
            || !str_starts_with($html, '<!doctype html>')
        ) {
            throw new \InvalidArgumentException('Compiled route is invalid');
        }
    }

    public static function validRoute(string $route): bool
    {
        return strlen($route) <= 500
            && !in_array('.', explode('/', $route), true)
            && !in_array('..', explode('/', $route), true)
            && preg_match('#\A/(?:[A-Za-z0-9._~-]+/)*\z#', $route) === 1;
    }
}

final readonly class CompiledSearchIndex
{
    /** @param array<string, mixed> $document */
    public function __construct(public string $locale, public array $document)
    {
        if (
            preg_match('/\A[a-z]{2,3}(?:-[A-Z]{2})?\z/', $locale) !== 1
            || ($document['locale'] ?? null) !== $locale
            || ($document['formatVersion'] ?? null) !== 1
        ) {
            throw new \InvalidArgumentException('Compiled search index is invalid');
        }
    }
}

final readonly class CompiledRedirect
{
    public function __construct(public string $from, public string $to, public int $status = 308)
    {
        if (
            !CompiledRoute::validRoute($from)
            || !CompiledRoute::validRoute($to)
            || $from === $to
            || !in_array($status, [301, 308], true)
        ) {
            throw new \InvalidArgumentException('Compiled redirect is invalid');
        }
    }

    /** @return array{from: string, to: string, status: int} */
    public function toArray(): array
    {
        return ['from' => $this->from, 'to' => $this->to, 'status' => $this->status];
    }
}

final readonly class ProfileSiteBuild
{
    /**
     * @param list<CompiledRoute> $routes
     * @param list<CompiledSearchIndex> $searchIndexes
     * @param list<CompiledRedirect> $redirects
     * @param list<string> $publicMediaIds
     */
    public function __construct(
        public array $routes,
        public array $searchIndexes,
        public array $redirects,
        public array $publicMediaIds,
        public ?RouteDiscovery $discovery = null,
    ) {
        if ($routes === [] || count($routes) > 10_000 || count($searchIndexes) > 16) {
            throw new \InvalidArgumentException('Profile site build is invalid');
        }
        $seenRoutes = [];
        foreach ($routes as $route) {
            if (!$route instanceof CompiledRoute) {
                throw new \InvalidArgumentException('Profile site routes are invalid');
            }
            if (isset($seenRoutes[$route->route])) {
                throw new ProfileBuildFailure(ProfileBuildReason::DuplicateRoute, route: $route->route);
            }
            $seenRoutes[$route->route] = true;
        }
        $discovery?->assertRoutes($routes);
        $seenLocales = [];
        foreach ($searchIndexes as $index) {
            if (!$index instanceof CompiledSearchIndex || isset($seenLocales[$index->locale])) {
                throw new \InvalidArgumentException('Profile search indexes are invalid');
            }
            $seenLocales[$index->locale] = true;
        }
        $seenRedirects = [];
        foreach ($redirects as $redirect) {
            if (
                !$redirect instanceof CompiledRedirect
                || isset($seenRedirects[$redirect->from])
                || isset($seenRoutes[$redirect->from])
            ) {
                throw new \InvalidArgumentException('Profile redirects are invalid');
            }
            $seenRedirects[$redirect->from] = true;
        }
        $seenMedia = [];
        foreach ($publicMediaIds as $mediaId) {
            if (
                !is_string($mediaId)
                || preg_match('/\A[a-f0-9]{32}\z/', $mediaId) !== 1
                || isset($seenMedia[$mediaId])
            ) {
                throw new \InvalidArgumentException('Profile public media selection is invalid');
            }
            $seenMedia[$mediaId] = true;
        }
    }
}

interface ProfileSiteBuilder
{
    public function build(FrozenSnapshot $snapshot): ProfileSiteBuild;
}

final readonly class CompilerIdentity
{
    public function __construct(public string $id, public string $version, public string $checksum)
    {
        if (
            preg_match('/\A[a-z][a-z0-9]*(?:[-_][a-z0-9]+)*\z/', $id) !== 1
            || preg_match('/\A[0-9]+\.[0-9]+\.[0-9]+(?:[-+][A-Za-z0-9.-]+)?\z/', $version) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/', $checksum) !== 1
        ) {
            throw new \InvalidArgumentException('Compiler identity is invalid');
        }
    }

    /** @return array{id: string, version: string, sha256: string} */
    public function toArray(): array
    {
        return ['id' => $this->id, 'version' => $this->version, 'sha256' => $this->checksum];
    }
}

final readonly class CompilationRequest
{
    public function __construct(
        public string $releaseId,
        public \DateTimeImmutable $createdAt,
        public CompilerIdentity $compiler,
    ) {
        if (preg_match('/\A[a-f0-9]{32}\z/', $releaseId) !== 1) {
            throw new \InvalidArgumentException('Compilation release identity is invalid');
        }
    }
}
