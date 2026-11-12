<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional;

final class TextNormalizer
{
    public static function search(string $value): string
    {
        $folded = self::fold($value);
        $matches = [];
        $result = preg_match_all('/[\p{L}\p{N}]+/u', $folded, $matches);
        if ($result === false || $result === 0) {
            return '';
        }
        return implode(' ', $matches[0]);
    }

    public static function slug(string $value): string
    {
        $slug = preg_replace('/[^a-z0-9]+/', '-', self::fold($value));
        return is_string($slug) ? trim($slug, '-') : '';
    }

    private static function fold(string $value): string
    {
        if (!class_exists(\Normalizer::class)) {
            throw new \RuntimeException('Unicode normalization requires the intl extension');
        }
        $normalized = \Normalizer::normalize($value, \Normalizer::FORM_KD);
        if (!is_string($normalized)) {
            throw new \InvalidArgumentException('Text is not valid Unicode');
        }
        $withoutMarks = preg_replace('/\p{M}+/u', '', $normalized);
        if (!is_string($withoutMarks)) {
            throw new \InvalidArgumentException('Text is not valid Unicode');
        }
        return mb_strtolower($withoutMarks, 'UTF-8');
    }
}
