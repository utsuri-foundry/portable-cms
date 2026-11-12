<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Compiler;

/** Mount-independent local URLs keep preview and publication bytes identical. */
final class ArtifactUrl
{
    public static function relative(string $target, string $route): string
    {
        if (!CompiledRoute::validRoute($route) || !str_starts_with($target, '/') || str_starts_with($target, '//')
            || preg_match('/[\x00-\x20\x7f\\\\<>"\']/u', $target) !== 0) {
            throw new \InvalidArgumentException('Artifact URL is invalid');
        }
        $path = parse_url($target, PHP_URL_PATH);
        if (!is_string($path) || str_contains($path, '..') || str_contains($path, '//') || preg_match('#\A/[A-Za-z0-9/_.~-]*\z#', $path) !== 1) {
            throw new \InvalidArgumentException('Artifact URL path is invalid');
        }
        $depth = count(array_filter(explode('/', $route), static fn(string $part): bool => $part !== ''));
        return ($depth === 0 ? './' : str_repeat('../', $depth)) . substr($target, 1);
    }

    public static function escaped(string $target, string $route): string
    {
        return \Utsuri\PortableCms\Conventional\Http\Html::attribute(self::relative($target, $route));
    }
}
