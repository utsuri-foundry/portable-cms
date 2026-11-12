<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\ReleaseGate;

/** Developer-only Git boundary; never copied into an installed runtime. */
final readonly class ReleaseRepository
{
    public function __construct(private string $root) {}

    /** @param list<string> $arguments */
    public function git(array $arguments): string
    {
        $process = proc_open(['git', '-C', $this->root, ...$arguments], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException('Git is required by the engine release tooling');
        }
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0 || !is_string($output)) {
            throw new \RuntimeException('Engine release Git operation failed; fetch the configured base first. ' . trim((string) $error));
        }
        return $output;
    }

    public function revision(): string
    {
        return trim($this->git(['rev-parse', '--verify', 'HEAD^{commit}']));
    }

    public function dirty(): bool
    {
        return $this->git(['status', '--porcelain', '--untracked-files=normal']) !== '';
    }

    /** @return array<string, array{before: ?string, after: ?string}> */
    public function changes(string $base): array
    {
        $base = trim($this->git(['rev-parse', '--verify', $base . '^{commit}']));
        $paths = array_unique([...explode("\0", trim($this->git(['diff', '--name-only', '--no-renames', '-z', $base, '--']), "\0")),
            ...explode("\0", trim($this->git(['ls-files', '--others', '--exclude-standard', '-z']), "\0"))]);
        $known = array_fill_keys(explode("\0", trim($this->git(['ls-tree', '-r', '--name-only', '-z', $base]), "\0")), true);
        $changes = [];
        foreach ($paths as $path) {
            if ($path === '') {
                continue;
            }
            $changes[$path] = ['before' => isset($known[$path]) ? $this->git(['show', $base . ':' . $path]) : null,
                'after' => is_file($this->root . '/' . $path) ? (string) file_get_contents($this->root . '/' . $path) : null];
        }
        return $changes;
    }
}
