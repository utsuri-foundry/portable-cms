<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Compiler;

use Utsuri\PortableCms\Conventional\Content\ProfilePackage;
use Utsuri\PortableCms\Conventional\JsonSchemaValidator;
use Utsuri\PortableCms\Conventional\Preview\PreviewArtifact;

final readonly class StaticCompilation
{
    /**
     * @param array<string, mixed> $manifest
     * @param list<array{path: string, mime: string, byteSize: int, sha256: string, cachePolicy: string}> $files
     */
    public function __construct(
        public string $outputRoot,
        public array $manifest,
        public array $files,
    ) {}
}

final class StaticSiteCompiler
{
    public function __construct(
        private readonly string $artifactSchemaPath,
        private readonly string $searchSchemaPath,
        private readonly ?SharingImageProcessor $sharingImageProcessor = null,
        private readonly ?WebsiteIconProcessor $websiteIconProcessor = null,
    ) {
        if (!is_file($artifactSchemaPath) || !is_file($searchSchemaPath)) {
            throw new \InvalidArgumentException('Compiler schemas are unavailable');
        }
    }

    public function compile(
        ProfilePackage $package,
        FrozenSnapshot $snapshot,
        ProfileSiteBuilder $builder,
        FilesystemMediaSource $mediaSource,
        CompilationRequest $request,
        string $outputRoot,
        ?\Utsuri\PortableCms\Conventional\Design\FontStore $fontStore = null,
    ): StaticCompilation {
        self::assertProfile($package, $snapshot);
        $vimeo = VimeoPresentation::enabled($package->manifest, $snapshot);
        foreach ($snapshot->media as $media) {
            if (!str_starts_with($media->mime, 'video/')) {
                continue;
            }
            $facts = \Utsuri\PortableCms\Conventional\Media\PreencodedVideoProcessor::inspectFile($mediaSource->file($media), $media->mime, $media->byteSize);
            if ($facts['width'] !== $media->width || $facts['height'] !== $media->height
                || ($media->durationMilliseconds !== null && ($facts['durationMilliseconds'] !== $media->durationMilliseconds || $facts['hasAudio'] !== $media->hasAudio))) {
                throw new \InvalidArgumentException('Frozen video metadata disagrees with verified bytes');
            }
        }
        if (file_exists($outputRoot) || is_link($outputRoot)) {
            throw new \RuntimeException('Compiler output root must not exist');
        }
        $parent = realpath(dirname($outputRoot));
        if ($parent === false || !is_dir($parent) || is_link(dirname($outputRoot))) {
            throw new \RuntimeException('Compiler output parent is unavailable');
        }
        $targetRoot = $parent . DIRECTORY_SEPARATOR . basename($outputRoot);
        $workingRoot = $targetRoot . '.working-' . $request->releaseId;
        if (file_exists($workingRoot) || is_link($workingRoot)) {
            throw new \RuntimeException('Compiler working root already exists');
        }
        if (!mkdir($workingRoot, 0755)) {
            throw new \RuntimeException('Compiler working root could not be created');
        }

        try {
            $files = [];
            $sharingImages = [];
            if ($builder instanceof SharingSiteBuilder && $snapshot->publicUrlContext !== null) {
                $selected = $builder->sharingMediaIds($snapshot);
                if (!array_is_list($selected) || count($selected) > 1000 || count(array_unique($selected)) !== count($selected)) {
                    throw new \InvalidArgumentException('Profile sharing media selection is invalid');
                }
                sort($selected, SORT_STRING);
                foreach ($selected as $id) {
                    $source = $snapshot->media($id);
                    if ($source === null || $this->sharingImageProcessor === null) {
                        throw new \InvalidArgumentException('Frozen sharing media or its processor is unavailable');
                    }
                    $output = $this->sharingImageProcessor->process($source, $mediaSource->file($source));
                    if ($output->image->mediaId !== $id || $output->image->sourceSha256 !== $source->checksum) {
                        throw new \InvalidArgumentException('Sharing processor returned a different source identity');
                    }
                    if (!in_array($output->image->path, array_column($files, 'path'), true)) {
                        $this->writeFile($workingRoot, $output->image->path, $output->bytes, 'image/jpeg', 'immutable', $files);
                    }
                    $image = $output->image;
                    $sharingImages[] = new SharingImage($image->mediaId, $image->sourceSha256, $image->sha256, $image->byteSize, $image->processorVersion, $source->text, $snapshot->defaultLocale);
                    unset($output);
                }
            }
            $icon = null;
            if ($builder instanceof WebsiteIconSiteBuilder && ($iconId = $builder->websiteIconMediaId($snapshot)) !== null) {
                $source = $snapshot->media($iconId);
                if ($source === null || $this->websiteIconProcessor === null) {
                    throw new \InvalidArgumentException('Frozen website icon or its processor is unavailable');
                }
                $output = $this->websiteIconProcessor->process($source, $mediaSource->file($source));
                $icon = $output->icon;
                if ($icon->mediaId !== $iconId || $icon->sourceSha256 !== $source->checksum) {
                    throw new \InvalidArgumentException('Website icon processor returned a different source identity');
                }
                foreach ($icon->files as $file) {
                    $this->writeFile($workingRoot, $file['path'], $output->bytes[$file['size']], 'image/png', 'immutable', $files);
                }
                unset($output);
            }
            $site = match (true) {
                $builder instanceof WebsiteIconSiteBuilder => $builder->buildWithAssets($snapshot, new SharingImages($sharingImages), $icon),
                $builder instanceof SharingSiteBuilder && $snapshot->publicUrlContext !== null => $builder->buildWithSharing($snapshot, new SharingImages($sharingImages)),
                default => $builder->build($snapshot),
            };
            MediaPublicationValidation::assertSite($site, $snapshot);
            if ($snapshot->publicUrlContext?->baseUrl !== $site->discovery?->context?->baseUrl) {
                throw new \InvalidArgumentException('Discovery graph does not identify the frozen public URL context');
            }
            if ($site->discovery !== null) {
                $this->writeFile($workingRoot, 'robots.txt', $site->discovery->robots(), 'text/plain; charset=utf-8', 'route', $files);
                if ($snapshot->publicUrlContext !== null) {
                    $this->writeFile($workingRoot, 'sitemap.xml', $site->discovery->sitemap(), 'application/xml; charset=utf-8', 'route', $files);
                }
            }
            $speculationRulesPath = null;
            foreach ($vimeo ? VimeoPresentation::assets() : [] as $path => $asset) {
                $this->writeFile($workingRoot, $path, $asset['body'], $asset['mime'], 'immutable', $files);
            }
            foreach ($package->manifest->assets as $asset) {
                if (\Utsuri\PortableCms\Conventional\Content\SpeculationRules::asset($asset)) {
                    $speculationRulesPath = $asset->path;
                    $this->writeFile($workingRoot, $asset->path, \Utsuri\PortableCms\Conventional\Content\SpeculationRules::compile($package->assetBytes($asset->path, \Utsuri\PortableCms\Conventional\Content\SpeculationRules::MAX_BYTES)), $asset->mime, 'route', $files);
                    continue;
                }
                $this->copyFile(
                    $workingRoot,
                    $asset->path,
                    $package->assetPath($asset->path),
                    $asset->mime,
                    'immutable',
                    $files,
                );
            }
            if ($snapshot->designRegistries !== null) {
                $fonts = ArtifactStyles::fonts($package, $snapshot);
                foreach ($fonts['uploads'] as $font) {
                    if ($fontStore === null) {
                        throw new \RuntimeException('Frozen uploaded font source is unavailable');
                    }
                    $path = $font['path'];
                    if (!in_array($path, array_column($files, 'path'), true)) {
                        $this->copyFile($workingRoot, $path, $fontStore->file($font['sha256'], $font['byteSize']), 'font/woff2', 'immutable', $files);
                    }
                    $copied = array_values(array_filter($files, static fn(array $file): bool => $file['path'] === $path))[0];
                    if ($copied['byteSize'] !== $font['byteSize'] || $copied['sha256'] !== $font['sha256']) {
                        throw new \RuntimeException('Copied font differs from its frozen identity');
                    }
                }
                $this->writeFile($workingRoot, 'assets/design.css', (string) ArtifactStyles::design($snapshot, $fonts['paths']), 'text/css; charset=utf-8', 'immutable', $files);
            }
            $compositionCss = ArtifactStyles::composition($package, $snapshot);
            if ($compositionCss !== '') {
                $this->writeFile($workingRoot, 'assets/composition.css', $compositionCss, 'text/css; charset=utf-8', 'immutable', $files);
            }
            foreach ($site->routes as $route) {
                $this->writeFile(
                    $workingRoot,
                    self::routePath($route->route),
                    VimeoPresentation::document($route->html, $route->route, $vimeo),
                    'text/html; charset=utf-8',
                    'route',
                    $files,
                );
            }
            foreach ($site->searchIndexes as $index) {
                $json = self::json($index->document);
                JsonSchemaValidator::validateSearchJson($json, self::read($this->searchSchemaPath));
                $this->writeFile(
                    $workingRoot,
                    'search/' . $index->locale . '.json',
                    $json,
                    'application/json',
                    'immutable',
                    $files,
                );
            }
            $publicMedia = [];
            $focalPoints = [];
            foreach ($site->publicMediaIds as $mediaId) {
                $media = $snapshot->media($mediaId);
                if (!$media instanceof FrozenMedia) {
                    throw new \InvalidArgumentException('Profile selected media outside the frozen snapshot');
                }
                if (str_starts_with($media->mime, 'image/')) {
                    $focalPoints[] = $media->focalPoint;
                }
                foreach ($media->renditions() as $rendition) {
                    $path = FilesystemMediaSource::publicPath($rendition);
                    $this->copyFile(
                        $workingRoot,
                        $path,
                        $mediaSource->file($rendition),
                        $rendition->mime,
                        'immutable',
                        $files,
                    );
                    $publicMedia[] = [
                        'mediaId' => $media->id,
                        'variant' => $rendition->variant,
                        'path' => $path,
                        'sha256' => $rendition->checksum,
                    ];
                }
            }
            $this->writeFile($workingRoot, 'assets/media-focal.css', MediaFocalStyle::stylesheet($focalPoints), 'text/css; charset=utf-8', 'immutable', $files);
            $feed = ContentFeedSnapshot::build($package->manifest, $snapshot);
            if ($feed !== null) {
                foreach ($feed->documents as $path => $json) {
                    $this->writeFile($workingRoot, $feed->directory() . $path, $json, 'application/json', 'immutable', $files);
                }
                foreach ($feed->media as $path => $image) {
                    $this->copyFile($workingRoot, $path, $mediaSource->file($image), $image->mime, 'immutable', $files);
                }
                $this->writeFile($workingRoot, $feed->directory() . 'manifest.json', ContentFeedSnapshot::json($feed->manifest), 'application/json', 'immutable', $files);
            }
            usort($files, static fn(array $left, array $right): int => $left['path'] <=> $right['path']);
            usort($publicMedia, static fn(array $left, array $right): int => $left['path'] <=> $right['path']);
            $redirects = array_map(
                static fn(CompiledRedirect $redirect): array => $redirect->toArray(),
                $site->redirects,
            );
            if ($snapshot->publishedSlugHistory !== null) {
                if ($site->discovery === null) {
                    throw new \InvalidArgumentException('Published history requires verified route identities');
                }
                foreach ($snapshot->publishedSlugHistory->redirectsFor($site->discovery, $snapshot->profileId, $snapshot->defaultLocale) as $redirect) {
                    $redirects[] = $redirect->toArray();
                }
            }
            usort($redirects, static fn(array $left, array $right): int => $left['from'] <=> $right['from']);
            self::assertRedirects($redirects, $files);
            $delivery = null;
            if ($site->discovery !== null && $snapshot->publicUrlContext !== null) {
                $rules = ApachePublicDelivery::configuration($snapshot->publicUrlContext, array_map(static fn(array $redirect): CompiledRedirect => new CompiledRedirect($redirect['from'], $redirect['to'], $redirect['status']), $redirects), $site->discovery->paths(), $speculationRulesPath, $vimeo);
                $this->writeFile($workingRoot, 'delivery/apache24.conf', $rules, 'text/plain; charset=utf-8', 'route', $files);
                $delivery = ['formatVersion' => 1, 'target' => 'apache24', 'path' => 'delivery/apache24.conf', 'sha256' => hash('sha256', $rules)];
                usort($files, static fn(array $a, array $b): int => $a['path'] <=> $b['path']);
            }
            $manifest = [
                ...($vimeo ? ['frameSources' => [VimeoPresentation::ORIGIN]] : []),
                'formatVersion' => 1,
                'releaseId' => $request->releaseId,
                'snapshotId' => $snapshot->id,
                'createdAt' => self::timestamp($request->createdAt),
                'profile' => [
                    'id' => $package->manifest->id,
                    'version' => $package->manifest->version,
                    'sha256' => $package->checksum,
                ],
                'compiler' => $request->compiler->toArray(),
                'searchFormatVersion' => 1,
                'files' => $files,
                'redirects' => $redirects,
                'publicMedia' => $publicMedia,
            ];
            if ($snapshot->includeDrafts) {
                $manifest['includeDrafts'] = true;
            }
            if ($feed !== null) {
                $manifest['contentFeed'] = $feed->manifest;
            }
            if ($snapshot->preparationLocales !== []) {
                $manifest['preparationLocales'] = $snapshot->preparationLocales;
            }
            if ($snapshot->designRegistries !== null) {
                $manifest['designRegistries'] = ['revision' => $snapshot->designRegistries->revision(), 'sha256' => $snapshot->designRegistries->checksum()];
            }
            if ($snapshot->publicUrlContext !== null) {
                $manifest['publicUrlContext'] = [...$snapshot->publicUrlContext->document(), 'sha256' => $snapshot->publicUrlContext->checksum()];
            }
            if ($sharingImages !== []) {
                $manifest['sharingImages'] = array_map(static fn(SharingImage $image): array => $image->document(), $sharingImages);
            }
            if ($icon !== null) {
                $manifest['websiteIcon'] = $icon->document();
            }
            if ($site->discovery !== null) {
                $manifest['routeIdentities'] = $site->discovery->publicationRoutes();
            }
            if ($delivery !== null) {
                $manifest['delivery'] = $delivery;
            }
            $manifestJson = self::json($manifest);
            JsonSchemaValidator::validateArtifactJson($manifestJson, self::read($this->artifactSchemaPath));
            $this->writeUntrackedManifest($workingRoot, $manifestJson);
            $this->verifyFiles($workingRoot, $files);
            if (!rename($workingRoot, $targetRoot)) {
                throw new \RuntimeException('Compiler artifact could not be committed');
            }
            return new StaticCompilation($targetRoot, $manifest, $files);
        } catch (\Throwable $error) {
            self::removeTree($workingRoot);
            throw $error;
        }
    }

