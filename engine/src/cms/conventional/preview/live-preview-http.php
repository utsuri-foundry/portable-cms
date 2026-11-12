<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Preview;

use Utsuri\PortableCms\Conventional\Compiler\FilesystemMediaSource;
use Utsuri\PortableCms\Conventional\Compiler\FrozenMedia;
use Utsuri\PortableCms\Conventional\Content\ProfilePackage;
use Utsuri\PortableCms\Conventional\Http\FileBody;
use Utsuri\PortableCms\Conventional\Http\Html;
use Utsuri\PortableCms\Conventional\Http\Request;
use Utsuri\PortableCms\Conventional\Http\Response;
use Utsuri\PortableCms\Conventional\I18n\Translator;

/**
 * Read-only token path for live preview pages. It runs before session handling because the
 * sandboxed frame has an opaque origin and sends no CMS cookies; the token names one render
 * of a signed-in session and is the only authority.
 */
final class LivePreviewHttp
{
    public function __construct(public readonly LivePreviewService $previews, private readonly ProfilePackage $package, private readonly string $mediaRoot) {}

    public static function matches(Request $request): bool
    {
        return str_starts_with($request->path, '/live-previews/');
    }

    public function serve(Request $request, Translator $t): Response
    {
        return $this->response($request, $t)->withHeader('x-robots-tag', 'noindex, nofollow, noarchive')->asIsolated();
    }

    private function response(Request $request, Translator $t): Response
    {
        if (!in_array($request->method, ['GET', 'HEAD'], true)
            || preg_match('#\A/live-previews/([a-f0-9]{64})/([A-Za-z0-9/_.~-]{0,600})\z#', $request->path, $match) !== 1
            || str_contains($match[2], '..') || str_contains($match[2], '//')) {
            return self::notice(404, $t->text('live.page.expired'), $t);
        }
        $render = $request->queryValue('render');
        $document = preg_match('/\A(?:[a-f0-9]{32})?\z/', $render) === 1 ? $this->previews->document($match[1], $render === '' ? null : $render) : null;
        if ($document === null) {
            return self::notice(404, $t->text('live.page.expired'), $t);
        }
        $path = $match[2] === '' || str_ends_with($match[2], '/') ? $match[2] . 'index.html' : $match[2];
        if (isset($document->files[$path])) {
            $file = $document->files[$path];
            $response = (new Response(200, $file['body']))->withHeader('content-type', $file['mime']);
            return $document->vimeoFrame && str_starts_with($file['mime'], 'text/html') ? $response->withVimeoFrame(\Utsuri\PortableCms\Conventional\Compiler\VimeoPresentation::scriptHash($document->files[\Utsuri\PortableCms\Conventional\Compiler\VimeoPresentation::SCRIPT]['body'])) : $response;
        }
        if (str_ends_with($path, 'index.html')) {
            // Links lead to pages this render did not draw; the editor chooses pages in the pane.
            return self::notice(404, $t->text('live.page.elsewhere'), $t);
        }
        if ($path === LivePreviewService::BRIDGE_PATH) {
            $bridge = \Utsuri\PortableCms\Conventional\HostIo::attempt(fn() => file_get_contents(dirname(__DIR__) . '/client/live-preview-bridge.js'))->value;
            if (!is_string($bridge)) {
                throw new \RuntimeException('Live preview bridge is unavailable');
            }
            return self::cached((new Response(200, $bridge))->withHeader('content-type', 'text/javascript; charset=utf-8'));
        }
        if (isset($document->media[$path])) {
            $item = $document->media[$path];
            $source = new FrozenMedia($item['mediaId'], $item['ownerRecordId'], $item['variant'], $item['mime'], $item['byteSize'], $item['sha256'], null, null);
            $file = (new FilesystemMediaSource($this->mediaRoot, privateDerivatives: true))->file($source);
            return self::cached(Response::file(FileBody::verified($file, $item['byteSize'], $item['sha256']), $item['mime'], $request->header('range')));
        }
        if (isset($document->fonts[$path])) {
            $font = $document->fonts[$path];
            $file = (new \Utsuri\PortableCms\Conventional\Design\FilesystemFontStore($this->mediaRoot . '/fonts'))->file($font['sha256'], $font['byteSize']);
            return self::cached(Response::file(FileBody::verified($file, $font['byteSize'], $font['sha256']), 'font/woff2', $request->header('range')));
        }
        foreach ($this->package->manifest->assets as $asset) {
            if ($asset->path === $path) {
                return self::cached(Response::file(FileBody::verified($this->package->assetPath($asset->path), $asset->byteSize, $asset->checksum), $asset->mime, $request->header('range')));
            }
        }
        return self::notice(404, $t->text('live.page.missing'), $t);
    }

    /** Verified files keep their bytes for the workspace token, so later renders reuse them; pages and generated styles never do. */
    private static function cached(Response $response): Response
    {
        return $response->withHeader('cache-control', 'private, max-age=' . LivePreviewService::LIFETIME_SECONDS);
    }

    private static function notice(int $status, string $text, Translator $t): Response
    {
        $body = '<!doctype html><html lang="' . Html::attribute($t->locale()) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . Html::text($text) . '</title></head><body><p>' . Html::text($text) . '</p></body></html>';
        return (new Response($status, $body))->withHeader('content-type', 'text/html; charset=utf-8');
    }
}
