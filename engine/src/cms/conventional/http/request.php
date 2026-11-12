<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Http;

final readonly class Request
{
    /**
     * @param array<string, string> $headers
     * @param array<string, string|list<string>> $form
     * @param array<string, string> $cookies
     * @param array<string, string> $query
     * @param list<UploadedFile> $files
     */
    public function __construct(
        public string $method,
        public string $path,
        public array $headers = [],
        public array $form = [],
        public array $cookies = [],
        public string $remoteAddress = '',
        private ?RequestBody $body = null,
        public array $query = [],
        public array $files = [],
        public ?UploadedFont $upload = null,
    ) {}

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function formValue(string $name): string
    {
        $value = $this->form[$name] ?? '';
        return is_string($value) ? $value : '';
    }

    /** @return list<string> */
    public function formValues(string $name): array
    {
        $value = $this->form[$name] ?? [];
        return is_array($value) ? $value : ($value === '' ? [] : [$value]);
    }

    public function cookie(string $name): ?string
    {
        return $this->cookies[$name] ?? null;
    }

    public function queryValue(string $name): string
    {
        $value = $this->query[$name] ?? '';
        return is_string($value) && strlen($value) <= 400 ? $value : '';
    }

    /** @return array<string, mixed> */
    public function jsonBody(int $maximumBytes = 65_536): array
    {
        $stream = $this->bodyStream();
        $value = stream_get_contents($stream, $maximumBytes + 1);
        fclose($stream);
        if (!is_string($value) || strlen($value) > $maximumBytes) {
            throw new RequestBodyTooLarge();
        }
        try {
            $decoded = json_decode($value, true, 16, JSON_THROW_ON_ERROR);
        } catch (\Throwable $error) {
            throw new InvalidJsonBody(previous: $error);
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new InvalidJsonBody();
        }
        return $decoded;
    }

    /** @return resource */
    public function bodyStream(): mixed
    {
        if ($this->body === null) {
            throw new InvalidRequestBody();
        }
        return $this->body->open();
    }

    public static function fromGlobals(int $maxBodyBytes = 16_384, int $maxPutBodyBytes = 16_384): self
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $contentLength = filter_var(
            $_SERVER['CONTENT_LENGTH'] ?? null,
            FILTER_VALIDATE_INT,
            FILTER_NULL_ON_FAILURE,
        );
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $websiteUpload = $method === 'POST' && parse_url($uri, PHP_URL_PATH) === '/settings/publication'
            && in_array($_POST['_website_upload'] ?? null, ['iconMediaId', 'adminLogoMediaId', 'darkAdminLogoMediaId'], true);
        $uploadField = $websiteUpload ? ($_POST['_website_upload'] ?? null) : ($_POST['_upload_field'] ?? null);
        $nativeUpload = $method === 'POST' && (parse_url($uri, PHP_URL_PATH) === '/media/upload'
            || $websiteUpload || preg_match('#\A/records/[a-z][a-z0-9_-]*/[a-f0-9]{32}/upload(?:\?.*)?\z#', $uri) === 1);
        $designUpload = $method === 'POST' && parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) === '/settings/design';
        $descriptions = $method === 'POST' && parse_url($uri, PHP_URL_PATH) === '/media/descriptions';
        $videoFrame = $method === 'POST' && preg_match('#\A/media/[a-f0-9]{32}/video-frame\z#', (string) parse_url($uri, PHP_URL_PATH)) === 1;
        $bodyLimit = $videoFrame ? \Utsuri\PortableCms\Conventional\Media\VideoFrame::REQUEST_BYTES : ($nativeUpload ? 335_609_856 : ($method === 'PUT' ? $maxPutBodyBytes : ($designUpload || $descriptions ? 2_097_152 + 524_288 : $maxBodyBytes)));
        if (is_int($contentLength) && $contentLength > $bodyLimit) {
            throw new RequestBodyTooLarge();
        }

        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);
        if (!is_string($path) || $path === '' || !str_starts_with($path, '/')) {
            $path = '/';
        }

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (!is_string($value)) {
                continue;
            }
            if (str_starts_with($key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = $value;
            }
        }
        foreach (['CONTENT_TYPE' => 'content-type', 'CONTENT_LENGTH' => 'content-length'] as $key => $name) {
            if (isset($_SERVER[$key]) && is_string($_SERVER[$key])) {
                $headers[$name] = $_SERVER[$key];
            }
        }

        return new self(
            method: $method,
            path: $path,
            headers: $headers,
            form: self::formMap($_POST),
            cookies: self::stringMap($_COOKIE),
            remoteAddress: is_string($_SERVER['REMOTE_ADDR'] ?? null)
                ? $_SERVER['REMOTE_ADDR']
                : '',
            body: RequestBody::fromPhpInput(),
            query: self::stringMap($_GET),
            files: $nativeUpload ? UploadedFile::fromGlobals($_FILES, is_string($uploadField) && \Utsuri\PortableCms\Conventional\Content\ProfileManifest::fieldId($uploadField) ? 'images_' . $uploadField : 'images') : [],
            upload: UploadedFont::fromGlobals(),
        );
    }

    /**
     * @param array<array-key, mixed> $input
     * @return array<string, string>
     */
    private static function stringMap(array $input): array
    {
        $result = [];
        foreach ($input as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $result[$key] = $value;
            }
        }
        return $result;
    }

    /**
     * @param array<array-key, mixed> $input
     * @return array<string, string|list<string>>
     */
    private static function formMap(array $input): array
    {
        $result = [];
        foreach ($input as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            if (is_string($value)) {
                $result[$key] = $value;
                continue;
            }
            if (!is_array($value) || count($value) > 100) {
                continue;
            }
            $items = [];
            foreach ($value as $item) {
                if (!is_string($item)) {
                    $items = [];
                    break;
                }
                $items[] = $item;
            }
            if ($items !== []) {
                $result[$key] = $items;
            }
        }
        return $result;
    }
}

