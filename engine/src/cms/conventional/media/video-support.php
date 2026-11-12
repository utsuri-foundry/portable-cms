<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Media;

/** Host facts shared by the standalone probe and installed prepared-video adapter. */
final class VideoSupport
{
    public const DEFAULT_BYTES = 40 * 1024 * 1024;
    public const MAX_BYTES = 256 * 1024 * 1024;

    public static function available(): bool
    {
        return PHP_INT_SIZE >= 8 && function_exists('fopen') && function_exists('fseek') && function_exists('fread') && function_exists('hash_file') && function_exists('copy') && function_exists('fstat') && function_exists('hash_update_stream') && function_exists('fwrite');
    }
}

/** Operator evidence from a completed trailer upload, assembly and preparation on both web and CLI. */
final readonly class VideoUploadQualification
{
    public function __construct(public int $maximumBytes, public int $requestTimeoutSeconds, public int $verifiedCompletionSeconds)
    {
        if ($maximumBytes <= VideoSupport::DEFAULT_BYTES || $maximumBytes > VideoSupport::MAX_BYTES
            || $requestTimeoutSeconds < 30 || $requestTimeoutSeconds > 180
            || $verifiedCompletionSeconds < 1 || $verifiedCompletionSeconds * 2 > $requestTimeoutSeconds) {
            throw new \InvalidArgumentException('Invalid prepared-video host qualification');
        }
    }

    public static function from(mixed $value): ?self
    {
        if ($value === null) {
            return null;
        }
        if (!is_array($value) || count($value) !== 3 || !is_int($value['maximumBytes'] ?? null)
            || !is_int($value['requestTimeoutSeconds'] ?? null) || !is_int($value['verifiedCompletionSeconds'] ?? null)) {
            throw new \InvalidArgumentException('Invalid prepared-video host qualification');
        }
        return new self($value['maximumBytes'], $value['requestTimeoutSeconds'], $value['verifiedCompletionSeconds']);
    }
}

/** Pure policy. Missing evidence or regressed host facts preserve the legacy ceiling. */
final readonly class VideoUploadPolicy
{
    /** @param list<string> $reasons */
    public function __construct(public int $maximumBytes = VideoSupport::DEFAULT_BYTES, public array $reasons = ['qualification_missing'], public int $requestTimeoutSeconds = 30, public int $nativeMaximumBytes = VideoSupport::DEFAULT_BYTES) {}

    /** @param array<string, mixed> $limits */
    public static function qualify(?VideoUploadQualification $evidence, array $limits, ?int $freeBytes, bool $available): self
    {
        $reasons = [];
        $maximum = $evidence->maximumBytes ?? VideoSupport::DEFAULT_BYTES;
        $budget = $evidence->requestTimeoutSeconds ?? 30;
        if ($evidence === null) {
            $reasons[] = 'qualification_missing';
        }
        if (!$available) {
            $reasons[] = 'video_adapter_unavailable';
        }
        if ($freeBytes === null || $freeBytes < $maximum * 4 + 64 * 1024 * 1024) {
            $reasons[] = 'storage_headroom_insufficient';
        }
        $memory = $limits['memoryLimitBytes'] ?? null;
        if (!is_int($memory) || ($memory !== -1 && $memory < 64 * 1024 * 1024)) {
            $reasons[] = 'memory_limit_insufficient';
        }
        foreach (['maxExecutionSeconds', 'maxInputSeconds'] as $name) {
            $seconds = $limits[$name] ?? null;
            if ($name === 'maxInputSeconds' && $seconds === -1) {
                $seconds = $limits['maxExecutionSeconds'] ?? null;
            }
            if (!is_int($seconds) || ($seconds !== 0 && $seconds < $budget)) {
                $reasons[] = $name . '_insufficient';
            }
        }
        // PUT parts bypass multipart upload limits; retain room for the native fallback and POST controls.
        $post = $limits['postMaxBytes'] ?? null;
        $upload = $limits['uploadMaxBytes'] ?? null;
        if (($limits['fileUploads'] ?? false) !== true || !is_int($post) || ($post !== 0 && $post < 6 * 1024 * 1024)
            || !is_int($upload) || $upload < 5 * 1024 * 1024) {
            $reasons[] = 'php_upload_limits_insufficient';
        }
        $maximum = $reasons === [] ? $maximum : VideoSupport::DEFAULT_BYTES;
        // Existing sessions may still need the qualified completion budget after their own parts consume space.
        foreach (['maxExecutionSeconds', 'maxInputSeconds'] as $name) {
            $seconds = $limits[$name] ?? null;
            if (is_int($seconds) && $seconds > 0) {
                $budget = min($budget, $seconds);
            }
        }
        $native = min($maximum, is_int($upload) ? max(0, $upload) : 0, $post === 0 ? $maximum : (is_int($post) ? max(0, $post - 1024 * 1024) : 0));
        return new self($maximum, $reasons, $budget, $native);
    }

    /** @return array<string, mixed> */
    public function report(): array
    {
        return ['maxBytes' => $this->maximumBytes, 'reasons' => $this->reasons, 'nativeMaxBytes' => $this->nativeMaximumBytes,
            'requestTimeoutSeconds' => $this->requestTimeoutSeconds, 'partBytes' => 5 * 1024 * 1024, 'sessionTtlSeconds' => 86400];
    }
}
