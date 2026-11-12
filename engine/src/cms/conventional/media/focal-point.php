<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Media;

use Utsuri\PortableCms\Conventional\I18n\InvalidInput;

/** Whole percentages of the oriented image; absent legacy metadata means centre. */
final readonly class FocalPoint
{
    public function __construct(public int $x = 50, public int $y = 50)
    {
        if ($x < 0 || $x > 100 || $y < 0 || $y > 100) {
            throw new InvalidInput('media.focal.invalid');
        }
    }

    public static function from(mixed $value = null): self
    {
        if ($value === null) {
            return new self();
        }
        if (!is_array($value) || count($value) !== 2 || !is_int($value['x'] ?? null) || !is_int($value['y'] ?? null)) {
            throw new InvalidInput('media.focal.invalid');
        }
        return new self($value['x'], $value['y']);
    }

    /** @return array{x: int, y: int} */
    public function document(): array
    {
        return ['x' => $this->x, 'y' => $this->y];
    }
}
