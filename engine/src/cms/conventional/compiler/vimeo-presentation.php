<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Compiler;

use Utsuri\PortableCms\Conventional\Content\FieldDefinition;
use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Content\VimeoVideo;
use Utsuri\PortableCms\Conventional\HostIo;
use Utsuri\PortableCms\Conventional\Http\Html;

/** Conditional engine assets and policy. Profiles without a value keep their exact bytes. */
final class VimeoPresentation
{
    public const ORIGIN = 'https://player.vimeo.com';
    public const SCRIPT = 'assets/portable-vimeo-player.js';
    public const STYLE = 'assets/portable-vimeo-player.css';

    public static function enabled(ProfileManifest $profile, FrozenSnapshot $snapshot): bool
    {
        foreach ($snapshot->records as $record) {
            $type = $profile->type($record->typeId);
            if ($type !== null && self::hasValue($type->fields, $record->body, $snapshot->locales)) {
                return true;
            }
        }
        return false;
    }

    /** @param list<FieldDefinition> $fields
     * @param array<string, mixed> $body
     * @param list<string> $locales
     */
    private static function hasValue(array $fields, array $body, array $locales): bool
    {
        foreach ($fields as $field) {
            $raw = $body[$field->id] ?? null;
            foreach ($field->localized && is_array($raw) ? $raw : [$raw] as $entry) {
                if ($entry === null) {
                    continue;
                }
                if ($field->type === 'vimeo_video') {
                    VimeoVideo::from($entry);
                    return true;
                }
                if (in_array($field->type, ['group', 'repeatable_group'], true) && is_array($entry)) {
                    foreach ($field->type === 'group' ? [$entry] : $entry as $item) {
                        if (is_array($item) && self::hasValue(array_map(static fn(FieldDefinition $child): FieldDefinition => \Utsuri\PortableCms\Conventional\Content\EditorialControls::childDefinition($child, $child->id, $field->localized), $field->fields), $item, $locales)) {
                            return true;
                        }
                    }
                }
            }
        }
        return false;
    }

    public static function policy(string $policy, bool $enabled): string
    {
        if (!$enabled) {
            return $policy;
        }
        if (preg_match('/(?:\A|;)\s*frame-src(?:\s|;|\z)/', $policy) === 1) {
            return preg_replace_callback('/(^|;)\s*frame-src\s+([^;]*)/', static function (array $match): string {
                $sources = array_values(array_filter(explode(' ', trim($match[2])), static fn(string $source): bool => $source !== '' && $source !== "'none'"));
                return $match[1] . ($match[1] === '' ? '' : ' ') . 'frame-src ' . implode(' ', array_unique([...$sources, self::ORIGIN]));
            }, $policy) ?? throw new \RuntimeException('Vimeo policy could not be rendered');
        }
        return $policy . '; frame-src ' . self::ORIGIN;
    }

    /** @return array<string, array{mime: string, body: string}> */
    public static function assets(): array
    {
        $files = [];
        foreach ([self::SCRIPT => ['vimeo-player.js', 'text/javascript; charset=utf-8'], self::STYLE => ['vimeo-player.css', 'text/css; charset=utf-8']] as $path => [$source, $mime]) {
            $body = HostIo::attempt(static fn() => file_get_contents(dirname(__DIR__) . '/client/' . $source))->value;
            if (!is_string($body) || strlen($body) > 16384) {
                throw new \RuntimeException('Vimeo presentation asset is unavailable');
            }
            $files[$path] = ['mime' => $mime, 'body' => $body];
        }
        return $files;
    }

    public static function scriptHash(string $bytes): string
    {
        return base64_encode(hash('sha256', $bytes, true));
    }

    public static function document(string $html, string $route, bool $enabled): string
    {
        if (!$enabled) {
            return $html;
        }
        $found = false;
        $html = preg_replace_callback('/<meta\b[^>]*>/i', static function (array $tag) use (&$found): string {
            if (preg_match('/\bhttp-equiv\s*=\s*([\'"])Content-Security-Policy\1/i', $tag[0]) !== 1) {
                return $tag[0];
            }
            $found = true;
            return preg_replace_callback('/\bcontent\s*=\s*([\'"])(.*?)\1/is', static fn(array $value): string => 'content="' . Html::attribute(self::policy(html_entity_decode($value[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'), true)) . '"', $tag[0]) ?? throw new \RuntimeException('Vimeo page policy is invalid');
        }, $html) ?? throw new \RuntimeException('Vimeo document could not be rendered');
        $head = $found ? '' : '<meta http-equiv="Content-Security-Policy" content="' . Html::attribute(self::policy(str_replace("; frame-ancestors 'none'", '', ApachePublicDelivery::CONTENT_SECURITY_POLICY), true)) . '">';
        $head .= '<link rel="stylesheet" href="' . ArtifactUrl::escaped('/' . self::STYLE, $route) . '"><script type="module" src="' . ArtifactUrl::escaped('/' . self::SCRIPT, $route) . '" integrity="sha256-' . self::scriptHash(self::assets()[self::SCRIPT]['body']) . '"></script>';
        $result = preg_replace_callback('/<\/head>/i', static fn(): string => $head . '</head>', $html, 1, $count);
        if ($result === null || $count !== 1) {
            throw new \InvalidArgumentException('Vimeo documents require a complete head');
        }
        return $result;
    }
}
