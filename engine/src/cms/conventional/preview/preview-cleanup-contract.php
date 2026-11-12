<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Preview;

use Utsuri\PortableCms\Conventional\Identity\Clock;

final readonly class PreviewCursor
{
    private function __construct(public \DateTimeImmutable $createdAt, public string $id) {}

    public static function from(string $value): ?self
    {
        if ($value === '') {
            return null;
        }
        if (preg_match('/\A([0-9]{14}\.[0-9]{6})-([a-f0-9]{32})\z/', $value, $parts) !== 1) {
            throw new PreviewSelectionInvalid();
        }
        $time = \DateTimeImmutable::createFromFormat('!YmdHis.u', $parts[1], new \DateTimeZone('UTC'));
        if ($time === false || $time->format('YmdHis.u') !== $parts[1]) {
            throw new PreviewSelectionInvalid();
        }
        return new self($time, $parts[2]);
    }

    public static function after(PreviewStatus $preview): self
    {
        return new self($preview->createdAt->setTimezone(new \DateTimeZone('UTC')), $preview->id);
    }

    public function value(): string
    {
        return $this->createdAt->format('YmdHis.u') . '-' . $this->id;
    }
}

final readonly class PreviewCleanupItem
{
    public function __construct(public PreviewStatus $preview, public bool $releaseLinked = false, public int $activeShares = 0, public bool $jobTerminal = true) {}

    public function eligible(): bool
    {
        return $this->reason() === null;
    }

    public function reason(): ?string
    {
        if ($this->preview->buildKind !== 'website') {
            return 'holding';
        }
        if ($this->releaseLinked) {
            return 'release';
        }
        return $this->jobTerminal && in_array($this->preview->status, ['ready', 'needs_attention'], true) ? null : 'busy';
    }
}

final readonly class PreviewCleanupPage
{
    /** @param list<PreviewCleanupItem> $items */
    public function __construct(public array $items, public ?PreviewCursor $next) {}
}

/** Optional compatibility port: existing preview adapters need not implement cleanup UI. */
interface PreviewCleanupRepository extends PreviewRepository
{
    /** @return list<PreviewCleanupItem> */
    public function cleanupPage(?PreviewCursor $before, int $limit, \DateTimeImmutable $now): array;
    public function cleanupItem(string $id, \DateTimeImmutable $now): ?PreviewCleanupItem;

    /**
     * The bounded private rename runs only after locking the preview and refusing release references.
     * @param \Closure(): void $quarantine
     */
    public function deleteGuarded(string $id, string $actor, string $audit, \DateTimeImmutable $now, \Closure $quarantine): void;
}

final class PreviewCleanupBudget
{
    private int $operations = 0;
    private \DateTimeImmutable $deadline;

    public function __construct(private readonly Clock $clock, private readonly int $maximumOperations = 4_096, int $seconds = 3)
    {
        if ($maximumOperations < 1 || $maximumOperations > 4_096 || $seconds < 1 || $seconds > 3) {
            throw new \InvalidArgumentException('Preview cleanup budget is invalid');
        }
        $this->deadline = $clock->now()->modify('+' . $seconds . ' seconds');
    }

    public function available(): bool
    {
        return $this->operations < $this->maximumOperations && $this->clock->now() < $this->deadline;
    }

    public function consume(): bool
    {
        if (!$this->available()) {
            return false;
        }
        ++$this->operations;
        return true;
    }
}

/** HMAC-bound, expiring finite selection. No IDs are derived from filters at confirmation time. */
final class PreviewSelection
{
    public const LIMIT = 20;

    public function __construct(private readonly string $secret, private readonly Clock $clock)
    {
        if (strlen($secret) < 32) {
            throw new \InvalidArgumentException('Preview selection secret is too short');
        }
    }

    /**
     * @param list<string> $ids
     * @return list<string>
     */
    public static function ids(array $ids): array
    {
        if ($ids === [] || count($ids) > self::LIMIT) {
            throw new PreviewSelectionInvalid();
        }
        foreach ($ids as $id) {
            if (!is_string($id) || preg_match('/\A[a-f0-9]{32}\z/', $id) !== 1) {
                throw new PreviewSelectionInvalid();
            }
        }
        return array_values(array_unique($ids));
    }

    /**
     * @param list<string> $ids
     * @param list<string> $approved
     * @param list<string> $recover
     */
    public function issue(array $ids, array $approved, string $actor, string $purpose, array $recover = []): string
    {
        $ids = self::ids($ids);
        $approved = $approved === [] ? [] : self::ids($approved);
        $recover = $recover === [] ? [] : self::ids($recover);
        if (array_diff($approved, $ids) !== [] || array_diff($recover, $ids) !== [] || !in_array($purpose, ['page', 'confirm'], true)) {
            throw new PreviewSelectionInvalid();
        }
        $json = json_encode(['version' => 1, 'actor' => $actor, 'purpose' => $purpose, 'expires' => $this->clock->now()->getTimestamp() + 900, 'ids' => $ids, 'approved' => $approved, 'recover' => $recover], JSON_THROW_ON_ERROR);
        $payload = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
        return $payload . '.' . hash_hmac('sha256', 'preview-selection-v1:' . $payload, $this->secret);
    }

    /** @return array{ids: list<string>, approved: list<string>, recover: list<string>} */
    public function read(string $token, string $actor, string $purpose): array
    {
        if (strlen($token) > 6000 || preg_match('/\A([A-Za-z0-9_-]+)\.([a-f0-9]{64})\z/', $token, $parts) !== 1
            || !hash_equals(hash_hmac('sha256', 'preview-selection-v1:' . $parts[1], $this->secret), $parts[2])) {
            throw new PreviewSelectionInvalid();
        }
        $bytes = base64_decode(strtr($parts[1], '-_', '+/'), true);
        try {
            $data = $bytes === false ? null : json_decode($bytes, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new PreviewSelectionInvalid();
        }
        if (!is_array($data) || array_keys($data) !== ['version', 'actor', 'purpose', 'expires', 'ids', 'approved', 'recover']
            || $data['version'] !== 1 || $data['actor'] !== $actor || $data['purpose'] !== $purpose
            || !is_int($data['expires']) || $data['expires'] <= $this->clock->now()->getTimestamp()
            || !is_array($data['ids']) || !array_is_list($data['ids']) || !is_array($data['approved']) || !array_is_list($data['approved']) || !is_array($data['recover']) || !array_is_list($data['recover'])) {
            throw new PreviewSelectionInvalid();
        }
        $ids = self::ids($data['ids']);
        $approved = $data['approved'] === [] ? [] : self::ids($data['approved']);
        $recover = $data['recover'] === [] ? [] : self::ids($data['recover']);
        if (array_diff($approved, $ids) !== [] || array_diff($recover, $ids) !== []) {
            throw new PreviewSelectionInvalid();
        }
        return ['ids' => $ids, 'approved' => $approved, 'recover' => $recover];
    }
}

final class PreviewSelectionInvalid extends \InvalidArgumentException {}
final class PreviewCleanupPending extends \RuntimeException
{
    public function __construct(public readonly string $outcome = 'cleanup_pending')
    {
        parent::__construct('Preview cleanup requires an explicit retry');
    }
}
