<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Publication;

use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Http\Request;
use Utsuri\PortableCms\Conventional\Http\Response;
use Utsuri\PortableCms\Conventional\Identity\SessionContext;
use Utsuri\PortableCms\Conventional\View\AdminChrome;
use Utsuri\PortableCms\Conventional\View\WebsitePublicationPage;

/** Authentication, same-origin and CSRF are enforced by the application host. */
final readonly class WebsitePublicationHttp
{
    public function __construct(private WebsitePublicationService $service, private ProfileManifest $profile, private AdminChrome $chrome, private ?\Utsuri\PortableCms\Conventional\Media\MediaLibrary $media = null, private ?\Utsuri\PortableCms\Conventional\Media\MediaUploadService $uploads = null, private ?\Utsuri\PortableCms\Conventional\Identity\RandomSource $random = null) {}

    public function handle(Request $request, SessionContext $actor): Response
    {
        $saved = $this->service->load();
        if ($request->method === 'GET') {
            return new Response(200, $this->render($request, $saved));
        }
        $input = [];
        foreach (['_revision', 'mode', 'websiteName', 'iconMediaId', ...array_merge(...array_map(static fn(string $locale): array => ['message_' . $locale, 'contact_' . $locale], $this->profile->locales))] as $key) {
            $input[$key] = $request->formValue($key);
        }
        foreach (['adminLogoMediaId' => $saved->administrationBranding->logoMediaId, 'darkAdminLogoMediaId' => $saved->administrationBranding->darkLogoMediaId] as $field => $id) {
            $input[$field] = array_key_exists($field, $request->form) ? $request->formValue($field) : ($id ?? '');
            if (preg_match('/\A(?:search|page:[1-9][0-9]{0,3})\z/', $request->formValue('_' . $field . '_action')) === 1) {
                $browsing = true;
            }
        }
        $expected = filter_var($request->formValue('_revision'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 4_294_967_293]]);
        if ($request->formValue('_website_upload') !== '') {
            if (!in_array($request->formValue('_website_upload'), ['iconMediaId', 'adminLogoMediaId', 'darkAdminLogoMediaId'], true)
                || $this->uploads === null || $this->random === null || $actor->userId === null || !is_int($expected) || $request->formValue('_complete') !== '1') {
                return new Response(422, $this->render($request, $saved, $input, 'website_publication_incomplete'));
            }
            if ($expected !== $saved->revision()) {
                return new Response(409, $this->render($request, $saved, $input, 'website_publication_changed', true));
            }
            try {
                $batch = \Utsuri\PortableCms\Conventional\Media\NativeUploadHttp::receive($request, null, null, $actor->userId, $this->uploads, $this->random, $this->profile, imagesOnly: true);
                return new Response($batch->failure === null ? 200 : 422, $this->render($request, $saved, $input, uploadNotice: new \Utsuri\PortableCms\Conventional\I18n\Message('upload.accepted', ['count' => count($batch->sessions)]), uploadError: $batch->failure?->text()));
            } catch (\Utsuri\PortableCms\Conventional\Media\UploadFailure $failure) {
                return new Response($failure->statusCode, $this->render($request, $saved, $input, uploadError: $failure->text()));
            }
        }
        if (isset($browsing) || $request->formValue('_icon_action') === 'search' || preg_match('/\Apage:[1-9][0-9]{0,3}\z/', $request->formValue('_icon_action')) === 1) {
            return new Response(200, $this->render($request, $saved, $input));
        }
        try {
            if (!is_int($expected) || $request->formValue('_complete') !== '1') {
                throw new PublicationFailure('website_publication_incomplete');
            }
            $document = ['formatVersion' => 1, 'profileId' => $this->profile->id, 'revision' => $expected + 1, 'mode' => $input['mode'], 'websiteName' => $input['websiteName'], 'iconMediaId' => $input['iconMediaId'] === '' ? null : $input['iconMediaId'],
                'administrationBranding' => ['logoMediaId' => $input['adminLogoMediaId'] === '' ? null : $input['adminLogoMediaId'], 'darkLogoMediaId' => $input['darkAdminLogoMediaId'] === '' ? null : $input['darkAdminLogoMediaId']],
                'translations' => array_map(static fn(string $locale): array => ['locale' => $locale, 'message' => $input['message_' . $locale], 'contact' => $input['contact_' . $locale]], $this->profile->locales)];
            $this->service->save($document, $expected, $actor, $request->formValue('confirmed') === '1');
        } catch (PublicationConflict) {
            return new Response(409, $this->render($request, $this->service->load(), $input, 'website_publication_changed', true));
        } catch (PublicationFailure $error) {
            return new Response(422, $this->render($request, $saved, $input, $error->codeName));
        }
        return Response::redirect('/settings/publication');
    }

    /** @param array<string, string> $input */
    private function render(Request $request, WebsitePublication $settings, array $input = [], ?string $error = null, bool $conflict = false, ?\Utsuri\PortableCms\Conventional\I18n\Message $uploadNotice = null, ?\Utsuri\PortableCms\Conventional\I18n\Message $uploadError = null): string
    {
        $action = $request->formValue('_icon_action');
        $page = str_starts_with($action, 'page:') ? max(1, min(1000, (int) substr($action, 5))) : 1;
        $selected = $input['iconMediaId'] ?? $settings->document()['iconMediaId'] ?? '';
        $logos = '';
        foreach (['adminLogoMediaId' => $settings->administrationBranding->logoMediaId, 'darkAdminLogoMediaId' => $settings->administrationBranding->darkLogoMediaId] as $field => $id) {
            $action = $request->formValue('_' . $field . '_action');
            $logoPage = str_starts_with($action, 'page:') ? max(1, min(1000, (int) substr($action, 5))) : 1;
            $selection = $input[$field] ?? $id ?? '';
            $logos .= \Utsuri\PortableCms\Conventional\View\AdministrationLogoPicker::render($field, $selection, $this->media?->search($request->formValue('_' . $field . '_query'), $logoPage), $this->media?->find($selection), $this->chrome->translator(), $this->uploads !== null);
        }
        return WebsitePublicationPage::render($this->profile, $this->chrome, $settings, $input, $error, $conflict, $this->media?->search($request->formValue('_icon_query'), $page), $this->media?->find($selected), $logos, $this->uploads !== null, $uploadNotice, $uploadError);
    }
}
