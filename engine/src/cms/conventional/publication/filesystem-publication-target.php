<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Publication;

use Utsuri\PortableCms\Conventional\HostIo;

interface PublicationTarget extends FeedTarget
{
    public function retainFeed(FeedRetention $retention): void;

    public function identity(): string;

    /** @param \Closure(): bool $operation */
    public function locked(\Closure $operation): bool;

    /** @param \Closure(string): (string|\Utsuri\PortableCms\Conventional\Media\MediaDelivery) $read */
    public function advance(Release $release, ?ReleaseArtifact $artifact, ?ReleaseArtifact $previous, \Closure $read, int $maximumFiles, bool $recovering): bool;

    public function receipt(Release $release, ReleaseArtifact $artifact, string $verificationId): string;

    public function authenticate(string $receipt, Release $release, ReleaseArtifact $artifact): void;

    public function cleanupJournal(string $id): void;
}

/** Dedicated Apache document root. In-place publication is journaled, not falsely atomic. */
final class FilesystemPublicationTarget implements PublicationTarget
{
    private string $root;
    private string $privateRoot;

    private FeedRetention $feedRetention;

    public function __construct(string $root, string $privateRoot, private readonly string $secret, private readonly ?\Closure $afterWrite = null)
    {
        $this->root = self::root($root);
        $this->privateRoot = self::root($privateRoot);
        $this->feedRetention = new FeedRetention();
        if (strlen($secret) < 32 || $this->root === DIRECTORY_SEPARATOR
            || self::within($this->root, $this->privateRoot) || self::within($this->privateRoot, $this->root)) {
            throw new PublicationFailure('publication_roots_unsafe');
        }
        HostIo::required(fn() => chmod($this->privateRoot, 0700));
    }

    public function locked(\Closure $operation): bool
    {
        $path = $this->privateRoot . '/publication.lock';
        if (is_link($path)) {
            throw new PublicationFailure('publication_lock_unsafe');
        }
        $stream = HostIo::attempt(fn() => fopen($path, 'c+b'))->value;
        if (!is_resource($stream)) {
            throw new PublicationFailure('publication_lock_unavailable');
        }
        try {
            HostIo::required(fn() => chmod($path, 0600));
            if (!HostIo::attempt(fn() => flock($stream, LOCK_EX | LOCK_NB))->value) {
                return false;
            }
            try {
                return $operation();
            } finally {
                HostIo::required(fn() => flock($stream, LOCK_UN));
            }
        } finally {
            HostIo::required(fn() => fclose($stream));
        }
    }

    public function identity(): string
    {
        return hash('sha256', $this->root . "\0" . $this->privateRoot);
    }

    public function retainFeed(FeedRetention $retention): void
    {
        $this->feedRetention = $retention;
    }

    public function synchronizeFeed(FeedRetention $retention, array $documents, int $maximumFiles): bool
    {
        $files = new FilesystemFeedPublication(
            $this->privateRoot,
            fn(string $path, string $bytes) => $this->write($this->path($path, true), $bytes, 0644),
            function (string $path): void {
                $candidate = $this->path($path, false);
                if (is_file($candidate)) {
                    HostIo::required(fn() => unlink($candidate));
                }
                $this->pruneEmptyParents($path);
            },
            fn(string $path): bool => file_exists($this->path($path, false)),
            fn(string $path, string $bytes) => $this->write($path, $bytes, 0600),
        );
        return $files->synchronize($retention, $documents, $maximumFiles);
    }

