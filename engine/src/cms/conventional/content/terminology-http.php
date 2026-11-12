<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Content;

use Utsuri\PortableCms\Conventional\Http\Request;
use Utsuri\PortableCms\Conventional\Http\Response;
use Utsuri\PortableCms\Conventional\Identity\SessionContext;
use Utsuri\PortableCms\Conventional\View\TerminologyPage;

/** Authentication, origin and session-bound CSRF checks belong to the HTTP host. */
final class TerminologyHttp
{
    public function __construct(private readonly TerminologyService $service, private readonly ProfileManifest $profile, private readonly ?\Utsuri\PortableCms\Conventional\View\AdminChrome $chrome = null, private readonly ?AdministrationLabels $administration = null) {}

    public function handle(Request $request, SessionContext $session): Response
    {
        $saved = $this->service->load();
        if ($request->method === 'GET') {
            return new Response(200, TerminologyPage::render($session->csrfToken, $this->profile, $saved, chrome: $this->chrome, administration: $this->administration, notice: $request->queryValue('saved') === '1' ? ($this->chrome?->translator() ?? \Utsuri\PortableCms\Conventional\I18n\Translator::english())->text('names.saved_notice') : ''));
        }
        $revision = filter_var($request->formValue('_revision'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 2_147_483_646]]);
        $values = [];
        foreach ($this->profile->types as $type) {
            foreach (['singular', 'plural'] as $key) {
                $name = $type->id . '__' . $key;
                $values[$name] = $request->formValue($name);
                foreach (\Utsuri\PortableCms\Conventional\I18n\InterfaceLanguage::SUPPORTED as $language) {
                    $values[$name . '__' . $language] = $request->formValue($name . '__' . $language);
                }
            }
        }
        if (!is_int($revision)) {
            return new Response(422, TerminologyPage::render(
                $session->csrfToken,
                $this->profile,
                $saved,
                $values,
                ['_revision' => new \Utsuri\PortableCms\Conventional\I18n\Message('names.revision_invalid')],
                chrome: $this->chrome,
                administration: $this->administration,
            ));
        }
        try {
            $result = $this->service->save($revision, $values, $session->userId ?? throw new \LogicException('Administrator required'));
        } catch (TerminologyConflict $conflict) {
            return new Response(409, TerminologyPage::render($session->csrfToken, $this->profile, $conflict->current, $values, conflict: true, chrome: $this->chrome, administration: $this->administration));
        }
        if ($result instanceof ValidationFailure) {
            return new Response(422, TerminologyPage::render($session->csrfToken, $this->profile, $saved, $result->values, $result->errors, chrome: $this->chrome, administration: $this->administration));
        }
        return Response::redirect('/settings/terminology?saved=1');
    }
}
