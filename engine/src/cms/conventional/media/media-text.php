<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Media;

use Utsuri\PortableCms\Conventional\I18n\InvalidInput;

/** Authored plain text, independent of storage, forms and renderers. Missing legacy metadata is incomplete. */
final readonly class MediaText
{
    /** @param list<array{locale: string, alternativeText: string, caption: string, credit: string}> $translations */
    private function __construct(public bool $decorative, public array $translations) {}

    /** @param ?list<string> $locales Installed languages, when available. */
    public static function from(mixed $value = null, ?array $locales = null): self
    {
        if ($value === null) {
            return new self(false, []);
        }
        if (!is_array($value) || ($value['formatVersion'] ?? null) !== 1 || !is_bool($value['decorative'] ?? null)
            || !is_array($value['translations'] ?? null) || !array_is_list($value['translations']) || count($value['translations']) > 16
            || array_diff(array_keys($value), ['formatVersion', 'decorative', 'translations']) !== []) {
            throw new InvalidInput('media.text.invalid');
        }
        $seen = [];
        $translations = [];
        foreach ($value['translations'] as $item) {
            if (!is_array($item) || !is_string($item['locale'] ?? null) || preg_match('/\A[a-z]{2,3}(?:-[A-Z]{2})?\z/', $item['locale']) !== 1
                || isset($seen[$item['locale']]) || ($locales !== null && !in_array($item['locale'], $locales, true))
                || array_diff(array_keys($item), ['locale', 'alternativeText', 'caption', 'credit']) !== []) {
                throw new InvalidInput('media.text.invalid');
            }
            $seen[$item['locale']] = true;
            $translation = ['locale' => $item['locale']];
            foreach (['alternativeText', 'caption', 'credit'] as $field) {
                $text = $item[$field] ?? null;
                if (!is_string($text) || !mb_check_encoding($text, 'UTF-8') || mb_strlen($text) > 500
                    || preg_match('/[\x00-\x1f\x7f<>]/u', $text) !== 0) {
                    throw new InvalidInput('media.text.invalid');
                }
                $translation[$field] = trim($text);
            }
            $translations[] = $translation;
        }
        return new self($value['decorative'], $translations);
    }

    /** @param list<string> $locales Languages currently offered by the editor. */
    public static function edited(mixed $value, array $locales, ?self $previous = null): self
    {
        $document = self::from($value, $locales)->document();
        foreach (($previous ?? self::from())->translations as $translation) {
            if (!in_array($translation['locale'], $locales, true)) {
                $document['translations'][] = $translation;
            }
        }
        return self::from($document);
    }

    /**
     * Empty translations fall back field by field to the authored default language.
     * @return array{alternativeText: string, caption: string, credit: string}
     */
    public function localized(string $locale, string $defaultLocale): array
    {
        $values = [];
        foreach ($this->translations as $translation) {
            $values[$translation['locale']] = $translation;
        }
        $result = ['alternativeText' => '', 'caption' => '', 'credit' => ''];
        foreach ($result as $field => $_) {
            $requested = $values[$locale][$field] ?? '';
            $result[$field] = $requested !== '' ? $requested : ($values[$defaultLocale][$field] ?? '');
        }
        if ($this->decorative) {
            $result['alternativeText'] = '';
        }
        return $result;
    }

    public function publishable(string $locale, string $defaultLocale): bool
    {
        return $this->decorative || $this->localized($locale, $defaultLocale)['alternativeText'] !== '';
    }

    /** @return array{formatVersion: int, decorative: bool, translations: list<array{locale: string, alternativeText: string, caption: string, credit: string}>} */
    public function document(): array
    {
        return ['formatVersion' => 1, 'decorative' => $this->decorative, 'translations' => $this->translations];
    }
}
