<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\HostIo;
use Utsuri\PortableCms\Conventional\HostIoFailure;
use Utsuri\PortableCms\Conventional\JsonSchemaValidator;
use Utsuri\PortableCms\Conventional\Media\FilesystemMediaStore;
use Utsuri\PortableCms\Conventional\Media\FilesystemUploadStorage;
use Utsuri\PortableCms\Conventional\Media\MediaContract;
use Utsuri\PortableCms\Conventional\Media\MediaJob;
use Utsuri\PortableCms\Conventional\Preview\FilesystemPreviewStore;
use Utsuri\PortableCms\Conventional\PrivateDiagnosticLog;

function hostIoFixture(): string
{
    $root = sys_get_temp_dir() . '/portable-cms-host-io-' . bin2hex(random_bytes(8));
    if (!mkdir($root, 0700)) {
        throw new RuntimeException('Could not create host operation fixture');
    }
    return $root;
}

function removeHostIoFixture(string $root): void
{
    if (!str_starts_with($root, sys_get_temp_dir() . '/portable-cms-host-io-') || is_link($root)) {
        throw new RuntimeException('Refusing to remove unexpected host fixture');
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $entry) {
        if ($entry->isDir() && !$entry->isLink()) {
            rmdir($entry->getPathname());
        } else {
            unlink($entry->getPathname());
        }
    }
    rmdir($root);
}

/** @template T
 * @param Closure(): T $operation
 * @return T
 */
function withoutHostWarning(Closure $operation): mixed
{
    $warnings = [];
    set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
        $warnings[] = $message;
        return true;
    });
    ob_start();
    try {
        return $operation();
    } finally {
        $output = ob_get_clean();
        restore_error_handler();
        assertSameValue([], $warnings, 'Host failures must not reach the previous warning handler');
        assertSameValue('', $output, 'Host failures must not emit output');
    }
}

/** @param list<string> $arguments
 * @return array{status: int, stdout: string, stderr: string}
 */
function runHostIoTool(string $tool, array $arguments, ?string $ca = null): array
{
    $command = [PHP_BINARY, '-d', 'display_errors=1'];
    if ($ca !== null) {
        array_push($command, '-d', 'openssl.cafile=' . $ca);
    }
    array_push($command, dirname(__DIR__, 2) . '/tools/' . $tool, ...$arguments);
    $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not run host tool test');
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['status' => proc_close($process), 'stdout' => $stdout === false ? '' : $stdout, 'stderr' => $stderr === false ? '' : $stderr];
}

$tests['host warning results are bounded redacted and distinguish native failure'] = static function (): void {
    $root = hostIoFixture();
    try {
        withoutHostWarning(static function () use ($root): void {
            $result = HostIo::attempt(static fn() => file_get_contents($root . '/credential-name-secret'));
            assertSameValue(false, $result->value, 'A failed read has no value');
            assertTrueValue($result->warningCount > 0, 'A warning is observed explicitly');
            assertTrueValue(!$result->succeeded(), 'A warning is never a successful operation');
            assertTrueValue(!str_contains(json_encode($result, JSON_THROW_ON_ERROR), $root), 'No raw path is retained');
            $nativeFalse = HostIo::attempt(static fn(): bool => false);
            assertSameValue(0, $nativeFalse->warningCount, 'Native false differs from a warning');
            assertTrueValue(!$nativeFalse->succeeded(), 'Native false also fails');
            assertSameValue(0, HostIo::attempt(static fn(): int => 0)->value, 'Zero is not coerced to false');
            try {
                HostIo::required(static fn() => chmod($root . '/credential-name-secret', 0600));
                throw new RuntimeException('Missing permission target was accepted');
            } catch (HostIoFailure $error) {
                assertSameValue('A required host operation failed', $error->getMessage(), 'Required failure is stable');
                assertSameValue(null, $error->getPrevious(), 'No raw warning is chained');
            }
        });
    } finally {
        removeHostIoFixture($root);
    }
};

$tests['host warning scopes restore nested handlers and propagate unexpected exceptions'] = static function (): void {
    $root = hostIoFixture();
    $warnings = 0;
    set_error_handler(static function () use (&$warnings): bool {
        $warnings++;
        return true;
    });
    try {
        $outer = HostIo::attempt(static function () use ($root): int {
            $inner = HostIo::attempt(static fn() => fopen($root . '/missing-inner', 'rb'));
            assertTrueValue($inner->warningCount > 0, 'Inner captures its own warning');
            file_get_contents($root . '/missing-outer');
            return 1;
        });
        assertSameValue(1, $outer->warningCount, 'Restored outer scope sees its own warning');
        $expected = new LogicException('Unexpected workflow bug');
        try {
            HostIo::attempt(static function () use ($expected): never {
                throw $expected;
            });
            throw new RuntimeException('Unexpected exception was swallowed');
        } catch (LogicException $error) {
            assertSameValue($expected, $error, 'Unexpected failures retain identity');
        }
        file_get_contents($root . '/missing-after-exception');
        assertSameValue(1, $warnings, 'Original handler is restored even after an exception');
        trigger_error('A non-I/O warning', E_USER_WARNING);
        assertSameValue(2, $warnings, 'User warnings are not captured as native I/O warnings');
    } finally {
        restore_error_handler();
        removeHostIoFixture($root);
    }
};

