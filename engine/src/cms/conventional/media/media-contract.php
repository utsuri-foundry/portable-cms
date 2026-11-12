<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Media;

use Utsuri\PortableCms\Conventional\HostIo;
use Utsuri\PortableCms\Conventional\I18n\LocalizedFailure;
use Utsuri\PortableCms\Conventional\I18n\Message;
use Utsuri\PortableCms\Conventional\I18n\Translator;

/** A stable upload failure code and status with editor-facing catalog text; the English rendering is the log message. */
final class UploadFailure extends \RuntimeException implements LocalizedFailure
{
    private readonly Message $text;

    /** @param array<string, string|int|float|Message> $parameters */
    public function __construct(
        public readonly string $codeName,
        public readonly int $statusCode,
        string $messageKey,
        array $parameters = [],
    ) {
        $this->text = new Message($messageKey, $parameters);
        parent::__construct(Translator::english()->message($this->text));
    }

    public function text(): Message
    {
        return $this->text;
    }
}

final readonly class UploadPart
{
    public function __construct(
        public int $partNumber,
        public int $byteSize,
        public string $checksum,
    ) {}
}

final readonly class UploadSession
{
    /** @param list<UploadPart> $parts */
    public function __construct(
        public string $id,
        public string $clientUploadId,
        public ?string $ownerRecordId,
        public string $actorUserId,
        public string $status,
        public string $objectKey,
        public string $originalName,
        public string $declaredType,
        public int $byteSize,
        public int $partSize,
        public int $partCount,
        public ?int $initialRecordRevision,
        public ?string $mediaId,
        public ?string $jobId,
        public \DateTimeImmutable $expiresAt,
        public array $parts,
    ) {}

    /** @return array<string, mixed> */
    public function publicValue(): array
    {
        return [
            'formatVersion' => 1,
            'id' => $this->id,
            'clientUploadId' => $this->clientUploadId,
            'ownerRecordId' => $this->ownerRecordId,
            'status' => $this->status,
            'originalName' => $this->originalName,
            'declaredType' => $this->declaredType,
            'byteSize' => $this->byteSize,
            'partSize' => $this->partSize,
            'partCount' => $this->partCount,
            'completedParts' => array_map(
                static fn(UploadPart $part): array => [
                    'partNumber' => $part->partNumber,
                    'byteSize' => $part->byteSize,
                ],
                $this->parts,
            ),
            'expiresAt' => $this->expiresAt->format('Y-m-d\TH:i:s.u\Z'),
            'mediaId' => $this->mediaId,
            'jobId' => $this->jobId,
        ];
    }
}

final readonly class StoredOriginal
{
    public function __construct(
        public string $key,
        public int $byteSize,
        public string $checksum,
    ) {}
}

final class MediaContract
{
    public const MAX_BYTES = 40 * 1024 * 1024;
    public const PART_BYTES = 5 * 1024 * 1024;
    public const SESSION_TTL_SECONDS = 24 * 60 * 60;
    public const MAX_IMAGE_DIMENSION = 20_000;
    public const MAX_IMAGE_PIXELS = 25_000_000;

    public static function imageDimensionsAllowed(int $width, int $height): bool
    {
        return $width > 0 && $height > 0 && $width <= self::MAX_IMAGE_DIMENSION && $height <= self::MAX_IMAGE_DIMENSION && $width * $height <= self::MAX_IMAGE_PIXELS;
    }

