<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Design;

use Utsuri\PortableCms\Conventional\I18n\InvalidInput;

final readonly class SizeCurve
{
    public function __construct(public float $min, public float $max, public int $from, public int $to)
    {
        if (!is_finite($min) || !is_finite($max) || $min < 0 || $max < $min || $max > 512
            || $from < 240 || $to > 3840 || $from >= $to) {
            throw new InvalidInput('design.error.curve');
        }
    }

    public static function fromDocument(mixed $value): self
    {
        RegistryDocument::keys($value, ['min', 'max', 'from', 'to']);
        foreach (['min', 'max'] as $key) {
            if (!is_int($value[$key]) && !is_float($value[$key])) {
                throw new InvalidInput('design.error.size_number');
            }
        }
        if (!is_int($value['from']) || !is_int($value['to'])) {
            throw new InvalidInput('design.error.viewport_number');
        }
        return new self((float) $value['min'], (float) $value['max'], $value['from'], $value['to']);
    }

    public function at(int $viewport): float
    {
        return $this->min + ($this->max - $this->min) * max(0, min(1, ($viewport - $this->from) / ($this->to - $this->from)));
    }

    public function css(): string
    {
        if ($this->min === $this->max) {
            return self::number($this->min / 16) . 'rem';
        }
        $slope = ($this->max - $this->min) / ($this->to - $this->from);
        return 'clamp(' . self::number($this->min / 16) . 'rem, calc('
            . self::number(($this->min - $slope * $this->from) / 16) . 'rem + '
            . self::number($slope * 100) . 'vw), ' . self::number($this->max / 16) . 'rem)';
    }

    /** @return array{min: float, max: float, from: int, to: int} */
    public function document(): array
    {
        return ['min' => $this->min, 'max' => $this->max, 'from' => $this->from, 'to' => $this->to];
    }

    public static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 8, '.', ''), '0'), '.');
    }
}