$tests['private file creation secures bytes and refuses existing paths without overwriting'] = static function (): void {
    $root = hostIoFixture();
    try {
        withoutHostWarning(static function () use ($root): void {
            $path = $root . '/private-config';
            HostIo::createPrivateFile($path, 'fictional-credential');
            assertSameValue('fictional-credential', file_get_contents($path), 'Exact private bytes are retained');
            assertSameValue(0600, fileperms($path) & 0777, 'Private creation enforces owner-only permissions');
            symlink($path, $root . '/link');
            foreach ([$path, $root . '/link', $root . '/missing-parent/config'] as $target) {
                $failed = false;
                try {
                    HostIo::createPrivateFile($target, 'must-not-overwrite');
                } catch (HostIoFailure) {
                    $failed = true;
                }
                assertTrueValue($failed, 'Unavailable or existing targets fail closed');
                assertSameValue('fictional-credential', file_get_contents($path), 'Failure cannot overwrite an existing or linked file');
            }
        });
    } finally {
        removeHostIoFixture($root);
    }
};

$tests['private reads and blocked upload targets fail without leaking host diagnostics'] = static function (): void {

    $root = hostIoFixture();
    try {
        withoutHostWarning(static function () use ($root): void {
            assertTrueValue(!MediaContract::signatureMatches($root . '/absent-password', 'image/jpeg'), 'Missing media fails closed');
            foreach ([static fn() => JsonSchemaValidator::validateFile($root . '/absent-password', $root . '/schema'), static fn() => ProfileManifest::load($root . '/absent-password')] as $read) {
                try {
                    $read();
                    throw new RuntimeException('Missing private input was accepted');
                } catch (InvalidArgumentException $error) {
                    assertTrueValue(!str_contains($error->getMessage(), $root), 'Validation errors contain no private path');
                }
            }
            $storage = new FilesystemUploadStorage($root . '/media');
            file_put_contents($root . '/media/uploads', 'blocked');
            $stream = fopen('php://memory', 'w+b');
            if (!is_resource($stream)) {
                throw new RuntimeException('Could not open upload fixture');
            }
            try {
                fwrite($stream, 'x');
                rewind($stream);
                $failed = false;
                try {
                    $storage->writePart(str_repeat('a', 32), 1, $stream, 1);
                } catch (RuntimeException $error) {
                    $failed = true;
                    assertTrueValue(!str_contains($error->getMessage(), $root), 'Blocked writes use safe errors');
                }
                assertTrueValue($failed, 'Blocked directory never reports upload success');
            } finally {
                fclose($stream);
            }
        });
    } finally {
        removeHostIoFixture($root);
    }
};

$tests['private roots reject symlinks before changing target permissions'] = static function (): void {
    $root = hostIoFixture();
    try {
        mkdir($root . '/outside', 0750);
        symlink($root . '/outside', $root . '/link');
        $before = fileperms($root . '/outside');
        foreach ([FilesystemUploadStorage::class, FilesystemPreviewStore::class] as $storage) {
            $failed = false;
            try {
                new $storage($root . '/link');
            } catch (RuntimeException) {
                $failed = true;
            }
            assertTrueValue($failed, 'Symbolic private roots are rejected');
            clearstatcache();
            assertSameValue($before, fileperms($root . '/outside'), 'Rejected roots cannot alter another target');
        }
    } finally {
        removeHostIoFixture($root);
    }
};

$tests['cleanup failure records one safe event without masking the primary failure'] = static function (): void {
    $root = hostIoFixture();
    $oldLog = ini_get('error_log');
    ini_set('error_log', $root . '/system.log');
    try {
        withoutHostWarning(static function () use ($root): void {
            $primary = new LogicException('Primary failure');
            try {
                try {
                    throw $primary;
                } finally {
                    assertSameValue(false, HostIo::cleanup(static fn() => unlink($root . '/private-token')), 'Missing cleanup fails explicitly');
                }
            } catch (LogicException $error) {
                assertSameValue($primary, $error, 'Cleanup preserves primary exception');
            }
            $log = file_get_contents($root . '/system.log');
            assertTrueValue(is_string($log) && substr_count($log, 'event=cleanup_failed') === 1, 'One bounded cleanup event is recorded');
            assertTrueValue(is_string($log) && !str_contains($log, $root) && !str_contains($log, 'private-token'), 'Diagnostic contains neither path nor private name');
            mkdir($root . '/blocked-log');
            ini_set('error_log', $root . '/blocked-log');
            assertSameValue(false, HostIo::cleanup(static fn(): bool => false), 'Failure remains bounded when system logging is also unavailable');
        });
    } finally {
        ini_set('error_log', $oldLog === false ? '' : $oldLog);
        removeHostIoFixture($root);
    }
};

