<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Export\ExportBudget;
use Utsuri\PortableCms\Conventional\Restore\FilesystemRestoreStore;
use Utsuri\PortableCms\Conventional\Restore\RestorePlan;
use Utsuri\PortableCms\Conventional\Restore\RestoreRejected;
use Utsuri\PortableCms\Conventional\Restore\RestoreRepository;
use Utsuri\PortableCms\Conventional\Restore\RestoreService;
use Utsuri\PortableCms\Conventional\Transfer\OperationLimits;
use Utsuri\PortableCms\Conventional\Transfer\PackageFiles;
use Utsuri\PortableCms\Conventional\Transfer\PackageManifest;

final class TransferTestRepository implements RestoreRepository
{
    public string $state = 'empty';
    public int $commits = 0;

    public function inspect(RestorePlan $plan, string $destinationIdentity, string $actorId): string
    {
        return $this->state;
    }

    public function reserve(RestorePlan $plan, string $destinationIdentity, string $actorId, DateTimeImmutable $now): string
    {
        return $this->state = 'preparing';
    }

    public function commit(RestorePlan $plan, string $destinationIdentity, string $actorId, DateTimeImmutable $now): void
    {
        $this->commits++;
        $this->state = 'completed';
    }
}

/** @return array{string, RestorePlan, FilesystemRestoreStore, FilesystemRestoreStore} */
function transferUnitFiles(): array
{
    $root = sys_get_temp_dir() . '/portable-transfer-unit-' . bin2hex(random_bytes(8));
    mkdir($root, 0700);
    mkdir($root . '/input', 0700);
    mkdir($root . '/input/blobs', 0700);
    mkdir($root . '/destination', 0700);
    $plan = restoreFixturePlan();
    foreach (restoreFixtureExport($plan)->document['media'] as $media) {
        copy($plan->package->root . '/fixtures/media/' . $media['id'] . '-display_1600_webp.webp', $root . '/input/blobs/' . $media['original']['sha256']);
    }
    file_put_contents($root . '/input/export.json', restoreFixtureExport($plan)->json());
    return [$root, $plan, new FilesystemRestoreStore($root . '/input'), new FilesystemRestoreStore($root . '/destination')];
}

$tests['portable completion proof binds exact profile envelope inventory and engine without executable paths'] = static function (): void {
    $plan = restoreFixturePlan();
    $size = strlen(restoreFixtureExport($plan)->json());
    $manifest = PackageManifest::create($plan, $size);
    $schema = (string) file_get_contents(dirname(__DIR__, 2) . '/schemas/portable-cms-content-package-v1.schema.json');
    $read = PackageManifest::read($manifest->json, $schema);
    $read->assertPlan($plan, $size);
    assertSameValue($manifest->checksum(), $read->checksum(), 'exact manifest bytes are the confirmation boundary');
    assertSameValue($plan->package->checksum, $read->document['profile']['packageSha256'], 'independent installed profile checksum is explicit');
    foreach (['engine', 'profile', 'export', 'inventory', 'inventory-type', 'code'] as $case) {
        $value = $manifest->document;
        match ($case) {
            'engine' => $value['engineVersion'] = '9.0.0',
            'profile' => $value['profile']['packageSha256'] = str_repeat('f', 64),
            'export' => $value['export']['sha256'] = str_repeat('f', 64),
            'inventory' => $value['inventory']['files']++,
            'inventory-type' => $value['inventory']['files'] = (float) $value['inventory']['files'],
            'code' => $value['profile']['phpPath'] = '../../outside/build.php',
        };
        try {
            PackageManifest::read(json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), $schema)->assertPlan($plan, $size);
            throw new RuntimeException('A changed completion proof was accepted: ' . $case);
        } catch (RestoreRejected) {
        }
    }
};