    /** @return array{filename: string, declaredType: string, byteSize: int} */
    public static function uploadIntent(string $filename, string $declaredType, int $byteSize, bool $videoEnabled = false, int $videoMaximumBytes = VideoSupport::DEFAULT_BYTES): array
    {
        if (!in_array($declaredType, self::uploadTypes($videoEnabled), true)) {
            throw new UploadFailure('unsupported_media_type', 422, 'upload.error.only_jpeg_png_and_webp_uploads_are');
        }
        if ($byteSize < 1) {
            throw new UploadFailure('invalid_upload_size', 411, 'upload.error.a_valid_file_size_is_required');
        }
        $maximum = str_starts_with($declaredType, 'video/') ? min(VideoSupport::MAX_BYTES, $videoMaximumBytes) : self::MAX_BYTES;
        if ($byteSize > $maximum) {
            if (!str_starts_with($declaredType, 'video/')) {
                throw new UploadFailure('upload_too_large', 413, 'upload.error.the_upload_exceeds_the_40_mb_limit');
            }
            throw new UploadFailure('upload_too_large', 413, 'video.upload_too_large', ['limit' => (string) round($maximum / 1048576, 2)]);
        }
        $basename = preg_split('/[\\\\\/]/u', $filename);
        $basename = is_array($basename) ? (string) end($basename) : 'upload';
        $clean = preg_replace('/[^\p{L}\p{N}_. -]/u', '_', $basename);
        $clean = mb_substr(is_string($clean) ? $clean : 'upload', 0, 180);
        return [
            'filename' => $clean !== '' ? $clean : 'upload',
            'declaredType' => $declaredType,
            'byteSize' => $byteSize,
        ];
    }

    /** @return list<string> */
    public static function uploadTypes(bool $videoEnabled = false): array
    {
        return ['image/jpeg', 'image/png', 'image/webp', ...($videoEnabled ? ['video/mp4', 'video/webm'] : [])];
    }

    public static function profileVideoEnabled(\Utsuri\PortableCms\Conventional\Content\ProfileManifest $profile): bool
    {
        return PreencodedVideoProcessor::available() && in_array('preencoded_video', [...$profile->capabilities->required, ...$profile->capabilities->optional], true);
    }

    /** A shared upload can serve any video field on its owning type; each assignment retains its own narrower bound.
     * @param list<\Utsuri\PortableCms\Conventional\Content\FieldDefinition> $fields
     */
    public static function videoFieldMaximum(array $fields): int
    {
        $maximum = 0;
        foreach ($fields as $field) {
            if ($field->type === 'video_reference') {
                $maximum = max($maximum, is_int($field->settings['maximumBytes'] ?? null) ? $field->settings['maximumBytes'] : self::MAX_BYTES);
            }
            $maximum = max($maximum, self::videoFieldMaximum($field->fields));
            foreach ($field->blockTypes as $block) {
                $maximum = max($maximum, self::videoFieldMaximum($block->fields));
            }
        }
        return min(VideoSupport::MAX_BYTES, $maximum);
    }

    public static function clientUploadId(string $value): string
    {
        if (
            $value === ''
            || !mb_check_encoding($value, 'UTF-8')
            || mb_strlen($value) > 300
            || preg_match('/[\x00-\x1f\x7f]/u', $value) === 1
        ) {
            throw new UploadFailure('invalid_client_upload_id', 422, 'upload.error.a_bounded_client_upload_id_is_required');
        }
        return $value;
    }

    public static function expectedPartSize(UploadSession $session, int $partNumber): int
    {
        if ($partNumber < 1 || $partNumber > $session->partCount) {
            throw new UploadFailure('invalid_part_number', 422, 'upload.error.the_upload_part_number_is_invalid');
        }
        return $partNumber === $session->partCount
            ? $session->byteSize - ($session->partSize * ($session->partCount - 1))
            : $session->partSize;
    }

    public static function signatureMatches(string $path, string $declaredType): bool
    {
        $handle = HostIo::attempt(fn() => fopen($path, 'rb'))->value;
        if (!is_resource($handle)) {
            return false;
        }
        try {
            $prefix = HostIo::attempt(fn() => fread($handle, 12))->value;
        } finally {
            fclose($handle);
        }
        if (!is_string($prefix)) {
            return false;
        }
        return match ($declaredType) {
            'image/jpeg' => str_starts_with($prefix, "\xFF\xD8\xFF"),
            'image/png' => str_starts_with($prefix, "\x89PNG\r\n\x1A\n"),
            'image/webp' => strlen($prefix) >= 12
                && substr($prefix, 0, 4) === 'RIFF'
                && substr($prefix, 8, 4) === 'WEBP',
            'video/mp4' => strlen($prefix) >= 12 && substr($prefix, 4, 4) === 'ftyp',
            'video/webm' => str_starts_with($prefix, "\x1a\x45\xdf\xa3"),
            default => false,
        };
    }
}
