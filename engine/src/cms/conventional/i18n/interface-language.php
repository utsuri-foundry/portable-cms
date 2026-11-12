<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\I18n;

/**
 * The five administration languages, independent of the website's content languages.
 * An account either chooses one or follows its browser; English is the fallback.
 */
final class InterfaceLanguage
{
    public const SUPPORTED = ['ca', 'es', 'en', 'fr', 'de'];
    public const SYSTEM = 'system';
    /** Each language named in itself, identical in every interface language. */
    public const AUTONYMS = ['ca' => 'Català', 'es' => 'Español', 'en' => 'English', 'fr' => 'Français', 'de' => 'Deutsch'];
    private const HEADER_LIMIT = 512;
    private const RANGE_LIMIT = 24;

    /** A stored or submitted account choice; null means System. */
    public static function preference(?string $value): ?string
    {
        return is_string($value) && in_array($value, self::SUPPORTED, true) ? $value : null;
    }

    /** Whether a submitted form value is System or one of the supported languages. */
    public static function choice(string $value): bool
    {
        return $value === self::SYSTEM || in_array($value, self::SUPPORTED, true);
    }

    /** Picks the best supported language from a bounded Accept-Language header. */
    public static function negotiate(?string $acceptLanguage): string
    {
        if ($acceptLanguage === null || $acceptLanguage === '' || strlen($acceptLanguage) > self::HEADER_LIMIT) {
            return Translator::DEFAULT_LOCALE;
        }
        $best = null;
        $bestQuality = 0.0;
        foreach (array_slice(explode(',', $acceptLanguage), 0, self::RANGE_LIMIT) as $range) {
            if (preg_match('/\A\s*([A-Za-z]{1,8})(?:-[A-Za-z0-9]{1,8})*\s*(?:;\s*q\s*=\s*(0(?:\.\d{0,3})?|1(?:\.0{0,3})?))?\s*\z/', $range, $match) !== 1) {
                continue;
            }
            $language = strtolower($match[1]);
            $quality = isset($match[2]) ? (float) $match[2] : 1.0;
            if ($quality <= 0.0 || !in_array($language, self::SUPPORTED, true)) {
                continue;
            }
            // Earlier ranges win ties, as browsers list preferences in order.
            if ($best === null || $quality > $bestQuality) {
                $best = $language;
                $bestQuality = $quality;
            }
        }
        return $best ?? Translator::DEFAULT_LOCALE;
    }

    /** The account choice when present, otherwise the browser's preference. */
    public static function resolve(?string $preference, ?string $acceptLanguage): string
    {
        return self::preference($preference) ?? self::negotiate($acceptLanguage);
    }
}
