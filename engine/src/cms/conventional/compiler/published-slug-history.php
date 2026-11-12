<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Compiler;

/** Caller persists this only after verified public activation, never on a draft save. */
final readonly class PublishedSlugHistory
{
    /** @param array<string, mixed> $document */
    private function __construct(private array $document, public PublicUrlContext $context) {}

    public static function from(mixed $document): self
    {
        if (!is_array($document) || count($document) !== 9
            || array_diff(array_keys($document), ['formatVersion', 'profileId', 'releaseId', 'artifactSha256', 'activatedAt', 'publicUrlContext', 'routes', 'previousPaths', 'verificationId']) !== []
            || ($document['formatVersion'] ?? null) !== 1
            || !is_string($document['profileId'] ?? null)
            || preg_match('/\A[a-z][a-z0-9]*(?:[-_][a-z0-9]+)*\z/', $document['profileId']) !== 1
            || strlen($document['profileId']) > 160) {
            throw new \InvalidArgumentException('Published slug history is invalid');
        }
        foreach (['releaseId', 'verificationId'] as $id) {
            if (!is_string($document[$id] ?? null) || preg_match('/\A[a-f0-9]{32}\z/', $document[$id]) !== 1) {
                throw new \InvalidArgumentException('Published release identity is invalid');
            }
        }
        if (!is_string($document['artifactSha256'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/', $document['artifactSha256']) !== 1
            || !is_string($document['activatedAt'] ?? null)
            || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?Z\z/', $document['activatedAt']) !== 1) {
            throw new \InvalidArgumentException('Published verification provenance is invalid');
        }
        $time = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.u\Z', str_contains($document['activatedAt'], '.') ? $document['activatedAt'] : str_replace('Z', '.000000Z', $document['activatedAt']), new \DateTimeZone('UTC'));
        $errors = \DateTimeImmutable::getLastErrors();
        if (!$time instanceof \DateTimeImmutable || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new \InvalidArgumentException('Published verification timestamp is invalid');
        }
        $routes = self::entries($document['routes'] ?? null, 10_000);
        $previous = self::entries($document['previousPaths'] ?? null, 5_000);
        $paths = [];
        $identities = [];
        foreach ([...$routes, ...$previous] as $index => $route) {
            if (isset($paths[$route['path']]) || ($index < count($routes) && isset($identities[self::identity($route)]))) {
                throw new \InvalidArgumentException('Published route history contains collisions');
            }
            $paths[$route['path']] = true;
            if ($index < count($routes)) {
                $identities[self::identity($route)] = true;
            }
        }
        return new self($document, PublicUrlContext::from($document['publicUrlContext']));
    }

    /** @return array<string, mixed> */
    public function document(): array
    {
        return $this->document;
    }

    /**
     * Moved addresses redirect to the same page's new address, including moves between language
     * layouts. Addresses of a language the next release no longer publishes redirect to the page in
     * the primary language, or to its home page. An address the same page keeps in another language
     * stays served.
     * @return list<CompiledRedirect>
     */
    public function redirectsFor(RouteDiscovery $next, string $profileId, ?string $primary = null): array
    {
        $this->assertScope($next->context, $profileId);
        $byIdentity = [];
        $languages = [];
        foreach ($next->publicationRoutes() as $route) {
            $byIdentity[self::identity($route)] = $route['path'];
            $languages[$route['locale']] = true;
        }
        $paths = array_fill_keys($next->paths(), true);
        $home = isset($paths['/']) ? $next->canonicalPath('/') : null;
        $redirects = [];
        foreach ([...self::entries($this->document['routes'], 10_000), ...self::entries($this->document['previousPaths'], 5_000)] as $old) {
            $destination = $byIdentity[self::identity($old)] ?? null;
            if ($destination === null && $primary !== null && !isset($languages[$old['locale']]) && isset($languages[$primary])) {
                $destination = $byIdentity[$old['translationGroup'] . ':' . $primary] ?? $home;
            }
            if ($destination === $old['path']) {
                continue;
            }
            if (isset($paths[$old['path']])) {
                // The same page in another language now has the address, as when the primary
                // language at the root changes, or the root becomes an alias of the home page.
                if ($next->route($old['path'])->translationGroup === $old['translationGroup']) {
                    continue;
                }
                throw new \InvalidArgumentException('A previously published path is occupied by another route');
            }
            if ($destination !== null) {
                $redirects[] = new CompiledRedirect($old['path'], $destination);
            }
        }
        usort($redirects, static fn(CompiledRedirect $a, CompiledRedirect $b): int => $a->from <=> $b->from);
        if (count($redirects) > 5_000) {
            throw new \InvalidArgumentException('Published redirect collection exceeds its limit');
        }
        return $redirects;
    }

    public function assertScope(?PublicUrlContext $context, string $profileId): void
    {
        if ($profileId !== $this->document['profileId'] || $context?->checksum() !== $this->context->checksum()) {
            throw new \InvalidArgumentException('Published history belongs to another profile or publication context');
        }
    }

    /**
     * Pure projection of a trusted successful activation event. Receipt validation,
     * transaction/CAS and private persistence belong to the publication service.
     * @param array{releaseId: string, artifactSha256: string, activatedAt: string, verificationId: string} $receipt
     */
    public static function afterVerifiedActivation(RouteDiscovery $routes, string $profileId, array $receipt, ?self $previous = null): self
    {
        if ($routes->context === null || count($receipt) !== 4) {
            throw new \InvalidArgumentException('A verified public activation context is required');
        }
        $old = [];
        if ($previous !== null) {
            $previous->redirectsFor($routes, $profileId);
            foreach ([...self::entries($previous->document['previousPaths'], 5_000), ...self::entries($previous->document['routes'], 10_000)] as $entry) {
                $old[$entry['path']] = $entry;
            }
        }
        $current = $routes->publicationRoutes();
        foreach ($current as $entry) {
            unset($old[$entry['path']]);
        }
        ksort($old, SORT_STRING);
        return self::from(['formatVersion' => 1, 'profileId' => $profileId, ...$receipt,
            'publicUrlContext' => $routes->context->document(), 'routes' => $current, 'previousPaths' => array_values($old)]);
    }

    /** @return list<array{path: string, locale: string, translationGroup: string}> */
    private static function entries(mixed $value, int $maximum): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > $maximum) {
            throw new \InvalidArgumentException('Published route collection is invalid');
        }
        foreach ($value as $entry) {
            if (!is_array($entry) || count($entry) !== 3 || array_diff(array_keys($entry), ['path', 'locale', 'translationGroup']) !== []
                || !is_string($entry['path'] ?? null) || !is_string($entry['locale'] ?? null) || !is_string($entry['translationGroup'] ?? null)) {
                throw new \InvalidArgumentException('Published route identity is invalid');
            }
            new DiscoveryRoute($entry['path'], $entry['locale'], $entry['translationGroup']);
        }
        return $value;
    }

    /** @param array{path: string, locale: string, translationGroup: string} $entry */
    private static function identity(array $entry): string
    {
        return $entry['translationGroup'] . ':' . $entry['locale'];
    }
}
