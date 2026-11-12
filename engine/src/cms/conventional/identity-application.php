<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional;

use Throwable;
use Utsuri\PortableCms\Conventional\Content\ContentService;
use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Export\ExportService;
use Utsuri\PortableCms\Conventional\Http\Cookie;
use Utsuri\PortableCms\Conventional\Http\InvalidJsonBody;
use Utsuri\PortableCms\Conventional\Http\InvalidRequestBody;
use Utsuri\PortableCms\Conventional\Http\OriginPolicy;
use Utsuri\PortableCms\Conventional\Http\Request;
use Utsuri\PortableCms\Conventional\Http\RequestBodyTooLarge;
use Utsuri\PortableCms\Conventional\Http\Response;
use Utsuri\PortableCms\Conventional\Http\SecurityHeaders;
use Utsuri\PortableCms\Conventional\I18n\InterfaceLanguage;
use Utsuri\PortableCms\Conventional\I18n\Translator;
use Utsuri\PortableCms\Conventional\Identity\Clock;
use Utsuri\PortableCms\Conventional\Identity\IdentityService;
use Utsuri\PortableCms\Conventional\Identity\RandomSource;
use Utsuri\PortableCms\Conventional\Identity\SessionContext;
use Utsuri\PortableCms\Conventional\Media\MediaJobRepository;
use Utsuri\PortableCms\Conventional\Media\MediaUploadService;
use Utsuri\PortableCms\Conventional\Media\UploadFailure;
use Utsuri\PortableCms\Conventional\Preview\PreviewNotDeletable;
use Utsuri\PortableCms\Conventional\Preview\PreviewNotFound;
use Utsuri\PortableCms\Conventional\Preview\PreviewService;
use Utsuri\PortableCms\Conventional\View\Pages;

final class IdentityApplication
{
    public function __construct(
        private readonly IdentityService $identity,
        private readonly OriginPolicy $originPolicy,
        private readonly Clock $clock,
        private readonly RandomSource $random,
        private readonly ?\Closure $errorObserver = null,
        private readonly ?\Closure $rejectionObserver = null,
        private readonly ?ContentService $content = null,
        private readonly ?ProfileManifest $profile = null,
        private readonly ?MediaUploadService $mediaUploads = null,
        private readonly ?MediaJobRepository $mediaJobs = null,
        private readonly ?PreviewService $previews = null,
        private readonly ?ExportService $exports = null,
        private readonly bool $insecureLoopback = false,
        private readonly ?Content\TerminologyService $terminology = null,
        private readonly bool $showTechnicalData = false,
        private readonly ?\Utsuri\PortableCms\Conventional\Media\MediaLibrary $mediaLibrary = null,
        private readonly ?Design\RegistryService $design = null,
        private readonly ?Ai\AssistanceService $assistance = null,
        private readonly ?Content\AdministrationLabels $administrationLabels = null,
        private readonly ?Preview\PreviewShareHttp $previewShares = null,
        private readonly ?Publication\PublicationService $publication = null,
        private readonly ?Publication\WebsitePublicationService $websitePublication = null,
        private readonly ?Identity\AccountService $accounts = null,
        private readonly ?Preview\LivePreviewHttp $livePreviews = null,
        private readonly ?Preview\PreviewCleanupHttp $previewCleanup = null,
        private readonly ?Content\SiteLanguagesService $siteLanguages = null,
        private readonly ?Content\SiteWordingService $siteWording = null,
        private readonly ?Content\LanguageTextDeletionService $languageText = null,
        private readonly ?string $installedReleaseId = null,
        private readonly ?Preview\NewestPreviewHttp $newestPreviews = null,
        private readonly ?Content\RecordHistoryService $recordHistory = null,
        private readonly ?Preview\EditorExactPreview $editorExact = null,
        private readonly bool $previewCronOnly = false,
    ) {}

    public function handle(Request $request): Response
    {
        $correlationId = bin2hex($this->random->bytes(12));
        try {
            $response = $this->route($request, $correlationId);
        } catch (RequestBodyTooLarge) {
            $response = new Response(413, Pages::error(413, $correlationId, $this->translator($request)));
        } catch (Throwable $error) {
            if ($this->errorObserver !== null) {
                ($this->errorObserver)($error, $correlationId);
            }
            $response = new Response(500, Pages::error(500, $correlationId, $this->translator($request)));
        }
        $theme = View\ThemePreference::fromCookie($request->cookie(View\ThemePreference::COOKIE));
        if (!$response->hasHeader('content-type') && !$response->sameOriginFrame) {
            $response = $response->withBody(View\AdminShell::withTheme($response->body, $theme));
        }
        return SecurityHeaders::apply($response, $correlationId, $this->insecureLoopback);
    }

