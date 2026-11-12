<?php

declare(strict_types=1);

/** A real, isolated PHP-FPM pool and bounded FastCGI client for the installed web entry point. */
final class FastCgiPreviewFixture
{
    /** @var resource|null */
    private mixed $process = null;
    /** @var array<string, string> */
    private array $cookies = [];

    public function __construct(private readonly string $root, string $configuration)
    {
        $prepend = '<?php register_shutdown_function(static function (): void {'
            . '$id = $_SERVER["HTTP_X_TEST_REQUEST"] ?? "";'
            . 'if (preg_match("/\\A[a-z0-9-]{1,40}\\z/", $id) === 1) {'
            . 'file_put_contents(' . var_export($root . '/finished-', true) . ' . $id, "finished");}});';
        file_put_contents($root . '/request-finished.php', $prepend);
        $user = posix_getpwuid(posix_geteuid());
        $group = posix_getgrgid(posix_getegid());
        if (!is_array($user) || !is_array($group)) {
            throw new RuntimeException('The FastCGI fixture requires the current Unix identity');
        }
        $pool = "[global]\ndaemonize = no\nerror_log = $root/fpm.log\n"
            . "[portable]\nuser = {$user['name']}\ngroup = {$group['name']}\n"
            . "listen = $root/fpm.sock\npm = static\npm.max_children = 2\n"
            . "env[CMS_CONFIG] = $configuration\n"
            . "php_admin_value[auto_prepend_file] = $root/request-finished.php\n";
        file_put_contents($root . '/fpm.conf', $pool);
        $this->process = proc_open(['php-fpm', '-F', '-y', $root . '/fpm.conf'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', $root . '/fpm.stdout', 'w'], 2 => ['file', $root . '/fpm.stderr', 'w']], $pipes);
        if (!is_resource($this->process)) {
            throw new RuntimeException('Pinned PHP-FPM is required for the preview wake proof');
        }
        try {
            $this->wait(static fn(): bool => file_exists($root . '/fpm.sock'), 'PHP-FPM did not start');
        } catch (Throwable $error) {
            $this->stop();
            throw $error;
        }
    }

    /** @param callable(): bool $ready */
    public function wait(callable $ready, string $failure): void
    {
        $deadline = hrtime(true) + 10_000_000_000;
        do {
            clearstatcache();
            if ($ready()) {
                return;
            }
            if (!is_resource($this->process) || !proc_get_status($this->process)['running']) {
                break;
            }
            usleep(20_000);
        } while (hrtime(true) < $deadline);
        throw new RuntimeException($failure . ': ' . (string) file_get_contents($this->root . '/fpm.stderr'));
    }

    public function finished(string $id): bool
    {
        clearstatcache();
        return is_file($this->root . '/finished-' . $id);
    }

    /**
     * FCGI_END_REQUEST marks the real fastcgi_finish_request boundary, not worker completion.
     * @param array<string, string> $form
     * @return array{status: int, headers: array<string, list<string>>, body: string}
     */
    public function request(string $method, string $path, array $form = [], string $id = 'request'): array
    {
        $socket = stream_socket_client('unix://' . $this->root . '/fpm.sock', $errorNumber, $errorMessage, 1);
        if (!is_resource($socket)) {
            throw new RuntimeException('FastCGI connection failed: ' . $errorMessage);
        }
        stream_set_timeout($socket, 10);
        $body = http_build_query($form);
        $parameters = [
            'SCRIPT_FILENAME' => dirname(__DIR__, 2) . '/tools/conventional-cms-web.php',
            'SCRIPT_NAME' => '/index.php', 'DOCUMENT_ROOT' => $this->root . '/public',
            'REQUEST_METHOD' => $method, 'REQUEST_URI' => $path,
            'QUERY_STRING' => (string) (parse_url($path, PHP_URL_QUERY) ?? ''),
            'SERVER_PROTOCOL' => 'HTTP/1.1', 'GATEWAY_INTERFACE' => 'CGI/1.1',
            'SERVER_NAME' => 'localhost', 'HTTP_HOST' => 'localhost:4180', 'SERVER_PORT' => '4180',
            'REMOTE_ADDR' => '127.0.0.1', 'HTTP_ORIGIN' => 'http://localhost:4180',
            'HTTP_X_TEST_REQUEST' => $id, 'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
            'CONTENT_LENGTH' => (string) strlen($body),
            'HTTP_COOKIE' => implode('; ', array_map(static fn(string $key, string $value): string => $key . '=' . $value, array_keys($this->cookies), $this->cookies)),
        ];
        $encoded = '';
        foreach ($parameters as $name => $value) {
            $encoded .= self::length(strlen($name)) . self::length(strlen($value)) . $name . $value;
        }
        try {
            self::write($socket, 1, pack('nCxxxxx', 1, 0));
            self::write($socket, 4, $encoded);
            self::write($socket, 4, '');
            self::write($socket, 5, $body);
            self::write($socket, 5, '');
            $output = '';
            do {
                $header = unpack('Cversion/Ctype/nrequest/nlength/Cpadding/Creserved', self::read($socket, 8));
                if (!is_array($header) || $header['version'] !== 1 || $header['request'] !== 1) {
                    throw new RuntimeException('Invalid FastCGI response');
                }
                $content = self::read($socket, $header['length']);
                self::read($socket, $header['padding']);
                if ($header['type'] === 6) {
                    $output .= $content;
                }
                if (strlen($output) > 4_194_304) {
                    throw new RuntimeException('FastCGI test response exceeded its bound');
                }
            } while ($header['type'] !== 3);
        } finally {
            fclose($socket);
        }
        $parts = explode("\r\n\r\n", $output, 2);
        $headers = [];
        foreach (explode("\r\n", $parts[0]) as $line) {
            $pair = explode(':', $line, 2);
            if (count($pair) === 2) {
                $headers[strtolower($pair[0])][] = trim($pair[1]);
            }
        }
        foreach ($headers['set-cookie'] ?? [] as $cookie) {
            $pair = explode('=', explode(';', $cookie, 2)[0], 2);
            $this->cookies[$pair[0]] = $pair[1];
        }
        return ['status' => isset($headers['status']) ? (int) $headers['status'][0] : 200, 'headers' => $headers, 'body' => $parts[1] ?? ''];
    }

    public function csrf(): string
    {
        return $this->cookies[\Utsuri\PortableCms\Conventional\Identity\IdentityService::CSRF_COOKIE] ?? throw new RuntimeException('FastCGI sign-in did not issue CSRF');
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
            $this->process = null;
        }
    }

    private static function length(int $length): string
    {
        return $length < 128 ? chr($length) : pack('N', $length | 0x80000000);
    }

    /** @param resource $socket */
    private static function write(mixed $socket, int $type, string $content): void
    {
        $bytes = pack('CCnnCC', 1, $type, 1, strlen($content), 0, 0) . $content;
        while ($bytes !== '') {
            $written = fwrite($socket, $bytes);
            if ($written === false || $written === 0) {
                throw new RuntimeException('FastCGI request could not be written');
            }
            $bytes = substr($bytes, $written);
        }
    }

    /** @param resource $socket */
    private static function read(mixed $socket, int $length): string
    {
        $result = '';
        while (strlen($result) < $length) {
            $bytes = fread($socket, $length - strlen($result));
            if ($bytes === false || $bytes === '') {
                throw new RuntimeException('FastCGI response did not finish within its deadline');
            }
            $result .= $bytes;
        }
        return $result;
    }
}