$tests['portable operation limits are finite and only bounded interruption is incomplete'] = static function (): void {
    assertSameValue(120, OperationLimits::fromOptions([])->seconds, 'default overall deadline');
    foreach ([['maximum-seconds' => '601'], ['maximum-batches' => '0'], ['maximum-files' => '65'], ['maximum-seconds' => ['1']]] as $options) {
        try {
            OperationLimits::fromOptions($options);
            throw new RuntimeException('Unbounded or ambiguous operation limits were accepted');
        } catch (RestoreRejected) {
        }
    }
    assertTrueValue(OperationLimits::incomplete(new RestoreRejected('export_runtime_budget_exhausted')), 'deadline exhaustion resumes');
    assertTrueValue(!OperationLimits::incomplete(new RestoreRejected('missing_export_chunk')), 'corruption never becomes incomplete success');
};

$tests['portable restore drives legacy batches once and resumes bounded interruptions before a single commit'] = static function (): void {
    [$root, $plan, $input, $destination] = transferUnitFiles();
    try {
        $clock = new IdentityTestClock(new DateTimeImmutable('2026-10-19T12:00:00Z'));
        $repository = new TransferTestRepository();
        $service = new RestoreService($repository, $destination, $clock);
        $seen = [];
        $progress = static function (array $report) use (&$seen): void {
            $seen[] = $report['preparedFiles'];
        };
        assertSameValue('preparing', $service->complete($plan, $input, str_repeat('a', 32), $plan->sha256, 1, 1, progress: $progress)['status'], 'finite batch exhaustion is incomplete');
        assertSameValue(0, $repository->commits, 'no partial content becomes authoritative');
        $interrupt = static function () use ($clock): void {
            $clock->current = $clock->current->modify('+2 seconds');
        };
        try {
            $service->complete($plan, $input, str_repeat('a', 32), $plan->sha256, 1, 10, new ExportBudget($clock, 1), $interrupt);
            throw new RuntimeException('A clock deadline was ignored');
        } catch (RestoreRejected $error) {
            assertTrueValue(OperationLimits::incomplete($error), 'injected time interruption has a stable incomplete reason');
        }
        assertSameValue('restored', $service->complete($plan, $input, str_repeat('a', 32), $plan->sha256, 1, progress: $progress)['status'], 'one later invocation completes all remaining batches');
        assertTrueValue($seen[1] > $seen[0], 'resume starts beyond the prepared prefix');
        assertSameValue(1, $repository->commits, 'exactly one authoritative commit');
        assertSameValue('already_restored', $service->complete($plan, $input, str_repeat('a', 32), $plan->sha256)['status'], 'completed replay verifies rather than reinserting');
    } finally {
        RestoreTestFixture::remove($root);
    }
};

$tests['portable metadata commits never replace another proof and final restore checks reject changed intent'] = static function (): void {
    [$root, $plan, $input, $destination] = transferUnitFiles();
    try {
        PackageFiles::commit($input, 'package.json', '{"version":1}');
        PackageFiles::commit($input, 'package.json', '{"version":1}');
        try {
            PackageFiles::commit($input, 'package.json', '{"version":2}');
            throw new RuntimeException('A completion proof was replaced');
        } catch (RestoreRejected) {
        }
        $repository = new TransferTestRepository();
        $checks = 0;
        $verify = static function () use (&$checks): void {
            if (++$checks > 1) {
                throw new RestoreRejected('package_metadata_changed');
            }
        };
        try {
            (new RestoreService($repository, $destination, new IdentityTestClock(new DateTimeImmutable('2026-10-19T12:00:00Z'))))->complete($plan, $input, str_repeat('a', 32), $plan->sha256, 1, verifyIntent: $verify);
            throw new RuntimeException('A changed package intent reached SQL');
        } catch (RestoreRejected $error) {
            assertSameValue('package_metadata_changed', $error->reason, 'intent is checked again at the commit boundary');
            assertSameValue(0, $repository->commits, 'no content commit after intent tampering');
        }
    } finally {
        RestoreTestFixture::remove($root);
    }
};
