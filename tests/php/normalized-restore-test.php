<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Content\ProfilePackage;
use Utsuri\PortableCms\Conventional\Export\NormalizedExport;
use Utsuri\PortableCms\Conventional\Identity\SystemRandomSource;
use Utsuri\PortableCms\Conventional\Restore\FilesystemRestoreStore;
use Utsuri\PortableCms\Conventional\Restore\RestorePlan;
use Utsuri\PortableCms\Conventional\Restore\RestoreRejected;

require_once __DIR__ . '/restore-fixture.php';

function restoreFixturePlan(?callable $mutate = null): RestorePlan
{
    $package = ProfilePackage::load(dirname(__DIR__, 2) . '/profiles/reference-studio');
    $document = RestoreTestFixture::document($package);
    if ($mutate !== null) {
        $mutate($document);
    }
    $export = new NormalizedExport($document);
    return new RestorePlan($export, hash('sha256', $export->json()), $package, new IdentityTestClock(new DateTimeImmutable('2026-10-04T09:00:00Z')), new SystemRandomSource());
}

/** Legacy-fixture assertions deliberately keep using the original JSON representation. */
function restoreFixtureExport(RestorePlan $plan): NormalizedExport
{
    return $plan->export instanceof NormalizedExport ? $plan->export : throw new RuntimeException('Expected a legacy fixture export');
}

$tests['restore plan validates every revision and rejects lossy mappings and stale graph projections'] = static function (): void {
    $plan = restoreFixturePlan();
    assertSameValue(6, count($plan->files), 'original and derivative for each fictional raster');
    assertTrueValue(!isset(restoreFixtureExport($plan)->document['revisions'][0]['body']['searchSharing']), 'legacy absent SEO remains absent');
    $fluid = restoreFixturePlan(static function (array &$document): void {
        $document['revisions'][0]['body']['layout']['rows'][0]['columns'][0]['share'] = 7.5;
    });
    assertSameValue(7.5, restoreFixtureExport($fluid)->document['revisions'][0]['body']['layout']['rows'][0]['columns'][0]['share'], 'fractional composition values retain their exact meaning');
    $mutations = [
        static function (array &$document): void {
            $document['profile']['version'] = '9.0.0';
        },
        static function (array &$document): void {
            $document['revisions'][0]['body']['unknownField'] = 'x';
        },
        static function (array &$document): void {
            $document['revisions'][0]['body']['year'] = '2025';
        },
        static function (array &$document): void {
            $document['revisions'][0]['body']['title']['fr'] = 'Unknown locale';
        },
        static function (array &$document): void {
            $document['revisions'][0]['body']['gallery'][] = str_repeat('f', 32);
        },
        static function (array &$document): void {
            $document['revisions'][0]['body']['layout']['rows'][0]['columns'][0]['content']['id'] = str_repeat('f', 32);
        },
        static function (array &$document): void {
            array_pop($document['relationships']);
        },
        static function (array &$document): void {
            $document['media'][0]['status'] = 'processing';
        },
    ];
    foreach ($mutations as $mutate) {
        try {
            restoreFixturePlan($mutate);
            throw new RuntimeException('Unsafe or incompatible export was accepted');
        } catch (RestoreRejected) {
        }
    }
};

