<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Preview;

use Utsuri\PortableCms\Conventional\Content\ContentService;
use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Http\Request;
use Utsuri\PortableCms\Conventional\Http\Response;
use Utsuri\PortableCms\Conventional\I18n\Message;
use Utsuri\PortableCms\Conventional\Identity\SessionContext;
use Utsuri\PortableCms\Conventional\View\AdminChrome;
use Utsuri\PortableCms\Conventional\View\EditorExactPreviewPage;

/** The application host owns session, role, origin and CSRF checks; POST here never saves form content. */
final readonly class EditorExactPreviewHttp
{
    public function __construct(private EditorExactPreview $exact, private ContentService $content, private ProfileManifest $profile, private AdminChrome $chrome, private bool $cronOnly = false) {}

    /** @return array{typeId: string, recordId: string, open: bool}|null */
    public static function route(Request $request): ?array
    {
        if ($request->method === 'POST' && preg_match('#\A/records/([a-z][a-z0-9_-]*)/([a-f0-9]{32}|new)/exact-preview\z#', $request->path, $match) === 1) {
            return ['typeId' => $match[1], 'recordId' => $match[2], 'open' => true];
        }
        if ($request->method === 'GET' && preg_match('#\A/previews/newest/records/([a-z][a-z0-9_-]*)/([a-f0-9]{32})\z#', $request->path, $match) === 1) {
            return ['typeId' => $match[1], 'recordId' => $match[2], 'open' => false];
        }
        return null;
    }

    /** @param array{typeId: string, recordId: string, open: bool} $route */
    public function handle(Request $request, array $route, SessionContext $actor): Response
    {
        $t = $this->chrome->translator();
        $type = $this->profile->type($route['typeId']);
        if (!$actor->editorial() || $type === null || ($actor->role === 'editor' && $type->archetype === 'site_settings')) {
            return new Response(404, $t->html('exact.unavailable'));
        }
        $locale = $route['open'] ? $request->formValue('_exact_locale') : $request->queryValue('locale');
        $locale = $locale === '' ? $this->profile->defaultLocale : $locale;
        if (!in_array($locale, $this->profile->locales, true)) {
            return new Response(400, $t->html('exact.unavailable'));
        }
        $base = '/previews/newest/records/' . $type->id . '/' . $route['recordId'] . '?locale=' . rawurlencode($locale);
        if ($route['open']) {
            if ($route['recordId'] === 'new' || $this->content->find($type->id, $route['recordId']) === null) {
                return new Response(422, EditorExactPreviewPage::failed($t, $this->profile, new PreviewDiagnostics([new Message('exact.save_first')])));
            }
            try {
                $preview = $this->exact->open($actor->userId ?? throw new PreviewNotFound());
            } catch (PreviewPreflightFailure $failure) {
                return new Response(422, EditorExactPreviewPage::failed($t, $this->profile, new PreviewDiagnostics($failure->issues, $failure->reference)));
            }
            return Response::redirect($base . ($preview->status === 'ready' ? '' : '&waiting=' . $preview->id));
        }
        $waiting = $request->queryValue('waiting');
        if ($waiting !== '' && preg_match('/\A[a-f0-9]{32}\z/', $waiting) !== 1) {
            return new Response(400, $t->html('exact.unavailable'));
        }
        $preview = $this->exact->resolve($waiting === '' ? null : $waiting);
        if ($preview === null) {
            return new Response(404, EditorExactPreviewPage::failed($t, $this->profile, new PreviewDiagnostics([new Message('exact.unavailable')])));
        }
        if (in_array($preview->status, ['queued', 'building'], true)) {
            $url = $base . '&waiting=' . $preview->id;
            return (new Response(202, EditorExactPreviewPage::waiting($t, $url, $preview->status, $this->cronOnly)))->withHeader('refresh', '2; url=' . $url);
        }
        if ($preview->status !== 'ready') {
            return new Response(422, EditorExactPreviewPage::failed($t, $this->profile, $preview->diagnostics, $preview));
        }
        if ($waiting !== '') {
            return Response::redirect($base);
        }
        $page = $this->exact->page($preview, $type->id, $route['recordId'], $locale);
        return (new Response(200, EditorExactPreviewPage::viewer($t, $this->exact->files($preview, $actor) . ltrim($page->path, '/'), $base, $page, $preview->id)))->withVimeoWorkspace();
    }
}
