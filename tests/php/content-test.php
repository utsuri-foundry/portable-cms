<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Content\ContentService;
use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Content\RecordConflict;
use Utsuri\PortableCms\Conventional\Content\RecordRepository;
use Utsuri\PortableCms\Conventional\Content\RecordSnapshot;
use Utsuri\PortableCms\Conventional\Export\ExportRepository;
use Utsuri\PortableCms\Conventional\Export\ExportService;
use Utsuri\PortableCms\Conventional\Export\NormalizedExport;
use Utsuri\PortableCms\Conventional\Http\OriginPolicy;
use Utsuri\PortableCms\Conventional\Http\Request;
use Utsuri\PortableCms\Conventional\Http\RequestBody;
use Utsuri\PortableCms\Conventional\Identity\IdentityService;
use Utsuri\PortableCms\Conventional\IdentityApplication;
use Utsuri\PortableCms\Conventional\Media\FilesystemUploadStorage;
use Utsuri\PortableCms\Conventional\Media\MediaJob;
use Utsuri\PortableCms\Conventional\Media\MediaJobRepository;
use Utsuri\PortableCms\Conventional\Media\MediaUploadService;
use Utsuri\PortableCms\Conventional\Media\ProcessedMedia;
use Utsuri\PortableCms\Conventional\Preview\PreviewBuild;
use Utsuri\PortableCms\Conventional\Preview\PreviewJob;
use Utsuri\PortableCms\Conventional\Preview\PreviewRepository;
use Utsuri\PortableCms\Conventional\Preview\PreviewService;
use Utsuri\PortableCms\Conventional\Preview\PreviewSnapshot;
use Utsuri\PortableCms\Conventional\Preview\PreviewStatus;

final class MemoryExportRepository implements ExportRepository
{
    /** @var list<array<string, mixed>> */
    public array $requests = [];

    public function create(
        string $exportId,
        string $auditId,
        string $actorUserId,
        string $profileId,
        string $profileVersion,
        array $locales,
        DateTimeImmutable $now,
    ): NormalizedExport {
        $this->requests[] = compact(
            'exportId',
            'auditId',
            'actorUserId',
            'profileId',
            'profileVersion',
            'locales',
            'now',
        );
        return new NormalizedExport([
            'formatVersion' => 1,
            'exportId' => $exportId,
            'createdAt' => $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),
            'profile' => ['id' => $profileId, 'version' => $profileVersion, 'locales' => $locales],
            'records' => [],
            'revisions' => [],
            'relationships' => [],
            'media' => [],
        ]);
    }
}

final class MemoryPreviewRepository implements PreviewRepository
{
    /** @var (Closure(bool): void)|null */
    public ?Closure $preflight = null;
    /** @var array<string, PreviewStatus> */
    public array $previews = [];

    /** @param list<string> $locales */
    public function enqueue(
        string $previewId,
        string $jobId,
        string $auditId,
        string $actorUserId,
        string $profileId,
        string $profileVersion,
        array $locales,
        DateTimeImmutable $now,
        bool $includeDrafts = false,
        bool $includePreparation = false,
    ): PreviewStatus {
        ($this->preflight ?? static function (): void {})($includeDrafts);
        return $this->previews[$previewId] = new PreviewStatus(
            $previewId,
            'queued',
            $jobId,
            null,
            null,
            $now,
            $now,
            $includeDrafts,
        );
    }

    public function list(int $limit): array
    {
        return array_slice(array_values($this->previews), 0, $limit);
    }

    public function find(string $previewId): ?PreviewStatus
    {
        return $this->previews[$previewId] ?? null;
    }

    public function claim(string $workerId, string $leaseToken, DateTimeImmutable $now, DateTimeImmutable $leaseExpiresAt): ?PreviewJob
    {
        throw new LogicException('not used by HTTP test');
    }

    public function snapshot(PreviewJob $job): PreviewSnapshot
    {
        throw new LogicException('not used by HTTP test');
    }

    public function complete(PreviewJob $job, PreviewBuild $build, string $auditId, DateTimeImmutable $now): void
    {
        throw new LogicException('not used by HTTP test');
    }

    public function fail(
        PreviewJob $job,
        string $errorCode,
        bool $retryable,
        DateTimeImmutable $retryAt,
        DateTimeImmutable $now,
        ?\Utsuri\PortableCms\Conventional\Preview\PreviewDiagnostics $diagnostics = null,
    ): void {
        throw new LogicException('not used by HTTP test');
    }

    public function delete(
        string $previewId,
        string $actorUserId,
        string $auditId,
        DateTimeImmutable $now,
    ): void {
        $preview = $this->previews[$previewId] ?? null;
        if ($preview === null) {
            throw new \Utsuri\PortableCms\Conventional\Preview\PreviewNotFound();
        }
        if (!in_array($preview->status, ['ready', 'needs_attention'], true)) {
            throw new \Utsuri\PortableCms\Conventional\Preview\PreviewNotDeletable();
        }
        unset($this->previews[$previewId]);
    }
}

