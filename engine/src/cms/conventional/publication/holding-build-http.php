<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Publication;

use Utsuri\PortableCms\Conventional\Http\Request;
use Utsuri\PortableCms\Conventional\Http\Response;
use Utsuri\PortableCms\Conventional\Preview\PreviewArtifact;

/** Private inspection lives under releases; it never enters exact preview or guest-share APIs. */
final class HoldingBuildHttp
{
    public static function matches(Request $request): bool
    {
        return $request->method === 'GET' && preg_match('#\A/releases/holding/[a-f0-9]{32}/artifact(?:/[a-zA-Z0-9/_.-]{0,500})?\z#', $request->path) === 1;
    }

    public static function handle(Request $request, HoldingPublication $holding): Response
    {
        if (str_ends_with($request->path, '/artifact')) {
            return Response::redirect($request->path . '/', 308);
        }
        $parts = explode('/', $request->path, 6);
        $path = $parts[5] === '' ? 'index.html' : $parts[5];
        try {
            PreviewArtifact::assertPath($path);
        } catch (\InvalidArgumentException) {
            return new Response(404);
        }
        $artifact = $holding->builds->artifact($parts[3], $path);
        return $artifact === null ? new Response(404) : (new Response(200, $artifact->contents))->withHeader('content-type', $artifact->mime)->withHeader('x-robots-tag', 'noindex, nofollow, noarchive');
    }
}
