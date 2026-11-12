<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Preview;

use Utsuri\PortableCms\Conventional\HostIo;

/** Private journal survives request termination on either side of the database commit. */
final class PreviewCleanupFiles
{
    public function __construct(private readonly string $root, private readonly FilesystemPreviewStore $store) {}

    /** @param \Closure(): string $operation */
    public function locked(string $id, \Closure $operation): string
    {
        PreviewSelection::ids([$id]);
        $directory = $this->directory('locks');
        // Fixed stripes keep lock inode usage bounded. Never unlink a live lock inode.
        $path = $directory . '/' . substr($id, 0, 2) . '.lock';
        if (is_link($path)) {
            throw new \RuntimeException('Unsafe cleanup lock');
        }
        $handle = HostIo::attempt(static fn() => fopen($path, 'c'))->value;
        if (!is_resource($handle)) {
            throw new \RuntimeException('Cleanup lock unavailable');
        }
        try {
            HostIo::required(static fn() => chmod($path, 0600));
            if (!HostIo::attempt(static fn() => flock($handle, LOCK_EX | LOCK_NB))->succeeded()) {
                return 'unprocessed';
            }
            return $operation();
        } finally {
            HostIo::required(static fn() => fclose($handle));
        }
    }

    public function journal(string $id): ?string
    {
        PreviewSelection::ids([$id]);
        $path = $this->directory() . '/' . $id . '.token';
        if (is_link($path)) {
            throw new \RuntimeException('Unsafe cleanup journal');
        }
        if (!is_file($path)) {
            return null;
        }
        if (HostIo::attempt(static fn() => filesize($path))->value !== 32) {
            throw new \RuntimeException('Invalid cleanup journal size');
        }
        $token = HostIo::attempt(static fn() => file_get_contents($path))->value;
        if (!is_string($token) || preg_match('/\A[a-f0-9]{32}\z/', $token) !== 1) {
            throw new \RuntimeException('Invalid cleanup journal');
        }
        return $token;
    }

    public function begin(string $id, string $token): void
    {
        PreviewSelection::ids([$id, $token]);
        $path = $this->directory() . '/' . $id . '.token';
        $temporary = $path . '.tmp';
        if (is_link($path) || is_link($temporary)) {
            throw new \RuntimeException('Unsafe cleanup journal');
        }
        if (HostIo::attempt(static fn() => file_put_contents($temporary, $token, LOCK_EX))->value !== strlen($token)) {
            throw new \RuntimeException('Incomplete cleanup journal');
        }
        HostIo::required(static fn() => chmod($temporary, 0600));
        HostIo::required(static fn() => rename($temporary, $path));
    }

    public function restore(string $id, string $token): void
    {
        $trash = $this->trash($id, $token);
        if (file_exists($trash) || is_link($trash)) {
            $this->store->restoreQuarantine($id, $trash);
        }
        $this->finish($id);
    }

    public function purge(string $id, string $token, PreviewCleanupBudget $budget): bool
    {
        $trash = $this->trash($id, $token);
        if (!$this->remove($trash, $budget, 0)) {
            return false;
        }
        $this->finish($id);
        return true;
    }

    private function finish(string $id): void
    {
        HostIo::required(fn() => unlink($this->directory() . '/' . $id . '.token'));
    }

    /** @return list<string> */
    public function pending(): array
    {
        $directory = $this->root . '/cleanup/journals';
        if (!is_dir($directory) || is_link($directory) || is_link(dirname($directory))) {
            return [];
        }
        $ids = [];
        foreach (new \DirectoryIterator($directory) as $entry) {
            if (preg_match('/\A([a-f0-9]{32})\.token\z/', $entry->getFilename(), $match) === 1 && !$entry->isLink()) {
                $ids[] = $match[1];
                if (count($ids) === PreviewSelection::LIMIT) {
                    break;
                }
            }
        }
        return $ids;
    }

    private function directory(string $child = 'journals'): string
    {
        $parent = $this->root . '/cleanup';
        if (is_link($parent) || (!is_dir($parent) && !HostIo::attempt(static fn() => mkdir($parent, 0700))->succeeded() && !is_dir($parent))) {
            throw new \RuntimeException('Unsafe cleanup directory');
        }
        $directory = $parent . '/' . $child;
        if (is_link($directory) || (!is_dir($directory) && !HostIo::attempt(static fn() => mkdir($directory, 0700))->succeeded() && !is_dir($directory))) {
            throw new \RuntimeException('Unsafe cleanup directory');
        }
        return $directory;
    }

    private function trash(string $id, string $token): string
    {
        PreviewSelection::ids([$id, $token]);
        if (is_link($this->root . '/trash')) {
            throw new \RuntimeException('Unsafe quarantine directory');
        }
        return $this->root . '/trash/' . $id . '-' . $token;
    }

    private function remove(string $path, PreviewCleanupBudget $budget, int $depth): bool
    {
        if (!$budget->consume()) {
            return false;
        }
        if (!file_exists($path) && !is_link($path)) {
            return true;
        }
        if (is_link($path) || !is_dir($path)) {
            HostIo::required(static fn() => unlink($path));
            return true;
        }
        if ($depth >= 64) {
            throw new \RuntimeException('Quarantine nesting limit exceeded');
        }
        foreach (new \DirectoryIterator($path) as $entry) {
            if (!$entry->isDot() && !$this->remove($entry->getPathname(), $budget, $depth + 1)) {
                return false;
            }
        }
        if (!$budget->available()) {
            return false;
        }
        HostIo::required(static fn() => rmdir($path));
        return true;
    }
}