final class ContentTestMediaJobs implements MediaJobRepository
{
    public function __construct(private readonly MemoryUploadRepository $uploads) {}

    public function claim(
        string $workerId,
        string $leaseToken,
        DateTimeImmutable $now,
        DateTimeImmutable $leaseExpiresAt,
    ): ?MediaJob {
        throw new LogicException('not used by HTTP test');
    }

    public function source(MediaJob $job): \Utsuri\PortableCms\Conventional\Media\MediaSource
    {
        throw new LogicException('not used by HTTP test');
    }

    public function complete(
        MediaJob $job,
        ProcessedMedia $processed,
        string $auditId,
        DateTimeImmutable $now,
    ): void {
        throw new LogicException('not used by HTTP test');
    }

    public function fail(
        MediaJob $job,
        string $errorCode,
        bool $retryable,
        DateTimeImmutable $retryAt,
        DateTimeImmutable $now,
    ): void {
        throw new LogicException('not used by HTTP test');
    }

    public function publicStatus(string $jobId): ?array
    {
        $job = $this->uploads->jobs[$jobId] ?? null;
        return is_array($job) ? [
            'formatVersion' => 1,
            'id' => $jobId,
            'type' => 'media_derivatives',
            'status' => (string) $job['status'],
            'attempts' => 0,
            'maxAttempts' => 3,
            'errorCode' => null,
            'mediaId' => null,
            'mediaStatus' => 'uploaded',
            'updatedAt' => '2026-09-30T12:00:00.000000Z',
        ] : null;
    }

    public function latestPublicStatus(): ?array
    {
        $jobId = array_key_last($this->uploads->jobs);
        return is_string($jobId) ? $this->publicStatus($jobId) : null;
    }
}

final class MemoryRecordRepository implements RecordRepository, \Utsuri\PortableCms\Conventional\Content\RecordBrowser, \Utsuri\PortableCms\Conventional\Content\PublishedRecordRepository
{
    /** @var array<string, RecordSnapshot> */
    public array $records = [];
    /** @var array<string, list<\Utsuri\PortableCms\Conventional\Content\RelationshipProjection>> */
    public array $relationships = [];
    /** @var array<string, RecordSnapshot> */
    public array $publishedRecords = [];

    public function list(string $typeId, int $limit): array
    {
        return array_slice(array_values(array_filter(
            $this->records,
            static fn(RecordSnapshot $record): bool => $record->typeId === $typeId,
        )), 0, $limit);
    }