final class RequestBody
{
    private bool $opened = false;

    private function __construct(private readonly \Closure $opener) {}

    public static function fromString(string $value): self
    {
        return new self(static function () use ($value): mixed {
            $stream = fopen('php://temp', 'w+b');
            if (!is_resource($stream) || fwrite($stream, $value) !== strlen($value)) {
                throw new \RuntimeException('Request body stream could not be created');
            }
            rewind($stream);
            return $stream;
        });
    }

    public static function fromPhpInput(): self
    {
        return new self(static fn(): mixed => fopen('php://input', 'rb'));
    }

    public static function fromUploadedPath(string $path): self
    {
        if (!is_uploaded_file($path)) {
            throw new InvalidRequestBody();
        }
        return new self(static fn(): mixed => fopen($path, 'rb'));
    }

    /** @return resource */
    public function open(): mixed
    {
        if ($this->opened) {
            throw new InvalidRequestBody('Request body was already consumed');
        }
        $this->opened = true;
        $stream = ($this->opener)();
        if (!is_resource($stream)) {
            throw new InvalidRequestBody();
        }
        return $stream;
    }
}

final class RequestBodyTooLarge extends \RuntimeException {}

final class InvalidRequestBody extends \RuntimeException {}

final class InvalidJsonBody extends \RuntimeException {}

final class UploadedFont
{
    private function __construct(private readonly \Closure $reader) {}
    public static function fromString(string $bytes): self
    {
        return new self(static fn(): string => $bytes);
    }
    public static function fromGlobals(): ?self
    {
        $file = $_FILES['font_file'] ?? null;
        if (!is_array($file) || ($file['error'] ?? null) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        return new self(static function () use ($file): string {
            if (($file['error'] ?? null) !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null)
                || !is_uploaded_file($file['tmp_name']) || !is_int($file['size'] ?? null) || $file['size'] > 2_097_152) {
                throw new \InvalidArgumentException('The font upload failed or exceeded 2 MB. Choose the file again.');
            }
            $bytes = file_get_contents($file['tmp_name'], false, null, 0, 2_097_153);
            if (!is_string($bytes) || strlen($bytes) > 2_097_152) {
                throw new \InvalidArgumentException('The font upload exceeds 2 MB.');
            }
            return $bytes;
        });
    }
    public function bytes(): string
    {
        return ($this->reader)();
    }
}
