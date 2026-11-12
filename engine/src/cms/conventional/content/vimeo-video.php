<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Content;

use Utsuri\PortableCms\Conventional\I18n\InvalidInput;

/** A bounded external reference, never a server-side fetch request. */
final readonly class VimeoVideo
{
    /** @param list<FieldDefinition> $fields */
    public static function declared(array $fields): bool
    {
        foreach ($fields as $field) {
            if ($field->type === 'vimeo_video' || self::declared($field->fields)) {
                return true;
            }
        }
        return false;
    }

    public const MAXIMUM_TITLE = 200;
    public const MAXIMUM_ADDRESS = 2048;

    private function __construct(public string $videoId, public ?string $privacyHash, public string $poster, public string $title) {}

    public static function from(mixed $value): self
    {
        if (!is_array($value) || count($value) !== 5 || ($value['formatVersion'] ?? null) !== 1
            || !is_string($value['videoId'] ?? null) || preg_match('/\A[1-9][0-9]{0,19}\z/', $value['videoId']) !== 1
            || !array_key_exists('privacyHash', $value) || ($value['privacyHash'] !== null && (!is_string($value['privacyHash']) || preg_match('/\A[A-Za-z0-9]{10}\z/', $value['privacyHash']) !== 1))) {
            throw new InvalidInput('vimeo.value_invalid');
        }
        if (!is_string($value['poster'] ?? null) || preg_match('/\A[a-f0-9]{32}\z/', $value['poster']) !== 1) {
            throw new InvalidInput('vimeo.poster_invalid');
        }
        if (!is_string($value['title'] ?? null) || trim($value['title']) === '') {
            throw new InvalidInput('vimeo.title_required');
        }
        if (!mb_check_encoding($value['title'], 'UTF-8') || mb_strlen($value['title'], 'UTF-8') > self::MAXIMUM_TITLE || preg_match('/[\x00-\x1f\x7f]/u', $value['title']) !== 0) {
            throw new InvalidInput('vimeo.title_invalid');
        }
        return new self($value['videoId'], $value['privacyHash'], $value['poster'], $value['title']);
    }

    /** @return array{videoId: string, privacyHash: ?string} */
    public static function address(string $url): array
    {
        if ($url === '') {
            throw new InvalidInput('vimeo.url_required');
        }
        if (strlen($url) > self::MAXIMUM_ADDRESS || preg_match('/[^\x21-\x7e]/', $url) !== 0 || str_contains($url, '\\') || str_contains($url, '%')) {
            throw new InvalidInput('vimeo.address_invalid');
        }
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https') {
            throw new InvalidInput('vimeo.scheme');
        }
        $host = strtolower($parts['host'] ?? '');
        if (!in_array($host, ['vimeo.com', 'player.vimeo.com'], true) || isset($parts['port']) || isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidInput('vimeo.host');
        }
        if (isset($parts['fragment'])) {
            throw new InvalidInput('vimeo.address_invalid');
        }
        $path = $parts['path'] ?? '';
        $hash = null;
        if ($host === 'vimeo.com') {
            if (isset($parts['query'])) {
                throw new InvalidInput('vimeo.address_invalid');
            }
            if (preg_match('#\A/([1-9][0-9]{0,19})(?:/([^/]+))?\z#', $path, $match) !== 1) {
                throw new InvalidInput('vimeo.id');
            }
            $id = $match[1];
            $hash = $match[2] ?? null;
        } else {
            if (preg_match('#\A/video/([1-9][0-9]{0,19})\z#', $path, $match) !== 1) {
                throw new InvalidInput('vimeo.id');
            }
            $id = $match[1];
            if (isset($parts['query'])) {
                if (preg_match('/\Ah=([^&]*)\z/', $parts['query'], $query) !== 1) {
                    throw new InvalidInput('vimeo.address_invalid');
                }
                $hash = $query[1];
            }
        }
        if ($hash !== null && preg_match('/\A[A-Za-z0-9]{10}\z/', $hash) !== 1) {
            throw new InvalidInput('vimeo.hash');
        }
        return ['videoId' => $id, 'privacyHash' => $hash];
    }

    /** Native controls retain their raw address until the authoritative validation boundary. */
    public static function authoring(mixed $value): self
    {
        if (is_array($value) && count($value) === 3 && is_string($value['url'] ?? null)) {
            return self::from(['formatVersion' => 1, ...self::address($value['url']), 'poster' => $value['poster'] ?? null, 'title' => $value['title'] ?? null]);
        }
        return self::from($value);
    }

    /** @return array{formatVersion: int, videoId: string, privacyHash: ?string, poster: string, title: string} */
    public function document(): array
    {
        return ['formatVersion' => 1, 'videoId' => $this->videoId, 'privacyHash' => $this->privacyHash, 'poster' => $this->poster, 'title' => $this->title];
    }

    public function pageUrl(): string
    {
        return 'https://vimeo.com/' . $this->videoId . ($this->privacyHash === null ? '' : '/' . $this->privacyHash);
    }

    public function playerUrl(): string
    {
        return 'https://player.vimeo.com/video/' . $this->videoId . '?' . ($this->privacyHash === null ? '' : 'h=' . $this->privacyHash . '&') . 'dnt=1&autoplay=1';
    }

    /** @param list<string> $locales
     * @param list<string>|null $requiredIn
     * @return array<string, mixed>|null
     */
    public static function field(mixed $raw, FieldDefinition $field, array $locales, bool $required, ?array $requiredIn = null, bool $authoring = false): ?array
    {
        if ($field->localized && $raw !== null && (!is_array($raw) || array_diff(array_keys($raw), $locales) !== [])) {
            throw new InvalidInput('vimeo.locales_invalid');
        }
        $values = [];
        foreach ($field->localized ? $locales : [''] as $locale) {
            $value = $field->localized ? ($raw[$locale] ?? null) : $raw;
            if ($value === null && $required && ($requiredIn === null || $locale === '' || in_array($locale, $requiredIn, true))) {
                throw new InvalidInput('vimeo.required');
            }
            $values[$locale] = $value === null ? null : ($authoring ? self::authoring($value) : self::from($value))->document();
        }
        return $field->localized && $raw !== null ? $values : ($field->localized ? null : $values['']);
    }

    /** @param list<FieldDefinition> $fields
     * @param array<string, mixed> $body
     * @param list<string> $locales
     * @param (\Closure(string): ?string)|null $mime
     */
    public static function assertFields(array $fields, array $body, array $locales, ?\Closure $mime = null): void
    {
        foreach ($fields as $field) {
            $raw = $body[$field->id] ?? null;
            if ($field->type === 'vimeo_video') {
                // ContentService/workflow/preflight own field requirements and public languages.
                // This boundary validates every supplied value, including history and preparation.
                $value = self::field($raw, $field, $locales, false);
                foreach ($field->localized && is_array($value) ? $value : [$value] as $entry) {
                    if ($entry !== null && $mime !== null && !str_starts_with($mime(self::from($entry)->poster) ?? '', 'image/')) {
                        throw new InvalidInput('vimeo.poster_invalid');
                    }
                }
            } elseif (in_array($field->type, ['group', 'repeatable_group'], true) && is_array($raw)) {
                foreach ($field->localized ? $locales : [''] as $locale) {
                    $container = $field->localized ? ($raw[$locale] ?? null) : $raw;
                    foreach ($field->type === 'group' ? [$container] : (is_array($container) ? $container : []) as $item) {
                        if (is_array($item)) {
                            self::assertFields(array_map(static fn(FieldDefinition $child): FieldDefinition => EditorialControls::childDefinition($child, $child->id, $locale !== ''), $field->fields), $item, $locales, $mime);
                        }
                    }
                }
            }
        }
    }
}
