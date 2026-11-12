<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Compiler;

use Utsuri\PortableCms\Conventional\HostIo;

/** Checksum-addressed PNG derivatives of a verified frozen raster. */
final readonly class WebsiteIcon
{
    /** @param list<array{size: int, path: string, sha256: string, byteSize: int}> $files */
    public function __construct(public string $mediaId, public string $sourceSha256, public string $processorVersion, public array $files)
    {
        if (preg_match('/\A[a-f0-9]{32}\z/', $mediaId) !== 1 || preg_match('/\A[a-f0-9]{64}\z/', $sourceSha256) !== 1
            || $processorVersion === '' || strlen($processorVersion) > 180 || preg_match('/[\x00-\x1f\x7f]/', $processorVersion) === 1
            || !array_is_list($files) || array_column($files, 'size') !== [32, 180, 512]) {
            throw new \InvalidArgumentException('Website icon identity is invalid');
        }
        foreach ($files as $file) {
            if (preg_match('/\A[a-f0-9]{64}\z/', $file['sha256']) !== 1 || $file['path'] !== 'icons/' . $file['sha256'] . '.png'
                || $file['byteSize'] < 1 || $file['byteSize'] > 10 * 1024 * 1024) {
                throw new \InvalidArgumentException('Website icon derivative is invalid');
            }
        }
    }

    /** @return array<string, mixed> */
    public function document(): array
    {
        return ['mediaId' => $this->mediaId, 'sourceSha256' => $this->sourceSha256,
            'recipe' => ['id' => 'imagick-website-icon-png', 'version' => 1, 'processorVersion' => $this->processorVersion], 'files' => $this->files];
    }

    public function head(string $route): string
    {
        $html = '';
        foreach ($this->files as $file) {
            $html .= '<link rel="' . ($file['size'] === 180 ? 'apple-touch-icon' : 'icon') . '" href="'
                . ArtifactUrl::escaped('/' . $file['path'], $route) . '" sizes="' . $file['size'] . 'x' . $file['size'] . '" type="image/png">';
        }
        return $html;
    }
}

final readonly class WebsiteIconOutput
{
    /** @param array<int, string> $bytes */
    public function __construct(public WebsiteIcon $icon, public array $bytes)
    {
        if (array_keys($bytes) !== [32, 180, 512]) {
            throw new \InvalidArgumentException('Website icon output is incomplete');
        }
        foreach ($icon->files as $file) {
            $data = $bytes[$file['size']];
            if (!str_starts_with($data, "\x89PNG\r\n\x1a\n") || strlen($data) !== $file['byteSize']
                || !hash_equals($file['sha256'], hash('sha256', $data))) {
                throw new \InvalidArgumentException('Website icon bytes differ from their verified identity');
            }
            $geometry = HostIo::attempt(static fn() => getimagesizefromstring($data))->value;
            if ($geometry === false
                || $geometry[0] !== $file['size'] || $geometry[1] !== $file['size'] || $geometry['mime'] !== 'image/png') {
                throw new \InvalidArgumentException('Website icon bytes differ from their verified identity');
            }
        }
    }
}

interface WebsiteIconProcessor
{
    public function process(FrozenMedia $source, string $verifiedPath): WebsiteIconOutput;
}

interface WebsiteIconSiteBuilder extends SharingSiteBuilder
{
    public function websiteIconMediaId(FrozenSnapshot $snapshot): ?string;

    public function buildWithAssets(FrozenSnapshot $snapshot, SharingImages $images, ?WebsiteIcon $icon): ProfileSiteBuild;
}
