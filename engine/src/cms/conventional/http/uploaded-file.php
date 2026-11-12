<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Http;

final readonly class UploadedFile
{
    public function __construct(public string $name, public string $mime, public int $byteSize, private RequestBody $body, public int $error = UPLOAD_ERR_OK) {}

    /** @return resource */
    public function open(): mixed
    {
        return $this->body->open();
    }

    /**
     * @param array<string, mixed> $files
     * @return list<self>
     */
    public static function fromGlobals(array $files, string $name = 'images'): array
    {
        $batch = $files[$name] ?? null;
        if (!is_array($batch) || !is_array($batch['name'] ?? null) || count($batch['name']) > 8) {
            return [];
        }
        $result = [];
        foreach ($batch['name'] as $index => $name) {
            $path = $batch['tmp_name'][$index] ?? null;
            $mime = $batch['type'][$index] ?? null;
            $size = $batch['size'][$index] ?? null;
            $error = $batch['error'][$index] ?? null;
            if (!is_string($name) || !is_string($mime) || !is_int($size) || $size < 0 || !is_int($error)) {
                throw new InvalidRequestBody('The native upload could not be accepted.');
            }
            if ($error === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            // Expected PHP transfer failures must reach the form adapter, preserving other input.
            if ($error !== UPLOAD_ERR_OK) {
                $result[] = new self($name, $mime, $size, RequestBody::fromString(''), $error);
                continue;
            }
            if (!is_string($path) || !is_uploaded_file($path)) {
                throw new InvalidRequestBody('The native upload could not be accepted.');
            }
            $result[] = new self($name, $mime, $size, RequestBody::fromUploadedPath($path));
        }
        return $result;
    }
}
