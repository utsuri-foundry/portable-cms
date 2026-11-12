<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Compiler;

/** Operator-owned publication identity. Never derived from an HTTP request. */
final readonly class PublicUrlContext
{
    private function __construct(public string $baseUrl) {}

    public static function from(mixed $value): self
    {
        if (!is_array($value) || array_diff(array_keys($value), ['formatVersion', 'baseUrl']) !== []
            || ($value['formatVersion'] ?? null) !== 1 || !is_string($value['baseUrl'] ?? null)) {
            throw new \InvalidArgumentException('Public URL context is invalid');
        }
        $url = $value['baseUrl'];
        $parts = parse_url($url);
        if (strlen($url) > 1000 || filter_var($url, FILTER_VALIDATE_URL) === false
            || !is_array($parts) || ($parts['scheme'] ?? null) !== 'https'
            || array_diff(array_keys($parts), ['scheme', 'host', 'port', 'path']) !== []
            || strlen($parts['host'] ?? '') > 253
            || preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*\z/', $parts['host'] ?? '') !== 1
            || !CompiledRoute::validRoute(rtrim($parts['path'] ?? '', '/') . '/')) {
            throw new \InvalidArgumentException('Public base URL must be a bounded HTTPS origin and optional path');
        }
        foreach (explode('/', $parts['path'] ?? '') as $segment) {
            if ($segment === '.' || $segment === '..') {
                throw new \InvalidArgumentException('Public base URL cannot contain traversal');
            }
        }
        return new self(rtrim($url, '/'));
    }

    public function route(string $route): string
    {
        if (!CompiledRoute::validRoute($route)) {
            throw new \InvalidArgumentException('Public route is invalid');
        }
        return $this->baseUrl . $route;
    }

    public function asset(string $path): string
    {
        \Utsuri\PortableCms\Conventional\Preview\PreviewArtifact::assertPath($path);
        if (in_array('.', explode('/', $path), true)) {
            throw new \InvalidArgumentException('Public asset path contains traversal');
        }
        return $this->baseUrl . '/' . $path;
    }

    /** @return array{formatVersion: int, baseUrl: string} */
    public function document(): array
    {
        return ['formatVersion' => 1, 'baseUrl' => $this->baseUrl];
    }

    public function checksum(): string
    {
        return hash('sha256', json_encode($this->document(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
