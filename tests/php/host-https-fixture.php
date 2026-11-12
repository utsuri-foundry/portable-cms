<?php

declare(strict_types=1);

// Test-only loopback TLS server; no customer host or external network is involved.
$pem = $argv[1] ?? '';
if (PHP_SAPI !== 'cli' || !str_starts_with($pem, sys_get_temp_dir() . '/portable-cms-host-io-') || !is_file($pem)) {
    exit(64);
}
$context = stream_context_create(['ssl' => ['local_cert' => $pem]]);
$server = stream_socket_server('tls://127.0.0.1:0', $number, $message, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
if (!is_resource($server)) {
    throw new RuntimeException('Loopback TLS server could not start');
}
fwrite(STDOUT, stream_socket_get_name($server, false) . "\n");
fflush(STDOUT);
try {
    for ($attempt = 0; $attempt < 3; $attempt++) {
        $stream = stream_socket_accept($server, 15);
        if (!is_resource($stream)) {
            throw new RuntimeException('Loopback TLS request was not received');
        }
        try {
            stream_set_timeout($stream, 5);
            $first = fgets($stream);
            $size = 0;
            for ($line = 0; $line < 32; $line++) {
                $header = fgets($stream);
                if (!is_string($header)) {
                    throw new RuntimeException('Loopback request headers are missing');
                }
                if (trim($header) === '') {
                    break;
                }
                if (preg_match('/\AContent-Length: (\d+)/i', $header, $matches) === 1) {
                    $size = (int) $matches[1];
                }
            }
            if ($size > 4096) {
                throw new RuntimeException('Loopback request exceeds bounds');
            }
            while ($size > 0) {
                $body = fread($stream, $size);
                if (!is_string($body) || $body === '') {
                    throw new RuntimeException('Loopback request body is incomplete');
                }
                $size -= strlen($body);
            }
            $body = is_string($first) && str_contains($first, '/install')
                ? '{"status":"installed"}'
                : (is_string($first) && str_contains($first, '/upgrade') ? '{"status":"upgraded","releaseId":"verified-fixture"}' : '{"status":"migrated","migrationsApplied":1,"migrationsSkipped":2}');
            $response = "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nConnection: close\r\nContent-Length: " . strlen($body) . "\r\n\r\n" . $body;
            if (fwrite($stream, $response) !== strlen($response)) {
                throw new RuntimeException('Loopback response could not be written');
            }
        } finally {
            fclose($stream);
        }
    }
} finally {
    fclose($server);
}