    private function route(Request $request, string $correlationId): Response
    {
        $t = $this->translator($request);
        if (str_starts_with($request->path, '/editor-preview-files/')) {
            return $this->editorExact?->serveFiles($request, $t) ?? new Response(404);
        }
        // This route deliberately opens no session and never accepts a media ID or storage path.
        if (str_starts_with($request->path, '/administration-logo/')) {
            return $request->method === 'GET' && $this->websitePublication !== null && $this->mediaLibrary !== null
                && preg_match('#\A/administration-logo/([a-f0-9]{64})\.webp\z#', $request->path, $logoMatch) === 1
                ? (new Media\AdministrationLogoDelivery($this->mediaLibrary))->serve($this->websitePublication->load(), $logoMatch[1]) : new Response(404);
        }
        if (str_starts_with($request->path, '/shared-previews/') || str_starts_with($request->path, '/shared-preview-files/')) {
            return $this->previewShares?->guest($request, $t) ?? new Response(404);
        }
        if (str_starts_with($request->path, '/newest-previews/') || str_starts_with($request->path, '/newest-preview-files/')) {
            return $this->newestPreviews?->guest($request, $t) ?? new Response(404);
        }
        if (Preview\LivePreviewHttp::matches($request)) {
            return $this->livePreviews?->serve($request, $t) ?? new Response(404, Pages::error(404, $correlationId, $t));
        }
        $recordRoute = $this->recordRoute($request->path);
        $fontRoute = $this->design !== null && $request->method === 'GET' && preg_match('#\A/fonts/([a-f0-9]{64})\.woff2\z#', $request->path, $fontMatch) === 1;
        $mediaRoute = $this->mediaRoute($request->method, $request->path);
        $previewRoute = $this->previewRoute($request->method, $request->path);
        $editorPreviewRoute = $this->editorExact === null ? null : Preview\EditorExactPreviewHttp::route($request);
        $nativeMatch = [];
        $nativeUploadRoute = $request->method === 'POST' && $this->mediaUploads !== null && $this->profile !== null
            && preg_match('#\A/records/([a-z][a-z0-9_-]*)/([a-f0-9]{32})/upload\z#', $request->path, $nativeMatch) === 1;
        $libraryUploadRoute = $request->method === 'POST' && $this->mediaUploads !== null && $this->profile !== null && $request->path === '/media/upload';
        if ($libraryUploadRoute) {
            $nativeUploadRoute = true;
            preg_match('#\A([a-z][a-z0-9_-]*)/([a-f0-9]{32})/([1-9][0-9]{0,9})\z#', $request->formValue('owner'), $ownerMatch);
            $nativeMatch = $request->formValue('owner') === 'website' ? [null, null, null] : [null, $ownerMatch[1] ?? '', $ownerMatch[2] ?? ''];
        }
        if ($nativeUploadRoute && !$libraryUploadRoute && $request->formValue('_upload_field') !== '') {
            $recordRoute = ['kind' => 'record', 'typeId' => $nativeMatch[1], 'recordId' => $nativeMatch[2]];
            $nativeUploadRoute = false;
        }
        $jobId = $request->method === 'GET'
            && preg_match('#\A/api/jobs/([a-f0-9]{32})\z#', $request->path, $jobMatch) === 1
                ? $jobMatch[1]
                : null;
        $knownRoute = ($request->method === 'GET' && in_array($request->path, ['/', '/login'], true))
            || ($request->method === 'POST' && in_array($request->path, ['/login', '/logout', '/jobs/wake', '/settings/theme', '/settings/language'], true))
            || ($this->content !== null && $this->profile !== null && $recordRoute !== null)
            || ($this->mediaUploads !== null && $mediaRoute !== null)
            || ($this->mediaJobs !== null && $jobId !== null);
        $knownRoute = $knownRoute || ($this->profile !== null && $previewRoute !== null);
        $knownRoute = $knownRoute || ($this->profile !== null && $request->method === 'GET' && $request->path === '/settings/installation');
        $knownRoute = $knownRoute || ($this->profile !== null && $this->content !== null && $editorPreviewRoute !== null);
        $knownRoute = $knownRoute || ($this->profile !== null && $this->recordHistory !== null && $request->path === '/settings/history' && in_array($request->method, ['GET', 'POST'], true));
        $knownRoute = $knownRoute || ($this->terminology !== null && $this->profile !== null
            && $request->path === '/settings/terminology' && in_array($request->method, ['GET', 'POST'], true));
        $libraryRoute = $this->mediaLibrary !== null && $this->content !== null && $this->profile !== null
            && \Utsuri\PortableCms\Conventional\Media\MediaLibraryHttp::matches($request);
        $knownRoute = $knownRoute || $libraryRoute;
        $knownRoute = $knownRoute || $nativeUploadRoute;
        $knownRoute = $knownRoute || $fontRoute;
        $knownRoute = $knownRoute || ($this->profile !== null && $request->path === '/releases' && in_array($request->method, ['GET', 'POST'], true));
        $knownRoute = $knownRoute || ($this->websitePublication !== null && $request->path === '/settings/publication' && in_array($request->method, ['GET', 'POST'], true));
        $knownRoute = $knownRoute || ($this->siteLanguages !== null && $this->profile !== null && $request->path === '/settings/languages' && in_array($request->method, ['GET', 'POST'], true));
        $knownRoute = $knownRoute || ($this->siteWording !== null && $this->profile !== null && $request->path === '/settings/wording' && in_array($request->method, ['GET', 'POST'], true));
        $knownRoute = $knownRoute || ($this->languageText !== null && $this->siteLanguages !== null && $this->profile !== null && $request->path === '/settings/languages/text' && in_array($request->method, ['GET', 'POST'], true));
        $holdingRoute = $this->publication?->holding !== null && Publication\HoldingBuildHttp::matches($request);
        $knownRoute = $knownRoute || $holdingRoute;
        $knownRoute = $knownRoute || ($this->accounts !== null && in_array($request->path, ['/account', '/accounts'], true) && in_array($request->method, ['GET', 'POST'], true));
        $knownRoute = $knownRoute || ($this->design !== null && $this->profile !== null && $request->path === '/settings/design' && in_array($request->method, ['GET', 'POST'], true));
        $knownRoute = $knownRoute || (
            $this->exports !== null
            && in_array($request->method, ['GET', 'POST'], true)
            && $request->path === '/exports'
        );
        if (!$knownRoute) {
            return new Response(404, Pages::error(404, $correlationId, $t));
        }
        if (
            $request->method === 'GET'
            && $request->path === '/'
            && $request->cookie(IdentityService::SESSION_COOKIE) === null
        ) {
            return Response::redirect('/login');
        }
        if (
            in_array($request->method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)
            && (
                $request->cookie(IdentityService::SESSION_COOKIE) === null
                || $request->cookie(IdentityService::CSRF_COOKIE) === null
            )
        ) {
            $this->observeRejection('credentials_missing', $correlationId);
            if ($mediaRoute !== null) {
                return Response::json(['error' => 'authentication_required'], 401);
            }
            return new Response(403, Pages::error(403, $correlationId, $t));
        }
        if (($mediaRoute !== null || $jobId !== null) && $request->cookie(IdentityService::SESSION_COOKIE) === null) {
            return Response::json(['error' => 'authentication_required'], 401);
        }

        $session = $this->identity->openSession(
            $request->cookie(IdentityService::SESSION_COOKIE),
            $request->cookie(IdentityService::CSRF_COOKIE),
        );
        $t = $this->translator($request, $session);
        if ($session->mustChangePassword && !in_array($request->path, ['/account', '/logout', '/settings/theme', '/settings/language'], true)) {
            return $this->withSessionCookies(Response::redirect('/account'), $session);
        }
        if (!Identity\AdministrationPermissions::allows($session, $request, $this->profile)) {
            return $this->withSessionCookies(new Response(403, Pages::error(403, $correlationId, $t)), $session);
        }
        // Names follow the interface language; stable IDs, routes and renderers never change.
        $profile = $this->presentedProfile($t);
        if ($request->path === '/settings/history' && $profile !== null && $this->recordHistory !== null) {
            if (!$session->administrator()) {
                return $this->withSessionCookies(Response::redirect('/login'), $session);
            }
            if ($request->method === 'POST' && $this->mutationRejection($request, $session) !== null) {
                return $this->withSessionCookies(new Response(403, Pages::error(403, $correlationId, $t)), $session);
            }
            return $this->withSessionCookies((new Content\RecordHistoryHttp($this->recordHistory, $profile, $this->chrome($request, $session)))->handle($request, $session), $session);
        }
        if ($request->path === '/settings/installation' && $profile !== null) {
            if (!$session->administrator()) {
                return $this->withSessionCookies(Response::redirect('/login'), $session);
            }
            return $this->withSessionCookies(new Response(200, View\InstallationPage::render($profile, $this->chrome($request, $session), $this->installedReleaseId)), $session);
        }
        if ($this->accounts !== null && in_array($request->path, ['/account', '/accounts'], true)) {
            if (!$session->authenticated()) {
                return $this->withSessionCookies(Response::redirect('/login'), $session);
            }
            if ($request->method === 'POST' && $this->mutationRejection($request, $session) !== null) {
                return $this->withSessionCookies(new Response(403, Pages::error(403, $correlationId, $t)), $session);
            }
            return $this->withSessionCookies((new Identity\AccountHttp($this->accounts, $profile, $this->chrome($request, $session)))->handle($request, $session), $session);
        }
        if ($holdingRoute) {
            if (!$session->editorial()) {
                return $this->withSessionCookies(Response::redirect('/login'), $session);
            }
            return $this->withSessionCookies(Publication\HoldingBuildHttp::handle($request, $this->publication->holding), $session);
        }
        if ($request->path === '/settings/publication' && $profile !== null && $this->websitePublication !== null) {
            if (!$session->editorial()) {
                return $this->withSessionCookies(Response::redirect('/login'), $session);
            }
            if ($request->method === 'POST' && $this->mutationRejection($request, $session) !== null) {
                return $this->withSessionCookies(new Response(403, Pages::error(403, $correlationId, $t)), $session);
            }
            return $this->withSessionCookies((new Publication\WebsitePublicationHttp($this->websitePublication, $profile, $this->chrome($request, $session), $this->mediaLibrary, $this->mediaUploads, $this->random))->handle($request, $session), $session);
        }
        if ($request->path === '/settings/languages' && $profile !== null && $this->siteLanguages !== null) {
            if (!$session->editorial()) {
                return $this->withSessionCookies(Response::redirect('/login'), $session);
            }
            if ($request->method === 'POST' && $this->mutationRejection($request, $session) !== null) {
                return $this->withSessionCookies(new Response(403, Pages::error(403, $correlationId, $t)), $session);
            }
            return $this->withSessionCookies((new Content\SiteLanguagesHttp($this->siteLanguages, $profile, $this->chrome($request, $session), $this->languageText))->handle($request, $session), $session);
        }
        if ($request->path === '/settings/languages/text' && $profile !== null && $this->siteLanguages !== null && $this->languageText !== null) {
            if (!$session->editorial() || $session->userId === null) {
                return $this->withSessionCookies(Response::redirect('/login'), $session);
            }
            if ($request->method === 'POST' && $this->mutationRejection($request, $session) !== null) {
                return $this->withSessionCookies(new Response(403, Pages::error(403, $correlationId, $t)), $session);
            }
            return $this->withSessionCookies((new Content\LanguageTextDeletionHttp($this->languageText, $this->siteLanguages, $profile, $this->chrome($request, $session)))->handle($request, $session), $session);
        }
        if ($request->path === '/settings/wording' && $profile !== null && $this->siteWording !== null) {
            if (!$session->editorial() || $session->userId === null) {
                return $this->withSessionCookies(Response::redirect('/login'), $session);
            }
            if ($request->method === 'POST' && $this->mutationRejection($request, $session) !== null) {
                return $this->withSessionCookies(new Response(403, Pages::error(403, $correlationId, $t)), $session);
            }
            // The page shows the languages editors work in: public and in preparation.
            $editing = $this->siteLanguages?->load()->editingProfile($profile) ?? $profile;
            return $this->withSessionCookies((new Content\SiteWordingHttp($this->siteWording, $editing, $this->chrome($request, $session)))->handle($request, $session), $session);
        }
        if ($request->path === '/releases' && $profile !== null) {
            if (!$session->editorial() || $session->userId === null) {
                return $this->withSessionCookies(Response::redirect('/login'), $session);
            }
            if ($request->method === 'POST') {
                $rejection = $this->mutationRejection($request, $session);
                if ($rejection !== null) {
                    $this->observeRejection($rejection, $correlationId);
                    return $this->withSessionCookies(new Response(403, Pages::error(403, $correlationId, $t)), $session);
                }
                if ($this->publication === null) {
                    return $this->withSessionCookies(new Response(501, View\PublicationPage::render($profile, $this->chrome($request, $session), null, $this->previews)), $session);
                }
                $action = $request->formValue('action');
                $id = $request->formValue('id');
                $previous = $request->formValue('previous');
                $scheduleRevision = filter_var($request->formValue('schedule_revision'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if (!in_array($action, ['approve', 'rollback', 'forget', 'wake', 'build_holding', 'delete_holding', 'move_schedule', 'cancel_schedule'], true) || preg_match('/\A[a-f0-9]{32}\z/', $id) !== 1
                    || !in_array($request->formValue('timing'), ['', 'now', 'later'], true)
                    || (in_array($action, ['move_schedule', 'cancel_schedule'], true) && !is_int($scheduleRevision))
                    || ($previous !== '' && preg_match('/\A[a-f0-9]{32}\z/', $previous) !== 1) || (!in_array($action, ['wake', 'build_holding'], true) && $request->formValue('confirmed') !== '1')) {
                    return $this->withSessionCookies(new Response(422, View\PublicationPage::render($profile, $this->chrome($request, $session), $this->publication, $this->previews, 'confirmation_required')), $session);
                }
                try {
                    match ($action) {
                        'approve' => $this->publication->approve($id, $previous === '' ? null : $previous, $session, $request->formValue('timing') === 'later' ? $this->publication->scheduleTime($request->formValue('scheduled_at')) : null),
                        'move_schedule', 'cancel_schedule' => $this->publication->changeSchedule($id, is_int($scheduleRevision) ? $scheduleRevision : 0, $action === 'move_schedule' ? $this->publication->scheduleTime($request->formValue('scheduled_at')) : null, $session),
                        'rollback' => $this->publication->rollback($id, $previous === '' ? null : $previous, $session),
                        'forget' => $this->publication->forget($id, $session),
                        'wake' => $this->publication->retry($id, $previous === '' ? null : $previous, $session),
                        'build_holding' => $this->publication->holding?->create($session->userId, self::holdingRevision($request)) ?? throw new Publication\PublicationFailure('holding_unavailable'),
                        'delete_holding' => $this->publication->holding?->builds->delete($id, $session->userId),
                    };
                } catch (Publication\PublicationConflict) {
                    return $this->withSessionCookies(new Response(409, View\PublicationPage::render($profile, $this->chrome($request, $session), $this->publication, $this->previews, 'release_changed')), $session);
                } catch (Publication\PublicationFailure|Preview\PreviewNotReleasable|Preview\PreviewNotDeletable|Preview\PreviewNotFound $error) {
                    return $this->withSessionCookies(new Response(422, View\PublicationPage::render($profile, $this->chrome($request, $session), $this->publication, $this->previews, $error instanceof Publication\PublicationFailure ? $error->codeName : 'preview_not_releasable')), $session);
                }
                return $this->withSessionCookies(Response::redirect('/releases'), $session);
            }
            $response = new Response(200, View\PublicationPage::render($profile, $this->chrome($request, $session), $this->publication, $this->previews));
            if ($this->publication?->runnable() === true || array_filter($this->publication?->holding?->builds->list() ?? [], static fn($build): bool => in_array($build->status, ['queued', 'building'], true)) !== []) {
                $response = $response->withHeader('refresh', '5; url=/releases');
            }
            return $this->withSessionCookies($response, $session);
        }
        if ($fontRoute && $this->design !== null) {
            if (!$session->editorial()) {
                return new Response(403, Pages::error(403, $correlationId, $t));
            }
            foreach ($this->design->load()->faces() as $font) {
                if ($font['origin'] === 'upload' && $font['sha256'] === $fontMatch[1]) {
                    return (new Response(200, $this->design->fonts->previewBytes($font['sha256'], $font['byteSize'])))->withHeader('content-type', 'font/woff2');
                }
            }
            return new Response(404, Pages::error(404, $correlationId, $t));
        }

        if ($request->method === 'GET' && $request->path === '/login') {
            $response = $session->editorial()
                ? Response::redirect('/')
                : new Response(200, Pages::login($session->csrfToken, profile: $profile, translator: $t, identity: $this->websitePublication?->load()->identity(), logos: $this->logos()));
            return $this->withSessionCookies($response, $session);
        }

        if ($request->method === 'POST' && $request->path === '/login') {
            $rejection = $this->mutationRejection($request, $session);
            if ($rejection !== null) {
                $this->observeRejection($rejection, $correlationId);
                return $this->withSessionCookies(
                    new Response(403, Pages::error(403, $correlationId, $t)),
                    $session,
                );
            }
            $result = $this->identity->login(
                $session,
                $request->formValue('login'),
                $request->formValue('password'),
                $request->remoteAddress,
            );
            if ($result->authenticated) {
                return $this->withSessionCookies(Response::redirect($result->session->mustChangePassword ? '/account' : '/'), $result->session, force: true);
            }
            $error = $result->failureStatus === 429 ? 'throttled' : 'credentials';
            return $this->withSessionCookies(
                new Response(
                    $result->failureStatus,
                    Pages::login($result->session->csrfToken, $request->formValue('login'), $error, $profile, $t, $this->websitePublication?->load()->identity(), $this->logos()),
                ),
                $result->session,
            );
        }

        if ($request->method === 'GET' && $request->path === '/') {
            if (!$session->editorial()) {
                return $this->withSessionCookies(Response::redirect('/login'), $session);
            }
            return $this->withSessionCookies(
                new Response(200, Pages::dashboard(
                    $session->csrfToken,
                    $session->displayName ?? $session->loginName ?? 'administrator',
                    $this->siteLanguages?->load()->editingProfile($profile) ?? $profile,
                    $this->mediaJobs?->latestPublicStatus(),
                    $this->chrome($request, $session),
                    $this->content?->recent(8) ?? [],
                    $this->previews?->latestCheck(),
                    $this->content === null ? [] : ($this->previews?->setup($this->content) ?? []),
                )),
                $session,
            );
        }

        if ($request->method === 'POST' && $request->path === '/settings/theme') {
            $rejection = !$session->authenticated()
                ? 'authorization_rejected'
                : $this->mutationRejection($request, $session);
            if ($rejection !== null) {
                $this->observeRejection($rejection, $correlationId);
                return $this->withSessionCookies(new Response(403, Pages::error(403, $correlationId, $t)), $session);
            }
            $theme = View\ThemePreference::tryFrom($request->formValue('theme'));
            if ($theme === null) {
                return $this->withSessionCookies(new Response(400, Pages::error(400, $correlationId, $t)), $session);
            }
            $response = str_contains($request->header('accept') ?? '', 'application/json')
                ? Response::json(['theme' => $theme->value])
                : Response::redirect(View\AdminChrome::returnPath($request->formValue('_return')));
            $system = $theme === View\ThemePreference::System;
            return $this->withSessionCookies($response->withCookie(new Cookie(
                View\ThemePreference::COOKIE,
                $system ? '' : $theme->value,
                $system ? 0 : 31_536_000,
                true,
                'Lax',
            )), $session);
        }

        if ($request->method === 'POST' && $request->path === '/settings/language') {
            $rejection = !$session->authenticated()
                ? 'authorization_rejected'
                : $this->mutationRejection($request, $session);
            if ($rejection !== null) {
                $this->observeRejection($rejection, $correlationId);
                return $this->withSessionCookies(new Response(403, Pages::error(403, $correlationId, $t)), $session);
            }
            $choice = $request->formValue('language');
            if (!InterfaceLanguage::choice($choice)) {
                return $this->withSessionCookies(new Response(400, Pages::error(400, $correlationId, $t)), $session);
            }
            $this->identity->changeInterfaceLanguage($session, InterfaceLanguage::preference($choice));
            return $this->withSessionCookies(Response::redirect(View\AdminChrome::returnPath($request->formValue('_return'))), $session);
        }

        if ($request->method === 'POST' && $request->path === '/logout') {
            $rejection = !$session->authenticated()
                ? 'authorization_rejected'
                : $this->mutationRejection($request, $session);
            if ($rejection !== null) {
                $this->observeRejection($rejection, $correlationId);
                return $this->withSessionCookies(
                    new Response(403, Pages::error(403, $correlationId, $t)),
                    $session,
                );
            }
            $this->identity->logout($session);
            return $this->expireSessionCookies(Response::redirect('/login'));
        }

        if ($nativeUploadRoute && $this->mediaUploads !== null && $profile !== null) {
            if (!$session->editorial() || $session->userId === null || $this->mutationRejection($request, $session) !== null) {
                return $this->withSessionCookies(new Response(403, Pages::error(403, $correlationId, $t)), $session);
            }
            try {
                $response = \Utsuri\PortableCms\Conventional\Media\NativeUploadHttp::handle(
                    $request,
                    $nativeMatch[1],
                    $nativeMatch[2],
                    $session->userId,
                    $this->mediaUploads,
                    $this->random,
                    $this->siteLanguages?->load()->editingProfile($profile) ?? $profile,
                    $t,
                    $libraryUploadRoute && $nativeMatch[2] !== null ? (int) ($ownerMatch[3] ?? 0) : null,
                    $this->mediaLibrary,
                    $session->csrfToken,
                );
            } catch (UploadFailure $error) {
                $response = new Response($error->statusCode, Pages::error($error->statusCode, $correlationId, $t));
            }
            return $this->withSessionCookies($response, $session);
        }

        if ($libraryRoute && $this->mediaLibrary !== null && $this->content !== null && $profile !== null) {
            if (!$session->editorial() || $session->userId === null) {
                return $this->withSessionCookies(Response::redirect('/login'), $session);
            }
            if ($request->method === 'POST' && $this->mutationRejection($request, $session) !== null) {
                return $this->withSessionCookies(new Response(403, Pages::error(403, $correlationId, $t)), $session);
            }
            return $this->withSessionCookies((new \Utsuri\PortableCms\Conventional\Media\MediaLibraryHttp(
                $this->mediaLibrary,
                $this->content,
                $this->siteLanguages?->load()->editingProfile($profile) ?? $profile,
                $this->chrome($request, $session),
                $this->mediaUploads !== null,
            ))->handle($request, $session, $correlationId), $session);
        }

        if ($request->path === '/settings/terminology' && $this->terminology !== null && $profile !== null) {
            if (!$session->editorial() || $session->userId === null) {
                return $this->withSessionCookies(Response::redirect('/login'), $session);
            }
            if ($request->method === 'POST') {
                $rejection = $this->mutationRejection($request, $session);
                if ($rejection !== null) {
                    $this->observeRejection($rejection, $correlationId);
                    return $this->withSessionCookies(new Response(403, Pages::error(403, $correlationId, $t)), $session);
                }
            }
            return $this->withSessionCookies(
                (new Content\TerminologyHttp($this->terminology, $this->profile, $this->chrome($request, $session), $this->administrationLabels))->handle($request, $session),
                $session,
            );
        }

        if ($request->path === '/settings/design' && $this->design !== null && $profile !== null) {
            if (!$session->editorial() || $session->userId === null) {
                return $this->withSessionCookies(Response::redirect('/login'), $session);
            }
            if ($request->method === 'POST') {
                $rejection = $this->mutationRejection($request, $session);
                if ($rejection !== null) {
                    $this->observeRejection($rejection, $correlationId);
                    return $this->withSessionCookies(new Response(403, Pages::error(403, $correlationId, $t)), $session);
                }
            }
            return $this->withSessionCookies((new Design\RegistryHttp($this->design, $profile, $this->chrome($request, $session)))->handle($request, $session), $session);
        }

        if ($request->path === '/exports' && $this->exports !== null) {
            if (!$session->editorial() || $session->userId === null) {
                return $this->withSessionCookies(Response::redirect('/login'), $session);
            }
            if ($request->method === 'GET') {
                return $this->withSessionCookies(
                    new Response(200, Pages::exportPage($session->csrfToken, $profile, $this->chrome($request, $session), $this->exports->capacity())),
                    $session,
                );
            }
            $rejection = $this->mutationRejection($request, $session);
            if ($rejection !== null) {
                $this->observeRejection($rejection, $correlationId);
                return $this->withSessionCookies(
                    new Response(403, Pages::error(403, $correlationId, $t)),
                    $session,
                );
            }
            try {
                if ($this->exports->capacity()?->chunkedRequired()) {
                    throw new \Utsuri\PortableCms\Conventional\Export\ExportCapacityFailure('private_package_required');
                }
                $export = $this->exports->create($session->userId);
            } catch (\Utsuri\PortableCms\Conventional\Export\ExportCapacityFailure) {
                return $this->withSessionCookies(new Response(422, Pages::exportPage($session->csrfToken, $profile, $this->chrome($request, $session), $this->exports->capacity(), true)), $session);
            }
            $exportDate = str_replace('-', '', substr($export->document['createdAt'], 0, 10));
            $filename = 'portable-cms-export-'
                . $exportDate
                . '-' . substr($export->document['exportId'], 0, 8) . '.json';
            $response = (new Response(200, $export->json()))
                ->withHeader('content-type', 'application/json; charset=utf-8')
                ->withHeader('content-disposition', 'attachment; filename="' . $filename . '"');
            return $this->withSessionCookies($response, $session);
        }

        if ($request->method === 'POST' && $request->path === '/jobs/wake') {
            $rejection = !$session->editorial()
                ? 'authorization_rejected'
                : $this->mutationRejection($request, $session);
            if ($rejection !== null) {
                $this->observeRejection($rejection, $correlationId);
                return $this->withSessionCookies(
                    new Response(403, Pages::error(403, $correlationId, $t)),
                    $session,
                );
            }
            return $this->withSessionCookies(
                (new Response(202, Pages::mediaWakeQueued($profile, $this->chrome($request, $session))))
                    ->withHeader('refresh', '2; url=/'),
                $session,
            );
        }

        if ($jobId !== null && $this->mediaJobs !== null) {
            if (!$session->editorial()) {
                return $this->withSessionCookies(
                    Response::json(['error' => 'authentication_required'], 401),
                    $session,
                );
            }
            $job = $this->mediaJobs->publicStatus($jobId);
            return $this->withSessionCookies(
                $job === null
                    ? Response::json(['error' => 'job_not_found'], 404)
                    : Response::json(['job' => $job]),
                $session,
            );
        }

        if ($mediaRoute !== null && $this->mediaUploads !== null) {
            if (!$session->editorial() || $session->userId === null) {
                return $this->withSessionCookies(
                    Response::json(['error' => 'authentication_required'], 401),
                    $session,
                );
            }
            if ($request->method !== 'GET') {
                $rejection = $this->mutationRejection($request, $session);
                if ($rejection !== null) {
                    $this->observeRejection($rejection, $correlationId);
                    return $this->withSessionCookies(
                        Response::json(['error' => 'request_rejected'], 403),
                        $session,
                    );
                }
            }
            try {
                $response = match ($mediaRoute['kind']) {
                    'create' => $this->createMediaUpload($request, $mediaRoute, $session->userId),
                    'status' => Response::json([
                        'upload' => $this->mediaUploads
                            ->status((string) $mediaRoute['sessionId'], $session->userId)
                            ->publicValue(),
                    ]),
                    'part' => $this->uploadMediaPart($request, $mediaRoute, $session->userId),
                    'complete' => $this->completeMediaUpload($mediaRoute, $session->userId),
                    'abort' => Response::json([
                        'upload' => $this->mediaUploads
                            ->abort((string) $mediaRoute['sessionId'], $session->userId)
                            ->publicValue(),
                    ]),
                };
            } catch (UploadFailure $error) {
                $response = Response::json([
                    'error' => $error->codeName,
                    'message' => $t->failure($error),
                ], $error->statusCode);
            } catch (InvalidJsonBody|InvalidRequestBody) {
                $response = Response::json(['error' => 'invalid_request_body'], 400);
            }
            return $this->withSessionCookies($response, $session);
        }

        if ($previewRoute !== null && $this->previews === null) {
            if (!$session->editorial()) {
                return $this->withSessionCookies(Response::redirect('/login'), $session);
            }
            if ($request->method === 'POST') {
                $rejection = $this->mutationRejection($request, $session);
                if ($rejection !== null) {
                    $this->observeRejection($rejection, $correlationId);
                    return $this->withSessionCookies(
                        new Response(403, Pages::error(403, $correlationId, $t)),
                        $session,
                    );
                }
            }
            return $this->withSessionCookies(
                new Response(501, Pages::featureUnavailable(
                    $session->csrfToken,
                    $profile,
                    $t->text('unavailable.previews.title'),
                    $t->text('unavailable.previews.message'),
                    'previews',
                    $this->chrome($request, $session),
                )),
                $session,
            );
        }

        if ($editorPreviewRoute !== null && $this->editorExact !== null && $this->content !== null && $profile !== null) {
            if (!$session->editorial()) {
                return $this->withSessionCookies(Response::redirect('/login'), $session);
            }
            if ($request->method === 'POST' && $this->mutationRejection($request, $session) !== null) {
                return $this->withSessionCookies(new Response(403, Pages::error(403, $correlationId, $t)), $session);
            }
            return $this->withSessionCookies((new Preview\EditorExactPreviewHttp($this->editorExact, $this->content, $profile, $this->chrome($request, $session), $this->previewCronOnly))->handle($request, $editorPreviewRoute, $session), $session);
        }
        if ($previewRoute !== null && $this->previews !== null) {
            if (!$session->editorial() || $session->userId === null) {
                return $this->withSessionCookies(Response::redirect('/login'), $session);
            }
            if ($request->method === 'POST') {
                $rejection = $this->mutationRejection($request, $session);
                if ($rejection !== null) {
                    $this->observeRejection($rejection, $correlationId);
                    return $this->withSessionCookies(
                        new Response(403, Pages::error(403, $correlationId, $t)),
                        $session,
                    );
                }
                if ($previewRoute['kind'] === 'newest-shares') {
                    return $this->withSessionCookies($this->newestPreviews?->manage($request, $session->userId, $session->csrfToken, $profile, $this->chrome($request, $session), $this->content?->pendingPublicationCount() ?? 0) ?? new Response(501), $session);
                }
                if ($previewRoute['kind'] === 'shares') {
                    return $this->withSessionCookies($this->previewShares?->manage($request, (string) $previewRoute['previewId'], $session->userId, $session->csrfToken, $profile, $this->chrome($request, $session)) ?? new Response(501), $session);
                }
                if ($previewRoute['kind'] === 'delete') {
                    try {
                        $this->previews->delete((string) $previewRoute['previewId'], $session->userId);
                    } catch (Preview\PreviewCleanupPending $pending) {
                        return $this->withSessionCookies($this->previewCleanup?->pending((string) $previewRoute['previewId'], $session->userId, $session->csrfToken, $profile, $this->chrome($request, $session), $pending->outcome) ?? new Response(202), $session);
                    } catch (PreviewNotFound) {
                        return $this->withSessionCookies(
                            new Response(404, Pages::error(404, $correlationId, $t)),
                            $session,
                        );
                    } catch (PreviewNotDeletable) {
                        return $this->withSessionCookies(
                            new Response(409, Pages::error(409, $correlationId, $t)),
                            $session,
                        );
                    }
                    return $this->withSessionCookies(Response::redirect('/previews'), $session);
                }
                if ($previewRoute['kind'] === 'cleanup') {
                    return $this->withSessionCookies($this->previewCleanup?->mutate($request, $session->userId, $session->csrfToken, $profile, $this->chrome($request, $session)) ?? new Response(501), $session);
                }
                if (!in_array($request->formValue('include_drafts'), ['', '1'], true) || !in_array($request->formValue('include_preparation'), ['', '1'], true)) {
                    return $this->withSessionCookies(new Response(400, Pages::error(400, $correlationId, $t)), $session);
                }
                $includeDrafts = $request->formValue('include_drafts') === '1';
                $includePreparation = $request->formValue('include_preparation') === '1';
                try {
                    $preview = $this->previews->create($session->userId, $includeDrafts, $includePreparation);
                } catch (Preview\PreviewPreflightFailure $failure) {
                    return $this->withSessionCookies(new Response(422, View\PreviewPreflightPage::render($profile, $session->csrfToken, $failure->issues, $this->chrome($request, $session), $includeDrafts, $failure->reference, $failure->checkedAt, $failure->links, $includePreparation)), $session);
                }
                return $this->withSessionCookies(
                    (new Response(202, Pages::previewQueued($profile, $preview, $this->chrome($request, $session))))
                        ->withHeader('refresh', '2; url=/previews/' . $preview->id),
                    $session,
                );
            }
            if ($previewRoute['kind'] === 'shares') {
                return $this->withSessionCookies($this->previewShares?->manage($request, (string) $previewRoute['previewId'], $session->userId, $session->csrfToken, $profile, $this->chrome($request, $session)) ?? new Response(501), $session);
            }
            if ($previewRoute['kind'] === 'list') {
                $newestLinks = $session->administrator() ? $this->newestPreviews?->listing($session->csrfToken, $t) : null;
                if ($this->previewCleanup !== null) {
                    return $this->withSessionCookies($this->previewCleanup->listing($request, $session->userId, $session->csrfToken, $profile, $this->chrome($request, $session), $this->content, $newestLinks), $session);
                }
                return $this->withSessionCookies(new Response(200, Pages::previewList(
                    $session->csrfToken,
                    $profile,
                    $this->previews->list(),
                    $this->chrome($request, $session),
                    $this->previews->latestCheck(),
                    $this->content === null ? [] : $this->previews->setup($this->content),
                    preparation: $this->previews->preparationLanguages(),
                    newestLinks: $newestLinks,
                    pendingPublication: $this->content?->pendingPublicationCount() ?? 0,
                )), $session);
            }
            $previewId = (string) $previewRoute['previewId'];
            if ($previewRoute['kind'] === 'artifact') {
                if (str_ends_with($request->path, '/artifact')) {
                    return $this->withSessionCookies(Response::redirect($request->path . '/', 308), $session);
                }
                $response = $this->previews->artifactResponse($previewId, (string) $previewRoute['artifactPath'], '/previews/' . $previewId . '/artifact/', $request->header('range'));
                $response = $response === null ? new Response(404, Pages::error(404, $correlationId, $t))
                    : $response->withHeader('x-robots-tag', 'noindex, nofollow, noarchive');
                return $this->withSessionCookies($response, $session);
            }
            $preview = $this->previews->find($previewId);
            $response = $preview === null
                ? new Response(404, Pages::error(404, $correlationId, $t))
                : new Response(200, Pages::previewStatus($session->csrfToken, $profile, $preview, $this->chrome($request, $session), $this->previewShares !== null, $this->publication === null ? '' : View\ReleaseScheduleView::notice(array_values(array_filter($this->publication->list(), static fn($release): bool => $release->previewId === $previewId)), $this->publication, $t), $this->recordHistory?->missingPreviewRevisions($previewId) ?? 0));
            if ($preview !== null && in_array($preview->status, ['queued', 'building'], true)) {
                $response = $response->withHeader('refresh', '2; url=/previews/' . $preview->id);
            }
            return $this->withSessionCookies(
                $response,
                $session,
            );
        }

        if ($recordRoute !== null && $this->content !== null && $profile !== null) {
            if (!$session->editorial() || $session->userId === null) {
                return $this->withSessionCookies(Response::redirect('/login'), $session);
            }
            $rejection = $request->method === 'POST' ? $this->mutationRejection($request, $session) : null;
            if ($rejection !== null) {
                $this->observeRejection($rejection, $correlationId);
                return $this->withSessionCookies(new Response(403, Pages::error(403, $correlationId, $t)), $session);
            }
            try {
                $response = (new Content\EditorialRecordHttp(
                    $this->content,
                    // Record editing shows the public languages and those in preparation; off languages keep their text.
                    $this->siteLanguages?->load()->editingProfile($profile) ?? $profile,
                    $this->random,
                    $this->mediaLibrary,
                    $this->mediaUploads !== null,
                    $this->showTechnicalData,
                    $this->design,
                    $this->assistance,
                    $this->chrome($request, $session),
                    $this->livePreviews?->previews,
                    $this->mediaUploads,
                    $this->publication,
                    $this->editorExact,
                ))->handle($request, $recordRoute, $session, $correlationId);
            } catch (\InvalidArgumentException) {
                $response = new Response(400, Pages::error(400, $correlationId, $t));
            }
            return $this->withSessionCookies($response, $session);
        }
        return new Response(404, Pages::error(404, $correlationId, $t));
    }

    /** @param array<string, int|string|null> $route */
    private function createMediaUpload(Request $request, array $route, string $actorUserId): Response
    {
        $contentType = strtolower(trim(explode(';', $request->header('content-type') ?? '')[0]));
        if ($contentType !== 'application/json') {
            throw new UploadFailure('content_type_required', 415, 'upload.error.upload_creation_requires_json');
        }
        $body = $request->jsonBody();
        $allowedKeys = ['formatVersion', 'expectedRevision', 'filename', 'declaredType', 'byteSize', 'clientUploadId'];
        $receivedKeys = array_keys($body);
        sort($allowedKeys, SORT_STRING);
        sort($receivedKeys, SORT_STRING);
        if ($receivedKeys !== $allowedKeys) {
            throw new UploadFailure('invalid_upload_intent', 422, 'upload.error.the_upload_request_is_invalid');
        }
        foreach (['formatVersion', 'expectedRevision', 'filename', 'declaredType', 'byteSize', 'clientUploadId'] as $key) {
            if (!array_key_exists($key, $body)) {
                throw new UploadFailure('invalid_upload_intent', 422, 'upload.error.the_upload_request_is_incomplete');
            }
        }
        if (
            $body['formatVersion'] !== 1
            || !is_int($body['expectedRevision'])
            || !is_string($body['filename'])
            || !is_string($body['declaredType'])
            || !is_int($body['byteSize'])
            || !is_string($body['clientUploadId'])
        ) {
            throw new UploadFailure('invalid_upload_intent', 422, 'upload.error.the_upload_request_is_invalid');
        }
        $ownerType = $this->profile?->type((string) $route['typeId']);
        if ($ownerType === null || $ownerType->kind !== 'collection') {
            throw new UploadFailure('record_not_found', 404, 'upload.error.the_target_record_was_not_found');
        }
        $upload = $this->mediaUploads?->create(
            (string) $route['typeId'],
            (string) $route['recordId'],
            $body['expectedRevision'],
            $body['filename'],
            $body['declaredType'],
            $body['byteSize'],
            $body['clientUploadId'],
            $actorUserId,
        ) ?? throw new \RuntimeException('Media upload service is unavailable');
        return Response::json(['upload' => $upload->publicValue()], 201);
    }

    /** @param array<string, int|string|null> $route */
    private function uploadMediaPart(Request $request, array $route, string $actorUserId): Response
    {
        $contentLength = filter_var(
            $request->header('content-length'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );
        if (!is_int($contentLength)) {
            throw new UploadFailure('content_length_required', 411, 'upload.error.a_valid_content_length_header_is_required');
        }
        $stream = $request->bodyStream();
        try {
            $upload = $this->mediaUploads?->uploadPart(
                (string) $route['sessionId'],
                (int) $route['partNumber'],
                $contentLength,
                $stream,
                $actorUserId,
            ) ?? throw new \RuntimeException('Media upload service is unavailable');
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
        return Response::json(['upload' => $upload->publicValue()]);
    }

    /** @param array<string, int|string|null> $route */
    private function completeMediaUpload(array $route, string $actorUserId): Response
    {
        $result = $this->mediaUploads?->complete((string) $route['sessionId'], $actorUserId)
            ?? throw new \RuntimeException('Media upload service is unavailable');
        return Response::json([
            'upload' => $result['session']->publicValue(),
            'media' => [
                'id' => (string) $result['media']['id'],
                'status' => (string) $result['media']['status'],
                'originalName' => (string) $result['media']['original_name'],
                'declaredType' => (string) $result['media']['declared_type'],
                'byteSize' => (int) $result['media']['byte_size'],
                'checksum' => (string) $result['media']['checksum'],
            ],
            'job' => [
                'id' => (string) $result['job']['id'],
                'type' => (string) $result['job']['job_type'],
                'status' => (string) $result['job']['status'],
            ],
        ], 202);
    }

    /** @return array{kind: 'list'|'new'|'record'|'workflow'|'live', typeId: string, recordId: string|null}|null */
    private function recordRoute(string $path): ?array
    {
        if ($this->livePreviews !== null && preg_match('#\A/records/([a-z][a-z0-9]*(?:[-_][a-z0-9]+)*)/([a-f0-9]{32}|new)/live-preview\z#', $path, $match) === 1) {
            return ['kind' => 'live', 'typeId' => $match[1], 'recordId' => $match[2] === 'new' ? null : $match[2]];
        }
        if (preg_match('#\A/records/([a-z][a-z0-9]*(?:[-_][a-z0-9]+)*)/([a-f0-9]{32})/status\z#', $path, $match) === 1) {
            return ['kind' => 'workflow', 'typeId' => $match[1], 'recordId' => $match[2]];
        }
        if (preg_match('#\A/records/([a-z][a-z0-9]*(?:[-_][a-z0-9]+)*)\z#', $path, $match) === 1) {
            return ['kind' => 'list', 'typeId' => $match[1], 'recordId' => null];
        }
        if (preg_match('#\A/records/([a-z][a-z0-9]*(?:[-_][a-z0-9]+)*)/new\z#', $path, $match) === 1) {
            return ['kind' => 'new', 'typeId' => $match[1], 'recordId' => null];
        }
        if (preg_match(
            '#\A/records/([a-z][a-z0-9]*(?:[-_][a-z0-9]+)*)/([a-f0-9]{32})\z#',
            $path,
            $match,
        ) === 1) {
            return ['kind' => 'record', 'typeId' => $match[1], 'recordId' => $match[2]];
        }
        return null;
    }

    /** @return array{kind: 'create'|'status'|'part'|'complete'|'abort', typeId: string|null, recordId: string|null, sessionId: string|null, partNumber: int|null}|null */
    private function mediaRoute(string $method, string $path): ?array
    {
        if (
            $method === 'POST'
            && preg_match('#\A/api/records/([a-z][a-z0-9]*(?:[-_][a-z0-9]+)*)/([a-f0-9]{32})/media-uploads\z#', $path, $match) === 1
        ) {
            return [
                'kind' => 'create',
                'typeId' => $match[1],
                'recordId' => $match[2],
                'sessionId' => null,
                'partNumber' => null,
            ];
        }
        if (preg_match('#\A/api/media-uploads/([a-f0-9]{32})\z#', $path, $match) === 1) {
            $kind = match ($method) {
                'GET' => 'status',
                'DELETE' => 'abort',
                default => null,
            };
            return $kind === null ? null : [
                'kind' => $kind,
                'typeId' => null,
                'recordId' => null,
                'sessionId' => $match[1],
                'partNumber' => null,
            ];
        }
        if (
            $method === 'PUT'
            && preg_match('#\A/api/media-uploads/([a-f0-9]{32})/parts/([1-9][0-9]?)\z#', $path, $match) === 1
        ) {
            return [
                'kind' => 'part',
                'typeId' => null,
                'recordId' => null,
                'sessionId' => $match[1],
                'partNumber' => (int) $match[2],
            ];
        }
        if (
            $method === 'POST'
            && preg_match('#\A/api/media-uploads/([a-f0-9]{32})/complete\z#', $path, $match) === 1
        ) {
            return [
                'kind' => 'complete',
                'typeId' => null,
                'recordId' => null,
                'sessionId' => $match[1],
                'partNumber' => null,
            ];
        }
        return null;
    }

    /** @return array{kind: 'list'|'status'|'artifact'|'delete'|'shares'|'newest-shares'|'cleanup', previewId: string|null, artifactPath: string|null}|null */
    private function previewRoute(string $method, string $path): ?array
    {
        if ($method === 'POST' && ($path === '/previews/shares/newest' || preg_match('#\A/previews/shares/newest/[a-f0-9]{32}/revoke\z#', $path) === 1)) {
            return ['kind' => 'newest-shares', 'previewId' => null, 'artifactPath' => null];
        }
        if ($method === 'POST' && in_array($path, ['/previews/delete/review', '/previews/delete/confirm'], true)) {
            return ['kind' => 'cleanup', 'previewId' => null, 'artifactPath' => null];
        }
        if ((in_array($method, ['GET', 'POST'], true) && preg_match('#\A/previews/([a-f0-9]{32})/shares\z#', $path, $shareMatch) === 1)
            || ($method === 'POST' && preg_match('#\A/previews/([a-f0-9]{32})/shares/[a-f0-9]{32}/revoke\z#', $path, $shareMatch) === 1)) {
            return ['kind' => 'shares', 'previewId' => $shareMatch[1], 'artifactPath' => null];
        }
        if (in_array($method, ['GET', 'POST'], true) && $path === '/previews') {
            return ['kind' => 'list', 'previewId' => null, 'artifactPath' => null];
        }
        if (
            $method === 'POST'
            && preg_match('#\A/previews/([a-f0-9]{32})/delete\z#', $path, $match) === 1
        ) {
            return ['kind' => 'delete', 'previewId' => $match[1], 'artifactPath' => null];
        }
        if (
            $method === 'GET'
            && preg_match('#\A/previews/([a-f0-9]{32})\z#', $path, $match) === 1
        ) {
            return ['kind' => 'status', 'previewId' => $match[1], 'artifactPath' => null];
        }
        if (
            $method === 'GET'
            && preg_match('#\A/previews/([a-f0-9]{32})/artifact/?\z#', $path, $match) === 1
        ) {
            return ['kind' => 'artifact', 'previewId' => $match[1], 'artifactPath' => 'index.html'];
        }
        if (
            $method === 'GET'
            && preg_match('#\A/previews/([a-f0-9]{32})/artifact/([a-zA-Z0-9][a-zA-Z0-9/_.-]{0,499})\z#', $path, $match) === 1
        ) {
            $artifactPath = $match[2];
            if (str_contains($artifactPath, '..') || str_contains($artifactPath, '//')) {
                return null;
            }
            return ['kind' => 'artifact', 'previewId' => $match[1], 'artifactPath' => str_ends_with($artifactPath, '/') ? $artifactPath . 'index.html' : $artifactPath];
        }
        return null;
    }

    private function mutationRejection(Request $request, SessionContext $session): ?string
    {
        $presented = $request->header('x-csrf-token') ?? $request->formValue('_csrf');
        $originRejection = $this->originPolicy->rejection($request);
        if ($originRejection !== null) {
            return $originRejection;
        }
        if (!$this->identity->csrfValid(
            $session,
            $presented,
            $request->cookie(IdentityService::CSRF_COOKIE),
        )) {
            return 'csrf_rejected';
        }
        return null;
    }

    private function chrome(Request $request, SessionContext $session): View\AdminChrome
    {
        return new View\AdminChrome(
            $session->csrfToken,
            $session->displayName ?? $session->loginName ?? $this->translator($request, $session)->text('shell.account.fallback_name'),
            View\ThemePreference::fromCookie($request->cookie(View\ThemePreference::COOKIE)),
            View\AdminChrome::returnPath($request->path),
            $this->content?->counts() ?? [],
            $this->clock->now(),
            [...($this->previews === null ? ['previews'] : []), ...($this->websitePublication === null ? ['publication-settings'] : []), ...($this->siteLanguages === null ? ['languages'] : []), ...($this->siteWording === null ? ['wording'] : []), ...($this->livePreviews === null ? ['live-preview'] : [])],
            $this->translator($request, $session),
            $session->authenticated() ? InterfaceLanguage::preference($session->interfaceLanguage) : null,
            $session->role ?? 'anonymous',
            $this->accounts !== null,
            $this->websitePublication?->load()->identity(),
            $this->logos(),
        );
    }

    private function logos(): ?Media\AdministrationLogos
    {
        return $this->mediaLibrary === null || $this->websitePublication === null ? null
            : (new Media\AdministrationLogoDelivery($this->mediaLibrary))->resolve($this->websitePublication->load());
    }

    private static function holdingRevision(Request $request): int
    {
        $revision = filter_var($request->formValue('_revision'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 4_294_967_294]]);
        return is_int($revision) ? $revision : throw new Publication\PublicationConflict();
    }

    private function presentedProfile(Translator $t): ?ProfileManifest
    {
        if ($this->profile === null) {
            return null;
        }
        $terminology = $this->terminology?->load() ?? new Content\EditorialTerminology($this->profile->id, 0, []);
        return $terminology->present($this->profile, $t->locale(), $this->administrationLabels);
    }

    /** Interface language for this request: the account's choice, then the browser's, then English. */
    private function translator(Request $request, ?SessionContext $session = null): Translator
    {
        $preference = $session !== null && $session->authenticated() ? $session->interfaceLanguage : null;
        return Translator::load(InterfaceLanguage::resolve($preference, $request->header('accept-language')));
    }

    private function observeRejection(string $reason, string $correlationId): void
    {
        if ($this->rejectionObserver === null) {
            return;
        }
        try {
            ($this->rejectionObserver)($reason, $correlationId);
        } catch (Throwable) {
        }
    }

    private function withSessionCookies(Response $response, SessionContext $session, bool $force = false): Response
    {
        if (!$force && !$session->credentialsChanged) {
            return $response;
        }
        $maxAge = max(0, $session->expiresAt->getTimestamp() - $this->clock->now()->getTimestamp());
        return $response
            ->withCookie(new Cookie(
                IdentityService::SESSION_COOKIE,
                $session->sessionToken,
                $maxAge,
                true,
                'Lax',
            ))
            ->withCookie(new Cookie(
                IdentityService::CSRF_COOKIE,
                $session->csrfToken,
                $maxAge,
                false,
                'Strict',
            ));
    }

    private function expireSessionCookies(Response $response): Response
    {
        return $response
            ->withCookie(new Cookie(IdentityService::SESSION_COOKIE, '', 0, true, 'Lax'))
            ->withCookie(new Cookie(IdentityService::CSRF_COOKIE, '', 0, false, 'Strict'));
    }
}