    private static function assertProfile(ProfilePackage $package, FrozenSnapshot $snapshot): void
    {
        if (
            $snapshot->profileId !== $package->manifest->id
            || $snapshot->profileVersion !== $package->manifest->version
            || !hash_equals($package->checksum, $snapshot->profileChecksum)
        ) {
            throw new \InvalidArgumentException('Compiler inputs do not identify one profile package');
        }
    }

    /**
     * @param list<array{path: string, mime: string, byteSize: int, sha256: string, cachePolicy: string}> $files
     */
    private function writeFile(
        string $root,
        string $path,
        string $contents,
        string $mime,
        string $cachePolicy,
        array &$files,
    ): void {
        if ($contents === '') {
            throw new \InvalidArgumentException('Compiler refuses empty artifacts');
        }
        $target = self::target($root, $path);
        $bytes = file_put_contents($target, $contents, LOCK_EX);
        if (!is_int($bytes) || $bytes !== strlen($contents) || !chmod($target, 0644)) {
            throw new \RuntimeException('Compiler artifact could not be written');
        }
        self::appendFile($files, $path, $mime, $bytes, hash('sha256', $contents), $cachePolicy);
    }

    /**
     * @param list<array{path: string, mime: string, byteSize: int, sha256: string, cachePolicy: string}> $files
     */
    private function copyFile(
        string $root,
        string $path,
        string $source,
        string $mime,
        string $cachePolicy,
        array &$files,
    ): void {
        $target = self::target($root, $path);
        if (!copy($source, $target) || !chmod($target, 0644)) {
            throw new \RuntimeException('Compiler artifact could not be copied');
        }
        $size = filesize($target);
        $checksum = hash_file('sha256', $target);
        if (!is_int($size) || !is_string($checksum)) {
            throw new \RuntimeException('Compiler copied artifact could not be verified');
        }
        self::appendFile($files, $path, $mime, $size, $checksum, $cachePolicy);
    }

