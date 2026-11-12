<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Content;

use Utsuri\PortableCms\Conventional\Http\Request;
use Utsuri\PortableCms\Conventional\Http\Response;
use Utsuri\PortableCms\Conventional\I18n\InvalidInput;
use Utsuri\PortableCms\Conventional\Identity\SessionContext;
use Utsuri\PortableCms\Conventional\View\AdminChrome;
use Utsuri\PortableCms\Conventional\View\LanguageTextDeletionPage;

/** Authentication, same-origin and CSRF are enforced by the application host; only the owner deletes. */
final readonly class LanguageTextDeletionHttp
{
    public function __construct(private LanguageTextDeletionService $service, private SiteLanguagesService $languages, private ProfileManifest $profile, private AdminChrome $chrome) {}

    public function handle(Request $request, SessionContext $actor): Response
    {
        if ($request->method === 'GET') {
            return new Response(200, $this->page($request->queryValue('locale'), $actor, done: $request->queryValue('done') === '1'));
        }
        $locale = $request->formValue('locale');
        try {
            $deletion = $request->formValue('_action') === 'continue'
                ? $this->service->continue($actor)
                : $this->service->start($locale, (int) filter_var($request->formValue('records'), FILTER_VALIDATE_INT, ['options' => ['default' => -1, 'min_range' => 0]]), $request->formValue('confirmed') === '1', $actor);
        } catch (InvalidInput $error) {
            return new Response(422, $this->page($locale, $actor, $error->text()));
        }
        return Response::redirect('/settings/languages/text?locale=' . rawurlencode($deletion->locale) . ($deletion->state === 'completed' ? '&done=1' : ''));
    }

    private function page(string $locale, SessionContext $actor, ?\Utsuri\PortableCms\Conventional\I18n\Message $error = null, bool $done = false): string
    {
        $latest = $this->service->latest();
        $running = $latest?->state === 'running' ? $latest : null;
        $locale = $running->locale ?? $locale;
        $state = $this->languages->load()->states[$locale] ?? null;
        return LanguageTextDeletionPage::render(
            $this->profile,
            $this->chrome,
            $state === null ? null : $locale,
            $state === null ? '' : $this->service->name($locale),
            $state,
            $state === 'off' && $running === null && !$done ? $this->service->affected($locale) : null,
            $running ?? ($done && $latest?->locale === $locale ? $latest : null),
            $actor->role === 'owner',
            $error,
        );
    }
}
