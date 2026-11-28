<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Media;

use Utsuri\PortableCms\Conventional\Content\ContentService;
use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Http\Request;
use Utsuri\PortableCms\Conventional\Http\Response;
use Utsuri\PortableCms\Conventional\Identity\SessionContext;
use Utsuri\PortableCms\Conventional\View\MediaLibraryPage;
use Utsuri\PortableCms\Conventional\View\Pages;

/** Parent application owns editorial session, origin and CSRF checks. */
final class MediaLibraryHttp
{
    /** @param bool $cronOnly Uploads wait for the scheduled worker instead of being prepared right after the response. */
    public function __construct(private readonly MediaLibrary $library, private readonly ContentService $content, private readonly ProfileManifest $profile, private readonly ?\Utsuri\PortableCms\Conventional\View\AdminChrome $chrome = null, private readonly bool $uploadsAvailable = false, private readonly bool $cronOnly = false) {}

    private function translator(): \Utsuri\PortableCms\Conventional\I18n\Translator
    {
        return $this->chrome?->translator() ?? \Utsuri\PortableCms\Conventional\I18n\Translator::english();
    }

    public static function matches(Request $request): bool
    {
        return in_array($request->method, ['GET', 'POST'], true)
            && preg_match('#\A/media(?:/descriptions|/status|/[a-f0-9]{32}(?:/(?:thumbnail|video|video-frame))?)?\z#', $request->path) === 1;
    }

    public function handle(Request $request, SessionContext $session, string $correlationId): Response
    {
        if ($request->path === '/media/descriptions') {
            return (new MediaDescriptionsHttp($this->library, $this->profile, $this->chrome))->handle($request, $session);
        }
        if ($request->path === '/media/status') {
            return $request->method === 'GET' ? $this->status($request) : new Response(405);
        }
        if ($request->path === '/media' && $request->method === 'GET') {
            $page = filter_var($request->queryValue('page'), FILTER_VALIDATE_INT);
            $listing = $this->library->browse($request->queryValue('q'), is_int($page) ? $page : 1, $request->queryValue('show'), $this->profile->locales, $this->profile->defaultLocale);
            return new Response(200, MediaLibraryPage::listing($listing, $this->profile, $this->chrome, $this->uploadsAvailable ? $this->content : null, $session->csrfToken, $this->library->videoUploads, $this->cronOnly, $this->library->preparation));
        }
        $id = explode('/', $request->path)[2] ?? '';
        $media = $this->library->find($id);
        if ($media === null) {
            return new Response(404, Pages::error(404, $correlationId, $this->chrome?->translator()));
        }
        if (str_ends_with($request->path, '/video-frame')) {
            if ($request->method !== 'POST' || $session->userId === null) {
                return new Response(405);
            }
            $encoded = $request->formValue('frame');
            if (strlen($encoded) > (int) ceil(VideoFrame::MAX_BYTES / 3) * 4) {
                return new Response(413);
            }
            $bytes = base64_decode($encoded, true);
            if ($bytes === false) {
                return new Response(422);
            }
            try {
                return new Response($this->library->cacheVideoFrame($id, $request->formValue('source'), $bytes) ? 204 : 409);
            } catch (\Utsuri\PortableCms\Conventional\I18n\InvalidInput $failure) {
                return new Response(422, $this->translator()->failure($failure));
            } catch (MediaProcessingFailure) {
                return new Response(503);
            }
        }
        if (str_ends_with($request->path, '/video')) {
            if ($request->method !== 'GET') {
                return new Response(405);
            }
            try {
                $bytes = $this->library->video($id);
            } catch (MediaProcessingFailure) {
                $bytes = null;
            }
            if ($bytes === null) {
                return new Response(404);
            }
            return Response::file($bytes, $media->mime, $request->header('range'));
        }
        if (str_ends_with($request->path, '/thumbnail')) {
            if ($request->method !== 'GET') {
                return new Response(405);
            }
            try {
                $bytes = $this->library->thumbnail($id);
            } catch (MediaProcessingFailure) {
                $bytes = null;
            }
            return $bytes === null ? new Response(404) : (new Response(200, $bytes))->withHeader('content-type', 'image/webp');
        }
        $error = null;
        $status = 200;
        $name = null;
        $submittedText = null;
        $submittedPoint = null;
        if ($request->method === 'POST' && $session->userId !== null) {
            $keepForWebsite = $request->formValue('_website_owner') === '1';
            if ($keepForWebsite && !$session->administrator()) {
                return new Response(403, Pages::error(403, $correlationId, $this->translator()));
            }
            $name = $request->formValue('name');
            $revision = filter_var($request->formValue('_revision'), FILTER_VALIDATE_INT);
            $submittedText = $request->formValue('_media_text') === '1' ? \Utsuri\PortableCms\Conventional\View\MediaTextForm::input($request, $this->profile) : null;
            $submittedPoint = $request->formValue('_focal_point') === '1' ? ['x' => $request->formValue('focal_x'), 'y' => $request->formValue('focal_y'), 'reset' => $request->formValue('focal_reset') === '1'] : null;
            try {
                $text = $keepForWebsite || $submittedText === null ? null : MediaText::edited($submittedText, $this->profile->locales, $media->text);
                $point = $keepForWebsite || $submittedPoint === null ? null : \Utsuri\PortableCms\Conventional\View\FocalPointForm::point($submittedPoint);
                $written = $keepForWebsite ? $this->library->keepForWebsite($id, is_int($revision) ? $revision : 0, $session->userId)
                    : $this->library->rename($id, is_int($revision) ? $revision : 0, $name, $session->userId, $text, $point);
                if (!$written) {
                    $error = $this->translator()->text('media.name.conflict');
                    $status = 409;
                    $media = $this->library->find($id) ?? $media;
                } elseif (!$keepForWebsite) {
                    return Response::redirect('/media/' . $id);
                } else {
                    $media = $this->library->find($id) ?? $media;
                }
            } catch (\Utsuri\PortableCms\Conventional\I18n\InvalidInput $failure) {
                $status = 422;
                $error = $this->translator()->failure($failure);
            } catch (\InvalidArgumentException) {
                $status = 422;
                $error = $this->translator()->text('media.name.invalid');
            }
        }
        return new Response($status, MediaLibraryPage::detail($media, $this->profile, $this->content, $this->library, $session->csrfToken, $error, $name, $this->chrome, $submittedText, $submittedPoint));
    }

