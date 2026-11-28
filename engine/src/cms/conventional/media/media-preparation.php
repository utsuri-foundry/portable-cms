<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Media;

use Utsuri\PortableCms\Conventional\HostIo;
use Utsuri\PortableCms\Conventional\I18n\Message;
use Utsuri\PortableCms\Conventional\Identity\Clock;

/**
 * When a file being prepared will be ready, said as an editor needs it. Without the scheduled worker,
 * files are prepared right after the response. With it, the private worker heartbeat says when the
 * last pass finished and how long the host waits between passes; the next pass is the estimate.
 */
final readonly class MediaPreparation
{
    /** A heartbeat older than three passes, and at least twenty minutes, means the schedule stopped. */
    private const LATE_PASSES = 3;
    private const LATE_SECONDS = 1200;

    public function __construct(private bool $scheduled = false, private ?string $heartbeatPath = null, private ?Clock $clock = null) {}

    /** The state line for a file that is not ready: when it will be, or that it failed and what to do. */
    public function message(LibraryMedia $media): Message
    {
        if (self::failed($media)) {
            return new Message('media.processing_attention');
        }
        if (!$this->scheduled || $media->status === 'processing') {
            return new Message('media.pending.moment');
        }
        [$completedAt, $interval] = $this->heartbeat() ?? [null, null];
        if ($completedAt === null || $this->clock === null) {
            return new Message('media.pending.minutes');
        }
        $age = max(0, $this->clock->now()->getTimestamp() - $completedAt);
        if ($age > max(self::LATE_PASSES * ($interval ?? 0), self::LATE_SECONDS)) {
            return new Message('media.pending.late');
        }
        if ($interval === null) {
            return new Message('media.pending.minutes');
        }
        return new Message('media.pending.about', ['minutes' => max(1, (int) ceil(($interval - $age % $interval) / 60))]);
    }

    /** Seconds a waiting page leaves between status checks. */
    public function pollSeconds(): int
    {
        return $this->scheduled ? 15 : 3;
    }

    public static function failed(LibraryMedia $media): bool
    {
        return $media->status === 'needs_attention' || $media->errorCode !== null;
    }

    /** @return array{int, ?int}|null The last pass's completion time and, once observed, the seconds between passes. */
    private function heartbeat(): ?array
    {
        if ($this->heartbeatPath === null || !is_file($this->heartbeatPath)) {
            return null;
        }
        $bytes = HostIo::attempt(fn(): string|false => file_get_contents($this->heartbeatPath, false, null, 0, 65536))->value;
        $value = is_string($bytes) ? json_decode($bytes, true) : null;
        $completed = is_array($value) && is_string($value['completedAt'] ?? null)
            ? \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value['completedAt'], new \DateTimeZone('UTC'))
            : false;
        if ($completed === false) {
            return null;
        }
        $interval = $value['intervalSeconds'] ?? null;
        return [$completed->getTimestamp(), is_int($interval) && $interval >= 1 && $interval <= 86400 ? $interval : null];
    }
}
