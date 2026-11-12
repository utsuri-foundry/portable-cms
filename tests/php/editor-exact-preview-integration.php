<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\ApplicationFactory;
use Utsuri\PortableCms\Conventional\Compiler\FrozenSnapshot;
use Utsuri\PortableCms\Conventional\Content\ContentService;
use Utsuri\PortableCms\Conventional\Content\MariaDbRecordRepository;
use Utsuri\PortableCms\Conventional\Content\ProfilePackage;
use Utsuri\PortableCms\Conventional\Content\RecordSnapshot;
use Utsuri\PortableCms\Conventional\Database\MigrationRunner;
use Utsuri\PortableCms\Conventional\Http\Request;
use Utsuri\PortableCms\Conventional\Http\Response;
use Utsuri\PortableCms\Conventional\Identity\AdministratorBootstrap;
use Utsuri\PortableCms\Conventional\Identity\Clock;
use Utsuri\PortableCms\Conventional\Identity\IdentityService;
use Utsuri\PortableCms\Conventional\Identity\SystemClock;
use Utsuri\PortableCms\Conventional\Identity\SystemRandomSource;
use Utsuri\PortableCms\Conventional\Preview\MariaDbPreviewRepository;
use Utsuri\PortableCms\Conventional\Preview\PreviewStatus;
use Utsuri\PortableCms\Conventional\RuntimeConfiguration;

require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/bootstrap.php';
require_once dirname(__DIR__, 2) . '/tools/development/reference-studio-seeder.php';
require_once dirname(__DIR__, 2) . '/tools/development/reference-media-seeder.php';
require_once __DIR__ . '/assertions.php';
require_once __DIR__ . '/restore-fixture.php';

