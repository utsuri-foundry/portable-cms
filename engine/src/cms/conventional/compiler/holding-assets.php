<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Compiler;

use Utsuri\PortableCms\Conventional\Content\ProfilePackage;
use Utsuri\PortableCms\Conventional\Publication\WebsitePublication;

/** Optional asset-aware hook; the existing two-argument renderer remains supported. */
interface HoldingPageAssetRenderer
{
    /** @return list<string> Paths in the verified package manifest. */
    public function assets(): array;

    public function render(WebsitePublication $settings, ?WebsiteIcon $icon, HoldingAssets $assets): HoldingPresentation;
}

/** A small verified copy plan and mount-independent URLs for the holding document and CSS. */
final readonly class HoldingAssets
{
    public const MAX_FILES = 16;
    public const MAX_FILE_BYTES = 2 * 1024 * 1024;
    public const MAX_TOTAL_BYTES = 8 * 1024 * 1024;

    /**
     * @param array<string, string> $paths
     * @param list<array{path: string, bytes: string, mime: string}> $files
     */
    private function __construct(private array $paths, public array $files) {}

    /** @param list<string> $requested */
    public static function load(ProfilePackage $package, array $requested): self
    {
        if (!array_is_list($requested) || count($requested) > self::MAX_FILES) {
            throw new \InvalidArgumentException('Holding asset list exceeds its bounds');
        }
        $declared = [];
        foreach ($package->manifest->assets as $asset) {
            $declared[$asset->path] = $asset;
        }
        $selected = [];
        $total = 0;
        foreach ($requested as $path) {
            if (!is_string($path) || !isset($declared[$path]) || isset($selected[$path])) {
                throw new \InvalidArgumentException('Holding asset is undeclared or repeated');
            }
            $asset = $declared[$path];
            $extension = match ($asset->mime) {
                'font/woff2' => $asset->role === 'font' ? 'woff2' : null,
                'image/png' => 'png',
                'image/jpeg' => 'jpg',
                'image/webp' => 'webp',
                'image/svg+xml' => 'svg',
                default => null,
            };
            if ($extension === null || (!str_starts_with($asset->mime, 'font/') && !in_array($asset->role, ['image', 'icon'], true))) {
                throw new \InvalidArgumentException('Holding assets must be packaged fonts or images');
            }
            $total += $asset->byteSize;
            if ($asset->byteSize > self::MAX_FILE_BYTES || $total > self::MAX_TOTAL_BYTES) {
                throw new \InvalidArgumentException('Holding asset bytes exceed their bounds');
            }
            $selected[$path] = ['asset' => $asset, 'path' => 'assets/holding/' . $asset->checksum . '.' . $extension];
        }
        $paths = [];
        $files = [];
        foreach ($selected as $source => $entry) {
            $bytes = $package->assetBytes($source, self::MAX_FILE_BYTES);
            $paths[$source] = $entry['path'];
            // Identical declarations share one immutable artifact file, never duplicate inventory entries.
            $files[$entry['path']] = ['path' => $entry['path'], 'bytes' => $bytes, 'mime' => $entry['asset']->mime];
        }
        ksort($files, SORT_STRING);
        return new self($paths, array_values($files));
    }

    public function url(string $source): string
    {
        return ArtifactUrl::relative('/' . $this->path($source), '/');
    }

    /** Stylesheets live directly in assets/, so their URLs have a different relative base. */
    public function cssUrl(string $source): string
    {
        return './' . substr($this->path($source), strlen('assets/'));
    }

    private function path(string $source): string
    {
        return $this->paths[$source] ?? throw new \InvalidArgumentException('Holding asset was not requested');
    }
}