    public function advance(Release $release, ?ReleaseArtifact $artifact, ?ReleaseArtifact $previous, \Closure $read, int $maximumFiles, bool $recovering): bool
    {
        if ($maximumFiles < 1 || $maximumFiles > 100) {
            throw new \InvalidArgumentException('Publication batch must be bounded');
        }
        $desired = $recovering ? $previous : $artifact;
        $obsolete = $recovering ? $artifact : $previous;
        $mode = $recovering ? 'recovery' : 'publication';
        $snapshotId = $desired?->document['contentFeed']['snapshotId'] ?? null;
        $paths = array_values(array_filter($desired?->paths() ?? [], fn(string $path): bool => !$this->feedRetention->suppresses($path, $snapshotId)));
        $remove = array_values(array_diff($obsolete?->paths() ?? [], $paths, array_keys($this->feedRetention->files)));
        $journalPath = $this->privateRoot . '/' . $release->id . '.json';
        $journal = $this->readJournal($journalPath);
        if ($recovering && $journal === null) {
            // No checked public write plan ever began. Never remove pre-existing unrelated files.
            if ($previous !== null) {
                $this->verify($previous);
            }
            return true;
        }
        $identity = hash('sha256', json_encode([$release->id, $release->previousId, $release->artifactChecksum, $mode, $this->root, $paths, $remove], JSON_THROW_ON_ERROR));
        if ($journal === null || $journal['mode'] !== $mode) {
            if (!$recovering) {
                $owned = array_fill_keys([...($previous?->paths() ?? []), ...array_keys($this->feedRetention->files)], true);
                foreach ($paths as $path) {
                    $candidate = $this->path($path, false);
                    if (file_exists($candidate) && !isset($owned[$path])) {
                        throw new PublicationFailure('unmanaged_public_file_conflict');
                    }
                }
            }
            $journal = ['identity' => $identity, 'mode' => $mode, 'cursor' => 0];
            $this->journal($journalPath, $journal);
        }
        if ($journal['identity'] !== $identity) {
            throw new PublicationFailure('publication_journal_conflict');
        }
        $actions = [...$paths, ...$remove];
        if ($journal['cursor'] > count($actions)) {
            throw new PublicationFailure('publication_journal_invalid');
        }
        $last = min(count($actions), $journal['cursor'] + $maximumFiles);
        $sizes = [];
        foreach ($desired->files ?? [] as $file) {
            $sizes[$file['path']] = $file['byteSize'];
        }
        $writtenBytes = 0;
        for ($index = $journal['cursor']; $index < $last; ++$index) {
            $path = $actions[$index];
            if ($index < count($paths)) {
                $size = $sizes[$path] ?? 0;
                if ($writtenBytes > 0 && $writtenBytes + $size > \Utsuri\PortableCms\Conventional\Media\VideoSupport::MAX_BYTES) {
                    $last = $index;
                    break;
                }
                $bytes = $path === '.htaccess' ? ($desired?->rules() ?? '') : $read($path);
                $this->write($this->path($path, true), $bytes, 0644);
                $writtenBytes += $size;
                if ($this->afterWrite !== null) {
                    ($this->afterWrite)($path, $index);
                }
            } else {
                $candidate = $this->path($path, false);
                if (file_exists($candidate)) {
                    HostIo::required(fn() => unlink($candidate));
                }
                // Repeat after an interrupted unlink too: empty route folders serve 403 on Apache.
                $this->pruneEmptyParents($path);
            }
            $journal['cursor'] = $index + 1;
            $this->journal($journalPath, $journal);
        }
        if ($last !== count($actions)) {
            return false;
        }
        if ($desired !== null) {
            $this->verify($desired);
        }
        foreach ($remove as $path) {
            if (file_exists($this->path($path, false))) {
                throw new PublicationFailure('obsolete_public_file_retained');
            }
        }
        return true;
    }

