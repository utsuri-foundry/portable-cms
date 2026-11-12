<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Identity\Clock;
use Utsuri\PortableCms\Conventional\Identity\SessionContext;
use Utsuri\PortableCms\Conventional\Identity\SystemRandomSource;
use Utsuri\PortableCms\Conventional\JsonSchemaValidator;
use Utsuri\PortableCms\Conventional\Preview\PreviewService;
use Utsuri\PortableCms\Conventional\Publication\FilesystemPublicationTarget;
use Utsuri\PortableCms\Conventional\Publication\MariaDbFeedRepository;
use Utsuri\PortableCms\Conventional\Publication\MariaDbPublicationRepository;
use Utsuri\PortableCms\Conventional\Publication\PublicationFailure;
use Utsuri\PortableCms\Conventional\Publication\PublicationService;
use Utsuri\PortableCms\Conventional\RuntimeConfiguration;

final class FeedRetentionClock implements Clock
{
    public function __construct(public DateTimeImmutable $time) {}

    public function now(): DateTimeImmutable
    {
        return $this->time;
    }
}

/** Uses the real compiler, MariaDB activation transaction, target, Apache and fake elapsed time.
 * @param array<string, mixed> $first
 * @param array<string, mixed> $second
 */
function exerciseContentFeedRetention(PDO $pdo, RuntimeConfiguration $configuration, PreviewService $previews, SessionContext $actor, string $sourceRelease, string $forgottenRelease, array $first, array $second, string $state, PublicationApacheServer $server): void
{
    $clock = new FeedRetentionClock(new DateTimeImmutable('+1 day'));
    $schemaRoot = dirname(__DIR__, 2) . '/schemas';
    $publication = new PublicationService(
        new MariaDbPublicationRepository($pdo),
        $previews,
        new FilesystemPublicationTarget($state . '/public', $state . '/media/publication', hash_hmac('sha256', 'publication-target-v1', $configuration->throttleSecret, true)),
        $configuration->publicUrlContext ?? throw new RuntimeException('Missing public context'),
        'reference-studio',
        (string) file_get_contents($schemaRoot . '/portable-cms-artifact-v1.schema.json'),
        $clock,
        new SystemRandomSource(),
    );
    mkdir($state . '/different-target');
    $different = new PublicationService(
        new MariaDbPublicationRepository($pdo),
        $previews,
        new FilesystemPublicationTarget($state . '/different-target', $state . '/media/publication', str_repeat('x', 32)),
        $configuration->publicUrlContext ?? throw new RuntimeException('Missing public context'),
        'reference-studio',
        (string) file_get_contents($schemaRoot . '/portable-cms-artifact-v1.schema.json'),
        $clock,
        new SystemRandomSource(),
    );
    $pdo->exec('UPDATE cms_feed_state SET dirty = 1 WHERE singleton_id = 1');
    try {
        foreach ([static fn() => $different->runBatch(1), static fn() => $different->purgeFeed($first['snapshotId'], $actor, 1)] as $operation) {
            try {
                $operation();
                throw new RuntimeException('Feed work changed publication target implicitly');
            } catch (PublicationFailure $error) {
                assertSameValue('publication_target_changed', $error->codeName, 'outbox delivery and purge remain bound to the verified target');
            }
        }
        assertSameValue(['.', '..'], scandir($state . '/different-target'), 'a different root receives no feed files');
    } finally {
        $pdo->exec('UPDATE cms_feed_state SET dirty = 0 WHERE singleton_id = 1');
    }
    $before = (int) $pdo->query('SELECT COUNT(*) FROM cms_feed_activations')->fetchColumn();
    $baseTime = $clock->time;
    $clock->time = $baseTime->modify('+30 days');
    $publication->rollback($sourceRelease, $publication->head()->activeId, $actor);
    finishPublication($publication);
    $clock->time = $baseTime;
    for ($i = 0; $i < 11; ++$i) {
        $publication->rollback($sourceRelease, $publication->head()->activeId, $actor);
        finishPublication($publication);
    }
    assertSameValue($before + 12, (int) $pdo->query('SELECT COUNT(*) FROM cms_feed_activations')->fetchColumn(), 'fourteen days protects more than ten recent activations');
    $publication->forget($forgottenRelease, $actor);
    assertSameValue(200, $server->get('/' . $second['fullRead'])['status'], 'feed retention is independent of forgetting a private release');
    $clock->time = $clock->time->modify('+16 days');
    $publication->runBatch(8);
    finishPublication($publication);
    assertSameValue(12, (int) $pdo->query('SELECT COUNT(*) FROM cms_feed_activations')->fetchColumn(), 'a backward clock adjustment cannot prune a still-recent activation through a later expired timestamp');
    $clock->time = $baseTime->modify('+46 days');
    $publication->runBatch(8);
    finishPublication($publication);
    assertSameValue(10, (int) $pdo->query('SELECT COUNT(*) FROM cms_feed_activations')->fetchColumn(), 'the last ten activations stay even after fourteen days');
    assertSameValue(404, $server->get('/' . $second['fullRead'])['status'], 'the publisher removes snapshot files outside both retention bounds');
    $pointer = json_decode((string) file_get_contents($state . '/public/feed/v1/current.json'), true, flags: JSON_THROW_ON_ERROR);
    assertTrueValue($pointer['cursorFloor'] > $first['sequence'], 'a cursor before the retained prefix is explicitly reset to a full read');
    assertSameValue($first['fullRead'], $pointer['fullRead'], 'reset points at the exact current snapshot');
    $repository = new MariaDbFeedRepository($pdo);
    $mediaPaths = array_values(array_filter(array_keys($repository->retention()->files), static fn(string $path): bool => str_starts_with($path, 'feed/v1/media/')));
    assertTrueValue($mediaPaths !== [], 'a retained snapshot keeps its derivative copies');
    $auditId = $pdo->query('SELECT id FROM cms_audit_events LIMIT 1')->fetchColumn();
    try {
        $repository->purge($first['snapshotId'], $actor->userId ?? throw new RuntimeException('Missing actor'), $auditId, $clock->now());
        throw new RuntimeException('An unaudited purge committed');
    } catch (PDOException) {
    }
    $purged = $pdo->prepare('SELECT purged FROM cms_feed_snapshots WHERE id = :id');
    $purged->execute(['id' => $first['snapshotId']]);
    assertSameValue(0, (int) $purged->fetchColumn(), 'an audit failure rolls the purge back');
    $process = proc_open([PHP_BINARY, $state . '/bundle/private/runtime/tools/conventional-purge-feed.php', '--config=' . $state . '/cms.json', '--snapshot=' . $first['snapshotId'], '--confirm=' . $first['snapshotId'], '--owner=publisher@example.test', '--maximum-files=1'], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Packaged feed purge command did not start');
    }
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    assertSameValue(2, proc_close($process), 'the installed operator command distinguishes incomplete purge: ' . $out . $err);
    assertSameValue('purging', json_decode($out, true, flags: JSON_THROW_ON_ERROR)['status'], 'CLI status matches its incomplete exit code');
    $complete = false;
    for ($i = 0; $i < 100 && !$complete; ++$i) {
        $complete = $publication->purgeFeed($first['snapshotId'], $actor, 1);
    }
    assertTrueValue($complete, 'an interrupted purge resumes with bounded batches');
    assertSameValue(404, $server->get('/' . $first['fullRead'])['status'], 'an explicit purge removes the current snapshot immediately instead of waiting for retention');
    foreach ($mediaPaths as $path) {
        assertSameValue(404, $server->get('/' . $path)['status'], 'purge removes derivative copies with no remaining retained reference');
    }
    assertSameValue(200, $server->get('/media/d0000000000000000000000000000001-display_960_webp.webp')['status'], 'feed purge does not remove independent website media');
    $pointerJson = (string) file_get_contents($state . '/public/feed/v1/current.json');
    $pointer = json_decode($pointerJson, true, flags: JSON_THROW_ON_ERROR);
    assertSameValue(['purged', null, null], [$pointer['status'], $pointer['snapshotId'], $pointer['fullRead']], 'the current pointer explicitly suspends full reads after purging its snapshot');
    $audit = $pdo->query("SELECT actor_user_id, metadata_json FROM cms_audit_events WHERE event_type = 'feed_snapshot_purged'")->fetchAll(PDO::FETCH_ASSOC);
    assertSameValue(1, count($audit), 'purge retries produce one attributed audit');
    assertSameValue($actor->userId, $audit[0]['actor_user_id'], 'purge audit identifies the operator');
    try {
        $publication->rollback($sourceRelease, $publication->head()->activeId, $actor);
        throw new RuntimeException('A purged snapshot was republished');
    } catch (PublicationFailure $error) {
        assertSameValue('feed_snapshot_purged', $error->codeName, 'retained artifacts cannot resurrect a purged feed');
    }
    JsonSchemaValidator::validateJson($pointerJson, (string) file_get_contents($schemaRoot . '/portable-cms-feed-v1.schema.json'));
    $listingJson = (string) file_get_contents($state . '/public/feed/v1/activations.json');
    JsonSchemaValidator::validateJson($listingJson, (string) file_get_contents($schemaRoot . '/portable-cms-feed-activations-v1.schema.json'));
    foreach ($repository->activations() as $activation) {
        $path = $activation->path();
        JsonSchemaValidator::validateJson((string) file_get_contents($state . '/public/' . $path), (string) file_get_contents($schemaRoot . '/portable-cms-feed-activation-v1.schema.json'));
    }
    // Compact retired metadata reaches the real tombstone cap without publishing fixture content.
    $insert = $pdo->prepare('INSERT INTO cms_feed_snapshots (id, manifest_json, files_json, purged) VALUES (:id, :manifest, :files, :purged)');
    $marker = '{"capacityFixture":true}';
    $pdo->beginTransaction();
    for ($i = 0; $i < 9999; ++$i) {
        $insert->execute(['id' => hash('sha256', 'retired-feed-capacity-' . $i), 'manifest' => $marker, 'files' => '[]', 'purged' => 1]);
    }
    $extra = hash('sha256', 'new-feed-at-capacity');
    $insert->execute(['id' => $extra, 'manifest' => $marker, 'files' => '[]', 'purged' => 0]);
    $pdo->commit();
    try {
        $repository->purge($first['snapshotId'], $actor->userId ?? throw new RuntimeException('Missing actor'), bin2hex(random_bytes(16)), $clock->now());
        assertSameValue(1, (int) $pdo->query("SELECT COUNT(*) FROM cms_audit_events WHERE event_type = 'feed_snapshot_purged'")->fetchColumn(), 'a completed purge stays replayable at the tombstone cap without another audit');
        try {
            $repository->purge($extra, $actor->userId ?? throw new RuntimeException('Missing actor'), bin2hex(random_bytes(16)), $clock->now());
            throw new RuntimeException('Purge tombstones exceeded their bound');
        } catch (PublicationFailure $error) {
            assertSameValue('feed_purge_capacity', $error->codeName, 'a new purge fails closed at capacity while retries remain possible');
        }
    } finally {
        $cleanup = $pdo->prepare('DELETE FROM cms_feed_snapshots WHERE manifest_json = :marker');
        $cleanup->execute(['marker' => $marker]);
    }
    fwrite(STDOUT, "PASS static content feed: exact-preview snapshots, committed activation outbox resume, apply/rollback deltas, ten-activation and fourteen-day retention, independent media, cursor reset, audited resumable purge and resurrection refusal.\n");
}
