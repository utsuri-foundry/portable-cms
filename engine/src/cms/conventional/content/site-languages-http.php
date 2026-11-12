<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Content;

use Utsuri\PortableCms\Conventional\Http\Request;
use Utsuri\PortableCms\Conventional\Http\Response;
use Utsuri\PortableCms\Conventional\I18n\InvalidInput;
use Utsuri\PortableCms\Conventional\Identity\SessionContext;
use Utsuri\PortableCms\Conventional\View\AdminChrome;
use Utsuri\PortableCms\Conventional\View\SiteLanguagesPage;

/** Authentication, same-origin and CSRF are enforced by the application host; editors only read. */
final readonly class SiteLanguagesHttp
{
    private const NOTICES = ['saved', 'added', 'removed'];

    public function __construct(private SiteLanguagesService $service, private ProfileManifest $profile, private AdminChrome $chrome, private ?LanguageTextDeletionService $deletions = null) {}

    public function handle(Request $request, SessionContext $actor): Response
    {
        $saved = $this->service->load();
        $profile = $saved->installed($this->profile);
        if ($request->method === 'GET') {
            $notice = $request->queryValue('saved') === '1' ? 'saved' : $request->queryValue('notice');
            return new Response(200, SiteLanguagesPage::render($profile, $this->chrome, $saved, notice: in_array($notice, self::NOTICES, true) ? $notice : null, addable: $this->service->addable(), deletion: $this->deletions?->latest()));
        }
        $action = $request->formValue('_action');
        $input = ['_revision' => $request->formValue('_revision'), 'primary' => $request->formValue('primary')];
        $states = [];
        foreach (array_keys($saved->states) as $locale) {
            $states[$locale] = $input['state__' . $locale] = $request->formValue('state__' . $locale);
        }
        $expected = filter_var($input['_revision'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 4_294_967_293]]);
        try {
            if (!is_int($expected)) {
                throw new InvalidInput('languages.invalid');
            }
            if ($action === 'add' || $action === 'remove') {
                $action === 'add' ? $this->service->add($request->formValue('locale'), $expected, $actor) : $this->service->remove($request->formValue('locale'), $expected, $actor);
                return Response::redirect('/settings/languages?notice=' . ($action === 'add' ? 'added' : 'removed'));
            }
            $this->service->save($states, $input['primary'], $expected, $actor, $request->formValue('confirmed') === '1');
        } catch (SiteLanguagesConflict) {
            $current = $this->service->load();
            return new Response(409, SiteLanguagesPage::render($current->installed($this->profile), $this->chrome, $current, $action === '' ? $input : [], conflict: true, addable: $this->service->addable()));
        } catch (InvalidInput $error) {
            return new Response(422, SiteLanguagesPage::render($profile, $this->chrome, $saved, $action === 'add' || $action === 'remove' ? [] : $input, $error->text(), addable: $this->service->addable()));
        }
        return Response::redirect('/settings/languages?saved=1');
    }
}
