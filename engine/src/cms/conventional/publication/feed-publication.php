<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Publication;

use Utsuri\PortableCms\Conventional\Compiler\ContentFeedSnapshot;

/** Inventory authorized by the verified immutable artifact, never live CMS values. */
final readonly class FeedTransition
{
    /** @param array<string, mixed> $manifest
     * @param array<string, array{path: string, byteSize: int, sha256: string}> $files
     */
    public function __construct(public array $manifest, public array $files) {}

    public static function from(ReleaseArtifact $artifact): ?self
    {
        $manifest = $artifact->document['contentFeed'] ?? null;
        $expected = [];
        if ($manifest !== null) {
            ContentFeedSnapshot::validate($manifest);
            $directory = ContentFeedSnapshot::directoryFor($manifest['snapshotId']);
            $expected[$directory . 'manifest.json'] = ContentFeedSnapshot::file($directory . 'manifest.json', ContentFeedSnapshot::json($manifest));
            foreach ($manifest['collections'] as $collection) {
                foreach ($collection['pages'] as $page) {
                    $path = $directory . $page['path'];
                    $expected[$path] = [...$page, 'path' => $path];
                }
            }
            foreach ($manifest['media'] as $file) {
                $expected[$file['path']] = array_intersect_key($file, array_flip(['path', 'byteSize', 'sha256']));
            }
        }
        $observed = [];
        foreach ($artifact->files as $file) {
            if (str_starts_with($file['path'], ContentFeedSnapshot::ROOT)) {
                $observed[$file['path']] = array_intersect_key($file, array_flip(['path', 'byteSize', 'sha256']));
            }
        }
        // Object key order is not part of the file facts.
        if ($expected != $observed) {
            throw new PublicationFailure('feed_inventory_mismatch');
        }
        return $manifest === null ? null : new self($manifest, $expected);
    }
}

final readonly class FeedRetention
{
    /** @var array<string, true> */
    private array $purgedIndex;

    /** @param array<string, array{path: string, byteSize: int, sha256: string}> $files
     * @param list<string> $purged
     * @param array<string, array{path: string, byteSize: int, sha256: string}> $purgedFiles
     */
    public function __construct(public array $files = [], public array $purged = [], public array $purgedFiles = [])
    {
        foreach ($purged as $id) {
            ContentFeedSnapshot::directoryFor($id);
        }
        $this->purgedIndex = array_fill_keys($purged, true);
    }

    public function suppresses(string $path, ?string $snapshotId = null): bool
    {
        $prefix = ContentFeedSnapshot::ROOT . 'snapshots/';
        if (str_starts_with($path, $prefix) && isset($this->purgedIndex[substr($path, strlen($prefix), 64)])) {
            return true;
        }
        return $snapshotId !== null && isset($this->purgedIndex[$snapshotId]) && str_starts_with($path, ContentFeedSnapshot::ROOT . 'media/') && !isset($this->files[$path]);
    }
}

/** Small routing facts and original bytes; delivery never decodes every historical change list. */
final readonly class FeedActivation
{
    public function __construct(public int $sequence, public string $releaseId, public ?string $snapshotId, public string $json)
    {
        \Utsuri\PortableCms\Conventional\Database\SiteSequence::value($sequence);
        if ($sequence < 1 || preg_match('/\A[a-f0-9]{32}\z/', $releaseId) !== 1 || $json === '' || strlen($json) > ContentFeedSnapshot::MAX_JSON_BYTES) {
            throw new PublicationFailure('feed_activation_invalid');
        }
        if ($snapshotId !== null) {
            ContentFeedSnapshot::directoryFor($snapshotId);
        }
    }

    public function path(): string
    {
        return ContentFeedSnapshot::ROOT . 'activations/' . $this->sequence . '-' . $this->releaseId . '.json';
    }
}

interface FeedRepository
{
    public function dirty(): bool;

    /** Prune only outside both minimum retention bounds. */
    public function prune(\DateTimeImmutable $now): void;

    public function retention(): FeedRetention;

    /** @return list<FeedActivation> */
    public function activations(): array;

    public function floor(): int;

    public function synchronized(): void;

    public function assertAllowed(?FeedTransition $transition): void;

    public function purge(string $snapshot, string $actor, string $audit, \DateTimeImmutable $now): void;
}

interface FeedTarget
{
    /** At most $maximumFiles actions. A private journal/ledger survives process death. */
    /** @param array<string, string> $documents */
    public function synchronizeFeed(FeedRetention $retention, array $documents, int $maximumFiles): bool;
}

/** Static discovery files are materialized only from committed verified activations. */
final readonly class FeedPublication
{
    public function __construct(private FeedRepository $repository, private FeedTarget $target) {}

    public function synchronize(\DateTimeImmutable $now, int $maximumFiles): bool
    {
        $this->repository->prune($now);
        if (!$this->repository->dirty()) {
            return true;
        }
        $retention = $this->repository->retention();
        $activations = $this->repository->activations();
        $documents = [];
        $listing = [];
        foreach ($activations as $activation) {
            $path = $activation->path();
            $documents[$path] = $activation->json;
            $listing[] = ['sequence' => $activation->sequence, 'releaseId' => $activation->releaseId, 'path' => $path];
        }
        if ($activations !== []) {
            $current = $activations[array_key_last($activations)];
            $snapshot = $current->snapshotId;
            $purged = $snapshot !== null && in_array($snapshot, $retention->purged, true);
            $documents[ContentFeedSnapshot::ROOT . 'activations.json'] = ContentFeedSnapshot::json(['formatVersion' => 1, 'cursorFloor' => $this->repository->floor(), 'activations' => $listing]);
            // The discovery pointer follows its immutable dependencies and always describes reset behavior.
            $documents[ContentFeedSnapshot::ROOT . 'current.json'] = ContentFeedSnapshot::json(['formatVersion' => 1, 'sequence' => $current->sequence, 'releaseId' => $current->releaseId, 'activation' => $current->path(), 'snapshotId' => $purged ? null : $snapshot,
                'fullRead' => $snapshot === null || $purged ? null : ContentFeedSnapshot::directoryFor($snapshot) . 'manifest.json', 'activations' => ContentFeedSnapshot::ROOT . 'activations.json', 'cursorFloor' => $this->repository->floor(), 'missingFile' => 'read-current-pointer-and-restart-full-read', 'status' => $purged ? 'purged' : ($snapshot === null ? 'disabled' : 'available')]);
        }
        if (!$this->target->synchronizeFeed($retention, $documents, $maximumFiles)) {
            return false;
        }
        $this->repository->synchronized();
        return true;
    }

}
