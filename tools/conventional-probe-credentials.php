<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$token = bin2hex(random_bytes(32));
$result = [
    'token' => $token,
    'tokenHash' => password_hash($token, PASSWORD_DEFAULT),
    'expiresAt' => gmdate('Y-m-d\TH:i:s\Z', time() + 3600),
];

fwrite(
    STDOUT,
    json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
);
