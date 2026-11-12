<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Content;

use Utsuri\PortableCms\Conventional\Http\Request;
use Utsuri\PortableCms\Conventional\Http\Response;
use Utsuri\PortableCms\Conventional\I18n\InvalidInput;
use Utsuri\PortableCms\Conventional\Identity\SessionContext;
use Utsuri\PortableCms\Conventional\View\AdminChrome;
use Utsuri\PortableCms\Conventional\View\RecordHistoryPage;

/** The host enforces authentication, administrator authority, same-origin and CSRF. */
final readonly class RecordHistoryHttp
{
    public function __construct(private RecordHistoryService $service, private ProfileManifest $profile, private AdminChrome $chrome) {}

    public function handle(Request $request, SessionContext $actor): Response
    {
        $saved = $this->service->load();
        $limit = $saved->limit;
        $preview = null;
        $error = null;
        $status = 200;
        if ($request->method === 'POST') {
            try {
                if ($request->formValue('_action') === 'continue') {
                    $this->service->continue($actor);
                    return Response::redirect('/settings/history');
                }
                $limit = filter_var($request->formValue('limit'), FILTER_VALIDATE_INT);
                $expected = filter_var($request->formValue('_revision'), FILTER_VALIDATE_INT);
                if (!is_int($limit) || !is_int($expected) || $expected < 0) {
                    throw new InvalidInput('history.invalid');
                }
                RecordHistorySettings::validateLimit($limit);
                if ($expected !== $saved->revision) {
                    throw new InvalidInput('history.changed');
                }
                if ($limit < $saved->limit && $request->formValue('confirmed') !== '1') {
                    $preview = $this->service->excess($limit);
                } else {
                    $count = filter_var($request->formValue('removal_count'), FILTER_VALIDATE_INT);
                    $this->service->save($limit, $expected, $request->formValue('confirmed') === '1' && is_int($count) ? $count : null, $actor);
                    return Response::redirect('/settings/history?saved=1');
                }
            } catch (InvalidInput $failure) {
                $error = $failure->text();
                $status = 422;
                $limit = $saved->limit;
            }
        }
        return new Response($status, RecordHistoryPage::render($this->profile, $this->chrome, $saved, $this->service->retained(), $limit, $preview, $error, $request->queryValue('saved') === '1'));
    }
}
