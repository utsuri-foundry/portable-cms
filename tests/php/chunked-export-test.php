<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Content\ProfilePackage;
use Utsuri\PortableCms\Conventional\Export\ChunkedExport;
use Utsuri\PortableCms\Conventional\Export\ChunkedExportWriter;
use Utsuri\PortableCms\Conventional\Export\ExportBudget;
use Utsuri\PortableCms\Conventional\Export\ExportCapacity;
use Utsuri\PortableCms\Conventional\Export\ExportCapacityFailure;
use Utsuri\PortableCms\Conventional\Export\ExportCapacityStatus;
use Utsuri\PortableCms\Conventional\Export\NormalizedExport;
use Utsuri\PortableCms\Conventional\Restore\FilesystemRestoreStore;
use Utsuri\PortableCms\Conventional\Restore\RestorePlan;
use Utsuri\PortableCms\Conventional\Restore\RestoreRejected;

/** @return array{string, FilesystemRestoreStore, array<string, mixed>, NormalizedExport} */
function growthUnitPackage(): array
{
    $root = sys_get_temp_dir() . '/portable-growth-unit-' . bin2hex(random_bytes(8));
    mkdir($root, 0700);
    $store = new FilesystemRestoreStore($root);
    $profile = ProfilePackage::load(dirname(__DIR__, 2) . '/profiles/reference-studio');
    $legacy = new NormalizedExport(RestoreTestFixture::document($profile));
    $writer = new ChunkedExportWriter($store);
    $manifest = $writer->capture($legacy->metadata(), $legacy->rows(...));
    $writer->sealCapture($manifest);
    file_put_contents($root . '/export.json', json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
    return [$root, $store, $manifest, $legacy];
}

$tests['chunked file checkpoints resume bounded work but cannot authorize missing or changed bytes'] = static function (): void {
    [$root, $inventory, , $legacy] = growthUnitPackage();
    $destinationRoot = $root . '/destination';
    mkdir($destinationRoot, 0700);
    mkdir($root . '/blobs', 0700);
    try {
        $profile = ProfilePackage::load(dirname(__DIR__, 2) . '/profiles/reference-studio');
        foreach ($legacy->document['media'] as $media) {
            copy($profile->root . '/fixtures/media/' . $media['id'] . '-display_1600_webp.webp', $root . '/blobs/' . $media['original']['sha256']);
        }
        $input = $inventory->load(dirname(__DIR__, 2) . '/schemas/portable-cms-export-v1.schema.json');
        $plan = new RestorePlan($input->export, $input->sha256, $profile, new IdentityTestClock(new DateTimeImmutable('2026-10-19T12:00:00Z')), new IdentityTestRandom());
        $destination = new FilesystemRestoreStore($destinationRoot);
        assertSameValue(['prepared' => 1, 'complete' => false], $destination->stage($plan, $inventory, 1), 'first bounded batch');
        assertSameValue(['prepared' => 2, 'complete' => false], $destination->stage($plan, $inventory, 1), 'next batch resumes above zero');
        $prefix = 'restore-stage/' . $input->export->metadata()['exportId'];
        $cursor = new \Utsuri\PortableCms\Conventional\Restore\FilesystemBatchCursor($destination, $prefix . '/files.json', $plan->sha256, count($plan->files));
        $cursor->advance(count($plan->files));
        try {
            $destination->stage($plan, $inventory, 1);
            throw new RuntimeException('A forged cursor bypassed byte verification');
        } catch (RestoreRejected) {
            assertSameValue(2, $cursor->read(), 'missing bytes rewind to recoverable work');
        }
        file_put_contents($destination->path($prefix . '/' . hash('sha256', $plan->files[2]->key)), 'interrupted owned copy');
        assertSameValue(true, $destination->stage($plan, $inventory, 64)['complete'], 'owned partial copy is recovered');
        file_put_contents($destination->path($plan->files[0]->key), 'changed after staging');
        try {
            $destination->stage($plan, $inventory, 1);
            throw new RuntimeException('A completed cursor bypassed a changed blob');
        } catch (RestoreRejected) {
        }
        file_put_contents($destination->path($prefix . '/files.json'), '{"next":6}');
        try {
            $cursor->read();
            throw new RuntimeException('Unbound progress was accepted');
        } catch (RestoreRejected $error) {
            assertSameValue('invalid_batch_progress', $error->reason, 'progress must bind the exact intent');
        }
    } finally {
        RestoreTestFixture::remove($root);
    }
};

$tests['chunked exports retain legacy metadata rows graph meaning and media requirements'] = static function (): void {
    [$root, $store, $manifest, $legacy] = growthUnitPackage();
    try {
        $input = $store->load(dirname(__DIR__, 2) . '/schemas/portable-cms-export-v1.schema.json');
        assertTrueValue($input->export instanceof ChunkedExport, 'one installed reader recognizes v2');
        assertSameValue($legacy->metadata(), $input->export->metadata(), 'metadata keeps its exact meaning');
        foreach (ExportCapacity::COLLECTIONS as $kind) {
            assertSameValue(iterator_to_array($legacy->rows($kind), false), iterator_to_array($input->export->rows($kind), false), 'every retained row survives: ' . $kind);
            assertSameValue($legacy->count($kind), $input->export->count($kind), 'manifest count is exact');
        }
        $plan = new RestorePlan($input->export, $input->sha256, ProfilePackage::load(dirname(__DIR__, 2) . '/profiles/reference-studio'), new IdentityTestClock(new DateTimeImmutable('2026-10-19T12:00:00Z')), new IdentityTestRandom());
        assertSameValue(6, count($plan->files), 'shared semantic validation derives the original inventory');
        assertTrueValue($store->inventory($plan) !== [], 'missing blobs never claim completion');
        $store->assertExport($plan);
        $path = $store->path('chunks/' . $manifest['collections']['revisions'][0]['sha256'] . '.jsonl');
        file_put_contents($path, (string) file_get_contents($path) . "\n");
        try {
            iterator_to_array($input->export->rows('revisions'), false);
            throw new RuntimeException('A changed chunk was trusted');
        } catch (RestoreRejected) {
        }
    } finally {
        RestoreTestFixture::remove($root);
    }
};

$tests['legacy source admission distinguishes v2 nesting capacity from malformed revisions'] = static function (): void {
    $document = RestoreTestFixture::document(ProfilePackage::load(dirname(__DIR__, 2) . '/profiles/reference-studio'));
    $revision = $document['revisions'][0];
    ExportCapacity::requireLegacyRevision($revision);
    $revision['body']['body'] = ['en' => ['formatVersion' => 2, 'nodes' => [
        ['type' => 'paragraph', 'children' => [['text' => 'Archive', 'marks' => [['type' => 'link', 'href' => '/en/projects/']]]]],
    ]]];
    foreach (['nesting', 'items'] as $case) {
        $candidate = $revision;
        if ($case === 'items') {
            $candidate['body'] = ['many' => array_fill(0, 1001, 'word')];
        }
        try {
            NormalizedExport::entry('revisions', $candidate);
            throw new RuntimeException('The legacy contract was widened');
        } catch (InvalidArgumentException) {
        }
        try {
            ExportCapacity::requireLegacyRevision($candidate);
            throw new RuntimeException('A v2-only row was admitted to the legacy download');
        } catch (ExportCapacityFailure $error) {
            assertSameValue('legacy_export_revision_requires_private_package', $error->reason, 'a v2-only row has an operator path');
        }
    }
    foreach (['metadata', 'body'] as $case) {
        $candidate = $revision;
        if ($case === 'metadata') {
            $candidate['id'] = '../invalid';
        } else {
            $candidate['body'] = ['tooMany' => array_fill(0, 5001, 'word')];
        }
        try {
            ExportCapacity::requireLegacyRevision($candidate);
            throw new RuntimeException('Malformed input was reclassified as capacity');
        } catch (InvalidArgumentException) {
        }
    }
};

$tests['chunked capture preserves a historical localized link after current text is restored'] = static function (): void {
    $profile = ProfilePackage::load(dirname(__DIR__, 2) . '/profiles/reference-studio');
    $document = RestoreTestFixture::document($profile);
    $article = array_values(array_filter($document['records'], static fn(array $row): bool => $row['typeId'] === 'article'))[0];
    $original = array_values(array_filter($document['revisions'], static fn(array $row): bool => $row['id'] === $article['currentRevisionId']))[0];
    $linked = [...$original, 'id' => substr(hash('sha256', 'historical localized link'), 0, 32), 'revisionNumber' => 2];
    $linked['body']['body']['en']['nodes'][1]['children'] = [['text' => 'Archive', 'marks' => [['type' => 'link', 'href' => '/en/projects/']]]];
    $current = [...$original, 'id' => substr(hash('sha256', 'restored current text'), 0, 32), 'revisionNumber' => 3];
    $document['revisions'] = [...$document['revisions'], $linked, $current];
    foreach ($document['records'] as &$record) {
        if ($record['id'] === $article['id']) {
            $record['currentRevisionId'] = $current['id'];
            $record['currentRevisionNumber'] = 3;
        }
    }
    unset($record);
    foreach ($document['relationships'] as &$relationship) {
        if ($relationship['recordId'] === $article['id']) {
            $relationship['revisionId'] = $current['id'];
        }
    }
    unset($relationship);
    $root = sys_get_temp_dir() . '/portable-linked-history-' . bin2hex(random_bytes(8));
    mkdir($root, 0700);
    try {
        $store = new FilesystemRestoreStore($root);
        $manifest = (new ChunkedExportWriter($store))->capture(array_diff_key($document, array_flip(ExportCapacity::COLLECTIONS)), static fn(string $kind): iterable => $document[$kind]);
        file_put_contents($root . '/export.json', json_encode($manifest, JSON_THROW_ON_ERROR) . "\n");
        $input = $store->load(dirname(__DIR__, 2) . '/schemas/portable-cms-export-v1.schema.json');
        new RestorePlan($input->export, $input->sha256, $profile, new IdentityTestClock(new DateTimeImmutable('2026-10-19T12:00:00Z')), new IdentityTestRandom());
        $history = array_column(iterator_to_array($input->export->rows('revisions'), false), 'body', 'id');
        assertSameValue($linked['body'], $history[$linked['id']], 'the normal restore validator accepts the exact retained link');
        assertSameValue($original['body'], $input->export->revision($current['id'])['body'], 'current content stays restored independently of history');
    } finally {
        RestoreTestFixture::remove($root);
    }
};

$tests['release reader accepts the same bounded manifest tier as the compiler'] = static function (): void {
    $document = publicationFixture()->document;
    for ($number = 0; $number < 7000; $number++) {
        $document['files'][] = ['path' => 'assets/growth-' . $number . '.webp', 'mime' => 'image/webp', 'byteSize' => 1, 'sha256' => str_repeat('1', 64), 'cachePolicy' => 'immutable'];
    }
    $release = publicationFixture($document);
    assertTrueValue(strlen($release->json) > 1_048_576, 'the fixture exceeds ordinary form limits');
    assertSameValue(count($document['files']), count($release->files), 'the reader preserves every bounded artifact entry');
};

$tests['chunked manifests refuse paths missing chunks counts duplicates metadata injection and symlinks'] = static function (): void {
    [$root, $store, $manifest] = growthUnitPackage();
    try {
        $mutations = [
            static function (array &$m): void {
                $m['collections']['records'][0]['sha256'] = '../private';
            },
            static function (array &$m): void {
                $m['collections']['records'][0]['sha256'] = str_repeat('f', 64);
            },
            static function (array &$m): void {
                $m['collections']['records'][0]['rowCount']++;
            },
            static function (array &$m): void {
                $m['collections']['records'][] = $m['collections']['records'][0];
            },
            static function (array &$m): void {
                $m['metadata']['revisions'] = [];
            },
            static function (array &$m): void {
                $m['collections']['records'][0]['byteSize'] = ExportCapacity::CHUNK_BYTES + 1;
            },
            static function (array &$m): void {
                unset($m['collections']['media']);
            },
        ];
        foreach ($mutations as $mutate) {
            $candidate = $manifest;
            $mutate($candidate);
            try {
                new ChunkedExport($store, $candidate);
                throw new RuntimeException('An unsafe manifest was accepted');
            } catch (RestoreRejected) {
            }
        }
        $path = $store->path('chunks/' . $manifest['collections']['records'][0]['sha256'] . '.jsonl');
        rename($path, $path . '.original');
        symlink($path . '.original', $path);
        try {
            new ChunkedExport($store, $manifest);
            throw new RuntimeException('A chunk symlink was followed');
        } catch (RestoreRejected) {
        }
    } finally {
        RestoreTestFixture::remove($root);
    }
};

$tests['cross chunk graph validation refuses duplicate revisions and broken references before restore'] = static function (): void {
    $document = RestoreTestFixture::document(ProfilePackage::load(dirname(__DIR__, 2) . '/profiles/reference-studio'));
    $base = $document['revisions'][0];
    for ($number = 2; $number <= ExportCapacity::CHUNK_ROWS + 2; $number++) {
        $document['revisions'][] = [...$base, 'id' => str_pad(dechex($number), 32, '0', STR_PAD_LEFT), 'revisionNumber' => $number];
    }
    foreach (['duplicate', 'dangling', 'relationship'] as $case) {
        $root = sys_get_temp_dir() . '/portable-growth-graph-' . bin2hex(random_bytes(8));
        mkdir($root, 0700);
        try {
            $candidate = $document;
            if ($case === 'duplicate') {
                $candidate['revisions'][count($candidate['revisions']) - 1]['id'] = $base['id'];
            } elseif ($case === 'dangling') {
                $candidate['revisions'][count($candidate['revisions']) - 1]['recordId'] = str_repeat('f', 32);
            } else {
                $candidate['relationships'][0]['targetRecordId'] = str_repeat('f', 32);
            }
            try {
                (new ChunkedExportWriter(new FilesystemRestoreStore($root)))->capture(array_diff_key($candidate, array_flip(ExportCapacity::COLLECTIONS)), static fn(string $kind): iterable => $candidate[$kind]);
                throw new RuntimeException('A broken graph was captured');
            } catch (InvalidArgumentException) {
                assertTrueValue(!is_file($root . '/capture.json') && !is_file($root . '/export.json'), 'invalid graphs receive no completion markers');
            }
        } finally {
            RestoreTestFixture::remove($root);
        }
    }
};

$tests['chunked reads enforce invocation budgets and expose approaching capacity without pruning'] = static function (): void {
    [$root, $store, $manifest] = growthUnitPackage();
    try {
        $clock = new IdentityTestClock(new DateTimeImmutable('2026-10-19T12:00:00Z'));
        $budget = new ExportBudget($clock, 1);
        $clock->current = $clock->current->modify('+2 seconds');
        try {
            new ChunkedExport($store, $manifest, $budget);
            throw new RuntimeException('An exhausted budget was ignored');
        } catch (RestoreRejected $error) {
            assertSameValue('export_runtime_budget_exhausted', $error->reason, 'timeout is not completed success');
        }
        $capacity = new ExportCapacityStatus(['records' => 1600, 'revisions' => 16000, 'relationships' => 1, 'media' => 1], 100, 100, 100);
        assertTrueValue($capacity->approaching() && $capacity->chunkedRequired(), 'a growing history is visible before the supported bound');
    } finally {
        RestoreTestFixture::remove($root);
    }
};

$tests['chunked capture refuses count and row boundaries rather than truncating history'] = static function (): void {
    $profile = ProfilePackage::load(dirname(__DIR__, 2) . '/profiles/reference-studio');
    $document = RestoreTestFixture::document($profile);
    foreach (['records', 'row'] as $case) {
        $root = sys_get_temp_dir() . '/portable-growth-boundary-' . bin2hex(random_bytes(8));
        mkdir($root, 0700);
        try {
            $rows = static function (string $kind) use ($document, $case): iterable {
                if ($case === 'records' && $kind === 'records') {
                    for ($index = 0; $index <= ExportCapacity::limit('records'); $index++) {
                        yield [...$document['records'][0], 'id' => str_pad(dechex($index), 32, '0', STR_PAD_LEFT)];
                    }
                    return;
                }
                foreach ($document[$kind] as $row) {
                    if ($case === 'row' && $kind === 'revisions') {
                        $row['body']['large'] = array_fill(0, 20, str_repeat('x', 60_000));
                    }
                    yield $row;
                }
            };
            try {
                (new ChunkedExportWriter(new FilesystemRestoreStore($root)))->capture(array_diff_key($document, array_flip(ExportCapacity::COLLECTIONS)), $rows);
                throw new RuntimeException('An exceeded boundary was silently accepted');
            } catch (RestoreRejected $error) {
                assertSameValue('export_row_or_collection_capacity_exceeded', $error->reason, 'the unit bound is explicit');
                assertTrueValue(!is_file($root . '/capture.json'), 'a rejected capture never becomes resumable complete input');
            }
        } finally {
            RestoreTestFixture::remove($root);
        }
    }
};

$tests['chunked manifest byte bounds fail before opening attacker chosen inventory files'] = static function (): void {
    [$root, $store, $manifest] = growthUnitPackage();
    try {
        $manifest['collections']['records'] = [];
        for ($index = 0; $index <= intdiv(ExportCapacity::PACKAGE_BYTES, ExportCapacity::CHUNK_BYTES); $index++) {
            $manifest['collections']['records'][] = ['sha256' => hash('sha256', 'missing chunk ' . $index), 'byteSize' => ExportCapacity::CHUNK_BYTES, 'rowCount' => 1];
        }
        try {
            new ChunkedExport($store, $manifest);
            throw new RuntimeException('Aggregate byte exhaustion was ignored');
        } catch (RestoreRejected $error) {
            assertSameValue('chunked_export_capacity_exceeded', $error->reason, 'aggregate bounds precede filesystem work');
        }
    } finally {
        RestoreTestFixture::remove($root);
    }
};

$tests['capacity diagnostics explain full history and low memory fails before allocation'] = static function (): void {
    $metadata = new ExportCapacityStatus(['records' => 1, 'revisions' => 1, 'relationships' => 0, 'media' => 1000], 100, 100, 100, 33_554_432, 4000);
    assertTrueValue($metadata->chunkedRequired(), 'large media JSON cannot hide behind small history counts');
    assertTrueValue($metadata->legacyMemoryBytes() > $metadata->payloadBytes(), 'legacy memory accounts for collection and metadata overhead');
    $derivatives = new ExportCapacityStatus(['records' => 1, 'revisions' => 1, 'relationships' => 0, 'media' => 1], 100, 100, 100, 100, 20001);
    assertTrueValue($derivatives->exceeded() && $derivatives->chunkedRequired(), 'derivative ceilings are visible before buffering');
    $capacity = new ExportCapacityStatus(['records' => 2001, 'revisions' => 20000, 'relationships' => 1, 'media' => 1], 100, 100, 100);
    assertTrueValue($capacity->exceeded(), 'exceeded capacity differs from approaching capacity');
    $page = \Utsuri\PortableCms\Conventional\View\Pages::exportPage('csrf', ProfilePackage::load(dirname(__DIR__, 2) . '/profiles/reference-studio')->manifest, capacity: $capacity);
    assertTrueValue(str_contains($page, 'saved versions') && str_contains($page, 'Every retained version') && str_contains($page, 'disabled aria-describedby="export-package-required"'), 'native export page exposes counts and a usable operator route instead of a failing download');
    $code = 'require $argv[1]; try { \\Utsuri\\PortableCms\\Conventional\\Export\\ExportMemory::chunked(); exit(1); } catch (\\Utsuri\\PortableCms\\Conventional\\Restore\\RestoreRejected $error) { echo $error->reason; }';
    $process = proc_open([PHP_BINARY, '-d', 'memory_limit=64M', '-r', $code, dirname(__DIR__, 2) . '/engine/src/cms/conventional/bootstrap.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Isolated memory-budget process required');
    }
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    assertSameValue(0, proc_close($process), 'low memory is a typed qualification result, not a fatal: ' . $error);
    assertSameValue('insufficient_chunked_export_memory_budget', $output, 'insufficient headroom is detected before large allocations');
};
