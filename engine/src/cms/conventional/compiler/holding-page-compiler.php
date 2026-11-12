<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Compiler;

use Utsuri\PortableCms\Conventional\Content\ProfilePackage;
use Utsuri\PortableCms\Conventional\HostIo;
use Utsuri\PortableCms\Conventional\Http\Html;
use Utsuri\PortableCms\Conventional\JsonSchemaValidator;
use Utsuri\PortableCms\Conventional\Preview\PreviewArtifact;
use Utsuri\PortableCms\Conventional\Preview\PreviewBuild;
use Utsuri\PortableCms\Conventional\Preview\PreviewCompiler;
use Utsuri\PortableCms\Conventional\Preview\PreviewSnapshot;
use Utsuri\PortableCms\Conventional\Publication\WebsitePublication;
use Utsuri\PortableCms\Conventional\View\TrustedHtml;

/** A trusted profile may replace the body and stylesheet, never the engine-owned head or route set. */
interface HoldingPageRenderer
{
    public function render(WebsitePublication $settings, ?WebsiteIcon $icon): HoldingPresentation;
}

final readonly class HoldingPresentation
{
    public function __construct(public TrustedHtml $body, public string $css) {}
}

/** A bounded internal publication build, not an exact website preview. */
final readonly class HoldingPageCompiler implements PreviewCompiler
{
    public const MAX_BYTES = 40 * 1024 * 1024;

    public function __construct(private string $schemaRoot, private string $mediaRoot, private ?ProfilePackage $package = null, private ?WebsiteIconProcessor $iconProcessor = null) {}

    public function compile(PreviewSnapshot $snapshot, string $outputRoot): PreviewBuild
    {
        $settings = ($snapshot->holdingPage ?? throw new \InvalidArgumentException('A frozen holding document is required'))->forWebsite();
        $settings->assertScope($snapshot->profileId, $snapshot->locales);
        $context = $snapshot->publicUrlContext ?? throw new \InvalidArgumentException('Public URL context is required');
        if ($snapshot->includeDrafts || $settings->mode() !== 'coming_soon' || $snapshot->profileChecksum !== $this->package?->checksum) {
            throw new \InvalidArgumentException('Holding publication scope changed');
        }
        if (file_exists($outputRoot) || is_link($outputRoot) || !is_dir(dirname($outputRoot)) || is_link(dirname($outputRoot))) {
            throw new \InvalidArgumentException('Holding output must be a new private directory');
        }
        $files = [];
        $pending = [];
        $renderer = $this->package?->holdingRenderer();
        $assets = $renderer instanceof HoldingPageAssetRenderer
            ? HoldingAssets::load($this->package ?? throw new \LogicException('Holding package is unavailable'), $renderer->assets()) : null;
        foreach ($assets->files ?? [] as $file) {
            $this->plan($file['path'], $file['bytes'], $file['mime'], 'immutable', $files, $pending);
        }
        $icon = null;
        if (($iconId = $settings->document()['iconMediaId']) !== null) {
            $source = null;
            foreach ($snapshot->records as $record) {
                foreach ($record->media as $media) {
                    if ($media->id === $iconId) {
                        $source = new FrozenMedia($media->id, $record->id, 'display_960_webp', $media->mime, $media->byteSize, $media->checksum, $media->width, $media->height);
                    }
                }
            }
            foreach ($snapshot->websiteMedia as $media) {
                if ($media->id === $iconId) {
                    $source = new FrozenMedia($media->id, null, 'display_960_webp', $media->mime, $media->byteSize, $media->checksum, $media->width, $media->height);
                }
            }
            if ($source === null || $this->iconProcessor === null) {
                throw new \InvalidArgumentException('Frozen holding icon is unavailable');
            }
            $output = $this->iconProcessor->process($source, (new FilesystemMediaSource($this->mediaRoot, privateDerivatives: true))->file($source));
            $icon = $output->icon;
            foreach ($icon->files as $file) {
                $this->plan($file['path'], $output->bytes[$file['size']], 'image/png', 'immutable', $files, $pending);
            }
        }
        $presentation = $renderer instanceof HoldingPageAssetRenderer
            ? $renderer->render($settings, $icon, $assets ?? throw new \LogicException('Holding assets are unavailable'))
            : ($renderer?->render($settings, $icon) ?? self::fallback($settings, $icon));
        if (strlen($presentation->body->value) > 1024 * 1024 || strlen($presentation->css) > 128 * 1024) {
            throw new \InvalidArgumentException('Holding presentation exceeds its bounds');
        }
        $cssPath = 'assets/' . hash('sha256', $presentation->css) . '.css';
        $this->plan($cssPath, $presentation->css, 'text/css; charset=utf-8', 'immutable', $files, $pending);
        $iconHead = $icon?->head('/');
        if ($icon === null) {
            // A neutral engine favicon avoids an implicit missing favicon.ico request.
            $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32"><rect x="4" y="4" width="24" height="24" fill="#171717"/></svg>';
            $favicon = 'assets/' . hash('sha256', $svg) . '.svg';
            $this->plan($favicon, $svg, 'image/svg+xml', 'immutable', $files, $pending);
            $iconHead = '<link rel="icon" href="' . ArtifactUrl::escaped('/' . $favicon, '/') . '" type="image/svg+xml">';
        }
        $document = $settings->document();
        $locale = $snapshot->defaultLocale ?? $this->package?->manifest->defaultLocale ?? $snapshot->locales[0];
        $message = $document['translations'][array_search($locale, $snapshot->locales, true)]['message'];
        $html = '<!doctype html><html lang="' . Html::attribute($locale) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . Html::text($document['websiteName']) . '</title><meta name="description" content="' . Html::attribute($message) . '">'
            . '<meta name="robots" content="noindex,follow"><link rel="canonical" href="' . Html::attribute($context->route('/')) . '">'
            . $iconHead . '<link rel="stylesheet" href="' . ArtifactUrl::escaped('/' . $cssPath, '/') . '"></head><body>' . $presentation->body->value . '</body></html>';
        $this->plan('index.html', $html, 'text/html; charset=utf-8', 'route', $files, $pending);
        // Crawlers must be able to read the deliberate page-level noindex.
        $this->plan('robots.txt', "User-agent: *\nAllow: /\n", 'text/plain; charset=utf-8', 'route', $files, $pending);
        $rules = ApachePublicDelivery::configuration($context, [], ['/']);
        $this->plan('delivery/apache24.conf', $rules, 'text/plain; charset=utf-8', 'route', $files, $pending);
        usort($files, static fn(array $a, array $b): int => $a['path'] <=> $b['path']);
        $settingsJson = self::json($document);
        $snapshotId = substr(hash('sha256', $settingsJson . $snapshot->createdAt->format('Y-m-d\TH:i:s.uP') . ($icon === null ? '' : $icon->sourceSha256) . ($snapshot->profileChecksum ?? '')), 0, 32);
        $manifest = ['formatVersion' => 1, 'releaseId' => $snapshotId, 'snapshotId' => $snapshotId,
            'createdAt' => $snapshot->createdAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),
            'profile' => ['id' => $snapshot->profileId, 'version' => $snapshot->profileVersion, 'sha256' => $snapshot->profileChecksum ?? hash('sha256', $snapshot->profileId)],
            'compiler' => CompilerSource::identity()->toArray(), 'searchFormatVersion' => 1, 'files' => $files, 'redirects' => [], 'publicMedia' => [],
            'publicationMode' => 'coming_soon', 'websitePublication' => ['revision' => $settings->revision(), 'sha256' => hash('sha256', $settingsJson)],
            'routeIdentities' => [['path' => '/', 'locale' => $locale, 'translationGroup' => 'page:holding']],
            'publicUrlContext' => $context->document() + ['sha256' => $context->checksum()],
            'delivery' => ['formatVersion' => 1, 'target' => 'apache24', 'path' => 'delivery/apache24.conf', 'sha256' => hash('sha256', $rules)]];
        if ($icon !== null) {
            $manifest['websiteIcon'] = $icon->document();
        }
        $json = self::json($manifest) . "\n";
        $schema = HostIo::attempt(fn() => file_get_contents($this->schemaRoot . '/portable-cms-artifact-v1.schema.json'))->value;
        if (!is_string($schema)) {
            throw new \RuntimeException('Holding artifact schema is unavailable');
        }
        JsonSchemaValidator::validateJson($json, $schema);
        $artifacts = array_map(static fn(array $file): PreviewArtifact => new PreviewArtifact($file['path'], $file['mime'], $file['byteSize'], $file['sha256']), $files);
        $this->plan('artifact.json', $json, 'application/json', 'route', $files, $pending);
        $artifacts[] = new PreviewArtifact('artifact.json', 'application/json', strlen($json), hash('sha256', $json));
        if (array_sum(array_column($files, 'byteSize')) > self::MAX_BYTES) {
            throw new \InvalidArgumentException('Holding release exceeds its byte limit');
        }
        // Every declaration, read, presentation bound and inventory is checked before creating output.
        HostIo::required(static fn() => mkdir($outputRoot, 0700));
        foreach ($pending as $path => $bytes) {
            $directory = dirname($outputRoot . '/' . $path);
            if (!is_dir($directory)) {
                HostIo::required(static fn() => mkdir($directory, 0700, true));
            }
            HostIo::createPrivateFile($outputRoot . '/' . $path, $bytes);
        }
        return new PreviewBuild($artifacts);
    }

    public static function fallback(WebsitePublication $settings, ?WebsiteIcon $icon): HoldingPresentation
    {
        $document = $settings->document();
        $body = '<main>' . ($icon === null ? '' : '<img src="' . ArtifactUrl::escaped('/' . $icon->files[1]['path'], '/') . '" alt="" width="96" height="96">')
            . '<h1>' . Html::text($document['websiteName']) . '</h1>';
        foreach ($document['translations'] as $entry) {
            $body .= '<section lang="' . Html::attribute($entry['locale']) . '" aria-label="' . Html::attribute($entry['message']) . '"><p>' . Html::text($entry['message']) . '</p>'
                . ($entry['contact'] === '' ? '' : '<p class="contact">' . Html::text($entry['contact']) . '</p>') . '</section>';
        }
        $css = 'html{color-scheme:light;background:#fff;color:#171717;font-family:system-ui,sans-serif}body{margin:0}main{box-sizing:border-box;min-height:100svh;max-width:56rem;margin:auto;padding:clamp(2rem,6vw,6rem);display:flex;flex-direction:column;justify-content:center;align-items:flex-start}h1{max-width:100%;overflow-wrap:anywhere;font-size:clamp(2rem,6vw,4rem);line-height:1.1;margin:1rem 0 2rem}section{max-width:100%;margin-bottom:1.25rem}p{font-size:1.25rem;line-height:1.6;margin:.25rem 0;white-space:pre-wrap;overflow-wrap:anywhere}.contact{font-size:1rem}img{object-fit:contain}';
        return new HoldingPresentation(TrustedHtml::fromEngineMarkup($body . '</main>'), $css);
    }

    /**
     * @param list<array{path: string, mime: string, byteSize: int, sha256: string, cachePolicy: string}> $files
     * @param array<string, string> $pending
     */
    private function plan(string $path, string $bytes, string $mime, string $cache, array &$files, array &$pending): void
    {
        PreviewArtifact::assertPath($path);
        if (isset($pending[$path])) {
            throw new \InvalidArgumentException('Holding artifact path is repeated');
        }
        $pending[$path] = $bytes;
        $files[] = ['path' => $path, 'mime' => $mime, 'byteSize' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'cachePolicy' => $cache];
    }

    private static function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
