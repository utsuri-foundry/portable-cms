<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Preview;

use Utsuri\PortableCms\Conventional\Http\Cookie;
use Utsuri\PortableCms\Conventional\Http\OriginPolicy;
use Utsuri\PortableCms\Conventional\Http\Request;
use Utsuri\PortableCms\Conventional\Http\Response;
use Utsuri\PortableCms\Conventional\I18n\Translator;
use Utsuri\PortableCms\Conventional\View\PreviewSharePage;

/** The guest side of a fixed share link: secret exchange, the viewer and its files. Links are made on the Previews page. */
final class PreviewShareHttp
{
    public function __construct(
        private readonly PreviewShareService $shares,
        private readonly PreviewService $previews,
        private readonly OriginPolicy $originPolicy,
    ) {}

    public function guest(Request $request, Translator $t): Response
    {
        try {
            if (str_starts_with($request->path, '/shared-preview-files/')) {
                return (new PreviewFileHttp($this->previews, 'shared-preview-files'))->serve($request, $t, fn(string $id, string $token, string $remote): ?string => $this->shares->authorizeFiles($id, $token, $remote)?->previewId);
            }
            return $this->serve($request, $t);
        } catch (ShareUnavailable) {
            return new Response(404, $t->html('share.unavailable'));
        } catch (ShareRateLimited) {
            return (new Response(429, $t->html('share.rate_limited')))->withHeader('retry-after', '300');
        }
    }

    private function serve(Request $request, Translator $t): Response
    {
        if (preg_match('#\A/shared-previews/([a-f0-9]{32})(/.*)?\z#', $request->path, $match) !== 1) {
            $this->shares->authorize('', '', $request->remoteAddress);
            return new Response(404, $t->html('share.unavailable'));
        }
        $id = $match[1];
        $suffix = $match[2] ?? '';
        $base = '/shared-previews/' . $id . '/';
        if ($request->method === 'POST' && $suffix === '/renew') {
            if (!$this->originPolicy->allows($request)) {
                return new Response(403, $t->html('share.unavailable'));
            }
            $share = $this->shares->authorize($id, $request->cookie(self::cookieName($id)) ?? '', $request->remoteAddress);
            return new Response($share !== null && $this->shares->renewFiles($share, $request->formValue('token')) ? 204 : 404);
        }
        if ($request->method === 'GET' && $suffix === '') {
            return Response::redirect($base, 308);
        }
        if ($request->method === 'POST' && $suffix === '/open') {
            // A capability exchange is not a CMS mutation. It requires our origin and never opens a CMS session.
            if (!$this->originPolicy->allows($request)) {
                return new Response(403, $t->html('share.unavailable'));
            }
            $token = $request->formValue('token');
            $share = $this->shares->authorize($id, $token, $request->remoteAddress);
            if ($share === null) {
                return new Response(404, $t->html('share.unavailable'));
            }
            return Response::redirect($base)->withCookie(new Cookie(self::cookieName($id), $token, 30 * 86400, true, 'Strict'));
        }
        if ($request->method !== 'GET') {
            return new Response(404, $t->html('share.unavailable'));
        }
        $token = $request->cookie(self::cookieName($id)) ?? '';
        if ($suffix === '/' && $token === '') {
            return (new Response(200, PreviewSharePage::landing($base, $t)))->withHeader('content-type', 'text/html; charset=utf-8');
        }
        $share = $this->shares->authorize($id, $token, $request->remoteAddress);
        if ($share === null) {
            return new Response(404, $t->html('share.unavailable'));
        }
        if ($suffix === '/') {
            $files = (new PreviewFileHttp($this->previews, 'shared-preview-files'))->base($id, $this->shares->createFiles($share));
            return (new Response(200, PreviewSharePage::viewer($base, $t, $files)))
                ->withVimeoWorkspace()->withHeader('content-type', 'text/html; charset=utf-8');
        }
        if ($suffix === '/artifact') {
            return Response::redirect($base . 'artifact/', 308);
        }
        if (!str_starts_with($suffix, '/artifact/')) {
            return new Response(404, $t->html('share.unavailable'));
        }
        $path = substr($suffix, strlen('/artifact/'));
        $path = $path === '' ? 'index.html' : (str_ends_with($path, '/') ? $path . 'index.html' : $path);
        try {
            PreviewArtifact::assertPath($path);
        } catch (\InvalidArgumentException) {
            return new Response(404, $t->html('share.unavailable'));
        }
        return $this->previews->artifactResponse($share->previewId, $path, $base . 'artifact/', $request->header('range'), true)
            ?? new Response(404, $t->html('share.unavailable'));
    }

    public static function cookieName(string $id): string
    {
        return '__Host-preview-share-' . $id;
    }
}
