<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Compiler;

/** Where a profile places each language's addresses. */
enum LanguageLayout: string
{
    /** Every language under its prefix (`/ca/projects/`), with the primary language's home page also at `/`. */
    case Prefixed = 'prefixed';

    /** The primary language without a prefix (`/projects/`) and every other language under its prefix. */
    case PrimaryAtRoot = 'primary-at-root';

    /** A page's address in a language, from its path without a language prefix. */
    public function path(string $path, string $locale, string $primary): string
    {
        return $this === self::PrimaryAtRoot && $locale === $primary ? $path : '/' . $locale . $path;
    }
}
