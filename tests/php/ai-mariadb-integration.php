<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Ai\AssistanceFailure;
use Utsuri\PortableCms\Conventional\Ai\AssistancePolicy;
use Utsuri\PortableCms\Conventional\Ai\AssistanceRunner;
use Utsuri\PortableCms\Conventional\Ai\AssistanceService;
use Utsuri\PortableCms\Conventional\Ai\LibraryAssistanceImageSource;
use Utsuri\PortableCms\Conventional\Ai\MariaDbAssistanceRepository;
use Utsuri\PortableCms\Conventional\Content\ContentService;
use Utsuri\PortableCms\Conventional\Content\MariaDbRecordRepository;
use Utsuri\PortableCms\Conventional\Content\MariaDbSiteLanguages;
use Utsuri\PortableCms\Conventional\Content\ProfilePackage;
use Utsuri\PortableCms\Conventional\Http\OriginPolicy;
use Utsuri\PortableCms\Conventional\Http\Request;
use Utsuri\PortableCms\Conventional\Identity\IdentityService;
use Utsuri\PortableCms\Conventional\Identity\MariaDbIdentityRepository;
use Utsuri\PortableCms\Conventional\IdentityApplication;
use Utsuri\PortableCms\Conventional\Media\FilesystemMediaStore;
use Utsuri\PortableCms\Conventional\Media\MariaDbMediaLibrary;
use Utsuri\PortableCms\Conventional\Media\MediaLibrary;
use Utsuri\PortableCms\Conventional\RuntimeConfiguration;

