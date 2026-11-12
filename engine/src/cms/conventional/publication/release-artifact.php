<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Publication;

use Utsuri\PortableCms\Conventional\Compiler\ApachePublicDelivery;
use Utsuri\PortableCms\Conventional\Compiler\CompiledRedirect;
use Utsuri\PortableCms\Conventional\Compiler\DiscoveryRoute;
use Utsuri\PortableCms\Conventional\Compiler\PublicUrlContext;
use Utsuri\PortableCms\Conventional\Compiler\RouteDiscovery;
use Utsuri\PortableCms\Conventional\JsonSchemaValidator;
use Utsuri\PortableCms\Conventional\Preview\PreviewBuild;

/** Checked producer/target contract. No paths or delivery rules are editor input. */
final readonly class ReleaseArtifact
{
    public RouteDiscovery $routes;

    /** @var list<string> Canonical routes plus identity-less utility aliases such as the entry page. */
    public array $publicPaths;

    /** @var list<array{path: string, mime: string, byteSize: int, sha256: string, cachePolicy: string}> */
    public array $files;

    /** @var list<CompiledRedirect> */
    public array $redirects;

    public string $checksum;

    private ?string $speculationRulesPath;

    /** @var array<string, mixed> */
    public array $document;

    public function __construct(public string $json, PreviewBuild $inventory, PublicUrlContext $context, public string $profileId, string $schema)
    {
        JsonSchemaValidator::validateArtifactJson($json, $schema);
        $value = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($value) || ($value['includeDrafts'] ?? false) !== false || isset($value['preparationLocales'])
            || ($value['profile']['id'] ?? null) !== $profileId || !isset($value['routeIdentities'], $value['delivery'], $value['publicUrlContext'])
            || PublicUrlContext::from(array_intersect_key($value['publicUrlContext'], array_flip(['formatVersion', 'baseUrl'])))->checksum() !== $context->checksum()
            || ($value['publicUrlContext']['sha256'] ?? null) !== $context->checksum()
            || ($value['delivery']['target'] ?? null) !== 'apache24' || ($value['delivery']['path'] ?? null) !== 'delivery/apache24.conf') {
            throw new PublicationFailure('artifact_not_releasable');
        }
        $this->checksum = hash('sha256', $json);
        $this->document = $value;
        $this->files = $value['files'];
        $routes = [];
        foreach ($value['routeIdentities'] as $route) {
            $routes[] = new DiscoveryRoute($route['path'], $route['locale'], $route['translationGroup']);
        }
        $this->routes = new RouteDiscovery($context, $routes);
        $this->redirects = array_map(static fn(array $entry): CompiledRedirect => new CompiledRedirect($entry['from'], $entry['to'], $entry['status']), $value['redirects']);
        if ($this->holding() && ($this->routes->paths() !== ['/'] || $this->redirects !== [] || $value['publicMedia'] !== []
            || !isset($value['websitePublication']) || count(array_filter($this->files, static fn(array $file): bool => $file['mime'] === 'text/html; charset=utf-8')) !== 1
            || array_intersect(array_column($this->files, 'path'), ['sitemap.xml']) !== [])) {
            throw new PublicationFailure('holding_artifact_invalid');
        }
        $declared = [];
        $routePaths = [];
        $totalBytes = 0;
        $videoBytes = 0;
        $speculationRulesPath = null;
        foreach ($this->files as $file) {
            if (isset($declared[$file['path']]) || $file['path'] === 'artifact.json') {
                throw new PublicationFailure('artifact_inventory_mismatch');
            }
            $extension = strtolower(pathinfo($file['path'], PATHINFO_EXTENSION));
            if (in_array($extension, ['php', 'phtml', 'phar', 'cgi', 'pl', 'py', 'sh'], true)
                || preg_match('/(?:^|\.)(?:php[0-9]*|phtml|phar|cgi)(?:\.|$)/i', $file['path']) === 1
                || preg_match('#(?:^|/)\.#', $file['path']) === 1 || str_starts_with($file['path'], 'cms/')) {
                throw new PublicationFailure('unsafe_public_file');
            }
            $declared[$file['path']] = [$file['byteSize'], $file['sha256']];
            if ($file['mime'] === \Utsuri\PortableCms\Conventional\Content\SpeculationRules::MIME) {
                if ($speculationRulesPath !== null || $this->holding() || !\Utsuri\PortableCms\Conventional\Content\SpeculationRules::validPath($file['path'])
                    || $file['byteSize'] > \Utsuri\PortableCms\Conventional\Content\SpeculationRules::MAX_BYTES) {
                    throw new PublicationFailure('unsafe_speculation_rules');
                }
                $speculationRulesPath = $file['path'];
            }
            $totalBytes += $file['byteSize'];
            if (in_array($file['mime'], ['video/mp4', 'video/webm'], true)) {
                $extension = $file['mime'] === 'video/mp4' ? 'mp4' : 'webm';
                if (preg_match('/\Amedia\/[a-f0-9]{32}-prepared_video\.' . $extension . '\z/', $file['path']) !== 1
                    || $file['byteSize'] > \Utsuri\PortableCms\Conventional\Media\VideoSupport::MAX_BYTES) {
                    throw new PublicationFailure('unsafe_public_video');
                }
                $videoBytes += $file['byteSize'];
            }
            if (str_ends_with($file['path'], 'index.html')) {
                $routePaths[] = '/' . substr($file['path'], 0, -strlen('index.html'));
            }
        }
        if ($totalBytes - $videoBytes > 200 * 1024 * 1024 || $totalBytes > 8 * 1024 * 1024 * 1024) {
            throw new PublicationFailure('release_size_limit');
        }
        $declared['artifact.json'] = [strlen($json), $this->checksum];
        $observed = [];
        foreach ($inventory->artifacts as $entry) {
            $observed[$entry->path] = [$entry->byteSize, $entry->checksum];
        }
        ksort($declared, SORT_STRING);
        ksort($observed, SORT_STRING);
        sort($routePaths, SORT_STRING);
        $this->publicPaths = $routePaths;
        $this->speculationRulesPath = $speculationRulesPath;
        if ($declared !== $observed || array_diff($this->routes->paths(), $routePaths) !== []
            || ($value['delivery']['sha256'] ?? null) !== hash('sha256', $this->rules())) {
            throw new PublicationFailure('artifact_inventory_mismatch');
        }
        FeedTransition::from($this);
    }

    public function rules(): string
    {
        return ApachePublicDelivery::configuration($this->routes->context ?? throw new PublicationFailure('public_context_missing'), $this->redirects, $this->publicPaths, $this->speculationRulesPath);
    }

    public function holding(): bool
    {
        return ($this->document['publicationMode'] ?? 'live') === 'coming_soon';
    }

    /**
     * Assets first, then HTML, then the applied delivery descriptor.
     * @return list<string>
     */
    public function paths(): array
    {
        $assets = [];
        $pages = [];
        foreach ($this->files as $file) {
            if (str_ends_with($file['path'], '.html')) {
                $pages[] = $file['path'];
            } else {
                $assets[] = $file['path'];
            }
        }
        return [...$assets, 'artifact.json', ...$pages, '.htaccess'];
    }
}

final class PublicationFailure extends \RuntimeException
{
    public function __construct(public readonly string $codeName)
    {
        parent::__construct($codeName);
    }
}

final class PublicationConflict extends \RuntimeException {}