    public function receipt(Release $release, ReleaseArtifact $artifact, string $verificationId): string
    {
        $this->verify($artifact);
        $payload = json_encode(['formatVersion' => 1, 'verificationId' => $verificationId, 'releaseId' => $release->id, 'expectedPrevious' => $release->previousId,
            'artifactSha256' => $artifact->checksum, 'targetSha256' => $this->identity(), 'contextSha256' => $artifact->routes->context?->checksum(), 'deliverySha256' => hash('sha256', $artifact->rules())], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return json_encode(['payload' => $payload, 'signature' => hash_hmac('sha256', $payload, $this->secret)], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** Remove only empty ancestors of an obsolete owned file, never the public root. */
    private function pruneEmptyParents(string $path): void
    {
        for ($relative = dirname($path); $relative !== '.'; $relative = dirname($relative)) {
            $directory = $this->path($relative, false);
            if (!is_dir($directory)) {
                continue;
            }
            $stream = HostIo::attempt(fn() => opendir($directory))->value;
            if (!is_resource($stream)) {
                throw new PublicationFailure('target_directory_unavailable');
            }
            try {
                while (($entry = readdir($stream)) !== false) {
                    if ($entry !== '.' && $entry !== '..') {
                        return;
                    }
                }
            } finally {
                closedir($stream);
            }
            HostIo::required(fn() => rmdir($directory));
        }
    }

    public function authenticate(string $receipt, Release $release, ReleaseArtifact $artifact): void
    {
        $value = json_decode($receipt, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($value) || count($value) !== 2 || !is_string($value['payload'] ?? null) || !is_string($value['signature'] ?? null)
            || !hash_equals(hash_hmac('sha256', $value['payload'], $this->secret), $value['signature'])) {
            throw new PublicationFailure('invalid_target_receipt');
        }
        $payload = json_decode($value['payload'], true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($payload) || count($payload) !== 8 || ($payload['formatVersion'] ?? null) !== 1
            || ($payload['releaseId'] ?? null) !== $release->id || ($payload['expectedPrevious'] ?? null) !== $release->previousId
            || ($payload['artifactSha256'] ?? null) !== $artifact->checksum || ($payload['targetSha256'] ?? null) !== $this->identity()
            || ($payload['contextSha256'] ?? null) !== $artifact->routes->context?->checksum() || ($payload['deliverySha256'] ?? null) !== hash('sha256', $artifact->rules())
            || !is_string($payload['verificationId'] ?? null) || preg_match('/\A[a-f0-9]{32}\z/', $payload['verificationId']) !== 1) {
            throw new PublicationFailure('invalid_target_receipt');
        }
        $this->verify($artifact);
    }

    private function verify(ReleaseArtifact $artifact): void
    {
        foreach ($artifact->files as $file) {
            if ($this->feedRetention->suppresses($file['path'], $artifact->document['contentFeed']['snapshotId'] ?? null)) {
                continue;
            }
            $candidate = $this->path($file['path'], false);
            if (!is_file($candidate) || HostIo::attempt(fn() => filesize($candidate))->value !== $file['byteSize']
                || HostIo::attempt(fn() => hash_file('sha256', $candidate))->value !== $file['sha256']) {
                throw new PublicationFailure('target_verification_failed');
            }
        }
        foreach (['artifact.json' => $artifact->json, '.htaccess' => $artifact->rules()] as $path => $bytes) {
            $candidate = $this->path($path, false);
            if (HostIo::attempt(fn() => hash_file('sha256', $candidate))->value !== hash('sha256', $bytes)) {
                throw new PublicationFailure('target_verification_failed');
            }
        }
    }

    public function cleanupJournal(string $id): void
    {
        if (preg_match('/\A[a-f0-9]{32}\z/', $id) !== 1) {
            throw new \InvalidArgumentException('Release identity is invalid');
        }
        $path = $this->privateRoot . '/' . $id . '.json';
        if (is_link($path)) {
            throw new PublicationFailure('publication_journal_unsafe');
        }
        if (is_file($path)) {
            HostIo::cleanup(fn() => unlink($path));
        }
    }

    private function path(string $path, bool $createParents): string
    {
        if ($path !== '.htaccess') {
            \Utsuri\PortableCms\Conventional\Preview\PreviewArtifact::assertPath($path);
        }
        $cursor = $this->root;
        $segments = explode('/', $path);
        foreach ($segments as $index => $segment) {
            if ($segment === '.') {
                throw new PublicationFailure('unsafe_target_path');
            }
            $cursor .= '/' . $segment;
            if (is_link($cursor) || ($index < count($segments) - 1 && file_exists($cursor) && !is_dir($cursor))) {
                throw new PublicationFailure('unsafe_target_path');
            }
            if ($createParents && $index < count($segments) - 1 && !is_dir($cursor)) {
                HostIo::required(fn() => mkdir($cursor, 0755));
            }
        }
        return $cursor;
    }

    private function write(string $path, string|\Utsuri\PortableCms\Conventional\Media\MediaDelivery $bytes, int $permissions): void
    {
        $temporary = HostIo::attempt(fn() => tempnam(dirname($path), '.cms-write-'))->value;
        if (!is_string($temporary)) {
            throw new PublicationFailure('target_write_failed');
        }
        try {
            HostIo::required(fn() => chmod($temporary, $permissions));
            if (is_string($bytes)) {
                if (HostIo::attempt(fn() => file_put_contents($temporary, $bytes, LOCK_EX))->value !== strlen($bytes)) {
                    throw new PublicationFailure('target_write_failed');
                }
            } else {
                $output = HostIo::attempt(fn() => fopen($temporary, 'wb'))->value;
                if (!is_resource($output)) {
                    throw new PublicationFailure('target_write_failed');
                }
                try {
                    $bytes->writeTo($output, 0, $bytes->size());
                    HostIo::required(fn() => fflush($output));
                } finally {
                    fclose($output);
                }
            }
            HostIo::required(fn() => rename($temporary, $path));
        } finally {
            if (is_file($temporary)) {
                HostIo::cleanup(fn() => unlink($temporary));
            }
        }
    }

    /** @return array{identity: string, mode: string, cursor: int}|null */
    private function readJournal(string $path): ?array
    {
        if (is_link($path)) {
            throw new PublicationFailure('publication_journal_unsafe');
        }
        if (!file_exists($path)) {
            return null;
        }
        $bytes = HostIo::attempt(fn() => file_get_contents($path))->value;
        $value = is_string($bytes) && strlen($bytes) <= 1000 ? json_decode($bytes, true, 8, JSON_THROW_ON_ERROR) : null;
        if (!is_array($value) || count($value) !== 3 || !is_string($value['identity'] ?? null) || !is_string($value['mode'] ?? null)
            || !in_array($value['mode'], ['publication', 'recovery'], true) || !is_int($value['cursor'] ?? null) || $value['cursor'] < 0 || $value['cursor'] > 4002) {
            throw new PublicationFailure('publication_journal_invalid');
        }
        return $value;
    }

    /** @param array{identity: string, mode: string, cursor: int} $value */
    private function journal(string $path, array $value): void
    {
        $this->write($path, json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 0600);
    }

    private static function root(string $path): string
    {
        $resolved = realpath($path);
        if ($resolved === false || $resolved !== rtrim($path, '/') || !is_dir($resolved) || is_link($path) || !is_writable($resolved)) {
            throw new PublicationFailure('publication_root_unavailable');
        }
        return $resolved;
    }

    private static function within(string $path, string $root): bool
    {
        return $path === $root || str_starts_with($path, $root . '/');
    }
}
