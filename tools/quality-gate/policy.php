<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\QualityGate;

final readonly class QualityException
{
    /** @param list<string> $fingerprints */
    public function __construct(
        public string $path,
        public string $rule,
        public array $fingerprints,
        public string $owner,
        public string $reason,
        public \DateTimeImmutable $created,
        public \DateTimeImmutable $expires,
        public string $removalTask,
    ) {}
}

final class QualityPolicy
{
    /**
     * @param list<QualityFinding> $findings
     * @param list<QualityException> $exceptions
     * @return list<QualityFinding>
     */
    public function apply(array $findings, array $exceptions, string $root, \DateTimeImmutable $today): array
    {
        $remaining = [];
        $available = [];
        foreach ($exceptions as $exception) {
            $this->validateException($exception, $root, $today);
            foreach ($exception->fingerprints as $fingerprint) {
                $available[$exception->path][$exception->rule][$fingerprint] =
                    ($available[$exception->path][$exception->rule][$fingerprint] ?? 0) + 1;
            }
        }
        foreach ($findings as $finding) {
            $fingerprint = $finding->fingerprint();
            $count = $available[$finding->path][$finding->rule][$fingerprint] ?? 0;
            if ($count > 0) {
                $available[$finding->path][$finding->rule][$fingerprint] = $count - 1;
                continue;
            }
            $remaining[] = $finding;
        }
        foreach ($available as $path => $rules) {
            foreach ($rules as $rule => $fingerprints) {
                foreach ($fingerprints as $count) {
                    if ($count > 0) {
                        $remaining[] = new QualityFinding(
                            'quality.unused-exception',
                            $path,
                            1,
                            $rule,
                            'Quality exception is no longer necessary and must be removed.',
                        );
                    }
                }
            }
        }
        return $remaining;
    }

    /** @return list<QualityException> */
    public function load(string $path): array
    {
        $decoded = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || ($decoded['schema'] ?? null) !== 1 || !is_array($decoded['exceptions'] ?? null)) {
            throw new \RuntimeException('Quality exception registry is invalid');
        }
        $exceptions = [];
        foreach ($decoded['exceptions'] as $value) {
            if (!is_array($value)) {
                throw new \RuntimeException('Quality exception entry is invalid');
            }
            $keys = array_keys($value);
            sort($keys, SORT_STRING);
            if ($keys !== ['created', 'expires', 'fingerprints', 'owner', 'path', 'reason', 'removalTask', 'rule']) {
                throw new \RuntimeException('Quality exception properties are invalid');
            }
            $fingerprints = $value['fingerprints'];
            if (!is_array($fingerprints) || $fingerprints === []) {
                throw new \RuntimeException('Quality exception must name exact fingerprints');
            }
            $exceptions[] = new QualityException(
                $this->text($value, 'path'),
                $this->text($value, 'rule'),
                array_values(array_map(fn(mixed $item): string => $this->fingerprint($item), $fingerprints)),
                $this->text($value, 'owner'),
                $this->text($value, 'reason'),
                $this->date($value, 'created'),
                $this->date($value, 'expires'),
                $this->text($value, 'removalTask'),
            );
        }
        return $exceptions;
    }

    private function validateException(QualityException $exception, string $root, \DateTimeImmutable $today): void
    {
        if ($exception->created > $exception->expires || $today > $exception->expires) {
            throw new \RuntimeException('Quality exception is expired or has invalid dates');
        }
        if (!is_file($root . '/' . $exception->path)) {
            throw new \RuntimeException('Quality exception source path does not exist');
        }
        $tasks = glob($root . '/tasks/projects/*/tasks/' . $exception->removalTask . '.task.md');
        if (!is_array($tasks) || count($tasks) !== 1) {
            throw new \RuntimeException('Quality exception removal task does not exist');
        }
        $record = file_get_contents($tasks[0]);
        if (!is_string($record) || preg_match('/^state = "(?:done|cancelled)"$/m', $record) === 1) {
            throw new \RuntimeException('Quality exception removal task must be nonterminal');
        }
    }

    /** @param array<string, mixed> $value */
    private function text(array $value, string $key): string
    {
        $text = $value[$key] ?? null;
        if (!is_string($text) || trim($text) === '') {
            throw new \RuntimeException('Quality exception text property is invalid');
        }
        return $text;
    }

    private function fingerprint(mixed $value): string
    {
        if (!is_string($value) || preg_match('/\A[a-f0-9]{64}\z/', $value) !== 1) {
            throw new \RuntimeException('Quality exception fingerprint is invalid');
        }
        return $value;
    }

    /** @param array<string, mixed> $value */
    private function date(array $value, string $key): \DateTimeImmutable
    {
        $text = $this->text($value, $key);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $text, new \DateTimeZone('UTC'));
        if (!$date instanceof \DateTimeImmutable || $date->format('Y-m-d') !== $text) {
            throw new \RuntimeException('Quality exception date is invalid');
        }
        return $date;
    }
}
