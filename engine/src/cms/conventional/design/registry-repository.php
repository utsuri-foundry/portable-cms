<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Design;

interface RegistryRepository
{
    public function load(RegistryDocument $defaults, ?int $revision = null): RegistryDocument;
    public function save(RegistryDocument $intent, string $actor, string $auditId, \DateTimeImmutable $now): RegistryDocument;
}

final class RegistryConflict extends \RuntimeException
{
    public function __construct(public readonly RegistryDocument $current)
    {
        parent::__construct('Website design changed while editing.');
    }
}
