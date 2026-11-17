<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Preview;

use Utsuri\PortableCms\Conventional\Identity\RandomSource;

/**
 * A share link's secret, kept so that the link can be copied again later. The database holds
 * only the secret's hash for authorization and this sealed copy, encrypted with a key derived
 * from the installation's own configuration secret. A database copy alone does not reveal a
 * link; a host without the cipher keeps hashed links that simply cannot be copied again.
 */
final class LinkSecret
{
    private const CIPHER = 'aes-256-gcm';

    private readonly string $key;

    public function __construct(string $installationSecret, private readonly RandomSource $random)
    {
        if (strlen($installationSecret) < 32) {
            throw new \InvalidArgumentException('Link secret key material must contain at least 32 bytes');
        }
        $this->key = hash_hkdf('sha256', $installationSecret, 32, 'portable-cms preview link secret');
    }

    public function seal(string $secret): ?string
    {
        if (!self::available()) {
            return null;
        }
        $nonce = $this->random->bytes(12);
        $tag = '';
        $sealed = openssl_encrypt($secret, self::CIPHER, $this->key, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        return $sealed === false ? null : base64_encode($nonce . $tag . $sealed);
    }

    /** The 64-character secret, or null when the sealed copy is missing, foreign or unreadable here. */
    public function open(?string $sealed): ?string
    {
        if ($sealed === null || !self::available()) {
            return null;
        }
        $raw = base64_decode($sealed, true);
        if ($raw === false || strlen($raw) <= 28) {
            return null;
        }
        $secret = openssl_decrypt(substr($raw, 28), self::CIPHER, $this->key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        return $secret !== false && preg_match('/\A[a-f0-9]{64}\z/', $secret) === 1 ? $secret : null;
    }

    public static function available(): bool
    {
        return function_exists('openssl_encrypt') && in_array(self::CIPHER, openssl_get_cipher_methods(), true);
    }
}
