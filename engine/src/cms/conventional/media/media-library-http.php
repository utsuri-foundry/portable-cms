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
    public function __construct(private readonly MediaLibrary $library, private readonly ContentService $content, private readonly ProfileManifest $profile, private readonly ?\Utsuri\PortableCms\Conventional\View\AdminChrome $chrome = null, private readonly bool $uploadsAvailable = false) {}

    private function translator(): \Utsuri\PortableCms\Conventional\I18n\Translator
    {
        return $this->chrome?->translator() ?? \Utsuri\PortableCms\Conventional\I18n\Translator::english();
    }

    public static function matches(Request $request): bool
    {
        return in_array($request->method, ['GET', 'POST'], true)
            && preg_match('#\A/media(?:/descriptions|/[a-f0-9]{32}(?:/(?:thumbnail|video|video-frame))?)?\z#', $request->path) === 1;
    }

    public function handle(Request $request, SessionContext $session, string $correlationId): Response
    {
        if ($request->path === '/media/descriptions') {
            return (new MediaDescriptionsHttp($this->library, $this->profile, $this->chrome))->handle($request, $session);
        }
        if ($request->path === '/media' && $request->method === 'GET') {
            $page = filter_var($request->queryValue('page'), FILTER_VALIDATE_INT);
            return new Response(200, MediaLibraryPage::listing($this->library->search($request->queryValue('q'), is_int($page) ? $page : 1), $this->profile, $this->chrome, $this->uploadsAvailable ? $this->content : null, $session->csrfToken, $this->library->videoUploads));
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
}