$tests['private diagnostic logging secures permissions refuses links and stays nonblocking'] = static function (): void {
    $root = hostIoFixture();
    $oldLog = ini_get('error_log');
    ini_set('error_log', $root . '/system.log');
    try {
        withoutHostWarning(static function () use ($root): void {
            $path = $root . '/private.log';
            PrivateDiagnosticLog::append($path, "safe class-only event\n");
            clearstatcache();
            assertSameValue(0600, fileperms($path) & 0777, 'Private log is owner-only');
            assertSameValue("safe class-only event\n", file_get_contents($path), 'Entry is preserved');
            $stream = fopen($path, 'r+b');
            if (!is_resource($stream)) {
                throw new RuntimeException('Could not open diagnostic fixture');
            }
            try {
                flock($stream, LOCK_EX);
                PrivateDiagnosticLog::append($path, 'must not block');
                assertSameValue("safe class-only event\n", file_get_contents($path), 'Contended log never blocks or writes');
            } finally {
                flock($stream, LOCK_UN);
                fclose($stream);
            }
            symlink($path, $root . '/link.log');
            PrivateDiagnosticLog::append($root . '/link.log', 'must not follow');
            PrivateDiagnosticLog::append($root . '/missing-parent/private-name', 'must not leak');
            $log = file_get_contents($root . '/system.log');
            assertTrueValue(is_string($log) && substr_count($log, 'event=diagnostic_write_failed') === 3, 'Safe failures are counted');
            assertTrueValue(is_string($log) && !str_contains($log, $root) && !str_contains($log, 'must not'), 'No payload or path enters fallback logs');
        });
    } finally {
        ini_set('error_log', $oldLog === false ? '' : $oldLog);
        removeHostIoFixture($root);
    }
};

$tests['disabled system logging cannot replace an in-flight primary failure'] = static function (): void {
    $boundary = dirname(__DIR__, 2) . '/engine/src/cms/conventional/host-io.php';
    $script = 'require ' . var_export($boundary, true) . '; '
        . 'try { try { throw new LogicException("primary"); } '
        . 'finally { \\Utsuri\\PortableCms\\Conventional\\HostIo::cleanup(static fn(): bool => false); } } '
        . 'catch (LogicException $error) { fwrite(STDOUT, $error->getMessage()); }';
    $process = proc_open([PHP_BINARY, '-d', 'disable_functions=error_log', '-r', $script], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not run disabled log fixture');
    }
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    assertSameValue(0, proc_close($process), 'Disabled logging is not fatal');
    assertSameValue('primary', $output, 'Primary failure survives disabled logging');
    assertSameValue('', $errors, 'No logger error or private path leaks');
};

$tests['media cleanup does not traverse foreign targets and reports blocked cleanup'] = static function (): void {
    $root = hostIoFixture();
    $oldLog = ini_get('error_log');
    ini_set('error_log', $root . '/system.log');
    try {
        withoutHostWarning(static function () use ($root): void {
            $store = new FilesystemMediaStore($root);
            $job = new MediaJob(str_repeat('a', 32), 'running', [], 1, 3, str_repeat('b', 64), new DateTimeImmutable('2026-10-05T00:00:00Z'));
            $work = $store->workingDirectory($job);
            mkdir($work . '/foreign-directory');
            file_put_contents($root . '/keep', 'untouched');
            symlink($root . '/keep', $work . '/foreign-link');
            $store->removeWorkingDirectory($work);
            assertSameValue('untouched', file_get_contents($root . '/keep'), 'Cleanup never follows a foreign link');
            assertTrueValue(is_dir($work), 'Unowned nested content is left intact');
            $log = file_get_contents($root . '/system.log');
            assertTrueValue(is_string($log) && str_contains($log, 'event=cleanup_failed') && !str_contains($log, $root), 'Blocked removal is a safe diagnostic');
        });
    } finally {
        ini_set('error_log', $oldLog === false ? '' : $oldLog);
        removeHostIoFixture($root);
    }
};

