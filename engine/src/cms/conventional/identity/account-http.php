<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Identity;

use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Http\Request;
use Utsuri\PortableCms\Conventional\Http\Response;
use Utsuri\PortableCms\Conventional\View\AccountPage;
use Utsuri\PortableCms\Conventional\View\AdminChrome;

/** Invoked after exact origin and session-bound CSRF checks at the application boundary. */
final readonly class AccountHttp
{
    public function __construct(private AccountService $accounts, private ?ProfileManifest $profile, private AdminChrome $chrome) {}

    public function handle(Request $request, SessionContext $actor): Response
    {
        $personal = $request->path === '/account';
        $values = $request->method === 'POST' ? ['login' => $request->formValue('login'), 'name' => $request->formValue('name'), 'role' => $request->formValue('role')] : [];
        $failure = null;
        $status = 200;
        try {
            if ($request->method === 'POST') {
                $revision = filter_var($request->formValue('_revision'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($personal) {
                    if (!is_int($revision)) {
                        throw new AccountFailure('invalid_input');
                    }
                    $this->accounts->updateSelf($actor, $revision, $values['login'], $values['name'], $request->formValue('current_password'), $request->formValue('new_password'));
                } elseif ($request->formValue('action') === 'create') {
                    $this->accounts->create($actor, $values['login'], $values['name'], $values['role'], $request->formValue('temporary_password'));
                } else {
                    if (!is_int($revision) || $request->formValue('confirmed') !== '1' || preg_match('/\A[a-f0-9]{32}\z/', $request->formValue('id')) !== 1) {
                        throw new AccountFailure('confirmation');
                    }
                    $this->accounts->manage($actor, $request->formValue('id'), $revision, $request->formValue('action'), $request->formValue('temporary_password'));
                }
                return Response::redirect($request->path);
            }
        } catch (AccountFailure $error) {
            $failure = $error->reason;
            $status = $error->status;
        }
        $offset = filter_var($request->queryValue('offset'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 1_000_000]]);
        try {
            $self = $this->accounts->self($actor);
            $users = $personal ? [] : $this->accounts->page($actor, is_int($offset) ? $offset : 0);
        } catch (AccountFailure $error) {
            return new Response($error->status, \Utsuri\PortableCms\Conventional\View\Pages::error($error->status, '', $this->chrome->translator()));
        }
        return new Response($status, AccountPage::render($personal, $self, $users, $this->profile, $this->chrome, $values, $failure, is_int($offset) ? $offset : 0));
    }
}
