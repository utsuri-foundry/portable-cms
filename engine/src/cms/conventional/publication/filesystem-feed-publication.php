<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Publication;

use Utsuri\PortableCms\Conventional\Compiler\ContentFeedSnapshot;
use Utsuri\PortableCms\Conventional\HostIo;

/** Uses the publication target's checked I/O and lock; private ownership survives interrupted writes. */
final readonly class FilesystemFeedPublication
{
    private const MAX_STATE_BYTES = 32 * 1024 * 1024;

    /** @param \Closure(string, string): void $writePublic
     * @param \Closure(string): void $removePublic
     * @param \Closure(string): bool $existsPublic
     * @param \Closure(string, string): void $writePrivate
     */
    public function __construct(private string $privateRoot, private \Closure $writePublic, private \Closure $removePublic, private \Closure $existsPublic, private \Closure $writePrivate) {}

    /** @param array<string, string> $documents */
    public function synchronize(FeedRetention $retention, array $documents, int $maximumFiles): bool
    {
        if ($maximumFiles < 1 || $maximumFiles > 100) {
            throw new \InvalidArgumentException('Feed publication batch is not bounded');
        }
        $ledger = $this->read('feed-owned.json');
        $pending = $this->read('feed-pending.json');
        $owned = [...($ledger['files'] ?? []), ...($pending['files'] ?? []), ...$retention->purgedFiles];
        $desired = $retention->files;
        $writes = [];
        foreach ($documents as $path => $json) {
            self::path($path);
            $file = ContentFeedSnapshot::file($path, $json);
            $desired[$path] = $file;
            if (($ledger['files'][$path] ?? null) != $file || !(($this->existsPublic)($path))) {
                if (($this->existsPublic)($path) && !isset($owned[$path]) && !isset($retention->files[$path])) {
                    throw new PublicationFailure('unmanaged_feed_file_conflict');
                }
                $writes[$path] = $json;
            }
        }
        $remove = array_values(array_diff(array_keys($owned), array_keys($desired)));
        sort($remove, SORT_STRING);
        $identity = hash('sha256', self::stateJson([$desired, array_keys($writes), $remove]));
        if (($pending['identity'] ?? null) !== $identity) {
            $pending = ['formatVersion' => 1, 'identity' => $identity, 'cursor' => 0, 'files' => [...$owned, ...$desired]];
            $this->save('feed-pending.json', $pending);
        }
        $actions = [...array_keys($writes), ...$remove];
        $last = min(count($actions), $pending['cursor'] + $maximumFiles);
        for ($index = $pending['cursor']; $index < $last; ++$index) {
            $path = $actions[$index];
            self::path($path);
            if (isset($writes[$path])) {
                ($this->writePublic)($path, $writes[$path]);
            } else {
                ($this->removePublic)($path);
            }
            $pending['cursor'] = $index + 1;
            $this->save('feed-pending.json', $pending);
        }
        if ($last !== count($actions)) {
            return false;
        }
        $this->save('feed-owned.json', ['formatVersion' => 1, 'files' => $desired]);
        $path = $this->privateRoot . '/feed-pending.json';
        if (is_link($path)) {
            throw new PublicationFailure('feed_journal_unsafe');
        }
        if (is_file($path)) {
            HostIo::required(fn() => unlink($path));
        }
        return true;
    }

    private static function path(string $path): void
    {
        if (preg_match('#\Afeed/v1/(?:current\.json|activations\.json|activations/[1-9][0-9]*-[a-f0-9]{32}\.json|snapshots/[a-f0-9]{64}/(?:manifest\.json|[a-z][a-z0-9_-]*/[a-z]{2,3}(?:-[A-Z]{2})?/[1-9][0-9]*\.json)|media/[a-f0-9]{64}\.webp)\z#', $path) !== 1) {
            throw new PublicationFailure('feed_path_unsafe');
        }
    }

    /** @return array<string, mixed> */
    private function read(string $name): array
    {
        $path = $this->privateRoot . '/' . $name;
        if (is_link($path)) {
            throw new PublicationFailure('feed_journal_unsafe');
        }
        if (!is_file($path)) {
            return [];
        }
        $size = HostIo::attempt(fn() => filesize($path))->value;
        if (!is_int($size) || $size < 1 || $size > self::MAX_STATE_BYTES) {
            throw new PublicationFailure('feed_journal_invalid');
        }
        $json = HostIo::attempt(fn() => file_get_contents($path, false, null, 0, self::MAX_STATE_BYTES + 1))->value;
        if (!is_string($json) || strlen($json) > self::MAX_STATE_BYTES) {
            throw new PublicationFailure('feed_journal_invalid');
        }
        $value = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($value) || ($value['formatVersion'] ?? null) !== 1 || !is_array($value['files'] ?? null) || count($value['files']) > 50000
            || (isset($value['cursor']) && (!is_int($value['cursor']) || $value['cursor'] < 0 || $value['cursor'] > 50000))) {
            throw new PublicationFailure('feed_journal_invalid');
        }
        foreach ($value['files'] as $path => $file) {
            self::path($path);
            if (!is_array($file) || ($file['path'] ?? null) !== $path || !is_string($file['sha256'] ?? null) || !is_int($file['byteSize'] ?? null)) {
                throw new PublicationFailure('feed_journal_invalid');
            }
        }
        return $value;
    }

    /** @param array<string, mixed> $value */
    private function save(string $name, array $value): void
    {
        $path = $this->privateRoot . '/' . $name;
        if (is_link($path)) {
            throw new PublicationFailure('feed_journal_unsafe');
        }
        ($this->writePrivate)($path, self::stateJson($value));
    }

    private static function stateJson(mixed $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        if (strlen($json) > self::MAX_STATE_BYTES) {
            throw new PublicationFailure('feed_journal_capacity');
        }
        return $json;
    }
}
