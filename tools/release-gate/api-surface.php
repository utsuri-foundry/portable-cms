<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\ReleaseGate;

/** Reads declarations without executing either revision of trusted profile APIs. */
final class ApiSurface
{
    public static function fingerprint(string $source): string
    {
        $tokens = [];
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG, T_CLOSE_TAG], true)) {
                continue;
            }
            $tokens[] = is_array($token) ? $token[1] : $token;
        }
        $declarations = [];
        $depth = 0;
        $header = [];
        foreach ($tokens as $token) {
            if ($depth > 1) {
                if ($token === '{') {
                    $depth++;
                } elseif ($token === '}') {
                    $depth--;
                }
                continue;
            }
            if ($token === '}') {
                $depth--;
                $header = [];
                continue;
            }
            if ($token === '{' || $token === ';') {
                $private = in_array('private', array_slice($header, 0, array_search('function', $header, true) ?: count($header)), true);
                $constant = array_search('const', $header, true);
                $versionConstant = $constant !== false && ($header[$constant + 1] ?? null) === 'ENGINE_VERSION';
                // Constructors also declare promoted public properties, even when construction is private.
                if (($depth === 0 || !$private || in_array('__construct', $header, true)) && !$versionConstant
                    && !($depth === 0 && in_array($header[0] ?? '', ['use', 'declare'], true))) {
                    $declarations[] = implode(' ', $header);
                }
                $header = [];
                if ($token === '{') {
                    $depth++;
                }
                continue;
            }
            $header[] = $token;
        }
        return hash('sha256', implode("\n", $declarations));
    }
}
