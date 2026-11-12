<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\ApplicationFactory;
use Utsuri\PortableCms\Conventional\Content\ContentService;
use Utsuri\PortableCms\Conventional\Content\MariaDbRecordRepository;
use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Content\RecordSnapshot;
use Utsuri\PortableCms\Conventional\Database\MigrationRunner;
use Utsuri\PortableCms\Conventional\Identity\AdministratorBootstrap;
use Utsuri\PortableCms\Conventional\Identity\SystemClock;
use Utsuri\PortableCms\Conventional\Identity\SystemRandomSource;
use Utsuri\PortableCms\Conventional\Preview\FilesystemPreviewStore;
use Utsuri\PortableCms\Conventional\Preview\MariaDbPreviewRepository;
use Utsuri\PortableCms\Conventional\Preview\PreviewService;
use Utsuri\PortableCms\Conventional\RuntimeConfiguration;

require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/bootstrap.php';
require_once __DIR__ . '/assertions.php';
require_once __DIR__ . '/restore-fixture.php';
require_once __DIR__ . '/fastcgi-preview-fixture.php';

$dsn = getenv('CMS_TEST_EDITOR_EXACT_DSN');
if (!is_string($dsn) || $dsn === '') {
    throw new RuntimeException('An isolated exact-preview database is required');
}
$repositoryRoot = dirname(__DIR__, 2);
$migrations = glob($repositoryRoot . '/engine/migrations/*.sql');
sort($migrations, SORT_STRING);
$clock = new SystemClock();
$random = new SystemRandomSource();
$profile = ProfileManifest::gateZero();

