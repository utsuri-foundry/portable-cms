<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Upgrade;

use Utsuri\PortableCms\Conventional\Content\ProfileManifest;

/** Immutable build provenance; older installed builds explicitly have unknown provenance. */
final readonly class EngineBuild
{
    public function __construct(public string $version, public ?string $sourceRevision = null, public ?bool $sourceDirty = null)
    {
        if (!ProfileManifest::semanticVersion($version)) {
            throw new UpgradeFailure('engine_build_version_invalid');
        }
        if ($sourceRevision !== null && preg_match('/\A(?:[a-f0-9]{40}|[a-f0-9]{64})\z/', $sourceRevision) !== 1) {
            throw new UpgradeFailure('engine_build_source_revision_invalid');
        }
    }

    /** @param array<string, mixed> $document */
    public static function fromDocument(array $document): self
    {
        $version = $document['version'] ?? null;
        $revision = $document['sourceRevision'] ?? null;
        $dirty = $document['sourceDirty'] ?? null;
        if (!is_string($version) || ($revision !== null && !is_string($revision)) || ($dirty !== null && !is_bool($dirty))) {
            throw new UpgradeFailure('engine_build_metadata_invalid');
        }
        return new self($version, $revision, $dirty);
    }

    public static function installed(string $runtime, string $fallbackVersion): self
    {
        $path = $runtime . '/engine-version.json';
        return is_file($path) ? self::fromDocument(UpgradeFiles::json($path)) : new self($fallbackVersion);
    }

    /** @return array{version: string, sourceRevision: ?string, sourceDirty: ?bool} */
    public function document(): array
    {
        return ['version' => $this->version, 'sourceRevision' => $this->sourceRevision, 'sourceDirty' => $this->sourceDirty];
    }
}
