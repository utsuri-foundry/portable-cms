<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Identity\Clock;
use Utsuri\PortableCms\Conventional\Identity\SessionContext;
use Utsuri\PortableCms\Conventional\Identity\SystemRandomSource;
use Utsuri\PortableCms\Conventional\Preview\PreviewNotDeletable;
use Utsuri\PortableCms\Conventional\Preview\PreviewService;
use Utsuri\PortableCms\Conventional\Publication\FilesystemPublicationTarget;
use Utsuri\PortableCms\Conventional\Publication\MariaDbPublicationRepository;
use Utsuri\PortableCms\Conventional\Publication\PublicationConflict;
use Utsuri\PortableCms\Conventional\Publication\PublicationFailure;
use Utsuri\PortableCms\Conventional\Publication\PublicationService;
use Utsuri\PortableCms\Conventional\Publication\Release;
use Utsuri\PortableCms\Conventional\RuntimeConfiguration;
use Utsuri\PortableCms\Conventional\View\AdminChrome;
use Utsuri\PortableCms\Conventional\View\PublicationPage;

final class ReleaseScheduleTestClock implements Clock
{
    public function __construct(public DateTimeImmutable $time) {}

    public function now(): DateTimeImmutable
    {
        return $this->time;
    }
}

function scheduleConflict(Closure $operation): void
{
    try {
        $operation();
        throw new RuntimeException('A stale schedule mutation succeeded');
    } catch (PublicationConflict) {
    }
}

function scheduleTestService(PDO $pdo, RuntimeConfiguration $configuration, PreviewService $previews, ReleaseScheduleTestClock $clock): PublicationService
{
    return new PublicationService(
        new MariaDbPublicationRepository($pdo),
        $previews,
        new FilesystemPublicationTarget($configuration->publicationRoot ?? throw new RuntimeException('Missing public root'), $configuration->mediaRoot . '/publication', hash_hmac('sha256', 'publication-target-v1', $configuration->throttleSecret, true)),
        $configuration->publicUrlContext ?? throw new RuntimeException('Missing public context'),
        'reference-studio',
        (string) file_get_contents(dirname(__DIR__, 2) . '/schemas/portable-cms-artifact-v1.schema.json'),
        $clock,
        new SystemRandomSource(),
        timeZone: new DateTimeZone('Europe/Andorra'),
    );
}

