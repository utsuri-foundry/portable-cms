<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\HostIo;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/engine/src/cms/conventional/bootstrap.php';
$options = getopt('', ['config-path:', 'gate-output:', 'token-output:']);
try {
    foreach (['config-path', 'gate-output', 'token-output'] as $key) {
        if (!is_string($options[$key] ?? null) || $options[$key] === '') {
            throw new InvalidArgumentException('Private paths required');
        }
    }
    if (!str_starts_with($options['config-path'], '/') || $options['gate-output'] === $options['token-output']) {
        throw new InvalidArgumentException('Distinct outputs and absolute installed configuration required');
    }
    $token = bin2hex(random_bytes(32));
    $document = ['status' => 'open', 'expiresAt' => gmdate('Y-m-d\TH:i:s\Z', time() + 3_600), 'tokenHash' => hash('sha256', $token), 'runtimeConfig' => $options['config-path']];
    HostIo::createPrivateFile($options['gate-output'], json_encode($document, JSON_THROW_ON_ERROR) . "\n");
    HostIo::createPrivateFile($options['token-output'], $token . "\n");
    fwrite(STDOUT, "Created owner-only one-hour recovery gate and token file. Upload privately and remove after recovery.\n");
} catch (Throwable $error) {
    fwrite(STDERR, 'Recovery gate creation failed (' . $error::class . ").\n");
    exit(1);
}
