<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\View;

use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Http\Html;
use Utsuri\PortableCms\Conventional\Identity\Account;
use Utsuri\PortableCms\Conventional\Identity\AccountPolicy;

final class AccountPage
{
    /**
     * @param list<Account> $users
     * @param array<string, string> $values
     */
    public static function render(bool $personal, Account $self, array $users, ?ProfileManifest $profile, AdminChrome $chrome, array $values = [], ?string $failure = null, int $offset = 0): string
    {
        $t = $chrome->translator();
        $title = $t->text($personal ? 'account.personal' : 'account.team');
        $body = AdminShell::header($title, '<p class="admin-lede">' . $t->html($personal ? 'account.personal_help' : 'account.team_help') . '</p>');
        if ($failure !== null) {
            $body .= '<div class="admin-notice admin-notice--warning" role="alert"><p>' . $t->html('account.error.' . $failure) . '</p></div>';
        }
        if ($personal) {
            if ($self->mustChangePassword) {
                $body .= '<div class="admin-notice admin-notice--warning" role="status"><p>' . $t->html('account.replace_help') . '</p></div>';
            }
            $body .= '<section class="admin-section">' . self::form('/account', $chrome, $self->revision)
                . '<p>' . $t->html('account.role.' . $self->role) . '</p>'
                . self::input('login', $t->text('account.login'), 'email', $values['login'] ?? $self->login, true, 'username', 254)
                . self::input('name', $t->text('account.name'), 'text', $values['name'] ?? $self->name, true, 'name', 160)
                . self::input('current_password', $t->text('account.current_password'), 'password', '', $self->mustChangePassword, 'current-password', 72)
                . self::input('new_password', $t->text('account.new_password'), 'password', '', $self->mustChangePassword, 'new-password', 72)
                . '<p class="admin-meta">' . $t->html('account.password_help') . '</p>'
                . '<button type="submit" class="admin-button--primary">' . $t->html('editor.save') . '</button></form></section>';
        } else {
            $body .= '<section class="admin-section"><h2>' . $t->html('account.create') . '</h2>' . self::form('/accounts', $chrome)
                . '<input type="hidden" name="action" value="create">'
                . self::input('login', $t->text('account.login'), 'email', $values['login'] ?? '', true, 'off', 254)
                . self::input('name', $t->text('account.name'), 'text', $values['name'] ?? '', true, 'off', 160)
                . '<label>' . $t->html('account.role_label') . '<select name="role">';
            foreach ($self->role === 'owner' ? ['editor', 'administrator'] : ['editor'] as $role) {
                $body .= '<option value="' . $role . '"' . (($values['role'] ?? '') === $role ? ' selected' : '') . '>' . $t->html('account.role.' . $role) . '</option>';
            }
            $body .= '</select></label>' . self::input('temporary_password', $t->text('account.temporary_password'), 'password', '', true, 'new-password', 72)
                . '<p class="admin-meta">' . $t->html('account.password_help') . '</p><button type="submit">' . $t->html('account.create') . '</button></form></section>';
            foreach ($users as $user) {
                $body .= '<section class="admin-section"><h2>' . Html::text($user->name) . '</h2><p>' . Html::text($user->login) . '</p><p>'
                    . $t->html('account.role.' . $user->role) . ' · ' . $t->html('account.status.' . $user->status) . '</p>';
                if (AccountPolicy::below($self, $user)) {
                    $body .= '<details><summary>' . $t->html('account.manage') . '</summary>' . self::form('/accounts', $chrome, $user->revision)
                        . '<input type="hidden" name="id" value="' . Html::attribute($user->id) . '">'
                        . '<label>' . $t->html('account.action') . '<select name="action">';
                    $actions = ['reset', $user->status === 'active' ? 'disable' : 'enable', 'remove'];
                    if ($self->role === 'owner') {
                        $actions[] = $user->role === 'administrator' ? 'demote' : 'promote';
                        if ($user->role === 'administrator' && $user->status === 'active' && !$user->mustChangePassword) {
                            $actions[] = 'transfer';
                        }
                    }
                    foreach ($actions as $action) {
                        $body .= '<option value="' . $action . '">' . $t->html('account.action.' . $action) . '</option>';
                    }
                    $body .= '</select></label>' . self::input('temporary_password', $t->text('account.temporary_password'), 'password', '', false, 'new-password', 72)
                        . '<p class="admin-meta">' . $t->html('account.manage_help') . '</p>'
                        . '<label><input type="checkbox" name="confirmed" value="1" required> ' . $t->html('account.confirm') . '</label>'
                        . '<button type="submit">' . $t->html('account.apply') . '</button></form></details>';
                }
                $body .= '</section>';
            }
            if ($offset > 0) {
                $body .= '<a href="' . Html::url('/accounts?offset=' . max(0, $offset - 50)) . '">' . $t->html('account.previous') . '</a> ';
            }
            if (count($users) === 50) {
                $body .= '<a href="' . Html::url('/accounts?offset=' . ($offset + 50)) . '">' . $t->html('account.next') . '</a>';
            }
        }
        if ($personal && $self->mustChangePassword) {
            $body .= self::form('/logout', $chrome) . '<button type="submit">' . $t->html('shell.sign_out') . '</button></form>';
            return AdminShell::focused($title, TrustedHtml::fromEngineMarkup($body), $t);
        }
        return AdminShell::application($title, TrustedHtml::fromEngineMarkup($body), $profile, $personal ? 'account' : 'accounts', 'standard', $chrome);
    }

    private static function form(string $action, AdminChrome $chrome, ?int $revision = null): string
    {
        return '<form method="post" action="' . Html::url($action) . '"><input type="hidden" name="_csrf" value="' . Html::attribute($chrome->csrfToken) . '">'
            . ($revision === null ? '' : '<input type="hidden" name="_revision" value="' . $revision . '">');
    }

    private static function input(string $name, string $label, string $type, string $value, bool $required, string $autocomplete, int $maximum): string
    {
        return '<label>' . Html::text($label) . '<input name="' . Html::attribute($name) . '" type="' . Html::attribute($type) . '" value="' . Html::attribute($value) . '" maxlength="' . $maximum . '" autocomplete="' . Html::attribute($autocomplete) . '"' . ($required ? ' required' : '') . ($type === 'password' ? ' minlength="14"' : '') . '></label>';
    }
}
