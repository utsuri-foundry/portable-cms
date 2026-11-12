<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Content;

/** A deliberately bounded, mount-independent subset of the browser's rules format. */
final class SpeculationRules
{
    public const MIME = 'application/speculationrules+json';
    public const MAX_BYTES = 32_768;

    public static function asset(ProfileAsset $asset): bool
    {
        if ($asset->role !== 'speculation-rules' && $asset->mime !== self::MIME) {
            return false;
        }
        if ($asset->role !== 'speculation-rules' || $asset->mime !== self::MIME
            || $asset->byteSize > self::MAX_BYTES || !self::validPath($asset->path)) {
            self::invalid();
        }
        return true;
    }

    public static function validPath(string $path): bool
    {
        return preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_-]{0,95}\.json\z/', $path) === 1 && $path !== 'artifact.json';
    }

    /** Validate before use, then scope document predicates to the rules file's own mount. */
    public static function compile(string $bytes): string
    {
        if (strlen($bytes) > self::MAX_BYTES) {
            self::invalid();
        }
        try {
            $document = json_decode($bytes, false, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            self::invalid();
        }
        if (!$document instanceof \stdClass || get_object_vars($document) === []
            || array_diff(array_keys(get_object_vars($document)), ['prefetch', 'prerender']) !== []) {
            self::invalid();
        }
        $output = [];
        $rules = 0;
        $nodes = 0;
        foreach (get_object_vars($document) as $action => $entries) {
            if (!is_array($entries) || $entries === []) {
                self::invalid();
            }
            foreach ($entries as $entry) {
                if (++$rules > 32 || !$entry instanceof \stdClass) {
                    self::invalid();
                }
                $rule = get_object_vars($entry);
                if (array_diff(array_keys($rule), ['urls', 'where', 'eagerness', 'relative_to']) !== []
                    || array_key_exists('urls', $rule) === array_key_exists('where', $rule)
                    || (array_key_exists('eagerness', $rule) && !in_array($rule['eagerness'], ['immediate', 'eager', 'moderate', 'conservative'], true))) {
                    self::invalid();
                }
                if (array_key_exists('urls', $rule)) {
                    if (!is_array($rule['urls']) || $rule['urls'] === [] || count($rule['urls']) > 64
                        || (array_key_exists('relative_to', $rule) && $rule['relative_to'] !== 'ruleset')) {
                        self::invalid();
                    }
                    foreach ($rule['urls'] as $url) {
                        self::target($url, false);
                    }
                    $rule['relative_to'] = 'ruleset';
                } else {
                    if (array_key_exists('relative_to', $rule)) {
                        self::invalid();
                    }
                    $rule['where'] = ['and' => [
                        ['href_matches' => '*', 'relative_to' => 'ruleset'],
                        self::predicate($rule['where'], 0, $nodes),
                    ]];
                }
                $output[$action][] = $rule;
            }
        }
        $result = json_encode($output, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        if (strlen($result) > self::MAX_BYTES) {
            self::invalid();
        }
        return $result;
    }

    /** @return array<string, mixed> */
    private static function predicate(mixed $value, int $depth, int &$nodes): array
    {
        if (!$value instanceof \stdClass || $depth > 8 || ++$nodes > 128) {
            self::invalid();
        }
        $predicate = get_object_vars($value);
        $keys = array_diff(array_keys($predicate), ['relative_to']);
        if (count($keys) !== 1) {
            self::invalid();
        }
        $key = array_values($keys)[0];
        if (array_key_exists('relative_to', $predicate) && $key !== 'href_matches') {
            self::invalid();
        }
        $input = $predicate[$key];
        if (in_array($key, ['and', 'or'], true)) {
            if (!is_array($input) || $input === [] || count($input) > 16) {
                self::invalid();
            }
            $children = [];
            foreach ($input as $item) {
                $children[] = self::predicate($item, $depth + 1, $nodes);
            }
            return [$key => $children];
        }
        if ($key === 'not') {
            return ['not' => self::predicate($input, $depth + 1, $nodes)];
        }
        if ($key === 'href_matches') {
            self::target($input, true);
            if (array_key_exists('relative_to', $predicate) && $predicate['relative_to'] !== 'ruleset') {
                self::invalid();
            }
            return ['href_matches' => str_starts_with($input, './') ? substr($input, 2) : $input, 'relative_to' => 'ruleset'];
        }
        if ($key === 'selector_matches' && is_string($input)
            && preg_match('/\Aa?(?:\.[a-zA-Z][a-zA-Z0-9_-]{0,63}|\[data-[a-z][a-z0-9-]{0,63}\])\z/', $input) === 1) {
            return ['selector_matches' => $input];
        }
        self::invalid();
    }

    private static function target(mixed $value, bool $pattern): void
    {
        if (!is_string($value) || strlen($value) > 500
            || preg_match($pattern ? '/\A(?:\.\/)?[a-zA-Z0-9_\/-]*\*?\z/' : '/\A(?:\.\/)?[a-zA-Z0-9][a-zA-Z0-9_\/-]*\/?\z/', $value) !== 1
            || $value === '' || str_starts_with($value, '/') || str_contains($value, '//')) {
            self::invalid();
        }
    }

    private static function invalid(): never
    {
        throw new \InvalidArgumentException('Profile speculation rules must be bounded JSON with mount-local targets and supported predicates');
    }
}
