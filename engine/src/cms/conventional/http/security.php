<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Http;

final class Html
{
    public static function text(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    public static function attribute(string $value): string
    {
        return self::text($value);
    }

    public static function url(string $value): string
    {
        if (preg_match('/[\x00-\x20\x7f]/', $value)) {
            throw new \InvalidArgumentException('URL contains forbidden characters');
        }
        $internal = str_starts_with($value, '/') && !str_starts_with($value, '//');
        $external = filter_var($value, FILTER_VALIDATE_URL) !== false
            && strtolower((string) parse_url($value, PHP_URL_SCHEME)) === 'https';
        if (!$internal && !$external) {
            throw new \InvalidArgumentException('URL does not use an allowed route or scheme');
        }
        return self::attribute($value);
    }
}

final readonly class OriginPolicy
{
    private string $scheme;
    private string $host;
    private int $port;

    public function __construct(string $origin, bool $allowInsecureLoopback = false)
    {
        $parts = parse_url($origin);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            throw new \InvalidArgumentException('CMS origin must be an HTTPS origin');
        }
        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower((string) $parts['host']);
        $loopbackHttp = $allowInsecureLoopback
            && $scheme === 'http'
            && in_array($host, ['localhost', '127.0.0.1', '::1'], true);
        if ($scheme !== 'https' && !$loopbackHttp) {
            throw new \InvalidArgumentException('CMS origin must be an HTTPS origin');
        }
        if (
            (isset($parts['path']) && $parts['path'] !== '')
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            throw new \InvalidArgumentException('CMS origin cannot contain a path');
        }
        $this->scheme = $scheme;
        $this->host = $host;
        $this->port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);
    }

    public function allows(Request $request): bool
    {
        return $this->rejection($request) === null;
    }

    public function rejection(Request $request): ?string
    {
        $fetchSite = $request->header('sec-fetch-site');
        if ($fetchSite !== null && $fetchSite !== 'same-origin') {
            return 'fetch_site_rejected';
        }
        $origin = $request->header('origin');
        if ($origin === null || $origin === '') {
            return 'origin_missing';
        }
        if (strtolower(trim($origin)) === 'null') {
            return $fetchSite === 'same-origin' ? null : 'origin_opaque';
        }
        $parts = parse_url($origin);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return 'origin_invalid';
        }
        if (
            (isset($parts['path']) && !in_array($parts['path'], ['', '/'], true))
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            return 'origin_invalid';
        }
        if (strtolower((string) $parts['scheme']) !== $this->scheme) {
            return 'origin_scheme_mismatch';
        }
        if (!hash_equals($this->host, strtolower((string) $parts['host']))) {
            return 'origin_host_mismatch';
        }
        $port = isset($parts['port']) ? (int) $parts['port'] : ($this->scheme === 'https' ? 443 : 80);
        if ($port !== $this->port) {
            return 'origin_port_mismatch';
        }
        return null;
    }
}

final class SecurityHeaders
{
    public static function apply(Response $response, string $correlationId, bool $insecureLoopback = false): Response
    {
        $contentSecurityPolicy = "default-src 'self'; base-uri 'none'; object-src 'none'; frame-ancestors 'none'; form-action 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'";
        if ($response->sameOriginFrame) {
            $contentSecurityPolicy = str_replace("frame-ancestors 'none'", "frame-ancestors 'self'", $contentSecurityPolicy);
            $script = $response->vimeoScriptHash();
            $contentSecurityPolicy = str_replace("script-src 'self'", $script === null ? "script-src 'none'" : "script-src 'sha256-" . $script . "'", $contentSecurityPolicy);
        }
        if ($response->styleHash !== null) {
            $contentSecurityPolicy = str_replace("style-src 'self'", "style-src 'self' 'sha256-" . $response->styleHash . "'", $contentSecurityPolicy);
        }
        if ($response->isolated()) {
            // The sandbox gives profile scripts an opaque origin in a frame and in a tab of their own.
            $contentSecurityPolicy = str_replace("frame-ancestors 'none'", "frame-ancestors 'self'", \Utsuri\PortableCms\Conventional\Compiler\ApachePublicDelivery::CONTENT_SECURITY_POLICY)
                . '; sandbox allow-scripts' . ($response->isolatedPopups() ? ' allow-popups allow-popups-to-escape-sandbox' : '');
        }
        if ($response->vimeoWorkspace()) {
            $contentSecurityPolicy .= "; frame-src 'self'";
        }
        $contentSecurityPolicy = \Utsuri\PortableCms\Conventional\Compiler\VimeoPresentation::policy($contentSecurityPolicy, $response->vimeoFrame() || $response->vimeoWorkspace());
        if (!$insecureLoopback) {
            $contentSecurityPolicy .= '; upgrade-insecure-requests';
        }
        $headers = [
            'cache-control' => 'no-store',
            'content-security-policy' => $contentSecurityPolicy,
            'cross-origin-opener-policy' => 'same-origin',
            'cross-origin-resource-policy' => 'same-site',
            'permissions-policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
            'referrer-policy' => ($response->vimeoFrame() || $response->vimeoWorkspace()) && (!$response->isolated() || $response->isolatedPopups()) ? 'strict-origin-when-cross-origin' : 'no-referrer',
            'x-content-type-options' => 'nosniff',
            'x-correlation-id' => $correlationId,
            'x-robots-tag' => 'noindex, nofollow, noarchive',
        ];
        if (!$insecureLoopback) {
            $headers['strict-transport-security'] = 'max-age=63072000; includeSubDomains';
        }
        if ($response->isolated()) {
            // Opaque-origin requests are cross-site; the unguessable session-bound token is their authority.
            $headers['cross-origin-resource-policy'] = 'cross-origin';
            $headers['access-control-allow-origin'] = '*';
            if ($response->hasHeader('cache-control')) {
                unset($headers['cache-control']);
            }
        }
        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }
        if (!$response->hasHeader('content-type')) {
            $response = $response->withHeader('content-type', 'text/html; charset=utf-8');
        }
        return $response;
    }
}
