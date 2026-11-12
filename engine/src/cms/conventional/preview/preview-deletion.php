<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Preview;

use Utsuri\PortableCms\Conventional\Identity\Clock;
use Utsuri\PortableCms\Conventional\Identity\RandomSource;

/** Same guarded authority for individual and reviewed batch deletion. */
final readonly class PreviewDeletion
{
    public function __construct(private PreviewCleanupRepository $repository, private FilesystemPreviewStore $storage, private Clock $clock, private RandomSource $random) {}

    public function remove(string $id, string $actor, PreviewCleanupBudget $budget): string
    {
        PreviewSelection::ids([$id]);
        if (!$budget->consume()) {
            return 'unprocessed';
        }
        $files = $this->storage->cleanupFiles();
        return $files->locked($id, function () use ($files, $id, $actor, $budget): string {
            $token = $files->journal($id);
            $row = $this->repository->find($id);
            if ($token !== null) {
                if ($row === null) {
                    return $this->purge($files, $id, $token, $budget);
                }
                $files->restore($id, $token);
            }
            if ($row === null) {
                return 'absent';
            }
            $token = bin2hex($this->random->bytes(16));
            try {
                $this->repository->deleteGuarded($id, $actor, bin2hex($this->random->bytes(16)), $this->clock->now(), function () use ($files, $id, $token): void {
                    $files->begin($id, $token);
                    $this->storage->quarantine($id, $token);
                });
            } catch (\Throwable $error) {
                // A commit acknowledgement can fail after the commit. Never restore deleted artifacts.
                if ($files->journal($id) !== null && $this->repository->find($id) !== null) {
                    $files->restore($id, $token);
                }
                if ($error instanceof PreviewNotDeletable) {
                    return 'protected';
                }
                if ($error instanceof PreviewNotFound) {
                    return 'absent';
                }
                throw $error;
            }
            return $this->purge($files, $id, $token, $budget);
        });
    }

    /** @return list<string> */
    public function pending(): array
    {
        return $this->storage->cleanupFiles()->pending();
    }

    /** Recovery of a review-protected snapshot never authorizes deleting its row. */
    public function restoreOnly(string $id, PreviewCleanupBudget $budget): string
    {
        PreviewSelection::ids([$id]);
        if (!$budget->consume()) {
            return 'unprocessed';
        }
        $files = $this->storage->cleanupFiles();
        return $files->locked($id, function () use ($files, $id, $budget): string {
            $token = $files->journal($id);
            $row = $this->repository->find($id);
            if ($token === null) {
                return $row === null ? 'absent' : 'protected';
            }
            if ($row === null) {
                return $this->purge($files, $id, $token, $budget);
            }
            $files->restore($id, $token);
            return 'restored';
        });
    }

    private function purge(PreviewCleanupFiles $files, string $id, string $token, PreviewCleanupBudget $budget): string
    {
        try {
            return $files->purge($id, $token, $budget) ? 'deleted' : 'cleanup_pending';
        } catch (\Throwable) {
            \Utsuri\PortableCms\Conventional\HostIo::recordFailure('cleanup_failed');
            return 'cleanup_pending';
        }
    }
}
