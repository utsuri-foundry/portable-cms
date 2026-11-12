<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Design;

use Utsuri\PortableCms\Conventional\I18n\InvalidInput;

/**
 * All native forms, exports and compiler inputs pass through this semantic boundary.
 * @phpstan-type CurveData array{min: float, max: float, from: int, to: int}
 * @phpstan-type FontData array{id: string, label: string, origin: string, sha256: string, byteSize: int, sourceSha256: string, provenance: string, license: string}
 * @phpstan-import-type FamilyData from FontFamilies
 * @phpstan-import-type ResolvedFace from FontFamilies
 * @phpstan-type ThresholdData array{id: string, label: string, minWidth: int}
 * @phpstan-type RoleData array{id: string, label: string, fontId: string|null, fallback: string, weight: int, lineHeight: float, size: CurveData, overrides: list<array{thresholdId: string, size: CurveData}>}
 * @phpstan-type SpaceData array{id: string, label: string, size: CurveData}
 * @phpstan-type RegistryData array{formatVersion: int, profileId: string, revision: int, fonts: list<FontData|FamilyData>, responsive: list<ThresholdData>, typography: list<RoleData>, spacing: list<SpaceData>}
 */
final readonly class RegistryDocument
{
    /** @param RegistryData $value */
    private function __construct(private array $value) {}

    public static function fromDocument(mixed $value): self
    {
        self::keys($value, ['formatVersion', 'profileId', 'revision', 'fonts', 'responsive', 'typography', 'spacing']);
        if (!in_array($value['formatVersion'], [1, 2], true) || !self::id($value['profileId']) || !is_int($value['revision'])
            || $value['revision'] < 0 || $value['revision'] > 2_147_483_647) {
            throw new \InvalidArgumentException('Design registry identity is invalid.');
        }
        $fonts = self::items($value['fonts'], 32);
        FontFamilies::validate($value['fonts'], $value['formatVersion']);
        $thresholds = self::items($value['responsive'], 8);
        $previous = 0;
        foreach ($thresholds as $threshold) {
            self::keys($threshold, ['id', 'label', 'minWidth']);
            if (!is_int($threshold['minWidth']) || $threshold['minWidth'] < 240 || $threshold['minWidth'] > 3840 || $threshold['minWidth'] <= $previous) {
                throw new InvalidInput('design.error.thresholds');
            }
            $previous = $threshold['minWidth'];
        }
        foreach (self::items($value['typography'], 16) as $role) {
            self::keys($role, ['id', 'label', 'fontId', 'fallback', 'weight', 'lineHeight', 'size', 'overrides']);
            if (($role['fontId'] !== null && (!self::id($role['fontId']) || !isset($fonts[$role['fontId']])))
                || !in_array($role['fallback'], ['serif', 'sans-serif', 'monospace'], true)
                || !is_int($role['weight']) || $role['weight'] < 100 || $role['weight'] > 900
                || (!is_int($role['lineHeight']) && !is_float($role['lineHeight']))
                || !is_finite((float) $role['lineHeight']) || $role['lineHeight'] < 0.8 || $role['lineHeight'] > 3) {
                throw new InvalidInput('design.error.typography');
            }
            $size = SizeCurve::fromDocument($role['size']);
            if ($size->min < 8 || $size->max > 256) {
                throw new InvalidInput('design.error.typography_sizes');
            }
            if (!is_array($role['overrides']) || !array_is_list($role['overrides']) || count($role['overrides']) > 8) {
                throw new InvalidInput('design.error.overrides');
            }
            $seen = [];
            foreach ($role['overrides'] as $override) {
                self::keys($override, ['thresholdId', 'size']);
                if (!self::id($override['thresholdId']) || !isset($thresholds[$override['thresholdId']]) || isset($seen[$override['thresholdId']])) {
                    throw new InvalidInput('design.error.override_threshold');
                }
                $seen[$override['thresholdId']] = true;
                $curve = SizeCurve::fromDocument($override['size']);
                if ($curve->min < 8 || $curve->max > 256) {
                    throw new InvalidInput('design.error.override_sizes');
                }
            }
        }
        $spacing = self::items($value['spacing'], 32);
        $curves = [];
        $viewports = [240, 360, 768, 1280, 1920, 3840];
        foreach ($spacing as $token) {
            self::keys($token, ['id', 'label', 'size']);
            $curve = SizeCurve::fromDocument($token['size']);
            $curves[] = $curve;
            $viewports[] = $curve->from;
            $viewports[] = $curve->to;
        }
        // Differences between clamped affine curves are affine between every knot.
        // Checking their union of knots proves order over the entire supported interval.
        foreach ($viewports as $viewport) {
            $previous = -1;
            foreach ($curves as $curve) {
                if ($curve->at($viewport) <= $previous) {
                    throw new InvalidInput('design.error.spacing');
                }
                $previous = $curve->at($viewport);
            }
        }
        foreach ($value as $key => $child) {
            $value[$key] = self::canonicalValue($child);
        }
        ksort($value, SORT_STRING);
        /** @var RegistryData $value Validated above and normalized at this boundary. */
        return new self($value);
    }

    private static function canonicalValue(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        foreach ($value as $key => $child) {
            $value[$key] = in_array($key, ['min', 'max', 'lineHeight'], true) && (is_int($child) || is_float($child))
                ? (float) $child : self::canonicalValue($child);
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        return $value;
    }

    public function profileId(): string
    {
        return $this->value['profileId'];
    }
    public function revision(): int
    {
        return $this->value['revision'];
    }
    /** @return RegistryData */
    public function document(): array
    {
        return $this->value;
    }
    /** @return list<ResolvedFace> */
    public function faces(): array
    {
        return FontFamilies::faces($this);
    }
    public function withRevision(int $revision): self
    {
        $value = $this->value;
        $value['revision'] = $revision;
        return self::fromDocument($value);
    }
    public function checksum(): string
    {
        return hash('sha256', json_encode($this->value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
    }

    /** @param list<string> $expected */
    public static function keys(mixed $value, array $expected): void
    {
        if (!is_array($value)) {
            throw new \InvalidArgumentException('Design registry object is invalid.');
        }
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($keys !== $expected) {
            throw new \InvalidArgumentException('Design registry properties are invalid.');
        }
    }

    public static function id(mixed $value): bool
    {
        return is_string($value) && strlen($value) <= 80 && preg_match('/\A[a-z][a-z0-9]*(?:-[a-z0-9]+)*\z/', $value) === 1;
    }
    public static function hash(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[a-f0-9]{64}\z/', $value) === 1;
    }
    public static function text(mixed $value, int $maximum = 100, bool $multiline = false): bool
    {
        return is_string($value) && trim($value) !== '' && mb_check_encoding($value, 'UTF-8') && mb_strlen($value) <= $maximum
            && preg_match($multiline ? '/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f<>]/u' : '/[\x00-\x1f\x7f<>]/u', $value) === 0;
    }
    /** @return array<string, array<string, mixed>> */
    public static function items(mixed $value, int $maximum): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > $maximum) {
            throw new \InvalidArgumentException('Design registry collection is invalid.');
        }
        $index = [];
        foreach ($value as $item) {
            if (!is_array($item) || !self::id($item['id'] ?? null) || !self::text($item['label'] ?? null) || isset($index[$item['id']])) {
                throw new InvalidInput('design.error.identities');
            }
            $index[$item['id']] = $item;
        }
        return $index;
    }
}
