<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Identity;

use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Http\Request;

/** Server-side route authority; navigation visibility is only its presentation. */
final class AdministrationPermissions
{
    public static function allows(SessionContext $actor, Request $request, ?ProfileManifest $profile): bool
    {
        if (!$actor->authenticated()) {
            return true; // Existing authentication boundary supplies login/401 behavior.
        }
        if (!$actor->editorial()) {
            return in_array($request->path, ['/account', '/logout', '/login', '/settings/theme', '/settings/language'], true);
        }
        if ($actor->administrator()) {
            return true;
        }
        if ($actor->role !== 'editor') {
            return false;
        }
        if ($request->path === '/releases' && $request->method === 'GET') {
            return true;
        }
        if (in_array($request->path, ['/accounts', '/exports', '/releases'], true)
            || str_starts_with($request->path, '/releases/')
            || (str_starts_with($request->path, '/settings/') && !in_array($request->path, ['/settings/theme', '/settings/language'], true)
                && !($request->path === '/settings/languages' && $request->method === 'GET') && $request->path !== '/settings/wording')
            || str_contains($request->path, '/shares')
            || str_ends_with($request->path, '/status')
            || str_starts_with($request->path, '/holding')) {
            return false;
        }
        if (preg_match('#\A/(?:api/)?records/([a-z][a-z0-9_-]*)(?:/|\z)#', $request->path, $match)) {
            return $profile?->type($match[1])?->archetype !== 'site_settings';
        }
        if ($request->path === '/media/upload') {
            $type = explode('/', $request->formValue('owner'))[0];
            return $profile?->type($type)?->archetype !== 'site_settings';
        }
        return true;
    }
}
