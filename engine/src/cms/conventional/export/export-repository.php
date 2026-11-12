<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Export;

interface ExportRepository
{
    /** @param list<string> $locales */
    public function create(
        string $exportId,
        string $auditId,
        string $actorUserId,
        string $profileId,
        string $profileVersion,
        array $locales,
        \DateTimeImmutable $now,
    ): NormalizedExport;
}

final class ExportService
{
    public function __construct(
        private readonly ExportRepository $repository,
        private readonly \Utsuri\PortableCms\Conventional\Content\ProfileManifest $profile,
        private readonly \Utsuri\PortableCms\Conventional\Identity\Clock $clock,
        private readonly \Utsuri\PortableCms\Conventional\Identity\RandomSource $random,
    ) {}

    public function create(string $actorUserId): NormalizedExport
    {
        return $this->repository->create(
            bin2hex($this->random->bytes(16)),
            bin2hex($this->random->bytes(16)),
            $actorUserId,
            $this->profile->id,
            $this->profile->version,
            $this->profile->locales,
            $this->clock->now(),
        );
    }

    public function capacity(): ?ExportCapacityStatus
    {
        return $this->repository instanceof ExportCapacityRepository ? $this->repository->capacity() : null;
    }
}