$tests['restore inventory is checksum addressed read only bounded and rejects symbolic links'] = static function (): void {
    $root = sys_get_temp_dir() . '/portable-restore-unit-' . bin2hex(random_bytes(8));
    mkdir($root, 0700);
    mkdir($root . '/inventory', 0700);
    mkdir($root . '/inventory/blobs', 0700);
    mkdir($root . '/destination', 0700);
    try {
        $plan = restoreFixturePlan();
        foreach (restoreFixtureExport($plan)->document['media'] as $media) {
            copy($plan->package->root . '/fixtures/media/' . $media['id'] . '-display_1600_webp.webp', $root . '/inventory/blobs/' . $media['original']['sha256']);
        }
        $inventory = new FilesystemRestoreStore($root . '/inventory');
        file_put_contents($root . '/inventory/export.json', restoreFixtureExport($plan)->json());
        assertSameValue([], $inventory->inventory($plan), 'all source blobs verify');
        $loaded = $inventory->export(dirname(__DIR__, 2) . '/schemas/portable-cms-export-v1.schema.json');
        assertSameValue(restoreFixtureExport($plan)->document, ($loaded instanceof NormalizedExport ? $loaded->document : null), 'schema executes over the exported envelope');
        $destination = new FilesystemRestoreStore($root . '/destination');
        $destination->assertUnoccupied($plan);
        assertSameValue([], iterator_to_array(new FilesystemIterator($root . '/destination')), 'read-only check does not create files');
        $progress = $destination->stage($plan, $inventory, 1);
        assertSameValue(['prepared' => 1, 'complete' => false], $progress, 'a bounded stage reports incomplete rather than success');
        $stageDirectory = $root . '/destination/restore-stage/' . restoreFixtureExport($plan)->document['exportId'];
        $partial = $stageDirectory . '/' . hash('sha256', $plan->files[1]->key);
        file_put_contents($partial, 'interrupted partial bytes');
        assertSameValue(true, $destination->stage($plan, $inventory, 8)['complete'], 'owned partial stage is recoverable');
        assertSameValue(true, $destination->stage($plan, $inventory, 8)['complete'], 'replaying verified immutable files is idempotent');
        try {
            $destination->assertUnoccupied($plan);
            throw new RuntimeException('Existing matching files were adopted as an empty destination');
        } catch (RestoreRejected $error) {
            assertSameValue('destination_files_not_empty', $error->reason, 'matching bytes do not imply restore ownership');
        }
        $first = $plan->files[1];
        try {
            $inventory->verify('blobs/' . $first->sha256, new \Utsuri\PortableCms\Conventional\Restore\RestoreFile($first->key, $first->sha256, $first->byteSize, $first->mime, $first->width + 1, $first->height));
            throw new RuntimeException('Forged derivative geometry was accepted');
        } catch (RestoreRejected $error) {
            assertSameValue('blob_geometry_mismatch', $error->reason, 'checksum alone cannot attest geometry');
        }
        try {
            $inventory->assertOutside($root . '/inventory');
            throw new RuntimeException('Storage at the document root was accepted');
        } catch (RestoreRejected $error) {
            assertSameValue('storage_must_be_outside_document_root', $error->reason, 'operator paths cannot expose private bytes');
        }
        file_put_contents($root . '/inventory/export.json', restoreFixtureExport($plan)->json() . ' ');
        try {
            $inventory->assertExport($plan);
            throw new RuntimeException('A changed envelope was accepted as the confirmed export');
        } catch (RestoreRejected $error) {
            assertSameValue('export_changed', $error->reason, 'even an envelope whitespace change requires fresh confirmation');
        }
        foreach (['../secret', '/etc/passwd', 'originals/../secret'] as $path) {
            try {
                $inventory->path($path);
                throw new RuntimeException('Traversal was accepted');
            } catch (RestoreRejected) {
            }
        }
        $first = $plan->files[0];
        unlink($root . '/inventory/blobs/' . $first->sha256);
        symlink($destination->path($first->key), $root . '/inventory/blobs/' . $first->sha256);
        assertSameValue('unsafe_blob_path', $inventory->inventory($plan)[0]['reason'], 'matching bytes through a symlink are still rejected');
        unlink($root . '/inventory/blobs/' . $first->sha256);
        file_put_contents($root . '/inventory/blobs/' . $first->sha256, 'changed');
        assertSameValue('missing_or_changed_blob', $inventory->inventory($plan)[0]['reason'], 'checksum mismatch is reported safely');
    } finally {
        RestoreTestFixture::remove($root);
    }
};

$tests['restore filesystem budget is explicit and injectable'] = static function (): void {
    $root = sys_get_temp_dir() . '/portable-restore-budget-' . bin2hex(random_bytes(8));
    mkdir($root, 0700);
    try {
        $clock = new IdentityTestClock(new DateTimeImmutable('2026-10-04T09:00:00Z'));
        $store = new FilesystemRestoreStore($root, $clock, 1);
        $clock->current = $clock->current->modify('+2 seconds');
        try {
            $store->verify('missing', restoreFixturePlan()->files[0]);
            throw new RuntimeException('Expired restore budget was ignored');
        } catch (RestoreRejected $error) {
            assertSameValue('runtime_budget_exhausted_resume_required', $error->reason, 'deadline failure is explicit');
        }
    } finally {
        RestoreTestFixture::remove($root);
    }
};

$tests['canonical content validation preserves native decimals without passing them through form strings'] = static function (): void {
    $root = sys_get_temp_dir() . '/portable-restore-decimal-' . bin2hex(random_bytes(8));
    mkdir($root, 0700);
    try {
        $package = ProfilePackage::load(dirname(__DIR__, 2) . '/profiles/reference-studio');
        $profileJson = json_decode((string) file_get_contents($package->root . '/profile.json'), false, 64, JSON_THROW_ON_ERROR);
        foreach ($profileJson->recordTypes[0]->fields as $field) {
            if ($field->id === 'sortOrder') {
                $field->type = 'decimal';
                $field->settings = (object) ['minimum' => '0', 'maximum' => '100000', 'scale' => 6];
            }
        }
        file_put_contents($root . '/profile.json', json_encode($profileJson, JSON_THROW_ON_ERROR));
        $profile = \Utsuri\PortableCms\Conventional\Content\ProfileManifest::load($root . '/profile.json');
        $document = RestoreTestFixture::document($package);
        $service = new \Utsuri\PortableCms\Conventional\Content\ContentService(new \Utsuri\PortableCms\Conventional\Restore\ExportRecordLookup($document), $profile, new IdentityTestClock(new DateTimeImmutable('2026-10-04T09:00:00Z')), new IdentityTestRandom());
        foreach ([0.000001, 2, 37.5] as $number) {
            $body = $document['revisions'][0]['body'];
            $body['sortOrder'] = $number;
            assertSameValue($number, $service->validateCanonical('project', $body)->body['sortOrder'], 'native JSON numbers do not change stored meaning or representation');
        }
        foreach ([-1.0, 0.0000001, 100001.0] as $invalid) {
            $body['sortOrder'] = $invalid;
            try {
                $service->validateCanonical('project', $body);
                throw new RuntimeException('Declared decimal limits were ignored');
            } catch (InvalidArgumentException) {
            }
        }
    } finally {
        RestoreTestFixture::remove($root);
    }
};