    /**
     * Where each waiting file stands, for rows that update themselves until it is ready. Read-only
     * and bounded by the query length to twelve identities; unknown identities are left out.
     */
    private function status(Request $request): Response
    {
        $raw = $request->query['ids'] ?? '';
        if (!is_string($raw) || strlen($raw) > 400) {
            return Response::json(['error' => 'too_many_media'], 422);
        }
        $ids = array_values(array_unique(explode(',', $raw)));
        $t = $this->translator();
        $items = [];
        foreach ($ids as $id) {
            $media = preg_match('/\A[a-f0-9]{32}\z/', $id) === 1 ? $this->library->find($id) : null;
            if ($media === null) {
                continue;
            }
            $failed = MediaPreparation::failed($media);
            $ready = !$failed && $media->status === 'ready';
            $items[] = [
                'id' => $media->id,
                'state' => $failed ? 'failed' : ($ready ? 'ready' : 'preparing'),
                'text' => $ready ? null : ($failed ? $t->text(\Utsuri\PortableCms\Conventional\View\ReferenceControls::failedKey($media)) : $t->message($this->library->preparation->message($media))),
                'thumbnail' => $ready && ($media->thumbnail?->mime === 'image/webp' || $media->videoFrame !== null) ? '/media/' . $media->id . '/thumbnail' : null,
            ];
        }
        return Response::json(['items' => $items, 'poll' => $this->library->preparation->pollSeconds()]);
    }
}
