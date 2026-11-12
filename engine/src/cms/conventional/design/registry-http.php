<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Design;

use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Http\Request;
use Utsuri\PortableCms\Conventional\Http\Response;
use Utsuri\PortableCms\Conventional\I18n\InvalidInput;
use Utsuri\PortableCms\Conventional\Identity\SessionContext;
use Utsuri\PortableCms\Conventional\View\DesignPage;

/** Host checks authorization, origin and session-bound CSRF before invoking this adapter. */
final class RegistryHttp
{
    public function __construct(private readonly RegistryService $service, private readonly ProfileManifest $profile, private readonly ?\Utsuri\PortableCms\Conventional\View\AdminChrome $chrome = null) {}

    private function translator(): \Utsuri\PortableCms\Conventional\I18n\Translator
    {
        return $this->chrome?->translator() ?? \Utsuri\PortableCms\Conventional\I18n\Translator::english();
    }
    public function handle(Request $request, SessionContext $session): Response
    {
        $saved = $this->service->load();
        $values = [];
        foreach ($request->form as $name => $value) {
            if (is_string($value)) {
                $values[$name] = $value;
            }
        }
        if ($request->method === 'GET') {
            return new Response(200, DesignPage::render($session->csrfToken, $this->profile, $saved, $this->service->fonts->available(), notice: $request->queryValue('saved') === '1' ? $this->translator()->text('design.saved_notice') : '', chrome: $this->chrome));
        }
        try {
            $revision = filter_var($request->formValue('_revision'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 2_147_483_646]]);
            if (!is_int($revision)) {
                throw new InvalidInput('design.error.revision');
            }
            if ($request->formValue('_action') === 'calculate') {
                $specimen = RegistryDocument::fromDocument(RegistryForm::project($this->service->load($revision), $values));
                return new Response(200, DesignPage::render($session->csrfToken, $this->profile, $specimen, $this->service->fonts->available(), $values, notice: $this->translator()->text('design.calculated'), chrome: $this->chrome));
            }
            $this->service->save($revision, $values, $session->userId ?? throw new \LogicException('Administrator required'), $request->upload?->bytes());
        } catch (RegistryConflict $conflict) {
            return new Response(409, DesignPage::render(
                $session->csrfToken,
                $this->profile,
                $conflict->current,
                $this->service->fonts->available(),
                $values,
                notice: $this->translator()->text('design.conflict.notice'),
                conflict: true,
                chrome: $this->chrome,
            ));
        } catch (\InvalidArgumentException|FontUnavailable $error) {
            return new Response(422, DesignPage::render($session->csrfToken, $this->profile, $saved, $this->service->fonts->available(), $values, $this->translator()->failure($error), chrome: $this->chrome));
        }
        return Response::redirect('/settings/design?saved=1');
    }
}
