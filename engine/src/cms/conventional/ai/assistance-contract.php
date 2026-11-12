<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Ai;

final class AssistanceFailure extends \RuntimeException
{
    public function __construct(public readonly string $safeCode, public readonly bool $retryable = false)
    {
        parent::__construct($safeCode);
    }
}

/** Provider-neutral, plain-text suggestions. No executable output or provider metadata. */
final readonly class AssistanceCandidate
{
    /** @param array<string, array{description: string, caption: string, alt: string, taxonomy: list<string>}> $suggestions */
    private function __construct(public array $suggestions) {}

    /** @param list<string> $locales */
    public static function from(mixed $value, array $locales): self
    {
        try {
            $bytes = json_encode($value, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new AssistanceFailure('invalid_candidate');
        }
        if (!is_array($value) || count($value) !== 2 || !array_key_exists('formatVersion', $value) || !array_key_exists('suggestions', $value) || $value['formatVersion'] !== 1
            || !is_array($value['suggestions']) || strlen($bytes) > 65536) {
            throw new AssistanceFailure('invalid_candidate');
        }
        $keys = array_keys($value['suggestions']);
        sort($keys);
        $expected = $locales;
        sort($expected);
        if ($keys !== $expected || count($locales) > 10) {
            throw new AssistanceFailure('invalid_candidate');
        }
        $suggestions = [];
        foreach ($locales as $locale) {
            $entry = $value['suggestions'][$locale];
            if (!is_array($entry)) {
                throw new AssistanceFailure('invalid_candidate');
            }
            $keys = array_keys($entry);
            sort($keys);
            if ($keys !== ['alt', 'caption', 'description', 'taxonomy'] || !is_array($entry['taxonomy'])
                || !array_is_list($entry['taxonomy']) || count($entry['taxonomy']) > 5) {
                throw new AssistanceFailure('invalid_candidate');
            }
            $taxonomy = [];
            foreach ($entry['taxonomy'] as $term) {
                $taxonomy[] = self::text($term, 100);
            }
            $suggestions[$locale] = ['description' => self::text($entry['description'], 2000),
                'caption' => self::text($entry['caption'], 500), 'alt' => self::text($entry['alt'], 300), 'taxonomy' => $taxonomy];
        }
        return new self($suggestions);
    }

    private static function text(mixed $value, int $maximum): string
    {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value) > $maximum
            || preg_match('/[\x00-\x08\x0b-\x1f\x7f<>]/u', $value) !== 0) {
            throw new AssistanceFailure('invalid_candidate');
        }
        return trim(str_replace("\r\n", "\n", $value));
    }

    /** @return array<string, mixed> */
    public function document(): array
    {
        return ['formatVersion' => 1, 'suggestions' => $this->suggestions];
    }
}

final readonly class AssistanceRequest
{
    /** @param array<string, mixed> $frozen */
    public function __construct(public array $frozen) {}

    /** @return list<string> */
    public function locales(): array
    {
        $locales = $this->frozen['locales'] ?? null;
        if (!is_array($locales) || !array_is_list($locales) || $locales === [] || count($locales) > 10
            || count(array_filter($locales, 'is_string')) !== count($locales)) {
            throw new AssistanceFailure('source_unavailable');
        }
        return $locales;
    }
}

interface AssistanceProvider
{
    /** The image is verified private WebP bytes; the request has no credentials or filesystem paths. */
    public function suggest(AssistanceRequest $request, string $imageBytes, string $idempotencyKey): AssistanceCandidate;
}

final readonly class AssistanceJob
{
    public function __construct(
        public string $id,
        public string $status,
        public ?AssistanceRequest $request,
        public int $attempts,
        public string $lease,
        public ?AssistanceCandidate $candidate,
        public ?string $errorCode,
    ) {}
}

interface AssistanceRepository
{
    /** @return array<string, mixed> */
    public function source(string $typeId, string $recordId, string $mediaId): array;

    public function enqueue(string $id, string $clientId, string $actorId, AssistanceRequest $request, AssistancePolicy $policy, \DateTimeImmutable $now): AssistanceJob;

    public function find(string $id, string $actorId, string $recordId, \DateTimeImmutable $now): ?AssistanceJob;

    public function latest(string $actorId, string $recordId, \DateTimeImmutable $now): ?AssistanceJob;

    public function cancel(string $id, string $actorId, string $recordId, \DateTimeImmutable $now): void;

    public function claim(string $lease, \DateTimeImmutable $now): ?AssistanceJob;

    public function finish(AssistanceJob $job, ?AssistanceCandidate $candidate, ?string $errorCode, bool $retryable, \DateTimeImmutable $now): void;

    public function purge(\DateTimeImmutable $now): void;

    public function disablePending(): void;
}
