<?php

declare(strict_types=1);

/** Isolated conventional delivery smoke test, never a deployment or filesystem authority. */
final class PublicationApacheServer
{
    /** @var resource */
    private $process;

    public readonly string $origin;

    public function __construct(string $state, string $publicRoot)
    {
        $binary = null;
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $directory) {
            if (is_executable($directory . '/httpd')) {
                $binary = realpath($directory . '/httpd');
                break;
            }
        }
        if (!is_string($binary)) {
            throw new RuntimeException('Pinned Apache is required');
        }
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        if (!is_resource($socket)) {
            throw new RuntimeException('A loopback port is required');
        }
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        if (!is_string($address)) {
            throw new RuntimeException('Loopback address unavailable');
        }
        $port = substr($address, strrpos($address, ':') + 1);
        $this->origin = 'http://127.0.0.1:' . $port;
        $installation = dirname(dirname($binary));
        $lines = ['ServerRoot "' . $state . '"', 'Listen 127.0.0.1:' . $port, 'ServerName 127.0.0.1', 'PidFile "' . $state . '/httpd.pid"', 'ErrorLog "' . $state . '/httpd-error.log"', 'LogLevel warn', 'KeepAlive Off'];
        foreach (['mpm_event', 'unixd', 'authz_core', 'alias', 'headers', 'dir', 'mime'] as $module) {
            $lines[] = 'LoadModule ' . $module . '_module "' . $installation . '/modules/mod_' . $module . '.so"';
        }
        $lines = [...$lines, 'User #' . posix_geteuid(), 'Group #' . posix_getegid(), 'TypesConfig "' . $installation . '/conf/mime.types"', 'DocumentRoot "' . $publicRoot . '"', '<Directory "' . $publicRoot . '">', 'Require all granted', 'Options -Indexes -MultiViews', 'AllowOverride FileInfo Indexes', '</Directory>'];
        file_put_contents($state . '/httpd.conf', implode("\n", $lines));
        $process = proc_open([$binary, '-X', '-f', $state . '/httpd.conf'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', $state . '/httpd-out.log', 'w'], 2 => ['file', $state . '/httpd-err.log', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Apache could not start');
        }
        $this->process = $process;
        for ($attempt = 0; $attempt < 100; ++$attempt) {
            $connection = \Utsuri\PortableCms\Conventional\HostIo::attempt(static fn() => stream_socket_client('tcp://127.0.0.1:' . $port, timeout: 0.05))->value;
            if (is_resource($connection)) {
                fclose($connection);
                return;
            }
            if (!proc_get_status($process)['running']) {
                $this->close();
                throw new RuntimeException('Apache exited before readiness');
            }
            usleep(20_000);
        }
        $this->close();
        throw new RuntimeException('Apache readiness timed out');
    }

    /** @return array{status: int, headers: array<string, string>, body: string} */
    public function get(string $path): array
    {
        $context = stream_context_create(['http' => ['timeout' => 3, 'follow_location' => 0, 'ignore_errors' => true, 'header' => "Connection: close\r\n"]]);
        $body = file_get_contents($this->origin . $path, false, $context);
        if (!is_string($body) || !isset($http_response_header[0]) || preg_match('/\AHTTP\/\S+ (\d{3})/', $http_response_header[0], $status) !== 1) {
            throw new RuntimeException('Public response unavailable');
        }
        $headers = [];
        foreach (array_slice($http_response_header, 1) as $line) {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
        }
        return ['status' => (int) $status[1], 'headers' => $headers, 'body' => $body];
    }

    public function verify(\Utsuri\PortableCms\Conventional\Publication\ReleaseArtifact $artifact): void
    {
        foreach ($artifact->publicPaths as $route) {
            $served = $this->get($route);
            assertSameValue(200, $served['status'], 'real target serves every approved route');
            $path = ltrim($route, '/') . 'index.html';
            $file = array_values(array_filter($artifact->files, static fn(array $file): bool => $file['path'] === $path))[0];
            assertSameValue($file['sha256'], hash('sha256', $served['body']), 'served page is exactly the approved byte set');
            assertSameValue('nosniff', $served['headers']['x-content-type-options'] ?? null, 'applied target rules set security headers');
        }
        foreach ($artifact->redirects as $redirect) {
            $served = $this->get($redirect->from);
            assertSameValue($redirect->status, $served['status'], 'actual service-installed rule issues a permanent redirect');
            assertSameValue($artifact->routes->context->route($redirect->to), $served['headers']['location'] ?? null, 'redirect reaches current destination without a chain');
        }
    }

    public function close(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
    }
}