    /**
     * @param list<array{path: string, mime: string, byteSize: int, sha256: string, cachePolicy: string}> $files
     */
    private static function appendFile(
        array &$files,
        string $path,
        string $mime,
        int $bytes,
        string $checksum,
        string $cachePolicy,
    ): void {
        foreach ($files as $file) {
            if ($file['path'] === $path) {
                throw new \InvalidArgumentException('Compiler produced a duplicate artifact path');
            }
        }
        $files[] = [
            'path' => $path,
            'mime' => self::manifestMime($mime),
            'byteSize' => $bytes,
            'sha256' => $checksum,
            'cachePolicy' => $cachePolicy,
        ];
    }

    private function writeUntrackedManifest(string $root, string $json): void
    {
        $target = self::target($root, 'artifact.json');
        $bytes = file_put_contents($target, $json, LOCK_EX);
        if (!is_int($bytes) || $bytes !== strlen($json) || !chmod($target, 0644)) {
            throw new \RuntimeException('Compiler manifest could not be written');
        }
    }

    /**
     * @param list<array{path: string, mime: string, byteSize: int, sha256: string, cachePolicy: string}> $files
     */
    private function verifyFiles(string $root, array $files): void
    {
        foreach ($files as $file) {
            $path = self::target($root, $file['path'], false);
            $size = filesize($path);
            $checksum = hash_file('sha256', $path);
            if (
                !is_int($size)
                || !is_string($checksum)
                || $size !== $file['byteSize']
                || !hash_equals($file['sha256'], $checksum)
            ) {
                throw new \RuntimeException('Compiler artifact verification failed');
            }
        }
    }

