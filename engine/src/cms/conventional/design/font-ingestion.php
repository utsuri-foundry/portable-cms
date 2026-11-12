<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Design;

use Utsuri\PortableCms\Conventional\I18n\LocalizedFailure;
use Utsuri\PortableCms\Conventional\I18n\Message;
use Utsuri\PortableCms\Conventional\I18n\Translator;

interface FontSanitizer
{
    public function sanitize(string $woff2): string;
}

/** Font processing is not qualified or available on this host. */
final class FontUnavailable extends \RuntimeException implements LocalizedFailure
{
    private readonly Message $text;

    public function __construct(string $key)
    {
        $this->text = new Message($key);
        parent::__construct(Translator::english()->message($this->text));
    }

    public function text(): Message
    {
        return $this->text;
    }
}

/** The submitted font or its facts cannot be accepted. */
final class FontRejected extends \InvalidArgumentException implements LocalizedFailure
{
    private readonly Message $text;

    public function __construct(string $key)
    {
        $this->text = new Message($key);
        parent::__construct(Translator::english()->message($this->text));
    }

    public function text(): Message
    {
        return $this->text;
    }
}

final class Woff2
{
    public static function verify(string $bytes, bool $sanitized = false): void
    {
        if (strlen($bytes) < 48 || strlen($bytes) > 2_097_152 || substr($bytes, 0, 4) !== 'wOF2') {
            throw new FontRejected('design.font_error.upload_a_woff2_font_of_at');
        }
        $header = unpack('Nflavor/Nlength/ntables/nreserved/Nsfnt/Ncompressed/nmajor/nminor/NmetaOffset/NmetaLength/NmetaOriginal/NprivateOffset/NprivateLength', substr($bytes, 4, 44));
        if (!is_array($header) || !in_array($header['flavor'], [0x00010000, 0x4f54544f], true)
            || $header['length'] !== strlen($bytes) || $header['tables'] < 1 || $header['tables'] > 128
            || $header['reserved'] !== 0 || $header['sfnt'] < 12 || $header['sfnt'] > 8_388_608
            || $header['compressed'] < 1 || $header['compressed'] > strlen($bytes) - 48) {
            throw new FontRejected('design.font_error.the_woff2_header_is_invalid_or');
        }
        foreach (['meta', 'private'] as $section) {
            $offset = $header[$section . 'Offset'];
            $length = $header[$section . 'Length'];
            if (($offset === 0) !== ($length === 0) || ($offset !== 0 && ($offset < 48 || $offset + $length > strlen($bytes)))
                || ($sanitized && ($offset !== 0 || $length !== 0))) {
                throw new FontRejected('design.font_error.font_metadata_or_private_blocks_are');
            }
        }
        if ($sanitized && $header['metaOriginal'] !== 0) {
            throw new FontRejected('design.font_error.sanitized_font_contains_extended_metadata');
        }
    }
}

interface FontStore
{
    public function put(string $bytes): string;
    public function file(string $checksum, int $byteSize): string;
}


/** @phpstan-import-type FontData from RegistryDocument */
final class FontIngestion
{
    public function __construct(private readonly ?FontSanitizer $sanitizer, private readonly FontStore $store) {}
    public function available(): bool
    {
        return $this->sanitizer !== null;
    }

    /** Immutable checksum-verified bytes for an authenticated preview of a registry font. */
    public function previewBytes(string $checksum, int $byteSize): string
    {
        $bytes = file_get_contents($this->store->file($checksum, $byteSize));
        if (!is_string($bytes)) {
            throw new \RuntimeException('Preview font is unavailable');
        }
        return $bytes;
    }

    /** @return FontData */
    public function ingest(string $bytes, string $id, string $label, string $provenance, string $license): array
    {
        if ($this->sanitizer === null) {
            throw new FontUnavailable('design.font_error.font_upload_is_disabled_because_verified');
        }
        if (!RegistryDocument::id($id) || !RegistryDocument::text($label)
            || !RegistryDocument::text($provenance, 2000, true) || !RegistryDocument::text($license, 2000, true)) {
            throw new FontRejected('design.font_error.enter_a_stable_font_id_a');
        }
        Woff2::verify($bytes);
        $sanitized = $this->sanitizer->sanitize($bytes);
        Woff2::verify($sanitized, true);
        $checksum = $this->store->put($sanitized);
        return ['id' => $id, 'label' => trim($label), 'origin' => 'upload', 'sha256' => $checksum,
            'byteSize' => strlen($sanitized), 'sourceSha256' => hash('sha256', $bytes),
            'provenance' => trim($provenance), 'license' => trim($license)];
    }
}
