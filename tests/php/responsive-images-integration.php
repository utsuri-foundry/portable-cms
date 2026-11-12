<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Content\ProfilePackage;
use Utsuri\PortableCms\Conventional\Identity\Clock;
use Utsuri\PortableCms\Conventional\Identity\SystemRandomSource;
use Utsuri\PortableCms\Conventional\Media\FilesystemMediaStore;
use Utsuri\PortableCms\Conventional\Media\ImagickRasterProcessor;
use Utsuri\PortableCms\Conventional\Media\MariaDbMediaJobRepository;
use Utsuri\PortableCms\Conventional\Media\MariaDbRasterWidthQueue;
use Utsuri\PortableCms\Conventional\Media\MediaJobRunner;
use Utsuri\PortableCms\Conventional\Preview\MariaDbPreviewRepository;
use Utsuri\PortableCms\Conventional\Preview\ProfilePreviewCompiler;
use Utsuri\PortableCms\Conventional\RuntimeConfiguration;

final class RasterWidthIntegrationClock implements Clock
{
    public function __construct(public DateTimeImmutable $current) {}

    public function now(): DateTimeImmutable
    {
        return $this->current;
    }
}

/** Real immutable commits, expired leases and persisted pre/post-upgrade snapshots. */
function verifyRasterWidthRecovery(PDO $pdo, RuntimeConfiguration $configuration, string $actor, ProfilePackage $package): void
{
    $id = str_repeat('e', 32);
    $originalJob = str_repeat('f', 32);
    $owner = '10000000000000000000000000000001';
    $clock = new RasterWidthIntegrationClock(new DateTimeImmutable('2026-10-12T12:00:00Z'));
    $random = new SystemRandomSource();
    $time = $clock->current->format('Y-m-d H:i:s.u');
    $directory = $configuration->mediaRoot . '/originals/' . $id;
    mkdir($directory, 0700, true);
    $path = $directory . '/source';
    $image = new Imagick();
    try {
        $image->newImage(3200, 1800, new ImagickPixel('#6f806a'));
        $image->setImageFormat('png');
        $image->writeImage($path);
    } finally {
        $image->clear();
        $image->destroy();
    }
    $size = filesize($path);
    $checksum = hash_file('sha256', $path);
    $insert = $pdo->prepare("INSERT INTO cms_media (id, owner_record_id, status, original_name, declared_type, byte_size, checksum, original_key, created_by, created_at, updated_at) VALUES (:id, :owner, 'uploaded', 'Fictional large still.png', 'image/png', :size, :checksum, :original, :actor, :created, :updated)");
    $insert->execute(['id' => $id, 'owner' => $owner, 'size' => $size, 'checksum' => $checksum, 'original' => 'originals/' . $id . '/source', 'actor' => $actor, 'created' => $time, 'updated' => $time]);
    $insert = $pdo->prepare("INSERT INTO cms_jobs (id, job_type, status, payload_json, available_at, created_at, updated_at) VALUES (:id, 'media_derivatives', 'queued', :payload, :available, :created, :updated)");
    $insert->execute(['id' => $originalJob, 'payload' => json_encode(['formatVersion' => 1, 'mediaId' => $id, 'expectedByteSize' => $size, 'declaredType' => 'image/png'], JSON_THROW_ON_ERROR), 'available' => $time, 'created' => $time, 'updated' => $time]);
    $jobs = new MariaDbMediaJobRepository($pdo);
    $storage = new FilesystemMediaStore($configuration->mediaRoot);
    $processor = new ImagickRasterProcessor();
    assertTrueValue((new MediaJobRunner($jobs, $storage, $processor, $clock, $random))->runOne('width-original')->completed, 'legacy 960-only processing completes');
    $defaultPath = $configuration->mediaRoot . '/derivatives/' . $id . '/' . $id . '-960-webp.webp';
    $defaultChecksum = hash_file('sha256', $defaultPath);
    $previews = new MariaDbPreviewRepository($pdo, $package->manifest->designRegistries, $package->checksum);
    $before = $previews->enqueue(str_repeat('b', 32), str_repeat('b', 31) . '1', bin2hex($random->bytes(16)), $actor, $package->manifest->id, $package->manifest->version, $package->manifest->locales, $clock->current);
    $query = $pdo->prepare('SELECT snapshot_json FROM cms_previews WHERE id = :id');
    $query->execute(['id' => $before->id]);
    $frozenBefore = $query->fetchColumn();
    $queue = new MariaDbRasterWidthQueue($pdo);
    $queue->enqueue($clock->current);
    $queue->enqueue($clock->current);
    $count = $pdo->prepare("SELECT COUNT(*) FROM cms_jobs WHERE JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.mediaId')) = :id");
    $count->execute(['id' => $id]);
    assertSameValue(4, (int) $count->fetchColumn(), 'upgrade backfill creates exactly one durable job per missing width');
    assertSameValue(0, (int) $pdo->query("SELECT COUNT(*) FROM cms_jobs WHERE job_type = 'media_derivatives' AND status = 'queued'")->fetchColumn(), 'older workers never claim new backfill recipes after an engine rollback');
    $job = $jobs->claim('width-interrupted', bin2hex($random->bytes(32)), $clock->current, $clock->current->modify('+15 minutes'));
    if ($job === null) {
        throw new RuntimeException('Backfill did not create claimable work');
    }
    $source = $jobs->source($job);
    $work = $storage->workingDirectory($job);
    $processed = $processor->process($source, $storage->sourcePath($source), $work);
    $storage->commitDerivative($processed->derivative, $work . '/' . $processed->derivative->filename . '.tmp');
    $storage->removeWorkingDirectory($work);
    // Stop after the file commit, before the database transaction, as a terminated worker would.
    $media = $pdo->prepare('SELECT status FROM cms_media WHERE id = :id');
    $media->execute(['id' => $id]);
    assertSameValue('ready', $media->fetchColumn(), 'processing extra widths never hides the existing ready image');
    $overlap = $jobs->claim('width-overlap', bin2hex($random->bytes(32)), $clock->current, $clock->current->modify('+15 minutes'));
    assertTrueValue($overlap === null || $overlap->id !== $job->id, 'a live lease cannot be stolen');
    $clock->current = $clock->current->modify('+16 minutes');
    $runner = new MediaJobRunner(new MariaDbMediaJobRepository($pdo, prepareWidths: true), $storage, $processor, $clock, $random);
    // An overlap may have claimed a different width; advancing the clock recovers both leases.
    $runs = $runner->runBounded('width-recovery', 10, 60);
    assertSameValue(3, count(array_filter($runs, static fn($run): bool => $run->completed)), 'all interrupted and pending widths recover through ordinary jobs');
    $variants = $pdo->prepare('SELECT variant FROM cms_media_derivatives WHERE media_id = :id ORDER BY width');
    $variants->execute(['id' => $id]);
    assertSameValue(['480-webp', '960-webp', '1600-webp', '2400-webp'], $variants->fetchAll(PDO::FETCH_COLUMN), 'all finite widths are recorded');
    assertSameValue($defaultChecksum, hash_file('sha256', $defaultPath), 'upgrade never replaces the legacy derivative');
    $query->execute(['id' => $before->id]);
    assertSameValue($frozenBefore, $query->fetchColumn(), 'a saved preview retains exactly its pre-upgrade media facts');
    $compiler = new ProfilePreviewCompiler($package, $configuration->mediaRoot, dirname(__DIR__, 2) . '/schemas');
    foreach ([false, true] as $new) {
        if ($new) {
            $previews->enqueue(str_repeat('c', 32), str_repeat('c', 31) . '1', bin2hex($random->bytes(16)), $actor, $package->manifest->id, $package->manifest->version, $package->manifest->locales, $clock->current);
        }
        $previewJob = $previews->claim('width-projection', bin2hex($random->bytes(32)), $clock->current, $clock->current->modify('+15 minutes'));
        if ($previewJob === null) {
            throw new RuntimeException('Preview projection is unavailable');
        }
        $snapshot = $compiler->freeze($previews->snapshot($previewJob));
        assertSameValue($new ? [480, 1600, 2400] : [], array_map(static fn($width): int => $width->maximumWidth, $snapshot->media($id)->widths), 'only a new snapshot gains the completed widths');
        $previews->fail($previewJob, 'fixture_finished', false, $clock->current, $clock->current);
        $previews->delete($previewJob->previewId, $actor, bin2hex($random->bytes(16)), $clock->current);
    }
    $staleId = bin2hex($random->bytes(16));
    $staleMedia = str_repeat('a', 32);
    $staleTime = $clock->current->format('Y-m-d H:i:s.u');
    $stale = $pdo->prepare("INSERT INTO cms_jobs (id, job_type, status, payload_json, available_at, created_at, updated_at) VALUES (:id, 'media_image_width', 'queued', :payload, :available, :created, :updated)");
    $stale->execute(['id' => $staleId, 'payload' => json_encode(['formatVersion' => 1, 'mediaId' => $staleMedia, 'expectedByteSize' => $size, 'declaredType' => 'image/png', 'maximumWidth' => 480], JSON_THROW_ON_ERROR), 'available' => $staleTime, 'created' => $staleTime, 'updated' => $staleTime]);
    $failure = $runner->runOne('width-deleted-source');
    assertSameValue('source_missing', $failure->errorCode, 'a deleted source becomes a bounded job failure rather than blocking every claim');
    assertSameValue('needs_attention', $jobs->publicStatus($staleId)['status'], 'the stale job has observable terminal state');
    $media->execute(['id' => $id]);
    assertSameValue('ready', $media->fetchColumn(), 'a failed backfill never changes the ready fallback');
    $delete = $pdo->prepare('DELETE FROM cms_jobs WHERE id = :id');
    $delete->execute(['id' => $staleId]);
    $delete = $pdo->prepare("DELETE FROM cms_jobs WHERE JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.mediaId')) = :id");
    $delete->execute(['id' => $id]);
    $delete = $pdo->prepare('DELETE FROM cms_media WHERE id = :id');
    $delete->execute(['id' => $id]);
    fwrite(STDOUT, "PASS responsive image upgrade backfill, duplicate wakes, live/expired leases, interrupted immutable commit, ready fallback and frozen preview projections.\n");
}
