<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Content;

use Utsuri\PortableCms\Conventional\I18n\InvalidInput;
use Utsuri\PortableCms\Conventional\Identity\Clock;
use Utsuri\PortableCms\Conventional\Identity\RandomSource;
use Utsuri\PortableCms\Conventional\Identity\SessionContext;

/** Installation policy; current and published heads are additional to the historical allowance. */
final readonly class RecordHistorySettings
{
    public const DEFAULT_LIMIT = 50;

    public function __construct(public int $revision = 0, public int $limit = self::DEFAULT_LIMIT, public bool $pending = false)
    {
        self::validateLimit($limit);
        if ($revision < 0 || $revision > 4_294_967_294) {
            throw new InvalidInput('history.invalid');
        }
    }

    public static function validateLimit(int $limit): void
    {
        if ($limit < 10 || $limit > 500) {
            throw new InvalidInput('history.invalid');
        }
    }
}

interface RecordHistoryRepository
{
    public function load(): RecordHistorySettings;

    public function retained(): int;

    public function excess(int $limit): int;

    /** Compare policy revision and confirmed removal count before changing the policy. */
    public function save(int $limit, int $expected, ?int $confirmedCount, string $actor, string $auditId, \DateTimeImmutable $now): void;

    /** One record and at most 100 deletions, with policy/progress/audit in the same transaction. */
    public function batch(string $actor, string $auditId, \DateTimeImmutable $now): void;

    /** Number of frozen source references whose revisions are no longer retained. */
    public function missingPreviewRevisions(string $previewId): int;
}

final readonly class RecordHistoryService
{
    public function __construct(private RecordHistoryRepository $repository, private Clock $clock, private RandomSource $random) {}

    public function load(): RecordHistorySettings
    {
        return $this->repository->load();
    }

    public function retained(): int
    {
        return $this->repository->retained();
    }

    public function excess(int $limit): int
    {
        RecordHistorySettings::validateLimit($limit);
        return $this->repository->excess($limit);
    }

    public function missingPreviewRevisions(string $previewId): int
    {
        return $this->repository->missingPreviewRevisions($previewId);
    }

    public function save(int $limit, int $expected, ?int $confirmedCount, SessionContext $actor): void
    {
        $id = $this->administrator($actor);
        RecordHistorySettings::validateLimit($limit);
        $this->repository->save($limit, $expected, $confirmedCount, $id, bin2hex($this->random->bytes(16)), $this->clock->now());
    }

    public function continue(SessionContext $actor): void
    {
        $id = $this->administrator($actor);
        $this->repository->batch($id, bin2hex($this->random->bytes(16)), $this->clock->now());
    }

    private function administrator(SessionContext $actor): string
    {
        if (!$actor->administrator() || $actor->userId === null) {
            throw new InvalidInput('history.forbidden');
        }
        return $actor->userId;
    }
}
