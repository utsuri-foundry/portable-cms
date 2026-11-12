<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Content;

use Utsuri\PortableCms\Conventional\HostIo;

final readonly class ProfilePackage
{
    /** @var array<string, string> */
    private array $rendererPaths;

    /** @var array<string, string> */
    private array $assetPaths;

    /**
     * @param array<string, string> $rendererPaths
     * @param array<string, string> $assetPaths
     */
    private function __construct(
        public string $root,
        public ProfileManifest $manifest,
        public string $checksum,
        array $rendererPaths,
        array $assetPaths,
        /** Administration-only names, deliberately outside the package checksum and compiler input. */
        public AdministrationLabels $administrationLabels,
    ) {
        $this->rendererPaths = $rendererPaths;
        $this->assetPaths = $assetPaths;
    }

    public static function load(string $root, string $engineVersion = ProfileManifest::ENGINE_VERSION, bool $allowLegacyProfile = false): self
    {
        $resolved = realpath($root);
        if ($resolved === false || !is_dir($resolved) || is_link($root)) {
            throw new \InvalidArgumentException('Profile package root is invalid');
        }
        $resolved = rtrim($resolved, DIRECTORY_SEPARATOR);
        $manifestPath = self::file($resolved, 'profile.json');
        $manifest = ProfileManifest::load($manifestPath, $engineVersion, $allowLegacyProfile);
        $entries = [self::entry('profile.json', $manifestPath)];

        $rendererPaths = [];
        foreach ($manifest->renderers as $renderer) {
            $path = self::file($resolved, $renderer->entrypoint);
            $rendererPaths[$renderer->id] = $path;
            $entries[] = self::entry($renderer->entrypoint, $path);
        }
        foreach (self::supportFiles($resolved) as $relativePath => $path) {
            $entries[] = self::entry($relativePath, $path);
        }

        $assetPaths = [];
        foreach ($manifest->assets as $asset) {
            $path = self::file($resolved, $asset->path);
            $size = filesize($path);
            $checksum = hash_file('sha256', $path);
            if (
                !is_int($size)
                || !is_string($checksum)
                || $size !== $asset->byteSize
                || !hash_equals($asset->checksum, $checksum)
            ) {
                throw new \InvalidArgumentException('Profile asset integrity check failed');
            }
            $assetPaths[$asset->path] = $path;
            if (SpeculationRules::asset($asset)) {
                $bytes = HostIo::attempt(static fn() => file_get_contents($path, false, null, 0, SpeculationRules::MAX_BYTES + 1))->value;
                if (!is_string($bytes) || !hash_equals($asset->checksum, hash('sha256', $bytes))) {
                    throw new \InvalidArgumentException('Profile speculation rules integrity check failed');
                }
                SpeculationRules::compile($bytes);
            }
            $entries[] = self::entry($asset->path, $path);
        }

        if ($manifest->designRegistries !== null) {
            foreach ($manifest->designRegistries->faces() as $font) {
                $matches = array_filter($manifest->assets, static fn(ProfileAsset $asset): bool => $asset->mime === 'font/woff2' && $asset->checksum === $font['sha256'] && $asset->byteSize === $font['byteSize']);
                if ($font['origin'] !== 'package' || count($matches) !== 1) {
                    throw new \InvalidArgumentException('Profile design font must identify one verified packaged WOFF2 asset');
                }
                $asset = array_values($matches)[0];
                \Utsuri\PortableCms\Conventional\Design\Woff2::verify((string) file_get_contents($assetPaths[$asset->path]));
            }
        }
        usort(
            $entries,
            static fn(array $left, array $right): int => $left['path'] <=> $right['path'],
        );
        $digestInput = '';
        foreach ($entries as $entry) {
            $digestInput .= $entry['path'] . "\0" . $entry['size'] . "\0" . $entry['checksum'] . "\n";
        }
        $labels = AdministrationLabels::load($resolved, $manifest);
        if (!$allowLegacyProfile) {
            AdministrationMenuLabels::assert($manifest, $labels);
        }
        return new self(
            $resolved,
            $manifest,
            hash('sha256', $digestInput),
            $rendererPaths,
            $assetPaths,
            $labels,
        );
    }

    public function rendererPath(string $id): string
    {
        return $this->rendererPaths[$id]
            ?? throw new \InvalidArgumentException('Profile renderer is not installed');
    }

    public function siteBuilder(): \Utsuri\PortableCms\Conventional\Compiler\ProfileSiteBuilder
    {
        // This conventional entrypoint is part of the verified, trusted src package.
        $factory = require self::file($this->root, 'src/compiler.php');
        if (!$factory instanceof \Closure) {
            throw new \InvalidArgumentException('Profile compiler factory is invalid');
        }
        $builder = $factory($this);
        if (!$builder instanceof \Utsuri\PortableCms\Conventional\Compiler\ProfileSiteBuilder) {
            throw new \InvalidArgumentException('Profile compiler is unavailable');
        }
        return $builder;
    }

    public function assetPath(string $path): string
    {
        return $this->assetPaths[$path]
            ?? throw new \InvalidArgumentException('Profile asset is not installed');
    }

    /** Reverify bounded bytes at use time, including paths replaced since package loading. */
    public function assetBytes(string $path, int $maximumBytes): string
    {
        $asset = null;
        foreach ($this->manifest->assets as $entry) {
            if ($entry->path === $path) {
                $asset = $entry;
                break;
            }
        }
        if ($asset === null || $maximumBytes < 1 || $maximumBytes > 268_435_456 || $asset->byteSize > $maximumBytes) {
            throw new \InvalidArgumentException('Profile asset is unavailable within the byte limit');
        }
        $file = self::file($this->root, $path);
        $bytes = HostIo::attempt(static fn() => file_get_contents($file, false, null, 0, $maximumBytes + 1))->value;
        if (!is_string($bytes) || strlen($bytes) !== $asset->byteSize || !hash_equals($asset->checksum, hash('sha256', $bytes))) {
            throw new \InvalidArgumentException('Profile asset integrity check failed');
        }
        return $bytes;
    }

    public function holdingRenderer(): \Utsuri\PortableCms\Conventional\Compiler\HoldingPageRenderer|\Utsuri\PortableCms\Conventional\Compiler\HoldingPageAssetRenderer|null
    {
        if (!file_exists($this->root . '/src/holding.php') && !is_link($this->root . '/src/holding.php')) {
            return null;
        }
        $factory = require self::file($this->root, 'src/holding.php');
        $renderer = $factory instanceof \Closure ? $factory($this) : null;
        if (!$renderer instanceof \Utsuri\PortableCms\Conventional\Compiler\HoldingPageRenderer && !$renderer instanceof \Utsuri\PortableCms\Conventional\Compiler\HoldingPageAssetRenderer) {
            throw new \InvalidArgumentException('Profile holding renderer is invalid');
        }
        return $renderer;
    }

    /** @return array{path: string, size: int, checksum: string} */
    private static function entry(string $relativePath, string $path): array
    {
        $size = filesize($path);
        $checksum = hash_file('sha256', $path);
        if (!is_int($size) || $size < 1 || !is_string($checksum)) {
            throw new \InvalidArgumentException('Profile package file could not be verified');
        }
        return ['path' => $relativePath, 'size' => $size, 'checksum' => $checksum];
    }

    private static function file(string $root, string $relativePath): string
    {
        if (!ProfileManifest::safePath($relativePath)) {
            throw new \InvalidArgumentException('Profile package path is invalid');
        }
        $cursor = $root;
        foreach (explode('/', $relativePath) as $segment) {
            $cursor .= DIRECTORY_SEPARATOR . $segment;
            if (is_link($cursor)) {
                throw new \InvalidArgumentException('Profile package cannot contain symbolic links');
            }
        }
        $resolved = realpath($cursor);
        if (
            $resolved === false
            || !is_file($resolved)
            || !str_starts_with($resolved, $root . DIRECTORY_SEPARATOR)
        ) {
            throw new \InvalidArgumentException('Profile package file is unavailable');
        }
        return $resolved;
    }

    /** @return array<string, string> */
    private static function supportFiles(string $root): array
    {
        $files = [];
        foreach (['src' => ['php'], 'licenses' => ['txt']] as $name => $extensions) {
            $directory = $root . DIRECTORY_SEPARATOR . $name;
            if (!is_dir($directory) || is_link($directory)) {
                throw new \InvalidArgumentException('Profile support files are unavailable');
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($iterator as $file) {
                if (
                    !$file->isFile()
                    || $file->isLink()
                    || !in_array($file->getExtension(), $extensions, true)
                ) {
                    throw new \InvalidArgumentException('Profile support files contain an invalid entry');
                }
                $path = $file->getRealPath();
                if (!is_string($path) || !str_starts_with($path, $directory . DIRECTORY_SEPARATOR)) {
                    throw new \InvalidArgumentException('Profile support files are unsafe');
                }
                $relativePath = str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen($root) + 1));
                $files[$relativePath] = $path;
            }
        }
        if ($files === [] || count($files) > 128) {
            throw new \InvalidArgumentException('Profile support files are invalid');
        }
        ksort($files, SORT_STRING);
        return $files;
    }
}
