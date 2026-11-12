<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Content;

use Utsuri\PortableCms\Conventional\Http\Request;
use Utsuri\PortableCms\Conventional\Http\Response;
use Utsuri\PortableCms\Conventional\I18n\InvalidInput;
use Utsuri\PortableCms\Conventional\Identity\SessionContext;
use Utsuri\PortableCms\Conventional\View\AdminChrome;
use Utsuri\PortableCms\Conventional\View\SiteWordingPage;

/** Authentication, same-origin and CSRF are enforced by the application host. */
final readonly class SiteWordingHttp
{
    /** @param ProfileManifest $profile The profile as editing sees it, with the languages editors work in. */
    public function __construct(private SiteWordingService $service, private ProfileManifest $profile, private AdminChrome $chrome) {}

    public function handle(Request $request, SessionContext $actor): Response
    {
        $saved = $this->service->load();
        if ($request->method === 'GET') {
            return new Response(200, SiteWordingPage::render($this->profile, $this->chrome, $saved, notice: $request->queryValue('saved') === '1'));
        }
        $texts = [];
        $submitted = [];
        foreach ($this->profile->wording as $definition) {
            foreach ($this->service->languages() as $locale) {
                $field = SiteWording::field($definition->key, $locale);
                // A field the form did not send keeps its text.
                if (is_string($request->form[$field] ?? null)) {
                    $texts[$definition->key][$locale] = $submitted[$field] = $request->form[$field];
                }
            }
        }
        $reset = $request->formValue('_reset');
        $expected = filter_var($request->formValue('_revision'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 4_294_967_293]]);
        try {
            if (!is_int($expected) || ($reset !== '' && $this->profile->wording($reset) === null)) {
                throw new InvalidInput('wording.invalid');
            }
            $result = $this->service->save($expected, $texts, $reset === '' ? [] : [$reset], $actor);
        } catch (SiteWordingConflict) {
            return new Response(409, SiteWordingPage::render($this->profile, $this->chrome, $this->service->load(), $submitted, conflict: true));
        } catch (InvalidInput $error) {
            return new Response(422, SiteWordingPage::render($this->profile, $this->chrome, $saved, $submitted, failure: $error->text()));
        }
        if ($result instanceof ValidationFailure) {
            /** @var array<string, string> $values */
            $values = $result->values;
            return new Response(422, SiteWordingPage::render($this->profile, $this->chrome, $saved, $values, $result->errors));
        }
        return Response::redirect('/settings/wording?saved=1');
    }
}
