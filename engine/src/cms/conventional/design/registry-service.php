<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Design;

use Utsuri\PortableCms\Conventional\I18n\InvalidInput;
use Utsuri\PortableCms\Conventional\Identity\Clock;
use Utsuri\PortableCms\Conventional\Identity\RandomSource;

final class RegistryService
{
    public function __construct(
        private readonly RegistryRepository $repository,
        public readonly RegistryDocument $defaults,
        private readonly Clock $clock,
        private readonly RandomSource $random,
        public readonly FontIngestion $fonts,
    ) {}
    public function load(?int $revision = null): RegistryDocument
    {
        return $this->repository->load($this->defaults, $revision);
    }

    /** @param array<string, string> $values */
    public function save(int $revision, array $values, string $actor, ?string $fontBytes = null): RegistryDocument
    {
        $base = $this->load($revision);
        $intent = RegistryDocument::fromDocument(RegistryForm::project($base, $values));
        if ($fontBytes !== null) {
            $intent = FontUpload::add($intent, $values, $fontBytes, $this->fonts);
        }
        return $this->repository->save($intent, $actor, bin2hex($this->random->bytes(16)), $this->clock->now());
    }
}

/** Native form projection; immutable IDs, checksums and licensing facts come from the pinned revision. */
final class RegistryForm
{
    /** @return array<string, string> */
    public static function values(RegistryDocument $registry): array
    {
        $result = [];
        $document = $registry->document();
        foreach (['fonts', 'responsive', 'typography', 'spacing'] as $group) {
            foreach ($document[$group] as $item) {
                $prefix = $group . '__' . $item['id'] . '__';
                foreach (self::fields($group) as $key) {
                    $result[$prefix . $key] = (string) ($item[$key] ?? '');
                }
                if (isset($item['size'])) {
                    foreach ($item['size'] as $key => $value) {
                        $result[$prefix . $key] = (string) $value;
                    }
                }
                if ($group === 'typography') {
                    foreach ($document['responsive'] as $threshold) {
                        foreach (['min', 'max', 'from', 'to'] as $key) {
                            $result[$prefix . $threshold['id'] . '__' . $key] = '';
                        }
                    }
                    foreach ($item['overrides'] as $override) {
                        foreach ($override['size'] as $key => $value) {
                            $result[$prefix . $override['thresholdId'] . '__' . $key] = (string) $value;
                        }
                    }
                }
            }
        }
        return $result;
    }

    /** @return list<string> */
    public static function fields(string $group): array
    {
        return match ($group) {
            'responsive' => ['label', 'minWidth'],
            'typography' => ['label', 'fontId', 'fallback', 'weight', 'lineHeight'],
            default => ['label'],
        };
    }

    /** @param array<string, string> $values
     * @return array<string, mixed>
     */
    public static function project(RegistryDocument $base, array $values): array
    {
        $document = $base->document();
        foreach (self::values($base) as $name => $unused) {
            if (!array_key_exists($name, $values)) {
                throw new InvalidInput('design.error.incomplete');
            }
        }
        foreach (['fonts', 'responsive', 'typography', 'spacing'] as $group) {
            foreach ($document[$group] as &$item) {
                $prefix = $group . '__' . $item['id'] . '__';
                foreach (self::fields($group) as $key) {
                    $input = trim($values[$prefix . $key]);
                    $item[$key] = match ($key) {
                        'minWidth', 'weight' => self::integer($input),
                        'lineHeight' => self::decimal($input),
                        'fontId' => $input === '' ? null : $input,
                        default => $input,
                    };
                }
                if (isset($item['size'])) {
                    $item['size'] = self::curve($values, $prefix);
                }
                if ($group === 'typography') {
                    $item['overrides'] = [];
                    foreach ($document['responsive'] as $threshold) {
                        $overridePrefix = $prefix . $threshold['id'] . '__';
                        $inputs = array_map(static fn(string $key): string => trim($values[$overridePrefix . $key]), ['min', 'max', 'from', 'to']);
                        if (array_filter($inputs, static fn(string $value): bool => $value !== '') !== []) {
                            $item['overrides'][] = ['thresholdId' => $threshold['id'], 'size' => self::curve($values, $overridePrefix)];
                        }
                    }
                }
            }
            unset($item);
        }
        return $document;
    }

    /** @param array<string, string> $values
     * @return array{min: float, max: float, from: int, to: int}
     */
    private static function curve(array $values, string $prefix): array
    {
        return ['min' => self::decimal($values[$prefix . 'min']), 'max' => self::decimal($values[$prefix . 'max']),
            'from' => self::integer($values[$prefix . 'from']), 'to' => self::integer($values[$prefix . 'to'])];
    }
    private static function decimal(string $input): float
    {
        if (preg_match('/\A[0-9]{1,4}(?:\.[0-9]{1,4})?\z/', $input) !== 1) {
            throw new InvalidInput('design.error.decimals');
        }
        return (float) $input;
    }
    private static function integer(string $input): int
    {
        if (preg_match('/\A[0-9]{1,4}\z/', $input) !== 1) {
            throw new InvalidInput('design.error.integers');
        }
        return (int) $input;
    }
}
