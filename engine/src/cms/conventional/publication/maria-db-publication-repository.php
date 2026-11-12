<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Publication;

use PDO;
use Utsuri\PortableCms\Conventional\Compiler\PublishedSlugHistory;

/** The singleton reservation serializes durable intent; target effects are outside transactions. */
final class MariaDbPublicationRepository implements PublicationRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function feeds(): FeedRepository
    {
        return new MariaDbFeedRepository($this->pdo);
    }

    public function head(): PublicationHead
    {
        return $this->hydrateHead($this->pdo->query('SELECT * FROM cms_publication_state WHERE singleton_id = 1')->fetch(PDO::FETCH_ASSOC));
    }

    public static function history(PDO $pdo): ?PublishedSlugHistory
    {
        $json = $pdo->query('SELECT history_json FROM cms_publication_state WHERE singleton_id = 1')->fetchColumn();
        return is_string($json) ? PublishedSlugHistory::from(json_decode($json, true, 64, JSON_THROW_ON_ERROR)) : null;
    }

    public function find(string $id): ?Release
    {
        $query = $this->pdo->prepare('SELECT * FROM cms_releases WHERE id = :id');
        $query->execute(['id' => $id]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function list(): array
    {
        return array_map($this->hydrate(...), $this->pdo->query('SELECT * FROM cms_releases ORDER BY created_at DESC, id DESC LIMIT 100')->fetchAll(PDO::FETCH_ASSOC));
    }

    public function enqueue(Release $release, string $auditId, \DateTimeImmutable $now): void
    {
        $this->transaction(function () use ($release, $auditId, $now): void {
            $head = $this->lockedHead();
            if ($head->pendingId !== null || $head->activeId !== $release->previousId) {
                throw new PublicationConflict();
            }
            if ($head->activeId !== null && $this->find($head->activeId)?->targetChecksum !== $release->targetChecksum) {
                throw new PublicationFailure('publication_target_changed');
            }
            if ((int) $this->pdo->query('SELECT COUNT(*) FROM cms_releases')->fetchColumn() >= 100) {
                throw new PublicationFailure('release_retention_limit');
            }
            $query = $this->pdo->prepare('INSERT INTO cms_releases (id, preview_id, expected_previous, status, artifact_sha256, target_sha256, actor_user_id, created_at, updated_at, is_rollback, scheduled_at, schedule_revision) VALUES (:id, :preview, :previous, :status, :checksum, :target, :actor, :created, :updated, :rollback, :scheduled, :revision)');
            $query->execute(['id' => $release->id, 'preview' => $release->previewId, 'previous' => $release->previousId, 'status' => $release->status, 'checksum' => $release->artifactChecksum, 'target' => $release->targetChecksum, 'actor' => $release->actorId, 'created' => self::time($now), 'updated' => self::time($now), 'rollback' => (int) $release->rollback, 'scheduled' => $release->scheduledAt === null ? null : self::time($release->scheduledAt), 'revision' => $release->scheduleRevision]);
            if ($release->status === 'queued') {
                $this->pdo->prepare('UPDATE cms_publication_state SET pending_release = :id WHERE singleton_id = 1')->execute(['id' => $release->id]);
            }
            $this->audit($auditId, $release->actorId, $release->status === 'scheduled' ? 'release_scheduled' : 'release_approved', ['releaseId' => $release->id, 'previewId' => $release->previewId, 'expectedPrevious' => $release->previousId, 'artifactSha256' => $release->artifactChecksum, 'scheduledAt' => $release->scheduledAt?->format(DATE_ATOM)], $now);
        });
    }

    public function due(\DateTimeImmutable $now): bool
    {
        $query = $this->pdo->prepare("SELECT 1 FROM cms_releases WHERE status = 'scheduled' AND scheduled_at <= :at LIMIT 1");
        $query->execute(['at' => self::time($now)]);
        return $query->fetchColumn() !== false;
    }

    public function startDue(string $auditId, \DateTimeImmutable $now): void
    {
        $this->transaction(function () use ($auditId, $now): void {
            $head = $this->lockedHead();
            if ($head->pendingId !== null) {
                return;
            }
            $query = $this->pdo->prepare("SELECT * FROM cms_releases WHERE status = 'scheduled' AND scheduled_at <= :at ORDER BY scheduled_at, id LIMIT 1 FOR UPDATE");
            $query->execute(['at' => self::time($now)]);
            $row = $query->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                return;
            }
            $release = $this->hydrate($row);
            $stale = $head->activeId !== $release->previousId;
            $this->pdo->prepare('UPDATE cms_releases SET status = :status, schedule_revision = schedule_revision + 1, updated_at = :at WHERE id = :id')->execute(['status' => $stale ? 'needs_approval' : 'queued', 'at' => self::time($now), 'id' => $release->id]);
            if (!$stale) {
                $this->pdo->prepare('UPDATE cms_publication_state SET pending_release = :id WHERE singleton_id = 1')->execute(['id' => $release->id]);
            }
            $this->audit($auditId, $release->actorId, $stale ? 'release_schedule_stale' : 'release_schedule_started', ['releaseId' => $release->id, 'expectedPrevious' => $release->previousId, 'currentRelease' => $head->activeId, 'scheduledAt' => $release->scheduledAt?->format(DATE_ATOM)], $now);
        });
    }

    public function changeSchedule(string $id, int $expectedRevision, ?\DateTimeImmutable $at, string $actorId, string $auditId, \DateTimeImmutable $now): void
    {
        $this->transaction(function () use ($id, $expectedRevision, $at, $actorId, $auditId, $now): void {
            $this->lockedHead();
            $release = $this->find($id);
            if ($release === null || $release->scheduleRevision !== $expectedRevision || !in_array($release->status, $at === null ? ['scheduled', 'needs_approval'] : ['scheduled'], true)) {
                throw new PublicationConflict();
            }
            $this->pdo->prepare('UPDATE cms_releases SET status = :status, scheduled_at = :at, schedule_revision = schedule_revision + 1, updated_at = :updated WHERE id = :id')->execute(['status' => $at === null ? 'cancelled' : 'scheduled', 'at' => $at === null ? self::time($release->scheduledAt ?? $now) : self::time($at), 'updated' => self::time($now), 'id' => $id]);
            $this->audit($auditId, $actorId, $at === null ? 'release_schedule_cancelled' : 'release_schedule_moved', ['releaseId' => $id, 'previousTime' => $release->scheduledAt?->format(DATE_ATOM), 'scheduledAt' => $at?->format(DATE_ATOM), 'expectedRevision' => $expectedRevision], $now);
        });
    }

    public function scheduledForRecord(string $typeId, string $recordId): array
    {
        $query = $this->pdo->prepare("SELECT r.* FROM cms_releases r JOIN cms_previews p ON p.id = r.preview_id WHERE r.status = 'scheduled' AND (JSON_CONTAINS(p.snapshot_json, JSON_OBJECT('id', :id, 'typeId', :type, 'workflowState', 'published'), '$.records') = 1 OR EXISTS (SELECT 1 FROM cms_releases previous JOIN cms_previews before_preview ON before_preview.id = previous.preview_id WHERE previous.id = r.expected_previous AND JSON_CONTAINS(before_preview.snapshot_json, JSON_OBJECT('id', :before_id, 'typeId', :before_type, 'workflowState', 'published'), '$.records') = 1)) ORDER BY r.scheduled_at, r.id LIMIT 100");
        $query->execute(['id' => $recordId, 'type' => $typeId, 'before_id' => $recordId, 'before_type' => $typeId]);
        return array_map($this->hydrate(...), $query->fetchAll(PDO::FETCH_ASSOC));
    }

    public function progress(Release $release, bool $recovering, ?string $errorCode, \DateTimeImmutable $now): void
    {
        $this->transaction(function () use ($release, $recovering, $errorCode, $now): void {
            $this->assertPending($release);
            if ($recovering && $errorCode !== null) {
                $this->pdo->prepare('UPDATE cms_releases SET attempts = attempts + 1 WHERE id = :id')->execute(['id' => $release->id]);
            }
            $this->pdo->prepare('UPDATE cms_releases SET status = CASE WHEN attempts >= 3 THEN :attention ELSE :status END, error_code = COALESCE(:error, error_code), updated_at = :at WHERE id = :id')->execute(['attention' => 'needs_attention', 'status' => $recovering ? 'recovering' : 'applying', 'error' => $errorCode, 'at' => self::time($now), 'id' => $release->id]);
        });
    }

    public function activate(Release $release, ?PublishedSlugHistory $history, string $receipt, string $auditId, \DateTimeImmutable $now, ?FeedTransition $feed = null): void
    {
        $this->transaction(function () use ($release, $history, $receipt, $auditId, $now, $feed): void {
            $this->assertPending($release);
            if ($release->previousId !== null) {
                $this->pdo->prepare("UPDATE cms_releases SET status = 'retained' WHERE id = :id")->execute(['id' => $release->previousId]);
            }
            $this->pdo->prepare("UPDATE cms_releases SET status = 'active', receipt_json = :receipt, updated_at = :at WHERE id = :id")->execute(['receipt' => $receipt, 'at' => self::time($now), 'id' => $release->id]);
            $this->pdo->prepare('UPDATE cms_publication_state SET active_release = :id, pending_release = NULL, history_json = :history WHERE singleton_id = 1')->execute(['id' => $release->id, 'history' => $history === null ? null : json_encode($history->document(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]);
            $sequence = (new \Utsuri\PortableCms\Conventional\Database\MariaDbSiteSequence($this->pdo))->activation($release->id, $release->previewId, $now);
            (new MariaDbFeedRepository($this->pdo))->activated($release, $feed, $sequence, $now);
            $this->audit($auditId, $release->actorId, 'release_activated', ['releaseId' => $release->id, 'previousReleaseId' => $release->previousId, 'verification' => json_decode($receipt, true, 32, JSON_THROW_ON_ERROR)], $now);
        });
    }

    public function recovered(Release $release, string $auditId, \DateTimeImmutable $now): void
    {
        $this->transaction(function () use ($release, $auditId, $now): void {
            $this->assertPending($release);
            $this->pdo->prepare("UPDATE cms_releases SET status = 'failed', updated_at = :at WHERE id = :id")->execute(['at' => self::time($now), 'id' => $release->id]);
            $this->pdo->exec('UPDATE cms_publication_state SET pending_release = NULL WHERE singleton_id = 1');
            $this->audit($auditId, $release->actorId, 'release_recovered', ['releaseId' => $release->id, 'restoredReleaseId' => $release->previousId], $now);
        });
    }

    public function retry(string $pendingId, ?string $expectedPrevious, string $actorId, string $auditId, \DateTimeImmutable $now): void
    {
        $this->transaction(function () use ($pendingId, $expectedPrevious, $actorId, $auditId, $now): void {
            $head = $this->lockedHead();
            if ($head->pendingId !== $pendingId || $head->activeId !== $expectedPrevious) {
                throw new PublicationConflict();
            }
            $this->pdo->prepare("UPDATE cms_releases SET attempts = 0, status = 'recovering' WHERE id = :id AND status = 'needs_attention'")->execute(['id' => $head->pendingId]);
            $this->audit($auditId, $actorId, 'release_recovery_requested', ['releaseId' => $head->pendingId], $now);
        });
    }

    public function forget(string $id, string $actorId, string $auditId, \DateTimeImmutable $now): void
    {
        $this->transaction(function () use ($id, $actorId, $auditId, $now): void {
            $head = $this->lockedHead();
            $release = $this->find($id);
            $referenced = $this->pdo->prepare("SELECT 1 FROM cms_releases WHERE expected_previous = :id AND status IN ('scheduled', 'needs_approval') LIMIT 1");
            $referenced->execute(['id' => $id]);
            if ($release === null || $head->pendingId !== null || $head->activeId === $id || $referenced->fetchColumn() !== false || !in_array($release->status, ['retained', 'failed', 'cancelled', 'needs_approval'], true)) {
                throw new PublicationConflict();
            }
            $this->pdo->prepare('DELETE FROM cms_releases WHERE id = :id')->execute(['id' => $id]);
            $this->audit($auditId, $actorId, 'release_forgotten', ['releaseId' => $id], $now);
        });
    }

    private function assertPending(Release $release): void
    {
        $head = $this->lockedHead();
        if ($head->pendingId !== $release->id || $head->activeId !== $release->previousId) {
            throw new PublicationConflict();
        }
    }

    private function lockedHead(): PublicationHead
    {
        return $this->hydrateHead($this->pdo->query('SELECT * FROM cms_publication_state WHERE singleton_id = 1 FOR UPDATE')->fetch(PDO::FETCH_ASSOC));
    }

    private function transaction(\Closure $operation): void
    {
        $this->pdo->beginTransaction();
        try {
            $operation();
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    /** @param mixed $row */
    private function hydrateHead(mixed $row): PublicationHead
    {
        if (!is_array($row)) {
            throw new PublicationFailure('publication_state_missing');
        }
        return new PublicationHead($row['active_release'], $row['pending_release'], is_string($row['history_json']) ? PublishedSlugHistory::from(json_decode($row['history_json'], true, 64, JSON_THROW_ON_ERROR)) : null);
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Release
    {
        return new Release($row['id'], $row['preview_id'], $row['expected_previous'], $row['status'], $row['artifact_sha256'], $row['actor_user_id'], $row['error_code'], $row['target_sha256'], (bool) $row['is_rollback'], is_string($row['scheduled_at']) ? new \DateTimeImmutable($row['scheduled_at'], new \DateTimeZone('UTC')) : null, (int) $row['schedule_revision']);
    }

    /** @param array<string, mixed> $metadata */
    private function audit(string $id, string $actor, string $event, array $metadata, \DateTimeImmutable $now): void
    {
        $this->pdo->prepare('INSERT INTO cms_audit_events (id, actor_user_id, event_type, metadata_json, occurred_at) VALUES (:id, :actor, :event, :metadata, :at)')->execute(['id' => $id, 'actor' => $actor, 'event' => $event, 'metadata' => json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 'at' => self::time($now)]);
    }

    private static function time(\DateTimeImmutable $now): string
    {
        return $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