require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/bootstrap.php';
require_once __DIR__ . '/assertions.php';
$tests = [];
require_once __DIR__ . '/identity-test.php';
require_once __DIR__ . '/ai-assistance-test.php';
require_once __DIR__ . '/ai-fake-provider.php';
$configPath = $argv[1] ?? '';
$config = RuntimeConfiguration::load($configPath);
assertTrueValue($config->allowInsecureLoopback, 'AI integration requires an ephemeral loopback fixture');
// The concurrent browser server must share the same enabled policy as this job test.
// A disabled live configuration correctly reconciles all pending jobs as unavailable.
$originalConfig = (string) file_get_contents($configPath);
$enabledConfig = json_decode($originalConfig, true, 16, JSON_THROW_ON_ERROR);
$enabledConfig['aiAssistance'] = aiTestPolicyDocument();
file_put_contents($configPath, json_encode($enabledConfig, JSON_THROW_ON_ERROR), LOCK_EX);
register_shutdown_function(static function () use ($configPath, $originalConfig): void {
    file_put_contents($configPath, $originalConfig, LOCK_EX);
});
// The single-worker development server may still be draining requests from the previous
// journey that read the disabled configuration and would disable the jobs created below.
// It serves one request at a time, so one completed request is a barrier for all of them.
$origin = getenv('CMS_BROWSER_ORIGIN');
if (is_string($origin) && $origin !== '') {
    $barrier = file_get_contents($origin . '/login', false, stream_context_create(['http' => ['timeout' => 30, 'ignore_errors' => true]]));
    assertTrueValue(is_string($barrier), 'the development server should finish requests that read the disabled configuration');
}
$pdo = $config->connect();
// Earlier browser journeys add languages; retain their stored text during this revision proof.
$languages = new MariaDbSiteLanguages($pdo);
$profile = $languages->installed(ProfilePackage::load(dirname(__DIR__, 2) . '/profiles/reference-studio')->manifest);
$clock = new IdentityTestClock(new DateTimeImmutable('2026-10-03T14:00:00Z'));
$random = new IdentityTestRandom();
$policy = AssistancePolicy::from(aiTestPolicyDocument());
$repository = new MariaDbAssistanceRepository($pdo, $profile);
$other = new MariaDbAssistanceRepository($config->connect(), $profile);
$service = new AssistanceService($repository, $policy, $profile, $clock, $random);
$media = new MediaLibrary(new MariaDbMediaLibrary($pdo), new FilesystemMediaStore($config->mediaRoot), $clock, $random);
$content = new ContentService(new MariaDbRecordRepository($pdo), $profile, $clock, $random, $media, publicLocales: static fn(): array => $languages->load($profile)->public());
$record = $content->find('project', '10000000000000000000000000000001');
$mediaId = $record->body['gallery'][0];
$actor = (string) $pdo->query('SELECT id FROM cms_users LIMIT 1')->fetchColumn();
$provider = new AiFakeProvider();
$runner = new AssistanceRunner($repository, $service, $provider, new LibraryAssistanceImageSource($media), $clock, $random);
$request = static function (?string $client = null) use ($service, $content, $record, $mediaId, $random, $actor) {
    $saved = $content->find('project', $record->id);
    return $service->request('project', $record->id, $saved->revisionNumber, $mediaId, 'Fictional public courtyard.', $client ?? bin2hex($random->bytes(16)), $actor, true);
};
foreach (['consent', 'revision', 'media'] as $hostile) {
    try {
        $service->request(
            'project',
            $record->id,
            $hostile === 'revision' ? $record->revisionNumber + 1 : $record->revisionNumber,
            $hostile === 'media' ? str_repeat('f', 32) : $mediaId,
            '',
            str_repeat('1', 32),
            $actor,
            $hostile !== 'consent',
        );
        throw new RuntimeException('invalid request unexpectedly enqueued: ' . $hostile);
    } catch (AssistanceFailure) {
    }
}
$job = $request(str_repeat('a', 32));
assertSameValue($job->id, $request(str_repeat('a', 32))->id, 'duplicate delivery reserves quota and creates a job once');
assertSameValue(1, (int) $pdo->query('SELECT requests FROM cms_ai_quota')->fetchColumn(), 'idempotent request increments quota once');
foreach (['sourceRevision', 'mediaChecksum', 'derivativeChecksum', 'taxonomyRevision', 'locales', 'recipe', 'provider', 'model', 'policyFingerprint'] as $key) {
    assertTrueValue(array_key_exists($key, $job->request->frozen), 'job freezes ' . $key);
}
$revisionCount = (int) $pdo->query('SELECT COUNT(*) FROM cms_record_revisions')->fetchColumn();
assertSameValue(true, $runner->runOne(), 'fake provider completes real leased MariaDB job');
$completed = $service->find($job->id, $actor, $record->id);
assertSameValue('completed', $completed->status, 'candidate has a separate completion state');
assertSameValue('Draft caption (en)', $completed->candidate->suggestions['en']['caption'], 'purposes stay distinct');
assertSameValue($revisionCount, (int) $pdo->query('SELECT COUNT(*) FROM cms_record_revisions')->fetchColumn(), 'completion never creates record or term revisions');
assertSameValue(null, $service->find($job->id, str_repeat('f', 32), $record->id), 'private results belong to the requesting actor');
assertSameValue(null, $service->find($job->id, $actor, str_repeat('f', 32)), 'job cannot be applied to another record');

// Explicit authorization, CSRF and origin checks wrap every assistance action.
$app = new IdentityApplication(
    new IdentityService(new MariaDbIdentityRepository($pdo), $clock, $random, $config->throttleSecret),
    new OriginPolicy($config->origin, true),
    $clock,
    $random,
    content: $content,
    profile: $profile,
    insecureLoopback: true,
    mediaLibrary: $media,
    assistance: $service,
);
$anonymous = $app->handle(new Request('GET', '/login'));
$cookies = responseCookies($anonymous);
$login = $app->handle(new Request(
    'POST',
    '/login',
    ['origin' => $config->origin],
    ['_csrf' => $cookies[IdentityService::CSRF_COOKIE], 'login' => 'admin@portable.test', 'password' => 'portable-cms-local'],
    $cookies,
));
assertSameValue(303, $login->status, 'fixture login authenticates');
$cookies = responseCookies($login);
$path = '/records/project/' . $record->id;
$form = ['_revision' => (string) $record->revisionNumber, '_edit_sequence' => (string) $record->editSequence, '_ai_action' => 'request', '_editorial_end' => '1', '_ai_media' => $mediaId,
    '_ai_client' => str_repeat('b', 32), '_ai_consent' => '1', '_csrf' => $cookies[IdentityService::CSRF_COOKIE]];