$tests['installation clients report unavailable HTTPS without exposing credentials or warnings'] = static function (): void {
    $root = hostIoFixture();
    try {
        file_put_contents($root . '/token', str_repeat('a', 64));
        file_put_contents($root . '/password', 'fictional-password-secret');
        chmod($root . '/token', 0600);
        chmod($root . '/password', 0600);
        foreach ([
            ['conventional-install-client.php', ['--endpoint=https://127.0.0.1:1/secret-endpoint', '--token-file=' . $root . '/token', '--password-file=' . $root . '/password', '--login=owner@example.test', '--display-name=Owner']],
            ['conventional-migrate-client.php', ['--endpoint=https://127.0.0.1:1/secret-endpoint', '--token-file=' . $root . '/token']],
        ] as [$tool, $arguments]) {
            $result = runHostIoTool($tool, $arguments);
            assertSameValue(1, $result['status'], 'Unavailable network fails');
            assertSameValue('', $result['stdout'], 'No warning output enters stdout');
            foreach ([$root, 'secret-endpoint', 'fictional-password-secret', str_repeat('a', 64), 'Warning', 'failed to open stream'] as $private) {
                assertTrueValue(!str_contains($result['stderr'], $private), 'No private warning detail enters tool diagnostics');
            }
            assertTrueValue(str_contains($result['stderr'], 'status 0'), 'Network outcome remains explicit');
        }
    } finally {
        removeHostIoFixture($root);
    }
};

$tests['installation clients retain verified HTTPS response headers across warning scopes'] = static function (): void {
    $root = hostIoFixture();
    $process = null;
    try {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($key === false) {
            throw new RuntimeException('Could not create loopback TLS key');
        }
        $request = openssl_csr_new(['commonName' => '127.0.0.1'], $key, ['digest_alg' => 'sha256']);
        $certificate = $request === false ? false : openssl_csr_sign($request, null, $key, 1, ['digest_alg' => 'sha256']);
        if ($certificate === false || !openssl_x509_export($certificate, $ca) || !openssl_pkey_export($key, $private)) {
            throw new RuntimeException('Could not create loopback TLS certificate');
        }
        file_put_contents($root . '/ca.pem', $ca);
        file_put_contents($root . '/server.pem', $ca . $private);
        chmod($root . '/server.pem', 0600);
        file_put_contents($root . '/token', str_repeat('a', 64));
        file_put_contents($root . '/password', 'fictional-password-secret');
        chmod($root . '/token', 0600);
        chmod($root . '/password', 0600);
        $process = proc_open([PHP_BINARY, __DIR__ . '/host-https-fixture.php', $root . '/server.pem'], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $root . '/server.log', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start loopback TLS fixture');
        }
        stream_set_timeout($pipes[1], 5);
        $address = fgets($pipes[1]);
        if (!is_string($address) || preg_match('/\A127\.0\.0\.1:\d+\s*\z/', $address) !== 1) {
            throw new RuntimeException('Loopback TLS fixture did not become ready');
        }
        $origin = 'https://' . trim($address);
        $install = runHostIoTool('conventional-install-client.php', ['--endpoint=' . $origin . '/install', '--token-file=' . $root . '/token', '--password-file=' . $root . '/password', '--login=owner@example.test', '--display-name=Owner'], $root . '/ca.pem');
        assertSameValue(0, $install['status'], 'Verified TLS installation succeeds with retained 200 headers: ' . $install['stderr']);
        assertSameValue('', $install['stderr'], 'No hidden warning accompanies success');
        $migrate = runHostIoTool('conventional-migrate-client.php', ['--endpoint=' . $origin . '/migrate', '--token-file=' . $root . '/token'], $root . '/ca.pem');
        assertSameValue(0, $migrate['status'], 'Verified TLS migration succeeds with retained 200 headers: ' . $migrate['stderr']);
        assertTrueValue(str_contains($migrate['stdout'], '1 applied; 2 already complete'), 'Native response parsing retains counts');
        assertSameValue('', $migrate['stderr'], 'No hidden warning accompanies migration');
        $upgrade = runHostIoTool('conventional-upgrade-client.php', ['--endpoint=' . $origin . '/upgrade', '--token-file=' . $root . '/token'], $root . '/ca.pem');
        assertSameValue(0, $upgrade['status'], 'Upgrade client retains verified TLS response: ' . $upgrade['stderr']);
        assertTrueValue(str_contains($upgrade['stdout'], 'verified-fixture'), 'Upgrade response remains observable');
        assertSameValue('', $upgrade['stderr'], 'No hidden warning accompanies upgrade');
        fclose($pipes[1]);
        assertSameValue(0, proc_close($process), 'Loopback server served the three bounded requests');
        $process = null;
    } finally {
        if (is_resource($process)) {
            proc_terminate($process);
            if (isset($pipes[1]) && is_resource($pipes[1])) {
                fclose($pipes[1]);
            }
            proc_close($process);
        }
        removeHostIoFixture($root);
    }
};
