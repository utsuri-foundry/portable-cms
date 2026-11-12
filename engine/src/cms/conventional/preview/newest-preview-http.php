<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Preview;

use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Http\Cookie;
use Utsuri\PortableCms\Conventional\Http\OriginPolicy;
use Utsuri\PortableCms\Conventional\Http\Request;
use Utsuri\PortableCms\Conventional\Http\Response;
use Utsuri\PortableCms\Conventional\I18n\Translator;
use Utsuri\PortableCms\Conventional\View\AdminChrome;
use Utsuri\PortableCms\Conventional\View\NewestPreviewLinksView;
use Utsuri\PortableCms\Conventional\View\Pages;
use Utsuri\PortableCms\Conventional\View\PreviewSharePage;
use Utsuri\PortableCms\Conventional\View\TrustedHtml;

final class NewestPreviewHttp
{
    public function __construct(private readonly NewestPreviewLinkService $links, private readonly PreviewService $previews, private readonly OriginPolicy $originPolicy, private readonly string $origin) {}

    public function guest(Request $request, Translator $t): Response
    {
        try {
            if (str_starts_with($request->path, '/newest-preview-files/')) {
                return (new PreviewFileHttp($this->previews, 'newest-preview-files'))->serve($request, $t, fn(string $id, string $token, string $remote): ?string => $this->links->authorizeFiles($id, $token, $remote)?->previewId);
            }
            return $this->serve($request, $t);
        } catch (ShareUnavailable) {
            return new Response(404, $t->html('share.unavailable'));
        } catch (ShareRateLimited) {
            return (new Response(429, $t->html('share.unavailable')))->withHeader('retry-after', '300');
        }
    }

    private function serve(Request $request, Translator $t): Response
    {
        $unavailable = new Response(404, $t->html('share.unavailable'));
        if (preg_match('#\A/newest-previews/([a-f0-9]{32})(/.*)?\z#', $request->path, $match) !== 1) {
            $this->links->authorize('', '', $request->remoteAddress);
            return $unavailable;
        }
        $id = $match[1];
        $suffix = $match[2] ?? '';
        $base = '/newest-previews/' . $id . '/';
        if ($request->method === 'POST' && $suffix === '/renew') {
            if (!$this->originPolicy->allows($request)) {
                return new Response(403, $t->html('share.unavailable'));
            }
            return new Response($request->header('authorization') === null
                && $this->links->renewFiles($id, $request->formValue('token'), $request->cookie(self::cookieName($id)) ?? '', $request->remoteAddress) ? 204 : 404);
        }
        if ($request->method === 'GET' && $suffix === '') {
            return Response::redirect($base, 308);
        }
        if ($request->method === 'POST' && $suffix === '/open') {
            if (!$this->originPolicy->allows($request)) {
                return new Response(403, $t->html('share.unavailable'));
            }
            $token = $request->formValue('token');
            if ($this->links->authorize($id, $token, $request->remoteAddress) === null) {
                return $unavailable;
            }
            return Response::redirect($base)->withCookie(new Cookie(self::cookieName($id), $token, 30 * 86400, true, 'Strict'));
        }
        if ($request->method !== 'GET') {
            return $unavailable;
        }
        // An explicitly supplied, malformed header never falls back to a valid cookie.
        $authorization = $request->header('authorization');
        $token = $authorization === null ? ($request->cookie(self::cookieName($id)) ?? '')
            : (preg_match('/\A(?i:Bearer) ([a-f0-9]{64})\z/', $authorization, $bearer) === 1 ? $bearer[1] : '');
        if ($suffix === '/' && $token === '' && $authorization === null) {
            return (new Response(200, PreviewSharePage::landing($base, $t)))->withHeader('content-type', 'text/html; charset=utf-8');
        }
        $pinned = null;
        $path = null;
        if (preg_match('#\A/pinned/([a-f0-9]{32})/artifact/(.*)\z#', $suffix, $pin) === 1) {
            $pinned = $pin[1];
            $path = $pin[2];
        } elseif (str_starts_with($suffix, '/artifact/')) {
            $path = substr($suffix, strlen('/artifact/'));
        } elseif (!in_array($suffix, ['/', '/artifact'], true)) {
            $this->links->authorize($id, $token, $request->remoteAddress);
            return $unavailable;
        }
        if ($path !== null) {
            $path = $path === '' ? 'index.html' : (str_ends_with($path, '/') ? $path . 'index.html' : $path);
            try {
                PreviewArtifact::assertPath($path);
            } catch (\InvalidArgumentException) {
                return $unavailable;
            }
        }
        $resolution = $this->links->authorize($id, $token, $request->remoteAddress, $pinned);
        if ($resolution === null) {
            return $unavailable;
        }
        $mount = $base . 'pinned/' . $resolution->previewId . '/artifact/';
        if ($suffix === '/') {
            $files = (new PreviewFileHttp($this->previews, 'newest-preview-files'))->base($id, $this->links->createFiles($resolution));
            return (new Response(200, PreviewSharePage::viewer($base, $t, $files)))->withVimeoWorkspace()->withHeader('content-type', 'text/html; charset=utf-8');
        }
        if ($pinned === null) {
            // Relative Location is same-origin, and the final manifest address is the file base.
            return Response::redirect($mount . ($path ?? 'index.html'), 307);
        }
        return $this->previews->artifactResponse($resolution->previewId, (string) $path, $mount, $request->header('range'), true) ?? $unavailable;
    }

    public function listing(string $csrf, Translator $t, ?string $url = null, ?string $manifest = null): TrustedHtml
    {
        return NewestPreviewLinksView::render($csrf, $this->links->active(), $t, $url, $manifest);
    }

    /** Caller checks publication authority, origin and CSRF before entering. */
    public function manage(Request $request, string $actor, string $csrf, ?ProfileManifest $profile, AdminChrome $chrome, int $pendingPublication = 0): Response
    {
        try {
            if (preg_match('#\A/previews/shares/newest/([a-f0-9]{32})/revoke\z#', $request->path, $match) === 1) {
                $this->links->revoke($match[1], $actor);
                return Response::redirect('/previews#newest-preview-links');
            }
            $hours = $request->formValue('expiry_hours');
            if (!in_array($hours, ['1', '24', '168', '720'], true)) {
                throw new InvalidShareExpiry();
            }
            $created = $this->links->create($actor, (int) $hours);
            $base = $this->origin . '/newest-previews/' . $created['link']->id . '/';
            return new Response(200, Pages::previewList($csrf, $profile, $this->previews->list(), $chrome, $this->previews->latestCheck(), preparation: $this->previews->preparationLanguages(), newestLinks: $this->listing($csrf, \Utsuri\PortableCms\Conventional\View\AdminShell::translator($chrome), $base . '#' . $created['token'], $base . 'artifact/artifact.json'), pendingPublication: $pendingPublication));
        } catch (InvalidShareExpiry) {
            return new Response(422);
        } catch (ShareUnavailable) {
            return new Response(409);
        }
    }

    public static function cookieName(string $id): string
    {
        return '__Host-preview-follow-' . $id;
    }
}