    public function search(string|array $typeId, string $query, int $page, string $state = ''): \Utsuri\PortableCms\Conventional\Content\RecordPage
    {
        $items = array_values(array_filter($this->records, static fn(RecordSnapshot $record): bool => in_array($record->typeId, is_array($typeId) ? $typeId : [$typeId], true)
            && ($state === '' || ($state === 'published_changes' ? $record->hasUnpublishedChanges() : $record->workflowState === $state))
            && ($query === '' || mb_stripos(json_encode($record->body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $query) !== false)));
        return new \Utsuri\PortableCms\Conventional\Content\RecordPage(array_slice($items, ($page - 1) * 20, 20), count($items) > $page * 20, $page, $query);
    }

    public function stateCounts(string $typeId): array
    {
        $states = array_fill_keys(['draft', 'published', 'archived'], 0);
        foreach ($this->list($typeId, 10000) as $record) {
            ++$states[$record->workflowState];
        }
        $states['published_changes'] = count(array_filter($this->list($typeId, 10000), static fn(RecordSnapshot $record): bool => $record->hasUnpublishedChanges()));
        return $states;
    }

    public function transition(RecordSnapshot $record, string $state, string $revisionId, string $profileId, string $profileVersion, string $actorUserId, string $auditId, DateTimeImmutable $now): RecordSnapshot|RecordConflict
    {
        $current = $this->records[$record->id];
        if ($current->revisionNumber !== $record->revisionNumber) {
            return new RecordConflict($current);
        }
        $result = new RecordSnapshot($record->id, $record->typeId, $state, $revisionId, $record->revisionNumber + 1, $record->body, $now);
        if ($state === 'published') {
            $this->publishedRecords[$record->id] = $result;
        } else {
            unset($this->publishedRecords[$record->id]);
        }
        return $this->records[$record->id] = $result;
    }

    public function pendingPublicationCount(): int
    {
        return count(array_filter($this->records, static fn(RecordSnapshot $record): bool => $record->hasUnpublishedChanges()));
    }

    public function published(RecordSnapshot $record): ?RecordSnapshot
    {
        return $this->publishedRecords[$record->id] ?? null;
    }

    public function discard(RecordSnapshot $record, RecordSnapshot $published, array $relationships, string $revisionId, string $profileId, string $profileVersion, string $actorUserId, string $auditId, DateTimeImmutable $now): RecordSnapshot|RecordConflict
    {
        if ($this->records[$record->id]->revisionNumber !== $record->revisionNumber) {
            return new RecordConflict($this->records[$record->id]);
        }
        $this->relationships[$record->id] = $relationships;
        $result = new RecordSnapshot($record->id, $record->typeId, 'published', $revisionId, $record->revisionNumber + 1, $published->body, $now);
        $this->publishedRecords[$record->id] = $result;
        return $this->records[$record->id] = $result;
    }

    public function find(string $typeId, string $recordId): ?RecordSnapshot
    {
        $record = $this->records[$recordId] ?? null;
        return $record instanceof RecordSnapshot && $record->typeId === $typeId ? $record : null;
    }

    /**
     * @param array<string, mixed> $body
     * @param list<\Utsuri\PortableCms\Conventional\Content\RelationshipProjection> $relationships
     */
    public function create(
        string $recordId,
        string $revisionId,
        string $typeId,
        string $profileId,
        string $profileVersion,
        array $body,
        array $relationships,
        string $actorUserId,
        string $auditId,
        DateTimeImmutable $now,
    ): RecordSnapshot {
        $this->relationships[$recordId] = $relationships;
        return $this->records[$recordId] = new RecordSnapshot(
            $recordId,
            $typeId,
            'draft',
            $revisionId,
            1,
            $body,
            $now,
        );
    }

    /**
     * @param array<string, mixed> $body
     * @param list<\Utsuri\PortableCms\Conventional\Content\RelationshipProjection> $relationships
     */
    public function update(
        string $typeId,
        string $recordId,
        int $expectedRevisionNumber,
        string $revisionId,
        string $profileId,
        string $profileVersion,
        array $body,
        array $relationships,
        string $actorUserId,
        string $auditId,
        DateTimeImmutable $now,
    ): RecordSnapshot|RecordConflict {
        $current = $this->find($typeId, $recordId);
        if ($current === null) {
            throw new RuntimeException('Record not found');
        }
        if ($current->revisionNumber !== $expectedRevisionNumber) {
            return new RecordConflict($current);
        }
        $this->relationships[$recordId] = $relationships;
        return $this->records[$recordId] = new RecordSnapshot(
            $recordId,
            $typeId,
            $current->workflowState,
            $revisionId,
            $expectedRevisionNumber + 1,
            $body,
            $now,
            $current->publishedRevisionId,
            $current->publishedRevisionNumber,
            $current->publishedAt,
        );
    }
}

/** @return array{app: IdentityApplication, contentRepository: MemoryRecordRepository, uploadRepository: MemoryUploadRepository|null, mediaRoot: string|null, previewRepository: MemoryPreviewRepository|null, exportRepository: MemoryExportRepository|null, clock: IdentityTestClock} */
function contentTestApplication(
    bool $withMediaUploads = false,
    bool $withPreviews = false,
    bool $withExports = false,
    ?\Utsuri\PortableCms\Conventional\Content\TerminologyRepository $terminologyRepository = null,
    ?ProfileManifest $profileOverride = null,
    ?\Utsuri\PortableCms\Conventional\Media\MediaLibrary $library = null,
    ?\Utsuri\PortableCms\Conventional\Design\RegistryService $designService = null,
    ?\Utsuri\PortableCms\Conventional\Content\AdministrationLabels $administrationLabels = null,
    ?\Utsuri\PortableCms\Conventional\Preview\PreviewCheckHistory $previewChecks = null,
    ?\Utsuri\PortableCms\Conventional\Preview\PreviewStartup $previewStartup = null,
): array {
    $identityRepository = new MemoryIdentityRepository();
    $identityRepository->addUser(
        '11111111111111111111111111111111',
        'admin@example.test',
        'Content Administrator',
        password_hash('correct horse battery staple', PASSWORD_BCRYPT, ['cost' => 4]),
    );
    $clock = new IdentityTestClock(new DateTimeImmutable('2026-09-30T12:00:00Z'));
    $random = new IdentityTestRandom();
    $profile = $profileOverride ?? ProfileManifest::gateZero();
    $contentRepository = new MemoryRecordRepository();
    $uploadRepository = null;
    $mediaRoot = null;
    $mediaUploads = null;
    $mediaJobs = null;
    $previewRepository = null;
    if ($withMediaUploads) {
        $uploadRepository = new MemoryUploadRepository();
        $mediaRoot = sys_get_temp_dir() . '/portable-cms-http-upload-' . bin2hex(random_bytes(8));
        $mediaUploads = new MediaUploadService(
            $uploadRepository,
            new FilesystemUploadStorage($mediaRoot),
            $clock,
            $random,
        );
        $mediaJobs = new ContentTestMediaJobs($uploadRepository);
    }
    $previews = null;
    if ($withPreviews) {
        $previewRepository = new MemoryPreviewRepository();
        $previews = new PreviewService($previewRepository, $profile, $clock, $random, checks: $previewChecks, startup: $previewStartup);
    }
    $exportRepository = null;
    $exports = null;
    if ($withExports) {
        $exportRepository = new MemoryExportRepository();
        $exports = new ExportService($exportRepository, $profile, $clock, $random);
    }
    $app = new IdentityApplication(
        new IdentityService($identityRepository, $clock, $random, str_repeat('t', 32)),
        new OriginPolicy('https://cms.example.test'),
        $clock,
        $random,
        content: new ContentService($contentRepository, $profile, $clock, $random, $library),
        profile: $profile,
        mediaUploads: $mediaUploads,
        mediaJobs: $mediaJobs,
        previews: $previews,
        exports: $exports,
        design: $designService,
        terminology: $terminologyRepository === null ? null : new \Utsuri\PortableCms\Conventional\Content\TerminologyService($terminologyRepository, $profile, $clock, $random),
        mediaLibrary: $library,
        administrationLabels: $administrationLabels,
    );
    return compact(
        'app',
        'contentRepository',
        'uploadRepository',
        'mediaRoot',
        'previewRepository',
        'exportRepository',
        'clock',
    );
}

/** @return array<string, string> */
function contentMutationHeaders(): array
{
    return ['origin' => 'https://cms.example.test', 'sec-fetch-site' => 'same-origin'];
}

$tests['profile manifest is bounded data and rejects unknown executable-shaped properties'] = static function (): void {
    $profile = ProfileManifest::gateZero();
    assertSameValue('portable-gate-zero', $profile->id, 'fixture profile should load from its document');
    assertSameValue(3, count($profile->types), 'fixture profile should declare two collections and one taxonomy');
    assertSameValue('taxonomy', $profile->type('topic')?->kind, 'topic should be a taxonomy namespace');
    assertSameValue(
        'portfolio-entry',
        $profile->type('article')?->fields[2]->targetType,
        'article relationship should have a typed target',
    );

    $path = sys_get_temp_dir() . '/portable-cms-profile-' . bin2hex(random_bytes(8)) . '.json';
    $document = json_decode(
        (string) file_get_contents(dirname(__DIR__, 2) . '/engine/src/cms/conventional/profile/gate-zero.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $document['recordTypes'][0]['renderer'] = '<?php system("unexpected");';
    file_put_contents($path, json_encode($document, JSON_THROW_ON_ERROR));
    try {
        try {
            ProfileManifest::load($path);
            throw new RuntimeException('unknown profile property unexpectedly loaded');
        } catch (\Utsuri\PortableCms\Conventional\SchemaValidationFailure $error) {
            assertTrueValue(
                in_array('additionalProperties', array_column($error->issues, 'keyword'), true),
                'unknown properties should fail closed at the schema boundary',
            );
        }
    } finally {
        @unlink($path);
    }
};

$tests['localized taxonomy terms and typed relationships use the generic form and service'] = static function (): void {
    ['app' => $app, 'contentRepository' => $repository] = contentTestApplication();
    $cookies = authenticatedTestCookies($app);

    $invalidTopic = $app->handle(new Request(
        'POST',
        '/records/topic',
        contentMutationHeaders(),
        [
            '_csrf' => $cookies[IdentityService::CSRF_COOKIE],
            'label__ca' => 'Habitatge',
            'label__es' => 'Vivienda',
            'label__en' => '',
        ],
        $cookies,
    ));
    assertSameValue(422, $invalidTopic->status, 'taxonomy labels should require every configured locale');
    assertTrueValue(
        str_contains($invalidTopic->body, 'required in every public language'),
        'localized validation should be actionable',
    );

    $topicResponse = $app->handle(new Request(
        'POST',
        '/records/topic',
        contentMutationHeaders(),
        [
            '_csrf' => $cookies[IdentityService::CSRF_COOKIE],
            'label__ca' => 'Habitatge',
            'label__es' => 'Vivienda',
            'label__en' => 'Housing',
        ],
        $cookies,
    ));
    assertSameValue(303, $topicResponse->status, 'complete localized taxonomy term should save');
    $topic = array_values(array_filter(
        $repository->records,
        static fn(RecordSnapshot $record): bool => $record->typeId === 'topic',
    ))[0];
    assertSameValue('Habitatge', $topic->body['label']['ca'], 'localized body should retain Catalan');

    $entryResponse = $app->handle(new Request(
        'POST',
        '/records/portfolio-entry',
        contentMutationHeaders(),
        [
            '_csrf' => $cookies[IdentityService::CSRF_COOKIE],
            'title' => 'Courtyard house',
            'summary' => '',
            'topics' => [$topic->id],
        ],
        $cookies,
    ));
    assertSameValue(303, $entryResponse->status, 'portfolio entry should accept a topic reference');
    $entry = array_values(array_filter(
        $repository->records,
        static fn(RecordSnapshot $record): bool => $record->typeId === 'portfolio-entry',
    ))[0];
    assertSameValue('taxonomy', $repository->relationships[$entry->id][0]->kind, 'topic should project as taxonomy');

    $wrongTarget = $app->handle(new Request(
        'POST',
        '/records/article',
        contentMutationHeaders(),
        [
            '_csrf' => $cookies[IdentityService::CSRF_COOKIE],
            'title' => 'Invalid article',
            'body' => '',
            'featuredEntry' => $topic->id,
            'topics' => [$topic->id],
        ],
        $cookies,
    ));
    assertSameValue(422, $wrongTarget->status, 'relationship should reject an existing record of the wrong type');
    assertTrueValue(str_contains($wrongTarget->body, 'selection is invalid'), 'target error should remain generic');

    $articleResponse = $app->handle(new Request(
        'POST',
        '/records/article',
        contentMutationHeaders(),
        [
            '_csrf' => $cookies[IdentityService::CSRF_COOKIE],
            'title' => 'Housing notes',
            'body' => 'A short article.',
            'featuredEntry' => $entry->id,
            'topics' => [$topic->id, $topic->id],
        ],
        $cookies,
    ));
    assertSameValue(303, $articleResponse->status, 'valid typed and taxonomy relationships should save');
    $article = array_values(array_filter(
        $repository->records,
        static fn(RecordSnapshot $record): bool => $record->typeId === 'article',
    ))[0];
    assertSameValue($entry->id, $article->body['featuredEntry'], 'typed relationship should remain authoritative JSON');
    assertSameValue([$topic->id], $article->body['topics'], 'duplicate taxonomy selections should normalize');
    assertSameValue(
        ['record', 'taxonomy'],
        array_map(static fn($relationship): string => $relationship->kind, $repository->relationships[$article->id]),
        'article should project both relationship kinds',
    );

    $edit = $app->handle(new Request('GET', '/records/article/' . $article->id, cookies: $cookies));
    assertSameValue(200, $edit->status, 'related article should reload');
    assertTrueValue(str_contains($edit->body, 'Courtyard house'), 'record selector should show editorial labels');
    assertTrueValue(str_contains($edit->body, 'Housing'), 'taxonomy selector should show localized labels');
    assertTrueValue(
        substr_count($edit->body, 'class="admin-selection"') >= 2,
        'saved record and taxonomy selections should remain selected',
    );
};

$tests['profile-driven record pages require authentication and expose both fixture types'] = static function (): void {
    ['app' => $app] = contentTestApplication();
    $anonymous = $app->handle(new Request('GET', '/records/portfolio-entry'));
    assertSameValue(303, $anonymous->status, 'record lists should require authentication');

    $cookies = authenticatedTestCookies($app);
    $overview = $app->handle(new Request('GET', '/', cookies: $cookies));
    assertTrueValue(str_contains($overview->body, 'Portfolio entries'), 'profile navigation should include entries');
    assertTrueValue(str_contains($overview->body, 'Articles'), 'profile navigation should include articles');
    $newArticle = $app->handle(new Request('GET', '/records/article/new', cookies: $cookies));
    assertSameValue(200, $newArticle->status, 'the second fixture type should use the generic form route');
    assertTrueValue(str_contains($newArticle->body, 'name="body"'), 'article should render its own field shape');
};

$tests['exact preview routes require authentication and acknowledge durable queueing'] = static function (): void {
    ['app' => $app, 'previewRepository' => $repository] = contentTestApplication(withPreviews: true);
    assertTrueValue($repository instanceof MemoryPreviewRepository, 'preview fixture should be available');
    $anonymous = $app->handle(new Request('GET', '/previews'));
    assertSameValue(303, $anonymous->status, 'preview list should require authentication');

    $cookies = authenticatedTestCookies($app);
    $list = $app->handle(new Request('GET', '/previews', cookies: $cookies));
    assertSameValue(200, $list->status, 'authenticated editor should open preview list');
    assertTrueValue(str_contains($list->body, 'Create exact preview'), 'preview action should be explicit');

    $rejected = $app->handle(new Request(
        'POST',
        '/previews',
        contentMutationHeaders(),
        ['_csrf' => 'wrong-token'],
        $cookies,
    ));
    assertSameValue(403, $rejected->status, 'preview creation should require CSRF authority');
    assertSameValue(0, count($repository->previews), 'rejected request must not freeze a preview');

    $queued = $app->handle(new Request(
        'POST',
        '/previews',
        contentMutationHeaders(),
        ['_csrf' => $cookies[IdentityService::CSRF_COOKIE]],
        $cookies,
    ));
    assertSameValue(202, $queued->status, 'preview creation should acknowledge asynchronous work');
    assertSameValue(1, count($repository->previews), 'accepted request should queue exactly one preview');
    $preview = array_values($repository->previews)[0];
    assertSameValue(
        '2; url=/previews/' . $preview->id,
        $queued->headers()['refresh'][0] ?? null,
        'queue acknowledgement should progress to the stable status route',
    );
    $status = $app->handle(new Request('GET', '/previews/' . $preview->id, cookies: $cookies));
    assertSameValue(200, $status->status, 'queued preview should have a stable status page');
    assertTrueValue(str_contains($status->body, 'frozen preview is queued'), 'status should explain queued work');
    assertSameValue(
        '2; url=/previews/' . $preview->id,
        $status->headers()['refresh'][0] ?? null,
        'queued preview status should refresh without JavaScript',
    );
    $prematureDelete = $app->handle(new Request(
        'POST',
        '/previews/' . $preview->id . '/delete',
        contentMutationHeaders(),
        ['_csrf' => $cookies[IdentityService::CSRF_COOKIE]],
        $cookies,
    ));
    assertSameValue(409, $prematureDelete->status, 'non-terminal preview must not be deleted');
    $repository->previews[$preview->id] = new PreviewStatus(
        $preview->id,
        'ready',
        $preview->jobId,
        null,
        ['formatVersion' => 1, 'artifacts' => []],
        $preview->createdAt,
        $preview->updatedAt,
    );
    $deleted = $app->handle(new Request(
        'POST',
        '/previews/' . $preview->id . '/delete',
        contentMutationHeaders(),
        ['_csrf' => $cookies[IdentityService::CSRF_COOKIE]],
        $cookies,
    ));
    assertSameValue(303, $deleted->status, 'terminal preview should delete through POST/redirect/GET');
    assertSameValue(0, count($repository->previews), 'deleted preview should leave the repository');
};

$tests['normalized export requires authentication and downloads one audited snapshot'] = static function (): void {
    ['app' => $app, 'exportRepository' => $repository] = contentTestApplication(withExports: true);
    assertTrueValue($repository instanceof MemoryExportRepository, 'export fixture should be available');
    $anonymous = $app->handle(new Request('GET', '/exports'));
    assertSameValue(303, $anonymous->status, 'export page should require authentication');

    $cookies = authenticatedTestCookies($app);
    $page = $app->handle(new Request('GET', '/exports', cookies: $cookies));
    assertSameValue(200, $page->status, 'authenticated editor should open export page');
    assertTrueValue(
        str_contains($page->body, 'Download normalized export'),
        'export page should name its action clearly',
    );
    assertTrueValue(
        str_contains($page->body, 'does not contain image bytes'),
        'export page should not misrepresent metadata as a complete backup',
    );

    $rejected = $app->handle(new Request(
        'POST',
        '/exports',
        contentMutationHeaders(),
        ['_csrf' => 'wrong-token'],
        $cookies,
    ));
    assertSameValue(403, $rejected->status, 'export creation should require CSRF authority');
    assertSameValue(0, count($repository->requests), 'rejected export must not read a snapshot');

    $download = $app->handle(new Request(
        'POST',
        '/exports',
        contentMutationHeaders(),
        ['_csrf' => $cookies[IdentityService::CSRF_COOKIE]],
        $cookies,
    ));
    assertSameValue(200, $download->status, 'authorized export should download synchronously');
    assertSameValue(
        'application/json; charset=utf-8',
        $download->headers()['content-type'][0] ?? null,
        'download should retain an explicit JSON media type',
    );
    $disposition = $download->headers()['content-disposition'][0] ?? '';
    assertTrueValue(
        is_string($disposition)
            && preg_match('/\Aattachment; filename="portable-cms-export-20260930-[a-f0-9]{8}\.json"\z/', $disposition) === 1,
        'download name should be stable, dated, and opaque',
    );
    $document = json_decode($download->body, true, flags: JSON_THROW_ON_ERROR);
    assertSameValue('portable-gate-zero', $document['profile']['id'] ?? null, 'download should identify its profile');
    assertSameValue(1, count($repository->requests), 'authorized download should create one snapshot');
    assertSameValue(
        '11111111111111111111111111111111',
        $repository->requests[0]['actorUserId'] ?? null,
        'export should remain attributable to the administrator',
    );
};

$tests['record creation validates server-side preserves input and redirects after success'] = static function (): void {
    ['app' => $app, 'contentRepository' => $repository] = contentTestApplication();
    $cookies = authenticatedTestCookies($app);
    $invalid = $app->handle(new Request(
        'POST',
        '/records/portfolio-entry',
        contentMutationHeaders(),
        [
            '_csrf' => $cookies[IdentityService::CSRF_COOKIE],
            'title' => '',
            'summary' => '<script>alert("preserved")</script>',
        ],
        $cookies,
    ));
    assertSameValue(422, $invalid->status, 'missing required content should return the complete form');
    assertTrueValue(str_contains($invalid->body, 'Title is required.'), 'validation summary should be actionable');
    assertTrueValue(
        str_contains($invalid->body, '&lt;script&gt;alert(&quot;preserved&quot;)&lt;/script&gt;'),
        'preserved hostile input should remain escaped',
    );
    assertSameValue(0, count($repository->records), 'invalid input must not create a record');

    $created = $app->handle(new Request(
        'POST',
        '/records/portfolio-entry',
        contentMutationHeaders(),
        [
            '_csrf' => $cookies[IdentityService::CSRF_COOKIE],
            'title' => 'First entry',
            'summary' => "A reusable record.\r\nSecond line.",
        ],
        $cookies,
    ));
    assertSameValue(303, $created->status, 'successful creation should use POST/redirect/GET');
    assertSameValue(1, count($repository->records), 'one generic record should be created');
    $record = array_values($repository->records)[0];
    assertSameValue("A reusable record.\nSecond line.", $record->body['summary'], 'newlines should normalize');
    assertSameValue(
        ['/records/portfolio-entry/' . $record->id],
        $created->headers()['location'],
        'redirect should identify the stable record',
    );
    $reloaded = $app->handle(new Request(
        'GET',
        '/records/portfolio-entry/' . $record->id,
        cookies: $cookies,
    ));
    assertSameValue(200, $reloaded->status, 'created record should reload through its edit route');
    assertTrueValue(str_contains($reloaded->body, 'First entry'), 'saved content should survive reload');
};

$tests['record updates create revisions and stale submissions return an explicit conflict'] = static function (): void {
    ['app' => $app, 'contentRepository' => $repository] = contentTestApplication();
    $cookies = authenticatedTestCookies($app);
    $create = $app->handle(new Request(
        'POST',
        '/records/portfolio-entry',
        contentMutationHeaders(),
        [
            '_csrf' => $cookies[IdentityService::CSRF_COOKIE],
            'title' => 'Original',
            'summary' => 'Original summary',
        ],
        $cookies,
    ));
    $record = array_values($repository->records)[0];
    assertSameValue(303, $create->status, 'fixture creation should succeed');

    $updated = $app->handle(new Request(
        'POST',
        '/records/portfolio-entry/' . $record->id,
        contentMutationHeaders(),
        [
            '_csrf' => $cookies[IdentityService::CSRF_COOKIE],
            '_revision' => '1',
            'title' => 'Authoritative update',
            'summary' => 'Saved second revision',
        ],
        $cookies,
    ));
    assertSameValue(303, $updated->status, 'current revision should update');
    assertSameValue(2, $repository->records[$record->id]->revisionNumber, 'revision should advance exactly once');

    $conflict = $app->handle(new Request(
        'POST',
        '/records/portfolio-entry/' . $record->id,
        contentMutationHeaders(),
        [
            '_csrf' => $cookies[IdentityService::CSRF_COOKIE],
            '_revision' => '1',
            'title' => 'Stale editor input',
            'summary' => 'Do not discard this',
        ],
        $cookies,
    ));
    assertSameValue(409, $conflict->status, 'stale revision should return a conflict form');
    assertTrueValue(
        str_contains($conflict->body, 'based on revision 1; the current saved revision is 2'),
        'conflict should identify both revision numbers',
    );
    assertTrueValue(str_contains($conflict->body, 'Stale editor input'), 'conflict should preserve editor input');
    assertTrueValue(str_contains($conflict->body, 'Authoritative update'), 'conflict should show current saved input');
    assertTrueValue(str_contains($conflict->body, 'value="2"'), 'resolution form should target the current revision');
    assertSameValue(
        'Authoritative update',
        $repository->records[$record->id]->body['title'],
        'stale input must not overwrite the current record',
    );
};

$tests['authenticated JSON upload routes preserve security and resumable semantics'] = static function (): void {
    ['app' => $app, 'contentRepository' => $contentRepository, 'uploadRepository' => $uploads, 'mediaRoot' => $mediaRoot]
        = contentTestApplication(true);
    assertTrueValue($uploads instanceof MemoryUploadRepository, 'upload fixture should be available');
    assertTrueValue(is_string($mediaRoot), 'private media root should be available');
    $remove = static function (string $directory) use (&$remove): void {
        if (!is_dir($directory)) {
            return;
        }
        foreach (scandir($directory) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $directory . '/' . $item;
            is_dir($path) && !is_link($path) ? $remove($path) : @unlink($path);
        }
        @rmdir($directory);
    };
    try {
        $cookies = authenticatedTestCookies($app);
        $created = $app->handle(new Request(
            'POST',
            '/records/portfolio-entry',
            contentMutationHeaders(),
            [
                '_csrf' => $cookies[IdentityService::CSRF_COOKIE],
                'title' => 'Upload owner',
                'summary' => '',
            ],
            $cookies,
        ));
        assertSameValue(303, $created->status, 'owner record should save');
        $record = array_values($contentRepository->records)[0];
        $uploads->records[$record->id] = [
            'typeId' => $record->typeId,
            'revisionNumber' => $record->revisionNumber,
        ];
        $edit = $app->handle(new Request(
            'GET',
            '/records/portfolio-entry/' . $record->id,
            cookies: $cookies,
        ));
        assertTrueValue(str_contains($edit->body, 'data-media-upload'), 'saved record should expose the upload island');
        assertTrueValue(
            str_contains($edit->body, '/assets/resumable-upload.js?v=7'),
            'island should use a versioned external module',
        );

        $anonymous = $app->handle(new Request(
            'POST',
            '/api/records/portfolio-entry/' . $record->id . '/media-uploads',
        ));
        assertSameValue(401, $anonymous->status, 'upload API should reject missing authentication as JSON');
        assertSameValue(
            'application/json; charset=utf-8',
            $anonymous->headers()['content-type'][0] ?? null,
            'security headers must preserve explicit JSON content type',
        );

        $image = "\x89PNG\r\n\x1A\nDATA";
        $headers = contentMutationHeaders() + [
            'content-type' => 'application/json',
            'x-csrf-token' => $cookies[IdentityService::CSRF_COOKIE],
        ];
        $started = $app->handle(new Request(
            'POST',
            '/api/records/portfolio-entry/' . $record->id . '/media-uploads',
            $headers,
            cookies: $cookies,
            body: RequestBody::fromString(json_encode([
                'formatVersion' => 1,
                'expectedRevision' => 1,
                'filename' => 'route.png',
                'declaredType' => 'image/png',
                'byteSize' => strlen($image),
                'clientUploadId' => 'route-upload',
            ], JSON_THROW_ON_ERROR)),
        ));
        assertSameValue(201, $started->status, 'valid upload intent should create a session');
        $startedValue = json_decode($started->body, true, flags: JSON_THROW_ON_ERROR);
        $sessionId = $startedValue['upload']['id'] ?? null;
        assertTrueValue(is_string($sessionId), 'JSON response should expose an opaque session ID');

        $part = $app->handle(new Request(
            'PUT',
            '/api/media-uploads/' . $sessionId . '/parts/1',
            contentMutationHeaders() + [
                'content-length' => (string) strlen($image),
                'x-csrf-token' => $cookies[IdentityService::CSRF_COOKIE],
            ],
            cookies: $cookies,
            body: RequestBody::fromString($image),
        ));
        assertSameValue(200, $part->status, 'exact part body should upload');
        $completed = $app->handle(new Request(
            'POST',
            '/api/media-uploads/' . $sessionId . '/complete',
            contentMutationHeaders() + ['x-csrf-token' => $cookies[IdentityService::CSRF_COOKIE]],
            cookies: $cookies,
        ));
        assertSameValue(202, $completed->status, 'completed upload should queue derivative work');
        $completedValue = json_decode($completed->body, true, flags: JSON_THROW_ON_ERROR);
        assertSameValue('queued', $completedValue['job']['status'] ?? null, 'API should expose queued terminal handoff');
        $jobId = $completedValue['job']['id'] ?? null;
        assertTrueValue(is_string($jobId), 'upload completion should return a job ID');
        $jobStatus = $app->handle(new Request(
            'GET',
            '/api/jobs/' . $jobId,
            cookies: $cookies,
        ));
        assertSameValue(200, $jobStatus->status, 'authenticated editor should poll derivative status');
        $jobValue = json_decode($jobStatus->body, true, flags: JSON_THROW_ON_ERROR);
        assertSameValue('queued', $jobValue['job']['status'] ?? null, 'polling should expose bounded job state');
        $dashboard = $app->handle(new Request('GET', '/', cookies: $cookies));
        assertTrueValue(
            str_contains($dashboard->body, 'The latest image is queued and waiting to process.'),
            'overview should make the latest media state visible',
        );
        $wake = $app->handle(new Request(
            'POST',
            '/jobs/wake',
            contentMutationHeaders(),
            ['_csrf' => $cookies[IdentityService::CSRF_COOKIE]],
            $cookies,
        ));
        assertSameValue(202, $wake->status, 'manual recovery should acknowledge background processing');
        assertSameValue('2; url=/', $wake->headers()['refresh'][0] ?? null, 'acknowledgement should revisit overview');
        assertTrueValue(
            str_contains($wake->body, 'Media processing requested'),
            'manual recovery should never appear inert',
        );
    } finally {
        $remove($mediaRoot);
    }
};
