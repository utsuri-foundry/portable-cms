<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Identity\RandomSource;
use Utsuri\PortableCms\Conventional\Identity\SystemClock;
use Utsuri\PortableCms\Conventional\Identity\SystemRandomSource;
use Utsuri\PortableCms\Conventional\Preview\FilesystemPreviewStore;
use Utsuri\PortableCms\Conventional\Preview\MariaDbPreviewRepository;
use Utsuri\PortableCms\Conventional\Preview\PreviewCleanupService;
use Utsuri\PortableCms\Conventional\Preview\PreviewDeletion;
use Utsuri\PortableCms\Conventional\Preview\PreviewSelection;
use Utsuri\PortableCms\Conventional\Preview\PreviewService;

/** Isolated sandbox fixtures only. Verifies the real locking, audit and share cascade authority. */
function verifyPreviewCleanup(PDO $pdo, string $dsn, string $root, string $actor, ProfileManifest $profile): void
{
    $clock = new SystemClock();
    $random = new SystemRandomSource();
    $repository = new MariaDbPreviewRepository($pdo);
    $storage = new FilesystemPreviewStore($root . '/cleanup-integration');
    $cleanup = new PreviewCleanupService($repository, new PreviewDeletion($repository, $storage, $clock, $random), new PreviewSelection(str_repeat('s', 32), $clock), $clock);
    $job = $pdo->prepare("INSERT INTO cms_jobs (id, job_type, status, payload_json, available_at, created_at, updated_at) VALUES (:id, 'preview_build', 'completed', '{}', :available, :created, :updated)");
    $preview = $pdo->prepare("INSERT INTO cms_previews (id, profile_id, profile_version, status, snapshot_json, job_id, created_by, created_at, updated_at) VALUES (:id, :profile, :version, 'ready', '{}', :job, :actor, :created, :updated)");
    $time = '2026-10-12 12:00:00.000000';
    $ids = [];
    for ($i = 1; $i <= 65; ++$i) {
        $id = 'ee' . str_pad(dechex($i), 30, '0', STR_PAD_LEFT);
        $jobId = 'ed' . substr($id, 2);
        $job->execute(['id' => $jobId, 'available' => $time, 'created' => $time, 'updated' => $time]);
        $preview->execute(['id' => $id, 'profile' => $profile->id, 'version' => $profile->version, 'job' => $jobId, 'actor' => $actor, 'created' => $time, 'updated' => $time]);
        $ids[] = $id;
    }
    $seen = [];
    $page = $cleanup->page('');
    do {
        foreach ($page->items as $item) {
            $seen[] = $item->preview->id;
        }
        $cursor = $page->next;
        if ($cursor !== null) {
            $page = $cleanup->page($cursor->value());
        }
    } while ($cursor !== null);
    assertSameValue(65, count(array_intersect($ids, $seen)), 'real database pagination reaches every fixture beyond fifty');
    assertSameValue(count($seen), count(array_unique($seen)), 'ties never duplicate a preview');

    // Every release state, not just the active head, protects its source snapshot.
    $release = $pdo->prepare('INSERT INTO cms_releases (id, preview_id, status, artifact_sha256, target_sha256, actor_user_id, created_at, updated_at) VALUES (:id, :preview, :status, :artifact, :target, :actor, :created, :updated)');
    foreach (['queued', 'applying', 'recovering', 'needs_attention', 'active', 'retained', 'failed'] as $index => $status) {
        $id = $ids[$index];
        $review = $cleanup->review([$id], $actor);
        $release->execute(['id' => 'ec' . substr($id, 2), 'preview' => $id, 'status' => $status, 'artifact' => str_repeat('a', 64), 'target' => str_repeat('b', 64), 'actor' => $actor, 'created' => $time, 'updated' => $time]);
        assertSameValue('protected', $cleanup->confirm($review->token, $actor)->outcomes[$id], 'late ' . $status . ' release protects preview');
        assertSameValue('release', $repository->cleanupItem($id, $clock->now())?->reason(), 'list explains retained reference');
    }

    $id = $ids[8];
    $share = $pdo->prepare('INSERT INTO cms_preview_shares (id, preview_id, token_hash, created_by, created_at, expires_at) VALUES (:id, :preview, :hash, :actor, :created, :expires)');
    $share->execute(['id' => str_repeat('e', 32), 'preview' => $id, 'hash' => str_repeat('c', 64), 'actor' => $actor, 'created' => $time, 'expires' => $clock->now()->modify('+1 day')->format('Y-m-d H:i:s.u')]);
    assertSameValue(1, $repository->cleanupItem($id, $clock->now())?->activeShares, 'active link count in review');
    $review = $cleanup->review([$id], $actor);
    assertSameValue('deleted', $cleanup->confirm($review->token, $actor)->outcomes[$id], 'confirmed deletion');
    assertSameValue(0, (int) $pdo->query("SELECT COUNT(*) FROM cms_preview_shares WHERE id = '" . str_repeat('e', 32) . "'")->fetchColumn(), 'share cascade invalidated');
    $audit = $pdo->prepare("SELECT COUNT(*) FROM cms_audit_events WHERE event_type = 'preview_deleted' AND metadata_json LIKE :preview AND actor_user_id = :actor");
    $audit->execute(['preview' => '%' . $id . '%', 'actor' => $actor]);
    assertSameValue(1, (int) $audit->fetchColumn(), 'one attributed deletion audit');
    assertSameValue('absent', $cleanup->confirm($review->token, $actor)->outcomes[$id], 'replay is read-only for missing row');

    // Audit constraint failure rolls back both row deletion and the bounded private rename.
    $existingAudit = $pdo->query('SELECT id FROM cms_audit_events LIMIT 1')->fetchColumn();
    assertTrueValue(is_string($existingAudit), 'audit fixture');
    $fixedRandom = new class ($existingAudit) implements RandomSource {
        public function __construct(private readonly string $id) {}
        public function bytes(int $length): string
        {
            return substr(str_repeat((string) hex2bin($this->id), 4), 0, $length);
        }
    };
    $id = $ids[9];
    $artifactRoot = $root . '/cleanup-integration/artifacts/' . $id;
    mkdir($artifactRoot, 0700, true);
    file_put_contents($artifactRoot . '/index.html', 'verified fixture');
    try {
        (new PreviewService($repository, $profile, $clock, $fixedRandom, $storage))->delete($id, $actor);
        throw new RuntimeException('Audit failure unexpectedly committed');
    } catch (PDOException) {
    }
    assertTrueValue($repository->find($id) !== null, 'preview/job transaction rolled back');
    assertSameValue('verified fixture', file_get_contents($artifactRoot . '/index.html'), 'artifact restored after real audit rollback');
    assertSameValue([], $storage->cleanupFiles()->pending(), 'rollback cleanup journal removed');

    // FK source-row serialization makes a publication racing a guarded delete fail safely.
    $other = new PDO($dsn, 'portable', 'integration-secret', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
    $other->exec('SET SESSION innodb_lock_wait_timeout = 1');
    $id = $ids[10];
    $blocked = false;
    $repository->deleteGuarded($id, $actor, bin2hex($random->bytes(16)), $clock->now(), static function () use ($other, $id, $actor, $time, &$blocked): void {
        try {
            $statement = $other->prepare("INSERT INTO cms_releases (id, preview_id, status, artifact_sha256, target_sha256, actor_user_id, created_at, updated_at) VALUES (:id, :preview, 'queued', :artifact, :target, :actor, :created, :updated)");
            $statement->execute(['id' => 'ec' . substr($id, 2), 'preview' => $id, 'artifact' => str_repeat('a', 64), 'target' => str_repeat('b', 64), 'actor' => $actor, 'created' => $time, 'updated' => $time]);
        } catch (PDOException $error) {
            $blocked = (int) ($error->errorInfo[1] ?? 0) === 1205;
        }
    });
    assertSameValue(true, $blocked, 'concurrent release cannot insert a reference during deletion');
    assertSameValue(null, $repository->find($id), 'locked deletion committed');
    $removeRelease = $pdo->prepare('DELETE FROM cms_releases WHERE id = :id');
    foreach (array_slice($ids, 0, 7) as $id) {
        $removeRelease->execute(['id' => 'ec' . substr($id, 2)]);
    }
    foreach (array_chunk($ids, 20) as $chunk) {
        $review = $cleanup->review($chunk, $actor);
        $result = $cleanup->confirm($review->token, $actor);
        while ($result->retry !== null) {
            $result = $cleanup->confirm($result->retry, $actor);
        }
    }
    assertSameValue([], $storage->cleanupFiles()->pending(), 'fixture cleanup fully settled');
    fwrite(STDOUT, "PASS bulk preview cleanup: 65-row stable pagination, all release states, late-reference races, attributed audit rollback, shares and replay\n");
}