assertSameValue(403, $app->handle(new Request('POST', $path, ['origin' => $config->origin], $form))->status, 'anonymous mutations rejected');
assertSameValue(403, $app->handle(new Request('POST', $path, ['origin' => 'https://hostile.example.invalid'], $form, $cookies))->status, 'cross-origin AI mutations rejected');
$bad = $form;
$bad['_csrf'] = 'wrong';
assertSameValue(403, $app->handle(new Request('POST', $path, ['origin' => $config->origin], $bad, $cookies))->status, 'wrong CSRF rejected');
assertSameValue(1, (int) $pdo->query('SELECT COUNT(*) FROM cms_ai_jobs')->fetchColumn(), 'rejected mutations create no jobs');
$badTarget = $form;
$badTarget['_ai_action'] = 'apply:caption:en';
$badTarget['_ai_job'] = $job->id;
$badTarget['_ai_target_caption'] = 'gallery';
assertSameValue(422, $app->handle(new Request('POST', $path, ['origin' => $config->origin], $badTarget, $cookies))->status, 'AI cannot write an incompatible field');
$badTarget['_ai_target_caption'] = 'body';
$badTarget['_ai_action'] = 'apply:caption:xx';
assertSameValue(422, $app->handle(new Request('POST', $path, ['origin' => $config->origin], $badTarget, $cookies))->status, 'AI cannot create an uninstalled locale');
assertSameValue($revisionCount, (int) $pdo->query('SELECT COUNT(*) FROM cms_record_revisions')->fetchColumn(), 'forged application has no saved effects');

// One active lease across independent connections; cancellation invalidates a worker already dispatched.
$cancelled = $request();
$claimed = $repository->claim(str_repeat('b', 64), $clock->now());
assertSameValue(null, $other->claim(str_repeat('c', 64), $clock->now()), 'global concurrency is bounded to one');
$service->cancel($cancelled->id, $actor, $record->id);
$repository->finish($claimed, $completed->candidate, null, false, $clock->now());
assertSameValue('cancelled', $service->find($cancelled->id, $actor, $record->id)->status, 'cancelled lease cannot publish a candidate');
$exhausted = $request();
$repository->claim(str_repeat('d', 64), $clock->now());
$clock->current = $clock->current->modify('+3 minutes');
$other->claim(str_repeat('e', 64), $clock->now());
$clock->current = $clock->current->modify('+3 minutes');
assertSameValue(null, $runner->runOne() ? 'claimed' : null, 'exhausted expired lease is not stuck or reclaimed forever');
assertSameValue('failed', $service->find($exhausted->id, $actor, $record->id)->status, 'exhausted lease becomes a terminal safe failure');

$retry = $request();
$provider->failure = new AssistanceFailure('provider_retry', true);
$runner->runOne();
assertSameValue('retry', $service->find($retry->id, $actor, $record->id)->status, 'qualified retry uses durable backoff');
assertSameValue(false, $runner->runOne(), 'retry is not immediately dispatched');
$clock->current = $clock->current->modify('+5 minutes');
$provider->failure = null;
$runner->runOne();
assertSameValue('completed', $service->find($retry->id, $actor, $record->id)->status, 'second bounded attempt completes');

