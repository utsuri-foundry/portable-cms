<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Content;

/**
 * Whether the website publishes an llms.txt for AI assistants, and the introduction editors may
 * write for it in each language. Saved with Website settings and frozen into website builds.
 */
final readonly class LlmsTxtSettings
{
    public const INTRODUCTION_LENGTH = 1500;

    /** @param array<string, string> $introductions Non-empty plain text by locale, in the website's language order. */
    public function __construct(public bool $enabled = true, public array $introductions = [])
    {
        foreach ($introductions as $locale => $text) {
            if (!is_string($locale) || !ProfileManifest::localeId($locale) || !self::valid($text) || trim($text) === '') {
                throw new \InvalidArgumentException('llms.txt settings are invalid');
            }
        }
    }

    public static function from(mixed $document): self
    {
        if (!is_array($document) || count($document) !== 2 || !is_bool($document['enabled'] ?? null)
            || !is_array($document['introductions'] ?? null) || !array_is_list($document['introductions'])
            || count($document['introductions']) > 16) {
            throw new \InvalidArgumentException('llms.txt settings document is invalid');
        }
        $introductions = [];
        foreach ($document['introductions'] as $entry) {
            if (!is_array($entry) || count($entry) !== 2 || !is_string($entry['locale'] ?? null) || !is_string($entry['text'] ?? null)
                || isset($introductions[$entry['locale']])) {
                throw new \InvalidArgumentException('llms.txt settings document is invalid');
            }
            $introductions[$entry['locale']] = $entry['text'];
        }
        return new self($document['enabled'], $introductions);
    }

    /** Plain text of at most the introduction length, with line breaks but no other control characters. */
    public static function valid(mixed $text): bool
    {
        return is_string($text) && mb_check_encoding($text, 'UTF-8') && mb_strlen($text) <= self::INTRODUCTION_LENGTH
            && preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u', $text) !== 1;
    }

    /** Editors' text as saved: line breaks unified, outer blank space removed. */
    public static function normalize(string $text): string
    {
        return trim(str_replace(["\r\n", "\r"], "\n", $text));
    }

    /**
     * The same settings for these languages only, in their order.
     * @param list<string> $locales
     */
    public function only(array $locales): self
    {
        $introductions = [];
        foreach ($locales as $locale) {
            if (isset($this->introductions[$locale])) {
                $introductions[$locale] = $this->introductions[$locale];
            }
        }
        return new self($this->enabled, $introductions);
    }

    public function isDefault(): bool
    {
        return $this->enabled && $this->introductions === [];
    }

    /** @return array{enabled: bool, introductions: list<array{locale: string, text: string}>} */
    public function document(): array
    {
        $introductions = [];
        foreach ($this->introductions as $locale => $text) {
            $introductions[] = ['locale' => $locale, 'text' => $text];
        }
        return ['enabled' => $this->enabled, 'introductions' => $introductions];
    }
}