    /**
     * @param list<array{from: string, to: string, status: int}> $redirects
     * @param list<array{path: string, mime: string, byteSize: int, sha256: string, cachePolicy: string}> $files
     */
    private static function assertRedirects(array $redirects, array $files): void
    {
        $routes = [];
        foreach ($files as $file) {
            if ($file['cachePolicy'] === 'route' && $file['mime'] === 'text/html; charset=utf-8') {
                $routes[self::pathRoute($file['path'])] = true;
            }
        }
        $targets = [];
        foreach ($redirects as $redirect) {
            if (isset($targets[$redirect['from']]) || isset($routes[$redirect['from']])) {
                throw new \InvalidArgumentException('Compiler redirect source is duplicated or occupied');
            }
            $targets[$redirect['from']] = $redirect['to'];
        }
        foreach (array_keys($targets) as $source) {
            $visited = [];
            $target = $source;
            while (isset($targets[$target])) {
                if (isset($visited[$target])) {
                    throw new \InvalidArgumentException('Compiler redirect graph contains a cycle');
                }
                $visited[$target] = true;
                $target = $targets[$target];
            }
            if (!isset($routes[$target])) {
                throw new \InvalidArgumentException('Compiler redirect target does not resolve');
            }
            if (count($visited) > 1) {
                throw new \InvalidArgumentException('Compiler redirects must resolve without chains');
            }
        }
    }