$stale = $request();
$calls = $provider->calls;
$saved = $content->find('project', $record->id);
// The stored body is canonical, including legacy groups and nullable optional fields.
$updated = $content->saveCanonical('project', $record->id, $saved->revisionNumber, $saved->body, $actor);
assertTrueValue($updated instanceof \Utsuri\PortableCms\Conventional\Content\RecordSnapshot, 'staleness fixture creates a valid saved revision');
$runner->runOne();
assertSameValue($calls, $provider->calls, 'stale source never reaches provider');
assertSameValue('stale', $service->find($stale->id, $actor, $record->id)->errorCode, 'record revision staleness is explicit');
$taxonomyJob = $request();
$taxonomy = $content->create('discipline', ['label' => ['ca' => 'Prova IA', 'es' => 'Prueba IA', 'en' => 'AI fixture']], $actor);
assertTrueValue($taxonomy instanceof \Utsuri\PortableCms\Conventional\Content\RecordSnapshot, 'taxonomy staleness fixture creates a valid term without requiring off languages');
$runner->runOne();
assertSameValue('stale', $service->find($taxonomyJob->id, $actor, $record->id)->errorCode, 'new taxonomy revisions invalidate suggestions');
$mediaJob = $request();
$select = $pdo->prepare('SELECT checksum FROM cms_media WHERE id = :id');
$select->execute(['id' => $mediaId]);
$checksum = $select->fetchColumn();
$update = $pdo->prepare('UPDATE cms_media SET checksum = :checksum WHERE id = :id');
$update->execute(['checksum' => str_repeat('f', 64), 'id' => $mediaId]);
$runner->runOne();
$update->execute(['checksum' => $checksum, 'id' => $mediaId]);
assertSameValue('stale', $service->find($mediaJob->id, $actor, $record->id)->errorCode, 'media replacement is rejected before dispatch');
$changedDuringCall = $request();
$provider->afterDispatch = static function () use ($service, $changedDuringCall, $actor, $record): void {
    $service->cancel($changedDuringCall->id, $actor, $record->id);
};
$runner->runOne();
assertSameValue('cancelled', $service->find($changedDuringCall->id, $actor, $record->id)->status, 'in-flight cancellation discards output');
$provider->afterDispatch = null;
$diagnostic = $request();
$provider->failure = new RuntimeException('provider response with fictional-test-credential');
$runner->runOne();
assertSameValue('provider_failed', $service->find($diagnostic->id, $actor, $record->id)->errorCode, 'provider exceptions persist only allowlisted diagnostics');
$private = implode('', $pdo->query('SELECT request_json, candidate_json, error_code FROM cms_ai_jobs')->fetchAll(PDO::FETCH_COLUMN));
assertTrueValue(!str_contains($private, 'fictional-test-credential'), 'no credential enters retained provenance');

$disabledJob = $request();
$disabled = new AssistanceService($repository, null, $profile, $clock, $random);
(new AssistanceRunner($repository, $disabled, null, new LibraryAssistanceImageSource($media), $clock, $random))->runOne();
assertSameValue('unavailable', $service->find($disabledJob->id, $actor, $record->id)->errorCode, 'disabled provider drains queued work safely');

// Quotas and worst-case cost reservations are atomic and never refunded on cancellation or expiry.
$clock->current = new DateTimeImmutable('2026-10-04T14:00:00Z');
$document = aiTestPolicyDocument();
$document['dailyRequests'] = 1;
$limited = new AssistanceService($repository, AssistancePolicy::from($document), $profile, $clock, $random);
$saved = $content->find('project', $record->id);
$one = $limited->request('project', $record->id, $saved->revisionNumber, $mediaId, '', str_repeat('8', 32), $actor, true);
$limited->cancel($one->id, $actor, $record->id);
try {
    $limited->request('project', $record->id, $saved->revisionNumber, $mediaId, '', str_repeat('9', 32), $actor, true);
    throw new RuntimeException('cancelled job refunded quota');
} catch (AssistanceFailure $failure) {
    assertSameValue('quota_exceeded', $failure->safeCode, 'cancellation does not refund dispatch budget');
}
$clock->current = new DateTimeImmutable('2026-10-05T14:00:00Z');
$document['dailyBudgetMicros'] = 1999;
$budget = new AssistanceService($repository, AssistancePolicy::from($document), $profile, $clock, $random);
try {
    $budget->request('project', $record->id, $saved->revisionNumber, $mediaId, '', str_repeat('7', 32), $actor, true);
    throw new RuntimeException('worst-case retry cost not reserved');
} catch (AssistanceFailure $failure) {
    assertSameValue('quota_exceeded', $failure->safeCode, 'both attempts must fit the cost allowance');
}
$repository->purge($clock->now());
assertSameValue(0, (int) $pdo->query('SELECT COUNT(*) FROM cms_ai_jobs WHERE request_json IS NOT NULL OR candidate_json IS NOT NULL')->fetchColumn(), 'expiry removes all private prompts and candidates');
fwrite(STDOUT, "PASS optional AI MariaDB jobs: idempotency, freezes, authorization, leases, cancellation, retries, staleness, quotas, retention and safe diagnostics.\n");
