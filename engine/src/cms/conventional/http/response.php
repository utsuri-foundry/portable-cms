<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Http;

final class Response
{
    /** @var array<string, list<string>> */
    private array $headers = [];
    private ?\Utsuri\PortableCms\Conventional\Media\MediaDelivery $fileBody = null;
    private int $fileStart = 0;
    private int $fileLength = 0;
    private bool $isolated = false;
    private bool $isolatedPopups = true;
    private ?string $vimeoScriptHash = null;
    private bool $vimeoWorkspace = false;

    public function __construct(
        public readonly int $status,
        public readonly string $body = '',
        public readonly ?string $styleHash = null,
        public readonly bool $sameOriginFrame = false,
    ) {
        if ($styleHash !== null && preg_match('/\A[A-Za-z0-9+\/]{43}=\z/', $styleHash) !== 1) {
            throw new \InvalidArgumentException('Invalid style digest');
        }
    }

    public function withBody(string $body): self
    {
        $copy = new self($this->status, $body, $this->styleHash, $this->sameOriginFrame);
        $copy->headers = $this->headers;
        $copy->isolated = $this->isolated;
        $copy->isolatedPopups = $this->isolatedPopups;
        $copy->vimeoScriptHash = $this->vimeoScriptHash;
        $copy->vimeoWorkspace = $this->vimeoWorkspace;
        return $copy;
    }

    /** A private preview document or asset: public site policy in an opaque-origin sandbox, framed only by the CMS. */
    public function asIsolated(bool $allowPopups = true): self
    {
        $copy = clone $this;
        $copy->isolated = true;
        $copy->isolatedPopups = $allowPopups;
        return $copy;
    }

    public function isolatedPopups(): bool
    {
        return $this->isolatedPopups;
    }

    /** Only the validated frozen artifact/live document may grant this frame permission. */
    public function withVimeoFrame(string $scriptHash): self
    {
        if (preg_match('/\A[A-Za-z0-9+\/]{43}=\z/', $scriptHash) !== 1) {
            throw new \InvalidArgumentException('Invalid Vimeo script digest');
        }
        $copy = clone $this;
        $copy->vimeoScriptHash = $scriptHash;
        return $copy;
    }

    public function vimeoFrame(): bool
    {
        return $this->vimeoScriptHash !== null;
    }

    /** The trusted administration hosts the player beside the opaque live page. */
    public function withVimeoWorkspace(): self
    {
        $copy = clone $this;
        $copy->vimeoWorkspace = true;
        return $copy;
    }

    public function vimeoWorkspace(): bool
    {
        return $this->vimeoWorkspace;
    }

    public function vimeoScriptHash(): ?string
    {
        return $this->vimeoScriptHash;
    }

    public function isolated(): bool
    {
        return $this->isolated;
    }

    public function withHeader(string $name, string $value): self
    {
        $copy = clone $this;
        $copy->headers[strtolower($name)] = [$value];
        return $copy;
    }

    public function withAddedHeader(string $name, string $value): self
    {
        $copy = clone $this;
        $key = strtolower($name);
        $copy->headers[$key] ??= [];
        $copy->headers[$key][] = $value;
        return $copy;
    }

    public function withCookie(Cookie $cookie): self
    {
        return $this->withAddedHeader('set-cookie', $cookie->serialize());
    }

    /** @return array<string, list<string>> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function hasHeader(string $name): bool
    {
        return isset($this->headers[strtolower($name)]);
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $values) {
            foreach ($values as $index => $value) {
                header($name . ': ' . $value, $index === 0);
            }
        }
        $output = \Utsuri\PortableCms\Conventional\HostIo::attempt(fn() => fopen('php://output', 'wb'))->value;
        if (!is_resource($output)) {
            throw new \RuntimeException('Response output is unavailable');
        }
        try {
            $this->writeBodyTo($output);
        } finally {
            fclose($output);
        }
    }

    /** @param resource $output */
    public function writeBodyTo(mixed $output): void
    {
        if ($this->fileBody !== null) {
            $this->fileBody->writeTo($output, $this->fileStart, $this->fileLength);
        } else {
            \Utsuri\PortableCms\Conventional\HostIo::required(fn() => fwrite($output, $this->body));
        }
    }

    public static function file(\Utsuri\PortableCms\Conventional\Media\MediaDelivery $body, string $mime, ?string $range = null, bool $sameOriginFrame = false): self
    {
        $length = $body->size();
        $start = 0;
        $end = $length - 1;
        if ($range !== null) {
            if (preg_match('/\Abytes=([0-9]{1,10})-([0-9]{0,10})\z/', $range, $matches) !== 1
                || (int) $matches[1] >= $length || ($matches[2] !== '' && (int) $matches[2] < (int) $matches[1])) {
                return (new self(416))->withHeader('content-range', 'bytes */' . $length);
            }
            $start = (int) $matches[1];
            $end = $matches[2] === '' ? $end : min($end, (int) $matches[2]);
        }
        $response = new self($range === null ? 200 : 206, sameOriginFrame: $sameOriginFrame);
        $response->fileBody = $body;
        $response->fileStart = $start;
        $response->fileLength = $end - $start + 1;
        $response = $response->withHeader('content-type', $mime)->withHeader('accept-ranges', 'bytes')->withHeader('content-length', (string) $response->fileLength);
        return $range === null ? $response : $response->withHeader('content-range', 'bytes ' . $start . '-' . $end . '/' . $length);
    }

    public function emit(): never
    {
        $this->send();
        exit;
    }

    public static function redirect(string $location, int $status = 303): self
    {
        return (new self($status))->withHeader('location', $location);
    }

    /** @param array<string, mixed> $value */
    public static function json(array $value, int $status = 200): self
    {
        return (new self(
            $status,
            json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ))->withHeader('content-type', 'application/json; charset=utf-8');
    }
}

final readonly class Cookie
{
    public function __construct(
        public string $name,
        public string $value,
        public int $maxAge,
        public bool $httpOnly,
        public string $sameSite,
    ) {
        if (!preg_match('/\A__Host-[A-Za-z0-9_-]+\z/', $name)) {
            throw new \InvalidArgumentException('Cookie name must use the __Host- prefix');
        }
        if (!preg_match('/\A[A-Za-z0-9_-]*\z/', $value)) {
            throw new \InvalidArgumentException('Cookie value is not an opaque base64url token');
        }
        if (!in_array($sameSite, ['Lax', 'Strict'], true)) {
            throw new \InvalidArgumentException('Unsupported SameSite policy');
        }
    }

    public function serialize(): string
    {
        $parts = [
            $this->name . '=' . $this->value,
            'Path=/',
            'Max-Age=' . max(0, $this->maxAge),
            'Secure',
            'SameSite=' . $this->sameSite,
        ];
        if ($this->httpOnly) {
            $parts[] = 'HttpOnly';
        }
        return implode('; ', $parts);
    }
}
