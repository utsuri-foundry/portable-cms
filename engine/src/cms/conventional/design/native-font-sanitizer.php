<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Design;

use Utsuri\PortableCms\Conventional\Identity\RandomSource;

/** Optional qualified host adapter. No command, filename or option comes from uploaded content. */
final class NativeFontSanitizer implements FontSanitizer
{
    public function __construct(
        private readonly string $ots,
        private readonly string $compressor,
        private readonly string $scratchRoot,
        private readonly RandomSource $random,
    ) {
        foreach ([$ots, $compressor] as $executable) {
            if (!str_starts_with($executable, '/') || !is_file($executable) || !is_executable($executable)) {
                throw new FontUnavailable('design.font_error.qualified_font_sanitizer_executables_are_unavailable');
            }
        }
        if (!function_exists('proc_open') || is_link($scratchRoot) || !is_dir($scratchRoot)) {
            throw new FontUnavailable('design.font_error.qualified_private_font_processing_is_unavailable');
        }
    }

    public function sanitize(string $woff2): string
    {
        Woff2::verify($woff2);
        $path = $this->scratchRoot . '/font-processing.lock';
        if (is_link($path)) {
            throw new FontUnavailable('design.font_error.private_font_processing_lock_is_unsafe');
        }
        $lock = fopen($path, 'c+b');
        if (!is_resource($lock)) {
            throw new FontUnavailable('design.font_error.private_font_processing_lock_is_unavailable');
        }
        try {
            if (!chmod($path, 0600) || !flock($lock, LOCK_EX | LOCK_NB)) {
                throw new FontRejected('design.font_error.another_font_is_being_processed_try');
            }
            return $this->sanitizeExclusive($woff2);
        } finally {
            fclose($lock);
        }
    }

    private function sanitizeExclusive(string $woff2): string
    {
        $directory = $this->scratchRoot . '/font-' . bin2hex($this->random->bytes(16));
        if (file_exists($directory) || is_link($directory) || !mkdir($directory, 0700)) {
            throw new \RuntimeException('Font processing directory could not be created.');
        }
        try {
            $input = $directory . '/input.woff2';
            if (file_put_contents($input, $woff2) !== strlen($woff2)) {
                throw new \RuntimeException('Font processing input could not be written.');
            }
            $this->run([$this->ots, $input, $directory . '/clean.ttf']);
            $sfnt = $directory . '/clean.ttf';
            if (!is_file($sfnt) || filesize($sfnt) > 8_388_608) {
                throw new FontRejected('design.font_error.sanitized_font_exceeds_processing_limits');
            }
            $this->run([$this->compressor, $sfnt]);
            $output = $directory . '/clean.woff2';
            if (!is_file($output) || filesize($output) > 2_097_152) {
                throw new FontRejected('design.font_error.sanitized_font_exceeds_upload_limits');
            }
            $bytes = file_get_contents($output);
            if (!is_string($bytes)) {
                throw new FontRejected('design.font_error.sanitized_font_could_not_be_read');
            }
            Woff2::verify($bytes, true);
            return $bytes;
        } finally {
            foreach (['input.woff2', 'clean.ttf', 'clean.woff2'] as $name) {
                if (is_file($directory . '/' . $name) && !unlink($directory . '/' . $name)) {
                    throw new \RuntimeException('Private font cleanup failed.');
                }
            }
            if (!rmdir($directory)) {
                throw new \RuntimeException('Private font cleanup failed.');
            }
        }
    }

    /** @param list<string> $command */
    private function run(array $command): void
    {
        $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']], $pipes, null, [], ['bypass_shell' => true]);
        if (!is_resource($process)) {
            throw new FontUnavailable('design.font_error.font_processor_could_not_be_started');
        }
        $deadline = hrtime(true) + 10_000_000_000;
        try {
            do {
                $status = proc_get_status($process);
                if (!$status['running']) {
                    if ($status['exitcode'] !== 0) {
                        throw new FontRejected('design.font_error.the_font_could_not_be_verified');
                    }
                    return;
                }
                if (hrtime(true) > $deadline) {
                    proc_terminate($process, 9);
                    throw new FontRejected('design.font_error.font_processing_exceeded_its_time_limit');
                }
                usleep(10_000);
            } while (true);
        } finally {
            proc_close($process);
        }
    }
}