$root = dirname(__DIR__, 2);
$dsn = getenv('CMS_TEST_EDITOR_EXACT_DSN');
if (!is_string($dsn) || $dsn === '') {
    throw new RuntimeException('An isolated exact-editor database is required');
}
$pdo = new PDO($dsn, 'portable', 'integration-secret', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
$paths = glob($root . '/engine/migrations/*.sql');
sort($paths, SORT_STRING);
(new MigrationRunner($pdo))->apply($paths);
$clock = new SystemClock();
$random = new SystemRandomSource();
$owner = (new AdministratorBootstrap($pdo, $clock, $random))->create('exact@portable.test', 'Exact preview owner', 'portable-exact-fixture');
$package = ProfilePackage::load($root . '/profiles/reference-studio');
$snapshot = FrozenSnapshot::load($package->root . '/fixtures/snapshot.json', $root . '/schemas/portable-cms-snapshot-v1.schema.json', $package);
(new ReferenceStudioDevelopmentSeeder($package, $snapshot))->seed($pdo, $owner);
foreach (RestoreTestFixture::document($package)['revisions'] as $revision) {
    if ($revision['recordId'] === '10000000000000000000000000000002') {
        $revision['body']['slug']['en'] = 'light-workshop';
    }
    $pdo->prepare('UPDATE cms_record_revisions SET body_json = :body WHERE id = :id')->execute(['body' => json_encode($revision['body'], JSON_THROW_ON_ERROR), 'id' => $revision['id']]);
}
$state = sys_get_temp_dir() . '/editor-exact-' . bin2hex($random->bytes(8));
mkdir($state, 0700);
mkdir($state . '/media', 0700);
$configuration = new RuntimeConfiguration('https://cms.example.test', $dsn, 'portable', 'integration-secret', str_repeat('s', 32), $state . '/errors.log', $state . '/media', 'cron_only', profileRoot: $package->root, publicUrlContext: \Utsuri\PortableCms\Conventional\Compiler\PublicUrlContext::from(['formatVersion' => 1, 'baseUrl' => 'https://studio.example.test']));

try {
    ReferenceMediaSeeder::seed($pdo, $package, $snapshot, $owner, $state . '/media');
    $mediaRuns = ApplicationFactory::mediaRunner($configuration)->runBounded('editor-exact-media', 10, 60);
    assertSameValue(6, count(array_filter($mediaRuns, static fn($run): bool => $run->completed)), 'real private image preparation');
    $app = ApplicationFactory::identity($configuration);
    $cookies = [];
    $request = static function (string $method, string $path, array $form = [], array $headers = [], bool $authenticated = true) use ($app, &$cookies): Response {
        $query = [];
        parse_str((string) parse_url($path, PHP_URL_QUERY), $query);
        $response = $app->handle(new Request($method, (string) parse_url($path, PHP_URL_PATH), [...['origin' => 'https://cms.example.test', 'sec-fetch-site' => 'same-origin'], ...$headers], $form, $authenticated ? $cookies : [], '192.0.2.1', query: $query));
        if ($authenticated) {
            foreach ($response->headers()['set-cookie'] ?? [] as $header) {
                [$name, $value] = explode('=', explode(';', $header, 2)[0], 2);
                $cookies[$name] = $value;
            }
        }
        return $response;
    };
    $location = static fn(Response $response): string => $response->headers()['location'][0] ?? throw new RuntimeException('Expected local redirect');
    $recordId = '10000000000000000000000000000001';
    $recordPath = '/records/project/' . $recordId;
    $openPath = $recordPath . '/exact-preview';
    $stable = '/previews/newest/records/project/' . $recordId . '?locale=en';
    assertSameValue(303, $request('GET', $stable)->status, 'anonymous stable address requires sign-in');
    $request('GET', '/login');
    assertSameValue(303, $request('POST', '/login', ['_csrf' => $cookies[IdentityService::CSRF_COOKIE], 'login' => 'exact@portable.test', 'password' => 'portable-exact-fixture'])->status, 'native owner sign-in');
    $form = ['_csrf' => $cookies[IdentityService::CSRF_COOKIE], '_exact_locale' => 'en'];
    assertSameValue(403, $request('POST', $openPath, [...$form, '_csrf' => 'wrong'])->status, 'exact opening enforces CSRF');
    assertSameValue(403, $request('POST', $openPath, $form, ['origin' => 'https://hostile.example'])->status, 'exact opening enforces same origin');
    assertSameValue(400, $request('POST', $openPath, [...$form, '_exact_locale' => '../private'])->status, 'invalid locale cannot become a file path');
    assertSameValue(422, $request('POST', '/records/article/new/exact-preview', $form)->status, 'native new record must be saved first');
    assertSameValue(202, $request('POST', '/previews', ['_csrf' => $form['_csrf']])->status, 'ordinary published-only capture is available first');
    assertSameValue(true, ApplicationFactory::previewRunner($configuration)->runOne('editor-exact-published')->completed, 'published-only website builds through the real worker');
    $draftEditor = $request('GET', '/records/project/10000000000000000000000000000002');
    assertTrueValue(str_contains($draftEditor->body, 'data-exact-preview') && !str_contains($draftEditor->body, 'Open exact preview (home)'), 'a published-only build cannot mislabel a draft with its own page as a home fallback');
    $recordFacts = "SELECT r.current_revision_id, r.current_revision_number, v.site_sequence FROM cms_records r JOIN cms_record_revisions v ON v.id = r.current_revision_id WHERE r.id = '$recordId'";
    $beforeRecord = $pdo->query($recordFacts)->fetch(PDO::FETCH_ASSOC);
    $opened = $request('POST', $openPath, [...$form, 'title__en' => 'Never save this submitted value']);
    assertSameValue(303, $opened->status, 'native exact action starts a build without saving the record form: ' . strip_tags($opened->body));
    $waiting = $location($opened);
    assertTrueValue(str_starts_with($waiting, $stable . '&waiting='), 'pending tab uses a signed-in newest address');
    $firstId = substr($waiting, strrpos($waiting, '=') + 1);
    assertSameValue($beforeRecord, $pdo->query($recordFacts)->fetch(PDO::FETCH_ASSOC), 'opening changes neither content nor its concurrency identity');
    assertSameValue(202, $request('GET', $waiting)->status, 'new tab has a script-free waiting page');
    assertTrueValue(str_contains($request('GET', $waiting)->body, 'role="status"'), 'waiting state is accessible');
    assertSameValue(true, ApplicationFactory::previewRunner($configuration)->runOne('editor-exact-first')->completed, 'ordinary worker builds the complete draft-inclusive site');
    assertSameValue($stable, $location($request('GET', $waiting)), 'ready state clears the waiting build from the permanent address');
    $backup = new \Utsuri\PortableCms\Conventional\Upgrade\DatabaseBackup($pdo);
    $durableBefore = $backup->durableFingerprint();
    $firstView = $request('GET', $stable);
    assertSameValue($durableBefore, $backup->durableFingerprint(), 'opening private files does not change the durable upgrade fingerprint');
    assertSameValue(200, $firstView->status, 'newest viewer becomes ready');
    assertTrueValue(str_contains($firstView->body, '/assets/preview-vimeo.js'), 'the exact shell owns the sibling player module');
    assertTrueValue(str_contains($firstView->headers()['content-security-policy'][0], "frame-src 'self' https://player.vimeo.com"), 'only the CMS page and fixed Vimeo provider are admitted by the exact shell');
    assertSameValue(['no-store'], $firstView->headers()['cache-control'], 'newest resolution is never cached');
    $frame = static function (Response $response): string {
        preg_match('/<iframe[^>]+src="([^"]+)"/', $response->body, $match);
        return isset($match[1]) ? html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8') : throw new RuntimeException('Missing exact frame');
    };
    $firstFiles = $frame($firstView);
    assertTrueValue(str_contains($firstFiles, '/editor-preview-files/' . $firstId . '/') && str_ends_with($firstFiles, '/en/projects/archive-of-mist/'), 'frozen route identities choose the record page');
    $firstBytes = $request('GET', $firstFiles, authenticated: false)->body;
    assertTrueValue(str_contains($firstBytes, 'Archive of Mist'), 'cookie-free opaque frame receives only authorized frozen bytes');
    assertTrueValue(!str_contains($firstBytes, 'Never save this submitted value'), 'native exact action ignored unsaved values');
    $parts = explode('/', $firstFiles);
    $token = $parts[3];
    $stored = $pdo->prepare('SELECT token_hash FROM cms_editor_preview_files WHERE token_hash = :hash');
    $stored->execute(['hash' => hash('sha256', $token)]);
    assertSameValue(hash('sha256', $token), $stored->fetchColumn(), 'only file token hashes are stored');
    assertSameValue(0, (int) $pdo->query('SELECT COUNT(*) FROM cms_preview_follow_links')->fetchColumn(), 'opening from the editor creates no share link');
    $sessionCount = (int) $pdo->query('SELECT COUNT(*) FROM cms_sessions')->fetchColumn();
    $file = $request('GET', $firstFiles, authenticated: false);
    assertSameValue(['*'], $file->headers()['access-control-allow-origin'], 'opaque frame file transport admits its own frozen modules');
    assertTrueValue(str_contains($file->headers()['content-security-policy'][0], 'sandbox allow-scripts') && !str_contains($file->headers()['content-security-policy'][0], 'allow-same-origin'), 'profile scripts cannot reach administration origin');
    assertSameValue($sessionCount, (int) $pdo->query('SELECT COUNT(*) FROM cms_sessions')->fetchColumn(), 'file delivery opens no session');
    assertSameValue(404, $request('GET', str_replace($token, str_repeat('0', 64), $firstFiles), authenticated: false)->status, 'forged file token refused');
    assertSameValue(404, $request('GET', '/editor-preview-files/' . $firstId . '/' . $token . '/../private/cms.json', authenticated: false)->status, 'file traversal refused');
    $count = (int) $pdo->query('SELECT COUNT(*) FROM cms_previews')->fetchColumn();
    assertSameValue($stable, $location($request('POST', $openPath, $form)), 'unchanged kept website reuses newest ready draft preview immediately');
    assertSameValue($count, (int) $pdo->query('SELECT COUNT(*) FROM cms_previews')->fetchColumn(), 'reuse creates no duplicate job');

    // Equal timestamps and another record's edit must invalidate reuse, even with unchanged target values.
    $repository = new MariaDbPreviewRepository($pdo, $package->manifest->designRegistries, $package->checksum, publicUrlContext: $configuration->publicUrlContext);
    $first = $repository->find($firstId) ?? throw new RuntimeException('First preview missing');
    $fixedClock = new class ($first->createdAt) implements Clock {
        public function __construct(private DateTimeImmutable $value) {}
        public function now(): DateTimeImmutable
        {
            return $this->value;
        }
    };
    $content = new ContentService(new MariaDbRecordRepository($pdo), $package->manifest, $fixedClock, $random);
    $term = $content->find('discipline', '80000000000000000000000000000001');
    $body = $term->body;
    $body['label']['en'] = 'A change elsewhere on the kept website';
    assertTrueValue($content->rewrite('discipline', $term->id, $term->revisionNumber, $body, $owner) instanceof RecordSnapshot, 'other record changed through ordinary service');
    assertSameValue(null, $repository->reusableDraftPreview($package->manifest, $clock->now()), 'equal clocks do not conceal a changed site');
    assertSameValue(true, $repository->capturedRecord($firstId, $recordId, $package->manifest), 'record hints use the current profile package');
    assertSameValue(false, (new MariaDbPreviewRepository($pdo, profileChecksum: str_repeat('e', 64)))->capturedRecord($firstId, $recordId, $package->manifest), 'a different renderer package cannot supply a stale page hint at the same profile version');
    $nextWaiting = $location($request('POST', $openPath, $form));
    $nextId = substr($nextWaiting, strrpos($nextWaiting, '=') + 1);
    assertTrueValue($nextId !== $firstId, 'out-of-date preview starts a fresh capture');
    assertSameValue($nextWaiting, $location($request('POST', $openPath, $form)), 'repeated opening reuses a matching queued capture');
    assertSameValue(202, $request('GET', $nextWaiting)->status, 'an older ready preview does not replace the requested waiting state');
    assertSameValue(true, ApplicationFactory::previewRunner($configuration)->runOne('editor-exact-next')->completed, 'new draft-inclusive capture builds');
    $nextView = $request('GET', $stable);
    assertTrueValue(str_contains($frame($nextView), '/' . $nextId . '/'), 'reloading the same address follows the newer ready build');
    assertSameValue($firstBytes, $request('GET', $firstFiles, authenticated: false)->body, 'already issued files stay on the original immutable build');
    assertSameValue(404, $request('GET', str_replace('/' . $firstId . '/', '/' . $nextId . '/', $firstFiles), authenticated: false)->status, 'file lease cannot cross preview IDs');

    $homeId = '70000000000000000000000000000001';
    $homeEditor = $request('GET', '/records/site-settings/' . $homeId);
    assertTrueValue(str_contains($homeEditor->body, 'Open exact preview (home)'), 'record without a route says it will open home');
    $homeOpen = $request('POST', '/records/site-settings/' . $homeId . '/exact-preview', $form);
    $homeView = $request('GET', $location($homeOpen));
    assertTrueValue(str_ends_with($frame($homeView), '/en/') && str_contains($homeView->body, 'This record has no page'), 'route-less record opens the real localized home');

    $pdo->exec("UPDATE cms_users SET role = 'editor'");
    assertSameValue(200, $request('GET', $stable)->status, 'editor may resolve a record preview');
    assertSameValue(303, $request('POST', $openPath, $form)->status, 'editor may open a whole-site draft preview');
    assertSameValue(403, $request('POST', '/records/site-settings/' . $homeId . '/exact-preview', $form)->status, 'editor cannot bypass settings access through exact opening');
    assertSameValue(404, $request('GET', '/previews/newest/records/site-settings/' . $homeId . '?locale=en')->status, 'editor cannot bypass settings access through newest resolution');
    $pdo->exec("UPDATE cms_users SET role = 'owner'");
    $logoutFiles = $frame($request('GET', $stable));
    assertSameValue(303, $request('POST', '/logout', ['_csrf' => $cookies[IdentityService::CSRF_COOKIE]])->status, 'ordinary sign-out');
    assertSameValue(404, $request('GET', $logoutFiles, authenticated: false)->status, 'signing out immediately closes issued files');
    $request('GET', '/login');
    $request('POST', '/login', ['_csrf' => $cookies[IdentityService::CSRF_COOKIE], 'login' => 'exact@portable.test', 'password' => 'portable-exact-fixture']);
    $form['_csrf'] = $cookies[IdentityService::CSRF_COOKIE];

    $latestTerm = $content->find('discipline', $term->id);
    $body['label']['en'] = 'Change before a failed build';
    $content->rewrite('discipline', $latestTerm->id, $latestTerm->revisionNumber, $body, $owner);
    $failedWaiting = $location($request('POST', $openPath, $form));
    $failedId = substr($failedWaiting, strrpos($failedWaiting, '=') + 1);
    $json = $pdo->prepare('SELECT snapshot_json FROM cms_previews WHERE id = :id');
    $json->execute(['id' => $failedId]);
    $poisoned = json_decode($json->fetchColumn(), true, flags: JSON_THROW_ON_ERROR);
    foreach ($poisoned['records'] as &$captured) {
        if ($captured['typeId'] === 'home') {
            $captured['relationships']['featuredContent'] = [str_repeat('f', 32)];
        }
    }
    unset($captured);
    $pdo->prepare('UPDATE cms_previews SET snapshot_json = :json WHERE id = :id')->execute(['json' => json_encode($poisoned, JSON_THROW_ON_ERROR), 'id' => $failedId]);
    $run = ApplicationFactory::previewRunner($configuration)->runOne('editor-exact-failed');
    assertSameValue(false, $run->completed, 'real compiler rejects corrupt captured input');
    $failedPage = $request('GET', $failedWaiting);
    assertSameValue(422, $failedPage->status, 'waiting tab explains a failed build');
    $failedStatus = $repository->find($failedId);
    assertTrueValue($failedStatus instanceof PreviewStatus && $failedStatus->diagnostics !== null, 'worker saved ordinary preview diagnostics');
    $diagnosticHtml = \Utsuri\PortableCms\Conventional\View\PreviewDiagnosticList::render($failedStatus->diagnostics, \Utsuri\PortableCms\Conventional\I18n\Translator::english(), $package->manifest);
    assertTrueValue(str_contains($failedPage->body, $diagnosticHtml) && str_contains($request('GET', '/previews/' . $failedId)->body, $diagnosticHtml), 'waiting and preview detail show the same diagnostics');
    for ($i = 0; $i < 22; $i++) {
        $request('GET', $stable);
    }
    assertSameValue(20, (int) $pdo->query('SELECT COUNT(*) FROM cms_editor_preview_files f JOIN cms_sessions s ON s.id = f.session_id WHERE s.revoked_at IS NULL')->fetchColumn(), 'signed-in file leases are bounded per active session');
    $lastView = $request('GET', $stable);
    $lastFiles = $frame($lastView);
    assertSameValue(200, $request('GET', $lastFiles, authenticated: false)->status, 'most recent lease always survives its bound');
    $pdo->exec('UPDATE cms_editor_preview_files SET expires_at = UTC_TIMESTAMP(6) - INTERVAL 1 SECOND');
    assertSameValue(404, $request('GET', $lastFiles, authenticated: false)->status, 'expired file lease is closed');
    $activeFiles = $frame($request('GET', $stable));
    $pdo->exec('UPDATE cms_users SET security_version = security_version + 1');
    assertSameValue(404, $request('GET', $activeFiles, authenticated: false)->status, 'session security version changes immediately close files');
    assertSameValue(303, $request('GET', $stable)->status, 'revoked editorial authority cannot resolve the newest viewer');
    fwrite(STDOUT, "PASS editor exact preview: native read-only opening, draft reuse/freshness, equal clocks, queued reuse, newest reload, record/home routes, real build failure diagnostics and isolated session-bound files\n");
} finally {
    RestoreTestFixture::remove($state);
}
