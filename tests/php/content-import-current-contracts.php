<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Content\ProfilePackage;
use Utsuri\PortableCms\Conventional\Content\SiteLanguages;
use Utsuri\PortableCms\Conventional\Content\SiteWording;
use Utsuri\PortableCms\Conventional\Export\ChunkedExportWriter;
use Utsuri\PortableCms\Conventional\Export\NormalizedExport;
use Utsuri\PortableCms\Conventional\Identity\Clock;
use Utsuri\PortableCms\Conventional\Identity\RandomSource;
use Utsuri\PortableCms\Conventional\Import\ContentImportService;
use Utsuri\PortableCms\Conventional\Import\ImportRejected;
use Utsuri\PortableCms\Conventional\Import\MariaDbImportRepository;
use Utsuri\PortableCms\Conventional\Publication\WebsitePublication;
use Utsuri\PortableCms\Conventional\Restore\FilesystemRestoreStore;

require_once __DIR__ . '/trailer-fixture.php';
require_once __DIR__ . '/video-limits-fixture.php';

/** @param array<string, mixed> $document
 * @param list<string> $arguments
 */
function importTestCurrentContracts(PDO $pdo, ProfilePackage $package, ContentImportService $service, MariaDbImportRepository $repository, array $document, string $state, string $actor, Clock $clock, RandomSource $random, array $arguments): void
{
    $root = dirname(__DIR__, 2);
    $schema = $root . '/schemas/portable-cms-export-v1.schema.json';
    $inventory = new FilesystemRestoreStore($state . '/package', $clock);
    $input = $inventory->load($schema);
    $expiring = new class ($clock->now()) implements Clock {
        private int $reads = 0;
        public function __construct(private readonly DateTimeImmutable $start) {}
        public function now(): DateTimeImmutable
        {
            return ++$this->reads > 1 ? $this->start->modify('+181 seconds') : $this->start;
        }
    };
    $bounded = new ContentImportService($repository, $package, new \Utsuri\PortableCms\Conventional\Import\ImportMediaTransfer(new \Utsuri\PortableCms\Conventional\Media\MariaDbUploadRepository($pdo), $state . '/media', $clock, $random, new \Utsuri\PortableCms\Conventional\Media\MariaDbMediaLibrary($pdo)), $expiring, $random, true);
    $beforeDeadline = importTestCounts($pdo);
    try {
        $bounded->dryRun($input, $inventory, 'fictional-study', $actor);
        throw new RuntimeException('Expired content planning continued');
    } catch (ImportRejected $error) {
        assertSameValue('content_planning_time_budget_exhausted', $error->reason, 'planning uses its injected finite deadline');
        assertSameValue($beforeDeadline, importTestCounts($pdo), 'expired planning writes nothing');
    }
    $role = $pdo->query('SELECT role FROM cms_users WHERE id = ' . $pdo->quote($actor))->fetchColumn();
    try {
        foreach (['owner', 'administrator'] as $allowed) {
            $pdo->prepare('UPDATE cms_users SET role = :role WHERE id = :id')->execute(['role' => $allowed, 'id' => $actor]);
            assertSameValue($actor, $service->dryRun($input, $inventory, 'fictional-study', $actor)->context['administratorId'], 'both operator roles qualify');
        }
        $pdo->prepare("UPDATE cms_users SET role = 'editor' WHERE id = :id")->execute(['id' => $actor]);
        try {
            $service->dryRun($input, $inventory, 'fictional-study', $actor);
            throw new RuntimeException('Editor import authorized');
        } catch (ImportRejected $error) {
            assertSameValue('active_destination_administrator_required', $error->reason, 'editor cannot import');
        }
    } finally {
        $pdo->prepare('UPDATE cms_users SET role = :role WHERE id = :id')->execute(['role' => $role, 'id' => $actor]);
    }
    $jobId = md5('import-maintenance-guard');
    $pdo->prepare("INSERT INTO cms_language_text_deletions (id, profile_id, locale, actor_user_id, state, records_total, records_done, media_done, started_at, updated_at) VALUES (:id, :profile, 'es', :actor, 'running', 0, 0, 0, :started, :updated)")->execute(['id' => $jobId, 'profile' => $package->manifest->id, 'actor' => $actor, 'started' => $clock->now()->format('Y-m-d H:i:s.u'), 'updated' => $clock->now()->format('Y-m-d H:i:s.u')]);
    try {
        $service->dryRun($input, $inventory, 'fictional-study', $actor);
        throw new RuntimeException('Content import ran during text deletion');
    } catch (ImportRejected $error) {
        assertSameValue('language_text_deletion_in_progress', $error->reason, 'content maintenance cannot resurrect text during deletion');
    } finally {
        $pdo->prepare('DELETE FROM cms_language_text_deletions WHERE id = :id')->execute(['id' => $jobId]);
    }
    $oldPlan = $service->dryRun($input, $inventory, 'fictional-study', $actor);
    $languages = SiteLanguages::defaults($package->manifest)->document();
    $languages['revision'] = 1;
    foreach ($languages['languages'] as &$language) {
        if ($language['locale'] === 'es') {
            $language['state'] = 'off';
        }
    }
    unset($language);
    $definition = array_values(array_filter($package->manifest->wording, static fn($entry): bool => $entry->kind === 'text'))[0];
    $wording = SiteWording::of($package->manifest, 1, [$definition->key => ['en' => 'Destination wording']])->document();
    $website = WebsitePublication::defaults($package->manifest->id, $package->manifest->locales)->document();
    $website['revision'] = 1;
    $website['websiteName'] = 'Destination website name';
    $settings = [];
    foreach (['cms_site_languages' => $languages, 'cms_site_wording' => $wording, 'cms_website_publication' => $website] as $table => $value) {
        $json = json_encode($value, JSON_THROW_ON_ERROR);
        $pdo->prepare('INSERT INTO ' . $table . ' (profile_id, revision, document_json) VALUES (:profile, 1, :document)')->execute(['profile' => $package->manifest->id, 'document' => $json]);
        $settings[$table] = $json;
    }
    // An installed catalog language belongs to the envelope even before any text exists in it.
    $addedLanguages = $languages;
    $addedLanguages['revision'] = 2;
    $addedLanguages['languages'][] = ['locale' => 'it', 'state' => 'preparation'];
    $added = $document;
    $added['profile']['locales'][] = 'it';
    $added['siteLanguages'] = $addedLanguages;
    importTestWrite($state . '/package/export.json', $added);
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE cms_site_languages SET revision = 2, document_json = :document')->execute(['document' => json_encode($addedLanguages, JSON_THROW_ON_ERROR)]);
        $addedPlan = $service->dryRun($inventory->load($schema), $inventory, 'fictional-study', $actor);
        assertSameValue([], array_values(array_filter($addedPlan->records, static fn($record): bool => $record->action === 'blocked')), 'added languages in preparation accept older bodies without invented translations');
        assertSameValue('it', $addedPlan->context['destinationSiteLanguages']['languages'][3]['locale'], 'planning uses the full installed envelope');
    } finally {
        $pdo->rollBack();
        importTestWrite($state . '/package/export.json', $document);
    }
    try {
        $service->apply($input, $inventory, 'fictional-study', $actor, $oldPlan->sha256);
        throw new RuntimeException('A changed language choice retained approval');
    } catch (ImportRejected $error) {
        assertSameValue('reviewed_plan_changed', $error->reason, 'destination language choice binds the reviewed plan');
    }
    $document['siteLanguages'] = SiteLanguages::defaults($package->manifest)->document();
    $document['siteWording'] = SiteWording::defaults($package->manifest)->document();
    $document['websitePublication'] = WebsitePublication::defaults($package->manifest->id, $package->manifest->locales)->document();
    $document['websitePublication']['websiteName'] = 'Unadopted source name';
    $base = new NormalizedExport($document);
    $historicRecord = array_values(array_filter($document['records'], static fn(array $record): bool => $record['typeId'] === 'discipline'))[0];
    $historic = $base->revision($historicRecord['currentRevisionId']);
    $newId = md5('import-v2-current-term');
    mkdir($state . '/chunked-package', 0700);
    $chunkStore = new FilesystemRestoreStore($state . '/chunked-package', $clock);
    $manifest = (new ChunkedExportWriter($chunkStore))->capture($base->metadata(), static function (string $kind) use ($base, $historicRecord, $historic, $newId): iterable {
        if ($kind === 'revisions') {
            for ($number = 1; $number <= 5100; $number++) {
                yield [...$historic, 'id' => $number === 1 ? $historic['id'] : md5('import-history-' . $number), 'revisionNumber' => $number];
            }
        }
        foreach ($base->rows($kind) as $row) {
            if ($kind === 'records' && $row['id'] === $historicRecord['id']) {
                $row['currentRevisionId'] = $newId;
                $row['currentRevisionNumber'] = 5101;
            } elseif ($kind === 'revisions' && $row['recordId'] === $historicRecord['id']) {
                $row['id'] = $newId;
                $row['revisionNumber'] = 5101;
            } elseif ($kind === 'relationships' && $row['recordId'] === $historicRecord['id']) {
                $row['revisionId'] = $newId;
            }
            yield $row;
        }
    });
    mkdir($state . '/chunked-package/blobs', 0700);
    foreach ($document['media'] as $media) {
        copy($state . '/package/blobs/' . $media['original']['sha256'], $state . '/chunked-package/blobs/' . $media['original']['sha256']);
    }
    file_put_contents($state . '/chunked-package/export.json', json_encode($manifest, JSON_THROW_ON_ERROR));
    $chunkInput = $chunkStore->load($schema);
    $plan = $service->dryRun($chunkInput, $chunkStore, 'fictional-study', $actor);
    assertSameValue(5100, $plan->context['excluded']['historicalRevisions'], 'real v2 input exceeds legacy history bounds without importing history');
    foreach (['siteLanguages', 'siteWording', 'websitePublication'] as $setting) {
        assertSameValue(true, $plan->context['excluded'][$setting], 'destination setting stays explicit in the exclusion summary');
    }
    $before = importTestCounts($pdo);
    $result = $service->apply($chunkInput, $chunkStore, 'fictional-study', $actor, $plan->sha256);
    assertSameValue('applied', $result['status'], 'v2 current-content import completes');
    assertSameValue($before['cms_record_revisions'], importTestCounts($pdo)['cms_record_revisions'], 'historical transport and unchanged current bodies create no revisions');
    $after = importTestCounts($pdo);
    $again = $service->dryRun($chunkInput, $chunkStore, 'fictional-study', $actor);
    $service->apply($chunkInput, $chunkStore, 'fictional-study', $actor, $again->sha256);
    assertSameValue($after, importTestCounts($pdo), 'v2 replay creates no rows or audit');
    $legacy = $service->dryRun($input, $inventory, 'fictional-study', $actor);
    $service->apply($input, $inventory, 'fictional-study', $actor, $legacy->sha256);
    assertSameValue(5101, $repository->recordMappings('fictional-study', [$historicRecord['id']])[$historicRecord['id']]->sourceRevision, 'older unchanged v1 replay cannot move the source baseline backwards');
    $chunkArguments = array_map(static fn(string $argument): string => str_starts_with($argument, '--package=') ? '--package=' . $state . '/chunked-package' : $argument, $arguments);
    $cliPlan = importTestOperator($chunkArguments);
    assertSameValue('applied', importTestOperator([...$chunkArguments, '--apply', '--confirm=' . $cliPlan['planSha256']])['status'], 'installed CLI reads real v2 chunks');
    foreach ($settings as $table => $json) {
        assertSameValue($json, $pdo->query('SELECT document_json FROM ' . $table)->fetchColumn(), 'import retains destination settings byte-for-byte');
    }
    $record = $document['records'][0];
    $current = $repository->records()->find($record['typeId'], $record['id']);
    $offText = $current->body['title']['es'];
    $pdo->prepare("UPDATE cms_records SET workflow_state = 'published', published_revision_id = current_revision_id WHERE id = :id")->execute(['id' => $record['id']]);
    $version = $repository->recordMappings('fictional-study', [$record['id']])[$record['id']]->sourceRevision + 1;
    $revisionId = md5('import-off-language-revision');
    $document['records'][0]['currentRevisionNumber'] = $version;
    $document['records'][0]['currentRevisionId'] = $revisionId;
    $document['revisions'][0]['id'] = $revisionId;
    $document['revisions'][0]['revisionNumber'] = $version;
    $document['revisions'][0]['body']['title'] = $current->body['title'];
    $document['revisions'][0]['body']['title']['ca'] = 'Imported public title';
    $document['revisions'][0]['body']['title']['es'] = 'Source replacement must stay off';
    foreach ($document['relationships'] as &$relationship) {
        if ($relationship['recordId'] === $record['id']) {
            $relationship['revisionId'] = $revisionId;
        }
    }
    unset($relationship);
    importTestWrite($state . '/package/export.json', $document);
    $input = $inventory->load($schema);
    $plan = $service->dryRun($input, $inventory, 'fictional-study', $actor);
    assertSameValue($offText, $plan->records[0]->body['title']['es'], 'off-language text is retained during planning');
    $sequence = (int) $pdo->query('SELECT current_sequence FROM cms_site_sequence WHERE singleton_id = 1')->fetchColumn();
    $service->apply($input, $inventory, 'fictional-study', $actor, $plan->sha256, $plan->sha256);
    $saved = $repository->records()->find($record['typeId'], $record['id']);
    assertSameValue('published', $saved->workflowState, 'destination workflow state survives import');
    assertSameValue($offText, $saved->body['title']['es'], 'off-language text survives the actual revision');
    assertTrueValue((int) $pdo->query('SELECT site_sequence FROM cms_record_revisions WHERE id = ' . $pdo->quote($saved->revisionId))->fetchColumn() > $sequence, 'ordinary repository orders imported revisions in the site sequence');
    $document['media'][1]['text']['translations'][0]['caption'] = 'New caption without a rename';
    $document['media'][1]['focalPoint']['x'] = 35;
    $document['media'][1]['metadataRevision'] = 10;
    importTestWrite($state . '/package/export.json', $document);
    $input = $inventory->load($schema);
    $plan = $service->dryRun($input, $inventory, 'fictional-study', $actor);
    assertSameValue('update', $plan->media[1]->action, 'metadata-only source changes are visible');
    $jobs = importTestCounts($pdo)['cms_jobs'];
    $service->apply($input, $inventory, 'fictional-study', $actor, $plan->sha256);
    $library = new \Utsuri\PortableCms\Conventional\Media\MariaDbMediaLibrary($pdo);
    $image = $library->find($plan->media[1]->id);
    assertSameValue($document['media'][1]['text'], $image->text->document(), 'caption changes follow normal metadata validation');
    assertSameValue($document['media'][1]['focalPoint'], $image->focalPoint->document(), 'focal changes follow the same metadata revision');
    assertSameValue($jobs, importTestCounts($pdo)['cms_jobs'], 'metadata updates queue no duplicate image jobs');
    foreach ([9, null] as $staleVersion) {
        $stale = $document;
        $stale['media'][1]['text']['translations'][0]['caption'] = 'Older source caption';
        if ($staleVersion === null) {
            unset($stale['media'][1]['metadataRevision']);
        } else {
            $stale['media'][1]['metadataRevision'] = $staleVersion;
        }
        importTestWrite($state . '/package/export.json', $stale);
        $stalePlan = $service->dryRun($inventory->load($schema), $inventory, 'fictional-study', $actor);
        assertSameValue('conflict', $stalePlan->media[1]->action, 'an older or unversioned source cannot replace versioned metadata');
        assertTrueValue(in_array('source_media_metadata_not_newer', array_column($stalePlan->media[1]->issues, 'reason'), true), 'stale source metadata is distinguished from a local edit');
    }
    $document['media'][1]['metadataRevision'] = 11;
    importTestWrite($state . '/package/export.json', $document);
    $input = $inventory->load($schema);
    $newerMetadata = $service->dryRun($input, $inventory, 'fictional-study', $actor);
    $service->apply($input, $inventory, 'fictional-study', $actor, $newerMetadata->sha256);
    assertSameValue($image->metadataRevision, $library->find($image->id)->metadataRevision, 'advancing only the source counter does not rewrite identical destination metadata');
    $mapping = $repository->mediaMappings('fictional-study', [['id' => $document['media'][1]['id'], 'sha256' => $document['media'][1]['original']['sha256']]]);
    assertSameValue(11, array_values($mapping)[0]->sourceMetadataRevision, 'unchanged metadata still advances its own source baseline');
    $videoDocument = $document;
    $videoId = md5('import-prepared-video');
    $videoPath = $root . '/tests/fixtures/video/prepared.mp4';
    $videoDocument['media'][] = [...$document['media'][0], 'id' => $videoId, 'ownerRecordId' => $record['id'], 'originalName' => 'Trailer.mp4', 'status' => 'uploaded', 'declaredType' => 'video/mp4', 'detectedType' => null, 'width' => null, 'height' => null, 'original' => ['mime' => 'video/mp4', 'byteSize' => filesize($videoPath), 'sha256' => hash_file('sha256', $videoPath)], 'derivatives' => []];
    $videoDocument['revisions'][0]['body']['trailer'] = ['mediaId' => $videoId, 'posterId' => $document['media'][0]['id']];
    importTestWrite($state . '/package/export.json', $videoDocument);
    $videoPlan = $service->dryRun($inventory->load($schema), $inventory, 'video-proof', $actor);
    $video = array_values(array_filter($videoPlan->media, static fn($media): bool => $media->value['id'] === $videoId))[0];
    assertSameValue('blocked', $video->action, 'unsupported prepared videos cannot queue image processing');
    assertTrueValue(in_array('prepared_video_import_not_supported', array_column($video->issues, 'reason'), true), 'dry run identifies the unsupported video capability');
    assertSameValue('blocked', $videoPlan->records[0]->action, 'a record selecting a blocked video is also blocked');
    $limited = videoLimitsPackage($state . '/video-limits-profile', ['maximumBytes' => 1048576, 'maximumDurationSeconds' => 15, 'maximumWidth' => 1920, 'maximumHeight' => 1080, 'sound' => 'none']);
    $limitedService = new ContentImportService(
        $repository,
        $limited,
        new \Utsuri\PortableCms\Conventional\Import\ImportMediaTransfer(new \Utsuri\PortableCms\Conventional\Media\MariaDbUploadRepository($pdo), $state . '/media', $clock, $random, new \Utsuri\PortableCms\Conventional\Media\MariaDbMediaLibrary($pdo)),
        $clock,
        $random,
        true,
    );
    foreach (['duration', 'width', 'height', 'sound', 'bytes'] as $violation) {
        $bytes = videoLimitsClip($violation === 'bytes' ? 'fitting' : $violation);
        if ($violation === 'bytes') {
            $bytes .= pack('N', 1048576) . 'free' . str_repeat("\0", 1048568);
        }
        $sha256 = hash('sha256', $bytes);
        file_put_contents($state . '/package/blobs/' . $sha256, $bytes);
        $videoDocument['media'][count($videoDocument['media']) - 1]['original'] = ['mime' => 'video/mp4', 'byteSize' => strlen($bytes), 'sha256' => $sha256];
        importTestWrite($state . '/package/export.json', $videoDocument);
        $limitedPlan = $limitedService->dryRun($inventory->load($schema), $inventory, 'limited-video-proof', $actor);
        assertSameValue('blocked', $limitedPlan->records[0]->action, 'a measured video violation blocks its record');
        $issues = array_column($limitedPlan->records[0]->issues, null, 'reason');
        assertTrueValue(isset($issues['video.limit_' . $violation]), 'import names the measured field violation: ' . $violation);
        assertTrueValue(str_contains($issues['video.limit_' . $violation]['detail'], 'Trailer'), 'import field error names the field');
    }
    fwrite(STDOUT, "PASS existing-installation import retains blocked plans with measured video field errors\n");
    importTestWrite($state . '/package/export.json', $document);
    $projection = (new \Utsuri\PortableCms\Conventional\Preview\MariaDbPreviewRepository($pdo, $package->manifest->designRegistries, $package->checksum))->freezeSnapshot($package->manifest->id, $package->manifest->version, $package->manifest->locales, $clock->now(), true);
    $built = (new \Utsuri\PortableCms\Conventional\Preview\ProfilePreviewCompiler($package, $state . '/media', $root . '/schemas'))->compile($projection, $state . '/import-preview');
    assertTrueValue(count($built->artifacts) > 0, 'ordinary exact preview compiles imported records and metadata');
    $artifact = json_decode((string) file_get_contents($state . '/import-preview/artifact.json'), true, flags: JSON_THROW_ON_ERROR);
    $page = json_decode((string) file_get_contents($state . '/import-preview/feed/v1/snapshots/' . $artifact['contentFeed']['snapshotId'] . '/project/ca/1.json'), true, flags: JSON_THROW_ON_ERROR);
    assertSameValue('Imported public title', $page['records'][0]['fields']['title'], 'published imported revision enters the ordinary frozen content feed');
    assertSameValue(null, $pdo->query('SELECT active_release FROM cms_publication_state WHERE singleton_id = 1')->fetchColumn(), 'import and private preview never activate a public release');
    $person = array_values(array_filter($document['records'], static fn(array $entry): bool => $entry['typeId'] === 'person'))[0];
    $personRevision = array_values(array_filter($document['revisions'], static fn(array $entry): bool => $entry['recordId'] === $person['id']))[0];
    assertSameValue([], array_values(array_filter($document['relationships'], static fn(array $entry): bool => $entry['recordId'] === $person['id'])), 'standalone batch fixture has no external record graph');
    $person['id'] = md5('import-cli-batch-person');
    $person['currentRevisionId'] = md5('import-cli-batch-revision');
    $personRevision['id'] = $person['currentRevisionId'];
    $personRevision['recordId'] = $person['id'];
    $batch = $document;
    $batch['records'] = [$person];
    $batch['revisions'] = [$personRevision];
    $batch['relationships'] = [];
    foreach ($batch['media'] as &$batchMedia) {
        $batchMedia['ownerRecordId'] = $person['id'];
    }
    unset($batchMedia);
    mkdir($state . '/batch-package', 0700);
    mkdir($state . '/batch-package/blobs', 0700);
    foreach ($batch['media'] as $batchMedia) {
        copy($state . '/package/blobs/' . $batchMedia['original']['sha256'], $state . '/batch-package/blobs/' . $batchMedia['original']['sha256']);
    }
    importTestWrite($state . '/batch-package/export.json', $batch);
    $batchArguments = array_map(static fn(string $argument): string => str_starts_with($argument, '--package=') ? '--package=' . $state . '/batch-package' : (str_starts_with($argument, '--source=') ? '--source=cli-batch-proof' : $argument), $arguments);
    $batchPlan = importTestOperator($batchArguments);
    $partial = importTestOperator([...$batchArguments, '--apply', '--maximum-media=1', '--confirm=' . $batchPlan['planSha256']], 2);
    assertSameValue('preparing', $partial['status'], 'actual CLI returns nonzero while transfers remain');
    assertSameValue(0, $partial['skippedItems'], 'incomplete exit is independent of conflicts or rejected items');
    $batchPlan = importTestOperator($batchArguments);
    assertSameValue('applied', importTestOperator([...$batchArguments, '--apply', '--maximum-media=8', '--confirm=' . $batchPlan['planSha256']])['status'], 'fresh CLI invocation resumes to completion');
    $removed = $historicRecord;
    $removed['id'] = md5('import-delete-term');
    $removed['currentRevisionId'] = md5('import-delete-revision');
    $removed['currentRevisionNumber'] = 1;
    $removedRevision = [...$historic, 'id' => $removed['currentRevisionId'], 'recordId' => $removed['id'], 'revisionNumber' => 1];
    $deleteDocument = $document;
    $deleteDocument['records'] = [$removed];
    $deleteDocument['revisions'] = [$removedRevision];
    $deleteDocument['relationships'] = [];
    $deleteDocument['media'] = [];
    importTestWrite($state . '/package/export.json', $deleteDocument);
    $deleteInput = $inventory->load($schema);
    $deletePlan = $service->dryRun($deleteInput, $inventory, 'delete-proof', $actor);
    assertSameValue('create', $deletePlan->records[0]->action, 'standalone term can be imported without media');
    $sequenceBefore = (int) $pdo->query('SELECT current_sequence FROM cms_site_sequence WHERE singleton_id = 1')->fetchColumn();
    $commitClock = new class ($pdo, $clock->now(), $sequenceBefore) implements Clock {
        public function __construct(private readonly PDO $pdo, private readonly DateTimeImmutable $start, private readonly int $sequence) {}
        public function now(): DateTimeImmutable
        {
            $value = (int) $this->pdo->query('SELECT current_sequence FROM cms_site_sequence WHERE singleton_id = 1')->fetchColumn();
            return $value > $this->sequence ? $this->start->modify('+181 seconds') : $this->start;
        }
    };
    $commitBounded = new ContentImportService($repository, $package, new \Utsuri\PortableCms\Conventional\Import\ImportMediaTransfer(new \Utsuri\PortableCms\Conventional\Media\MariaDbUploadRepository($pdo), $state . '/media', $clock, $random, new \Utsuri\PortableCms\Conventional\Media\MariaDbMediaLibrary($pdo)), $commitClock, $random, true);
    $beforeCommit = importTestCounts($pdo);
    try {
        $commitBounded->apply($deleteInput, $inventory, 'delete-proof', $actor, $deletePlan->sha256);
        throw new RuntimeException('Expired record commit succeeded');
    } catch (ImportRejected $error) {
        assertSameValue('content_commit_time_budget_exhausted', $error->reason, 'single-record commit also checks its final deadline');
        assertSameValue($beforeCommit, importTestCounts($pdo), 'expired commit rolls back revisions relationships checkpoints and audit');
        assertSameValue($sequenceBefore, (int) $pdo->query('SELECT current_sequence FROM cms_site_sequence WHERE singleton_id = 1')->fetchColumn(), 'expired commit rolls back sequence allocation too');
    }
    $service->apply($deleteInput, $inventory, 'delete-proof', $actor, $deletePlan->sha256);
    $records = new \Utsuri\PortableCms\Conventional\Content\MariaDbRecordRepository($pdo);
    $created = $records->find($removed['typeId'], $removed['id']);
    if ($created === null) {
        throw new RuntimeException('Deletion fixture was not imported');
    }
    $archived = $records->transition($created, 'archived', md5('import-delete-archival'), $package->manifest->id, $package->manifest->version, $actor, md5('import-delete-archival-audit'), $clock->now());
    if (!$archived instanceof \Utsuri\PortableCms\Conventional\Content\RecordSnapshot) {
        throw new RuntimeException('Imported deletion fixture did not archive');
    }
    assertSameValue(true, $records->deleteArchived($archived, $actor, md5('import-delete-audit'), $clock->now()), 'private checkpoints do not block ordinary archived record deletion');
    $missing = $service->dryRun($deleteInput, $inventory, 'delete-proof', $actor);
    assertSameValue('blocked', $missing->records[0]->action, 'deleted destination is reported rather than recreated');
    assertTrueValue(in_array('mapped_destination_missing', array_column($missing->records[0]->issues, 'reason'), true), 'missing mapping retains its source identity');
    $service->apply($deleteInput, $inventory, 'delete-proof', $actor, $missing->sha256);
    assertSameValue(null, $records->find($removed['typeId'], $removed['id']), 'replaying import cannot resurrect a deleted record');
    fwrite(STDOUT, "PASS current import contracts: v2 history, roles, destination settings, off languages, sequence, metadata and exact-preview feed.\n");
}
