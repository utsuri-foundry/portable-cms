<?php

declare(strict_types=1);

// PHP's fixture server does not interpret the compiled Apache configuration.
$policy = file_get_contents($_SERVER['DOCUMENT_ROOT'] . '/delivery/apache24.conf');
if (!is_string($policy) || preg_match('/Header always set Content-Security-Policy "([^"\r\n]+)"/', $policy, $match) !== 1) {
    http_response_code(500);
    exit;
}
header('Content-Security-Policy: ' . $match[1]);
header('Referrer-Policy: strict-origin-when-cross-origin');
return false;