    private static function routePath(string $route): string
    {
        if (!CompiledRoute::validRoute($route)) {
            throw new \InvalidArgumentException('Compiler route is unsafe');
        }
        return ltrim($route, '/') . 'index.html';
    }

    private static function pathRoute(string $path): string
    {
        if ($path === 'index.html') {
            return '/';
        }
        if (!str_ends_with($path, '/index.html')) {
            throw new \InvalidArgumentException('Compiler route artifact is invalid');
        }
        return '/' . substr($path, 0, -strlen('index.html'));
    }

    private static function target(string $root, string $path, bool $create = true): string
    {
        PreviewArtifact::assertPath($path);
        $target = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
        $directory = dirname($target);
        if ($create && !is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new \RuntimeException('Compiler artifact directory could not be created');
        }
        if (!is_dir($directory) || is_link($directory) || !str_starts_with($directory, $root)) {
            throw new \RuntimeException('Compiler artifact directory is unsafe');
        }
        return $target;
    }

    /** @param array<string, mixed> $value */
    public static function json(array $value): string
    {
        return json_encode(
            $value,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ) . "\n";
    }

    private static function manifestMime(string $mime): string
    {
        return match ($mime) {
            'text/css' => 'text/css; charset=utf-8',
            'text/javascript' => 'text/javascript; charset=utf-8',
            default => $mime,
        };
    }

    private static function timestamp(\DateTimeImmutable $time): string
    {
        return $time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    private static function read(string $path): string
    {
        $contents = file_get_contents($path);
        if (!is_string($contents)) {
            throw new \RuntimeException('Compiler schema could not be read');
        }
        return $contents;
    }

    private static function removeTree(string $root): void
    {
        if (!is_dir($root) || is_link($root)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $path = $entry->getPathname();
            if ($entry->isDir() && !$entry->isLink()) {
                if (!rmdir($path)) {
                    throw new \RuntimeException('Compiler cleanup failed');
                }
            } elseif (!unlink($path)) {
                throw new \RuntimeException('Compiler cleanup failed');
            }
        }
        if (!rmdir($root)) {
            throw new \RuntimeException('Compiler cleanup failed');
        }
    }
}