foreach (['post_response' => 'wakepost_', 'cron_only' => 'wakecron_'] as $strategy => $prefix) {
    $root = sys_get_temp_dir() . '/preview-wake-' . bin2hex($random->bytes(6));
    mkdir($root, 0700);
    mkdir($root . '/public', 0700);
    mkdir($root . '/media', 0700);
    $configPath = $root . '/cms.json';
    file_put_contents($configPath, json_encode([
        'origin' => 'http://localhost:4180',
        'database' => ['dsn' => $dsn, 'username' => 'portable', 'password' => 'integration-secret', 'tablePrefix' => $prefix],
        'storage' => ['mediaRoot' => $root . '/media'],
        'throttleSecretBase64' => base64_encode(str_repeat('s', 32)),
        'errorLog' => $root . '/events.log', 'mediaWakeStrategy' => $strategy,
        'development' => ['allowInsecureLoopback' => true],
    ], JSON_THROW_ON_ERROR));
    chmod($configPath, 0600);
    $configuration = RuntimeConfiguration::load($configPath);
    $pdo = $configuration->connect();
    (new MigrationRunner($pdo))->apply($migrations);
    $actor = (new AdministratorBootstrap($pdo, $clock, $random))->create('wake@portable.test', 'Preview wake owner', 'portable-preview-wake');
    $content = new ContentService(new MariaDbRecordRepository($pdo), $profile, $clock, $random);
    $record = $content->create('portfolio-entry', ['title' => 'Kept FastCGI record', 'summary' => 'Fictional saved content'], $actor);
    assertTrueValue($record instanceof RecordSnapshot, 'the fixture has a kept record');
    $openPath = '/records/portfolio-entry/' . $record->id . '/exact-preview';
    $barrier = 'preview-wake-' . bin2hex($random->bytes(8));
    $quoted = $pdo->quote($barrier);
    // Deliberately hold the real worker's claim. An inline build would block the
    // FastCGI response and fail the client deadline, without relying on compiler speed.
    $pdo->exec("CREATE TRIGGER cms_preview_wake_barrier BEFORE UPDATE ON cms_jobs FOR EACH ROW
        BEGIN
            IF NEW.job_type = 'preview_build' AND NEW.status = 'running' THEN
                IF GET_LOCK($quoted, 30) = 1 THEN DO RELEASE_LOCK($quoted); END IF;
            END IF;
        END");
    $host = null;
    try {
        $host = new FastCgiPreviewFixture($root, $configPath);
        assertSameValue(200, $host->request('GET', '/login', id: 'login-form')['status'], 'real FastCGI sign-in form');
        assertSameValue(303, $host->request('POST', '/login', ['_csrf' => $host->csrf(), 'login' => 'wake@portable.test', 'password' => 'portable-preview-wake'], 'login')['status'], 'real FastCGI owner sign-in');
        assertSameValue(403, $host->request('POST', $openPath, ['_csrf' => 'wrong'], 'rejected')['status'], 'rejected exact opening cannot wake work');
        $form = ['_csrf' => $host->csrf(), '_exact_locale' => 'en'];
        assertSameValue(1, (int) $pdo->query('SELECT GET_LOCK(' . $quoted . ', 0)')->fetchColumn(), 'the fixture controls the worker claim');
        $opened = $host->request('POST', $openPath, $form, 'exact-open');
        assertSameValue(303, $opened['status'], 'exact opening finishes its redirect before any build can complete');
        $waiting = $opened['headers']['location'][0] ?? '';
        assertTrueValue(str_contains($waiting, '&waiting='), 'the redirect names its queued build');
        assertSameValue(1, (int) $pdo->query("SELECT COUNT(*) FROM cms_jobs WHERE job_type = 'preview_build'")->fetchColumn(), 'one exact opening creates one durable job');
        $page = $host->request('GET', $waiting, id: 'waiting');
        assertSameValue(202, $page['status'], 'the waiting tab responds while the worker is blocked');
        assertSameValue('2; url=' . $waiting, $page['headers']['refresh'][0] ?? null, 'waiting keeps refreshing the same capture');
        assertTrueValue(str_contains($page['body'], 'role="status"') && str_contains($page['body'], 'href="'), 'waiting retains an accessible status and manual refresh');
        if ($strategy === 'post_response') {
            assertTrueValue(!$host->finished('exact-open'), 'the request responds before its post-response continuation finishes');
            assertTrueValue(str_contains($page['body'], 'Waiting to build'), 'queued work does not claim to be building');
        } else {
            $host->wait(fn(): bool => $host->finished('exact-open'), 'cron-only response did not terminate');
            assertSameValue(0, (int) $pdo->query('SELECT SUM(attempts) FROM cms_jobs')->fetchColumn(), 'cron-only claims no job after the response');
            assertTrueValue(str_contains($page['body'], 'host’s scheduled worker'), 'cron-only waiting explains the scheduled worker');
        }
        $pdo->query('SELECT RELEASE_LOCK(' . $quoted . ')')->fetchColumn();
        if ($strategy === 'cron_only') {
            $cron = proc_open([PHP_BINARY, $repositoryRoot . '/tools/conventional-media-worker.php', '--config=' . $configPath, '--max-jobs=1', '--max-seconds=10', '--worker-id=cron-preview-proof'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', $root . '/cron.log', 'w'], 2 => ['file', $root . '/cron.stderr', 'w']], $pipes);
            assertTrueValue(is_resource($cron), 'ordinary cron worker starts');
            assertSameValue(0, proc_close($cron), 'ordinary cron completes the queued exact preview');
        }
        $host->wait(fn(): bool => (int) $pdo->query("SELECT COUNT(*) FROM cms_jobs WHERE job_type = 'preview_build' AND status = 'completed' AND attempts = 1")->fetchColumn() === 1, 'one preview job did not complete');
        $host->wait(fn(): bool => $host->finished('exact-open'), 'the post-response request did not terminate');
        $events = array_filter(array_map(static fn(string $line): mixed => json_decode($line, true), file($root . '/events.log', FILE_IGNORE_NEW_LINES)), 'is_array');
        foreach (['preview_job_claimed', 'preview_job_completed'] as $event) {
            assertSameValue(1, count(array_filter($events, static fn(array $entry): bool => ($entry['event'] ?? null) === $event)), 'exact opening records exactly one ' . $event);
        }
        assertSameValue('Kept FastCGI record', $content->find($record->typeId, $record->id)->body['title'], 'opening saves no content');
        if ($strategy === 'post_response') {
            $previews = new PreviewService(new MariaDbPreviewRepository($pdo), $profile, $clock, $random, new FilesystemPreviewStore($root . '/media/previews'));
            $other = $previews->create($actor, false);
            $pdo->query('SELECT GET_LOCK(' . $quoted . ', 0)')->fetchColumn();
            $reused = $host->request('POST', $openPath, $form, 'reuse-ready');
            assertSameValue(303, $reused['status'], 'ready capture is reused');
            assertTrueValue(!str_contains($reused['headers']['location'][0] ?? '', 'waiting='), 'ready reuse needs no waiting tab');
            $host->wait(fn(): bool => $host->finished('reuse-ready'), 'ready reuse unexpectedly tried to run queued work');
            assertSameValue(0, (int) $pdo->query("SELECT attempts FROM cms_jobs WHERE id = '" . $other->jobId . "'")->fetchColumn(), 'ready reuse does not wake an unrelated queued preview');
            $pdo->query('SELECT RELEASE_LOCK(' . $quoted . ')')->fetchColumn();
            assertSameValue(true, ApplicationFactory::previewRunner($configuration)->runOne('fixture-drain')->completed, 'drain the unrelated fixture capture');
            foreach (['from-list', 'from-preview-page'] as $id) {
                $before = (int) $pdo->query("SELECT COUNT(*) FROM cms_jobs WHERE status = 'completed'")->fetchColumn();
                $response = $host->request('POST', '/previews', ['_csrf' => $host->csrf(), 'include_drafts' => '1'], $id);
                assertSameValue(202, $response['status'], 'ordinary preview creation still responds before building');
                $host->wait(fn(): bool => $host->finished($id), 'ordinary preview creation did not finish');
                assertSameValue($before + 1, (int) $pdo->query("SELECT COUNT(*) FROM cms_jobs WHERE status = 'completed' AND attempts = 1")->fetchColumn(), 'ordinary preview opening wakes one bounded job');
            }
        }
        fwrite(STDOUT, 'PASS real FastCGI exact preview ' . $strategy . ": response-before-build, one leased job, safe reuse, waiting refresh and ordinary worker recovery.\n");
    } finally {
        $pdo->query('SELECT RELEASE_LOCK(' . $quoted . ')')->fetchColumn();
        $host?->stop();
        RestoreTestFixture::remove($root);
    }
}
