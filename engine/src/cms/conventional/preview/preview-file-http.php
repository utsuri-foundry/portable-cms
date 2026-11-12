<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Preview;

use Utsuri\PortableCms\Conventional\Http\Request;
use Utsuri\PortableCms\Conventional\Http\Response;
use Utsuri\PortableCms\Conventional\I18n\Translator;

/** One isolated transport for shares and editorial previews; repositories own parent authorization. */
final class PreviewFileHttp
{
    public function __construct(private readonly PreviewService $previews, private readonly string $namespace)
    {
        if (!in_array($namespace, ['shared-preview-files', 'newest-preview-files', 'editor-preview-files'], true)) {
            throw new \InvalidArgumentException('Preview file namespace is invalid');
        }
    }

    public function base(string $id, string $token): string
    {
        if (preg_match('/\A[a-f0-9]{32}\z/', $id) !== 1 || !PreviewFilePolicy::validToken($token)) {
            throw new \InvalidArgumentException('Preview file capability is invalid');
        }
        return '/' . $this->namespace . '/' . $id . '/' . $token . '/';
    }

    /** @param \Closure(string, string, string): ?string $authorize Returns exactly the authorized preview ID. */
    public function serve(Request $request, Translator $t, \Closure $authorize): Response
    {
        return $this->response($request, $t, $authorize)->asIsolated($this->namespace === 'editor-preview-files');
    }

    /** @param \Closure(string, string, string): ?string $authorize */
    private function response(Request $request, Translator $t, \Closure $authorize): Response
    {
        $unavailable = new Response(404, $t->html('share.unavailable'));
        if (!in_array($request->method, ['GET', 'HEAD'], true)
            || preg_match('#\A/' . preg_quote($this->namespace, '#') . '/([a-f0-9]{32})/([a-f0-9]{64})/([A-Za-z0-9/_.~-]{0,600})\z#', $request->path, $match) !== 1) {
            return $unavailable;
        }
        $previewId = $authorize($match[1], $match[2], $request->remoteAddress);
        $path = $match[3] === '' || str_ends_with($match[3], '/') ? $match[3] . 'index.html' : $match[3];
        try {
            PreviewArtifact::assertPath($path);
        } catch (\InvalidArgumentException) {
            return $unavailable;
        }
        return $previewId === null ? $unavailable
            : ($this->previews->artifactResponse($previewId, $path, $this->base($match[1], $match[2]), $request->header('range')) ?? $unavailable);
    }
}
