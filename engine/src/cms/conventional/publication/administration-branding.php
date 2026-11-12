<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Publication;

/** Administration-only selections; never part of the public website identity. */
final readonly class AdministrationBranding
{
    public const MAX_BYTES = 1_048_576;
    public const MAX_DIMENSION = 960;

    public function __construct(public ?string $logoMediaId = null, public ?string $darkLogoMediaId = null)
    {
        foreach ([$logoMediaId, $darkLogoMediaId] as $id) {
            if ($id !== null && preg_match('/\A[a-f0-9]{32}\z/', $id) !== 1) {
                throw new PublicationFailure('administration_logo_invalid');
            }
        }
        if ($logoMediaId === null && $darkLogoMediaId !== null) {
            throw new PublicationFailure('administration_logo_required');
        }
    }

    public static function from(mixed $value): self
    {
        if ($value === null) {
            return new self();
        }
        if (!is_array($value) || count($value) !== 2 || !array_key_exists('logoMediaId', $value) || !array_key_exists('darkLogoMediaId', $value)
            || ($value['logoMediaId'] !== null && !is_string($value['logoMediaId'])) || ($value['darkLogoMediaId'] !== null && !is_string($value['darkLogoMediaId']))) {
            throw new PublicationFailure('administration_logo_invalid');
        }
        return new self($value['logoMediaId'], $value['darkLogoMediaId']);
    }

    /** @return array{logoMediaId: ?string, darkLogoMediaId: ?string} */
    public function document(): array
    {
        return ['logoMediaId' => $this->logoMediaId, 'darkLogoMediaId' => $this->darkLogoMediaId];
    }

    /** Metadata admission shared by save, restore and delivery. Bytes are verified by storage. */
    public static function eligible(string $status, string $mime, int $width, int $height, int $bytes): bool
    {
        return $status === 'ready' && in_array($mime, ['image/png', 'image/webp', 'image/jpeg'], true)
            && $width >= 1 && $width <= self::MAX_DIMENSION && $height >= 1 && $height <= self::MAX_DIMENSION
            && $bytes >= 1 && $bytes <= self::MAX_BYTES;
    }
}