/** Uses the real lock, SQL transactions, exact compiler artifacts and bounded publication target. */
function exerciseReleaseSchedules(PDO $pdo, RuntimeConfiguration $configuration, PreviewService $previews, SessionContext $actor, string $previewId, DateTimeImmutable $now, string $privateDraftId): Release
{
    $clock = new ReleaseScheduleTestClock($now);
    $repository = new MariaDbPublicationRepository($pdo);
    $service = scheduleTestService($pdo, $configuration, $previews, $clock);
    $previous = $service->head()->activeId;
    $at = $now->modify('+1 hour');
    $schedule = $service->approve($previewId, $previous, $actor, $at);
    assertSameValue(['scheduled', $previous, null, false], [$schedule->status, $service->head()->activeId, $service->head()->pendingId, $service->runnable()], 'future schedules keep the publishing slot free and do not spin workers');
    assertSameValue($service->artifact($previewId)->checksum, $schedule->artifactChecksum, 'approval captures the exact frozen artifact');
    assertTrueValue(in_array($schedule->id, array_column($service->scheduledForRecord('project', '10000000000000000000000000000001'), 'id'), true), 'record notices resolve frozen snapshot membership');
    assertSameValue([], $service->scheduledForRecord('article', str_repeat('f', 32)), 'unrelated records have no schedule notice');
    assertSameValue([], $service->scheduledForRecord('article', $privateDraftId), 'a draft retained in the snapshot is not advertised as scheduled public content');
    try {
        $previews->delete($previewId, $actor->userId);
        throw new RuntimeException('Scheduled artifact was deletable');
    } catch (PreviewNotDeletable) {
    }
    $service->changeSchedule($schedule->id, 1, $at->modify('+1 hour'), $actor);
    scheduleConflict(static fn() => $service->changeSchedule($schedule->id, 1, null, $actor));
    assertSameValue($at->modify('+1 hour')->format(DATE_ATOM), $repository->find($schedule->id)->scheduledAt->format(DATE_ATOM), 'stale cancellation does not overwrite a moved schedule');
    $service->changeSchedule($schedule->id, 2, $at, $actor);
    $clock->time = $at->modify('-1 microsecond');
    assertSameValue(false, $service->runnable(), 'one microsecond before the boundary is idle');
    assertSameValue(false, $service->runBatch(1), 'direct worker call cannot start early');
    assertSameValue(null, $service->head()->pendingId, 'early worker never reserves publication');
    $clock->time = $at;
    assertSameValue(true, $service->runnable(), 'the exact boundary is runnable');
    $service->startScheduled();
    assertSameValue(['queued', $schedule->id], [$repository->find($schedule->id)->status, $service->head()->pendingId], 'worker admission reserves due intent before expensive media jobs');
    $service->runBatch(1);
    assertSameValue($schedule->id, $service->head()->pendingId, 'worker claims the approved artifact without an interactive account');
    scheduleConflict(static fn() => $service->changeSchedule($schedule->id, 3, null, $actor));
    scheduleConflict(static fn() => $service->changeSchedule($schedule->id, 3, $at->modify('+1 hour'), $actor));
    finishPublication($service);
    assertSameValue($schedule->id, $service->head()->activeId, 'normal bounded verification activates a scheduled release');
    assertSameValue($previews->artifact($previewId, 'en/index.html')->contents, file_get_contents($configuration->publicationRoot . '/en/index.html'), 'scheduled bytes are identical to the approved preview');

    $staleAt = $at->modify('+1 hour');
    $stale = $service->approve($previewId, $schedule->id, $actor, $staleAt);
    $intervening = $service->approve($previewId, $schedule->id, $actor);
    finishPublication($service);
    scheduleConflict(static fn() => $service->forget($schedule->id, $actor));
    $clock->time = $staleAt;
    $service->runBatch(1);
    assertSameValue(['needs_approval', $intervening->id, null], [$repository->find($stale->id)->status, $service->head()->activeId, $service->head()->pendingId], 'an intervening release requires a new approval and never overwrites newer content');
    scheduleConflict(static fn() => $service->changeSchedule($stale->id, 2, $staleAt->modify('+1 hour'), $actor));
    $service->changeSchedule($stale->id, 2, null, $actor);
    $cancelled = $service->approve($previewId, $intervening->id, $actor, $clock->time->modify('+1 hour'));
    $existingAudit = $pdo->query('SELECT id FROM cms_audit_events LIMIT 1')->fetchColumn();
    try {
        $repository->changeSchedule($cancelled->id, 1, $clock->time->modify('+2 hours'), $actor->userId, $existingAudit, $clock->now());
        throw new RuntimeException('Duplicate audit identity accepted');
    } catch (PDOException) {
        assertSameValue([1, $cancelled->scheduledAt->format(DATE_ATOM)], [$repository->find($cancelled->id)->scheduleRevision, $repository->find($cancelled->id)->scheduledAt->format(DATE_ATOM)], 'a failed audit rolls back the schedule mutation');
    }
    $service->changeSchedule($cancelled->id, 1, null, $actor);
    scheduleConflict(static fn() => $service->changeSchedule($cancelled->id, 1, null, $actor));
    $clock->time = $clock->time->modify('+2 hours');
    $service->runBatch(1);
    assertSameValue(['cancelled', $intervening->id, false], [$repository->find($cancelled->id)->status, $service->head()->activeId, $service->runnable()], 'cancelled schedules stay inert past their original time');

    $editor = new SessionContext('session', 'authenticated', $actor->userId, 'editor@example.test', 'Editor', 'editor', 'token', 'csrf', $clock->time->modify('+1 day'), false);
    foreach ([static fn() => $service->approve($previewId, $intervening->id, $editor, $clock->time->modify('+1 hour')), static fn() => $service->changeSchedule($cancelled->id, 2, null, $editor), static fn() => $service->changeSchedule($cancelled->id, 2, $clock->time->modify('+1 hour'), $editor)] as $operation) {
        try {
            $operation();
            throw new RuntimeException('Editor mutated a release schedule');
        } catch (PublicationFailure $failure) {
            assertSameValue('authorization_required', $failure->codeName, 'service authority protects all schedule commands');
        }
    }
    $profile = \Utsuri\PortableCms\Conventional\Content\ProfilePackage::load($configuration->profileRoot ?? throw new RuntimeException('Missing profile'))->manifest;
    $page = PublicationPage::render($profile, new AdminChrome('csrf', 'Editor', role: 'editor'), $service, $previews);
    assertTrueValue(!str_contains($page, 'action="/releases"'), 'editors see the release list without mutation controls');
    $audit = $pdo->query("SELECT actor_user_id, event_type, metadata_json FROM cms_audit_events WHERE event_type LIKE 'release_schedul%' ORDER BY occurred_at, id")->fetchAll(PDO::FETCH_ASSOC);
    assertTrueValue(count($audit) >= 9, 'schedule creation, moves, cancellation, start and stale approval are durable audit facts');
    foreach ($audit as $event) {
        assertSameValue($actor->userId, $event['actor_user_id'], 'every change retains the initiating account');
        assertTrueValue(str_contains($event['metadata_json'], 'releaseId'), 'audit identifies the precise release');
    }
    $service->forget($cancelled->id, $actor);
    $service->forget($stale->id, $actor);
    assertSameValue(null, $repository->find($cancelled->id), 'cancelled approvals can be forgotten without deleting audit');
    fwrite(STDOUT, "PASS scheduled releases: exact artifact, timezone, boundary, worker activation, intervening release, revision conflicts, cancellation, roles and attributed audit.\n");
    return $intervening;
}
