<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Transfer;

use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Export\ChunkedExport;
use Utsuri\PortableCms\Conventional\JsonSchemaValidator;
use Utsuri\PortableCms\Conventional\Restore\RestorePlan;
use Utsuri\PortableCms\Conventional\Restore\RestoreRejected;

/** Completion proof, never code or authority to install a profile. */
final readonly class PackageManifest
{
    public const MAX_BYTES = 16384;

    /** @param array<string, mixed> $document */
    private function __construct(public array $document, public string $json) {}

    public static function create(RestorePlan $plan, int $envelopeBytes, ?string $engineVersion = ProfileManifest::ENGINE_VERSION): self
    {
        if ($envelopeBytes < 2 || $envelopeBytes > 33_554_432 || ($engineVersion !== null && !ProfileManifest::semanticVersion($engineVersion))) {
            throw new RestoreRejected('invalid_package_envelope_or_engine');
        }
        $hash = hash_init('sha256');
        $total = 0;
        foreach ($plan->files as $file) {
            hash_update($hash, json_encode([$file->key, $file->sha256, $file->byteSize, $file->mime, $file->width, $file->height], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
            $total += $file->byteSize;
        }
        $document = [
            'formatVersion' => 1, 'kind' => 'portable-cms-content-package', 'restoreContractVersion' => 1,
            'engineVersion' => $engineVersion,
            'profile' => ['id' => $plan->package->manifest->id, 'version' => $plan->package->manifest->version, 'packageSha256' => $plan->package->checksum],
            'export' => ['formatVersion' => $plan->export instanceof ChunkedExport ? 2 : 1, 'sha256' => $plan->sha256, 'byteSize' => $envelopeBytes],
            'inventory' => ['files' => count($plan->files), 'byteSize' => $total, 'sha256' => hash_final($hash)],
        ];
        return new self($document, json_encode($document, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    }

    public static function read(string $json, string $schema): self
    {
        if (strlen($json) > self::MAX_BYTES) {
            throw new RestoreRejected('package_manifest_size_limit');
        }
        try {
            JsonSchemaValidator::validateJson($json, $schema);
            $value = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (\InvalidArgumentException | \JsonException) {
            throw new RestoreRejected('invalid_package_manifest');
        }
        if (!is_array($value)) {
            throw new RestoreRejected('invalid_package_manifest');
        }
        return new self($value, $json);
    }

    public function assertPlan(RestorePlan $plan, int $envelopeBytes): void
    {
        if (!is_string($this->document['profile']['packageSha256'] ?? null) || !hash_equals($plan->package->checksum, $this->document['profile']['packageSha256'])) {
            throw new RestoreRejected('exact_profile_package_mismatch');
        }
        $version = $this->document['engineVersion'];
        if ($version !== null && $version !== ProfileManifest::ENGINE_VERSION) {
            throw new RestoreRejected('package_engine_compatibility_requires_review');
        }
        foreach (self::create($plan, $envelopeBytes, $version)->document as $key => $expected) {
            $actual = $this->document[$key];
            if (is_array($expected)) {
                if (!is_array($actual)) {
                    throw new RestoreRejected('package_inventory_or_envelope_changed');
                }
                ksort($expected, SORT_STRING);
                ksort($actual, SORT_STRING);
            }
            // Compare checksum strings and integers strictly, independent of JSON property order.
            if ($expected !== $actual) {
                throw new RestoreRejected('package_inventory_or_envelope_changed');
            }
        }
    }

    public function checksum(): string
    {
        return hash('sha256', $this->json);
    }
}
