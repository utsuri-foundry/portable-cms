<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Preview;

use Utsuri\PortableCms\Conventional\Content\ContentService;
use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Http\Request;
use Utsuri\PortableCms\Conventional\Http\Response;
use Utsuri\PortableCms\Conventional\View\AdminChrome;
use Utsuri\PortableCms\Conventional\View\Pages;
use Utsuri\PortableCms\Conventional\View\PreviewCleanupPageView;

/** Invoked only after the application's existing session, role, origin and CSRF checks. */
final readonly class PreviewCleanupHttp
{
    public function __construct(private PreviewCleanupService $cleanup, private PreviewService $previews) {}

    public function listing(Request $request, string $actor, string $csrf, ?ProfileManifest $profile, AdminChrome $chrome, ?ContentService $content, ?\Utsuri\PortableCms\Conventional\View\TrustedHtml $newestLinks = null): Response
    {
        try {
            $page = $this->cleanup->page($request->queryValue('before'));
            return new Response(200, Pages::previewList($csrf, $profile, [], $chrome, $this->previews->latestCheck(), $content === null ? [] : $this->previews->setup($content), $page, $this->cleanup->pageToken($page, $actor), $this->cleanup->recoveryToken($actor), $this->previews->preparationLanguages(), $content?->pendingPublicationCount() ?? 0, $newestLinks));
        } catch (PreviewSelectionInvalid) {
            return new Response(400, PreviewCleanupPageView::invalid($profile, $chrome));
        }
    }

    public function mutate(Request $request, string $actor, string $csrf, ?ProfileManifest $profile, AdminChrome $chrome): Response
    {
        if ($request->method !== 'POST' || !in_array($request->path, ['/previews/delete/review', '/previews/delete/confirm'], true)) {
            return new Response(404);
        }
        try {
            if ($request->path === '/previews/delete/review') {
                $review = $this->cleanup->review($request->formValues('preview_ids'), $actor, $request->formValue('page_selection') === '' ? null : $request->formValue('page_selection'));
                return new Response(200, PreviewCleanupPageView::review($review, $csrf, $profile, $chrome));
            }
            $result = $this->cleanup->confirm($request->formValue('selection'), $actor);
            return new Response(200, PreviewCleanupPageView::result($result, $csrf, $profile, $chrome));
        } catch (PreviewSelectionInvalid) {
            return new Response(422, PreviewCleanupPageView::invalid($profile, $chrome));
        }
    }

    public function pending(string $id, string $actor, string $csrf, ?ProfileManifest $profile, AdminChrome $chrome, string $outcome): Response
    {
        // The explicit retry must carry the original approved ID even after its database row is gone.
        return new Response(202, PreviewCleanupPageView::result($this->cleanup->retrySingle($id, $actor, $outcome), $csrf, $profile, $chrome));
    }
}
