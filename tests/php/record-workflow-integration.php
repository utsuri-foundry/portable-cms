<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\ApplicationFactory;
use Utsuri\PortableCms\Conventional\Content\ContentService;
use Utsuri\PortableCms\Conventional\Content\MariaDbRecordRepository;
use Utsuri\PortableCms\Conventional\Content\ProfilePackage;
use Utsuri\PortableCms\Conventional\Database\MigrationRunner;
use Utsuri\PortableCms\Conventional\Export\MariaDbExportRepository;
use Utsuri\PortableCms\Conventional\Http\Request;
use Utsuri\PortableCms\Conventional\Http\Response;
use Utsuri\PortableCms\Conventional\Identity\AdministratorBootstrap;
use Utsuri\PortableCms\Conventional\Identity\IdentityService;
use Utsuri\PortableCms\Conventional\Identity\SystemClock;
use Utsuri\PortableCms\Conventional\Identity\SystemRandomSource;
use Utsuri\PortableCms\Conventional\Preview\FilesystemPreviewStore;
use Utsuri\PortableCms\Conventional\Preview\MariaDbPreviewRepository;
use Utsuri\PortableCms\Conventional\Preview\PreviewNotReleasable;
use Utsuri\PortableCms\Conventional\Preview\PreviewService;
use Utsuri\PortableCms\Conventional\Preview\ProfilePreviewCompiler;
use Utsuri\PortableCms\Conventional\RuntimeConfiguration;

require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/bootstrap.php';
require_once __DIR__ . '/assertions.php';
require_once __DIR__ . '/restore-fixture.php';

/** Native successful controls only, including defaults and rich-text row transport.
 * @return array<string, string|list<string>>
 */
function workflowNativeForm(string $html): array
{
    $previous = libxml_use_internal_errors(true);
    try {
        $dom = new DOMDocument();
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        $xpath = new DOMXPath($dom);
        $values = [];
        foreach ($xpath->query('//form[@data-editorial-form]//*[self::input or self::textarea or self::select]') as $control) {
            if (!$control instanceof DOMElement) {
                throw new RuntimeException('Native form projection requires element controls');
            }
            $name = $control->getAttribute('name');
            if ($name === '' || $control->hasAttribute('disabled') || $control->hasAttribute('form') || ($control->getAttribute('type') === 'checkbox' && !$control->hasAttribute('checked'))) {
                continue;
            }
            $value = $control->tagName === 'textarea' ? $control->textContent : $control->getAttribute('value');
            if ($control->tagName === 'select') {
                foreach ($control->getElementsByTagName('option') as $index => $option) {
                    if ($index === 0 || $option->hasAttribute('selected')) {
                        $value = $option->getAttribute('value');
                    }
                }
            }
            if (str_ends_with($name, '[]')) {
                $values[substr($name, 0, -2)][] = $value;
            } else {
                $values[$name] = $value;
            }
        }
        return $values;
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }
}

$root = dirname(__DIR__, 2);
$dsn = getenv('CMS_TEST_WORKFLOW_DSN');
if (!is_string($dsn) || $dsn === '') {
    throw new RuntimeException('An isolated empty workflow database is required');
}
$pdo = new PDO($dsn, 'portable', 'integration-secret', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
$paths = glob($root . '/engine/migrations/*.sql');
sort($paths, SORT_STRING);
(new MigrationRunner($pdo))->apply($paths);
$clock = new SystemClock();
$random = new SystemRandomSource();
$actor = (new AdministratorBootstrap($pdo, $clock, $random))->create('admin@portable.test', 'Workflow administrator', 'portable-cms-local');
$state = sys_get_temp_dir() . '/portable-workflow-' . bin2hex(random_bytes(8));
mkdir($state, 0700);
mkdir($state . '/media', 0700);
$package = ProfilePackage::load($root . '/profiles/reference-studio');
$config = new RuntimeConfiguration('https://cms.example.test', $dsn, 'portable', 'integration-secret', str_repeat('s', 32), null, $state . '/media', 'cron_only', profileRoot: $package->root);
$app = ApplicationFactory::identity($config);
$cookies = [];
$request = static function (string $method, string $path, array $form = [], array $query = []) use ($app, &$cookies): Response {
    $response = $app->handle(new Request($method, $path, ['origin' => 'https://cms.example.test', 'sec-fetch-site' => 'same-origin'], $form, $cookies, '192.0.2.1', query: $query));
    foreach ($response->headers()['set-cookie'] ?? [] as $header) {
        [$name, $value] = explode('=', explode(';', $header, 2)[0], 2);
        $cookies[$name] = $value;
    }
    return $response;
};

try {
    assertSameValue(0, (int) $pdo->query('SELECT COUNT(*) FROM cms_records')->fetchColumn(), 'acceptance starts without any content seeder');
    $request('GET', '/login');
    assertSameValue(303, $request('POST', '/login', ['_csrf' => $cookies[IdentityService::CSRF_COOKIE], 'login' => 'admin@portable.test', 'password' => 'portable-cms-local'])->status, 'native sign in');
    foreach (['/', '/previews'] as $path) {
        $page = $request('GET', $path);
        assertTrueValue(str_contains($page->body, 'Before your first preview') && str_contains($page->body, 'href="/records/home/new"') && str_contains($page->body, 'href="/records/about/new"') && !str_contains($page->body, 'Site name'), 'empty installation guides required pages without a duplicate profile identity');
    }
    $failedAt = '';
    for ($attempt = 0; $attempt < 2; ++$attempt) {
        $failed = $request('POST', '/previews', ['_csrf' => $cookies[IdentityService::CSRF_COOKIE], 'include_drafts' => '1']);
        assertSameValue(422, $failed->status, 'empty saved website fails without creating a job');
        assertTrueValue(str_contains($failed->body, 'name="include_drafts" value="1"'), 'native retry keeps drafts selected');
        preg_match('/<time datetime="([^"]+)"/', $failed->body, $checked);
        assertTrueValue(isset($checked[1]) && $checked[1] !== $failedAt, 'each actual check has a fresh visible timestamp');
        $failedAt = $checked[1];
        assertTrueValue(!str_contains($failed->body, 'duplicate page addresses'), 'fallback does not invent a cause');
    }
    assertSameValue(0, (int) $pdo->query('SELECT COUNT(*) FROM cms_previews')->fetchColumn(), 'failed checks never create previews');
    assertSameValue(0, (int) $pdo->query("SELECT COUNT(*) FROM cms_jobs WHERE job_type = 'preview_build'")->fetchColumn(), 'failed checks never create jobs');
    assertSameValue(2, (int) $pdo->query("SELECT COUNT(*) FROM cms_audit_events WHERE event_type = 'preview_check_failed'")->fetchColumn(), 'attempts remain durable after enqueue rollback');
    $reload = ApplicationFactory::identity($config);
    foreach (['/', '/previews'] as $path) {
        $page = $reload->handle(new Request('GET', $path, cookies: $cookies));
        assertTrueValue(str_contains($page->body, 'The latest preview check needs attention') && str_contains($page->body, $failedAt), 'fresh application sees the latest failure, mode and timestamp');
    }
    $create = static function (string $type, array $fields, bool $rich = false) use ($request, $package): string {
        $path = '/records/' . $type . '/new';
        $page = $request('GET', $path);
        assertSameValue(200, $page->status, 'ordinary creation form');
        if ($rich) {
            foreach ($package->manifest->locales as $locale) {
                $page = $request('POST', $path, [...workflowNativeForm($page->body), ...$fields, '_rich_action' => 'body__' . $locale . ':add:-1:-1']);
                assertSameValue(200, $page->status, 'native add paragraph');
                $fields['body__' . $locale . '__0__0__text'] = 'Original content ' . $locale;
            }
        }
        $created = $request('POST', $path, [...workflowNativeForm($page->body), ...$fields]);
        assertSameValue(303, $created->status, 'ordinary form creates a saved draft');
        return $created->headers()['location'][0];
    };
    $content = new ContentService(new MariaDbRecordRepository($pdo), $package->manifest, $clock, $random);
    $status = static function (string $path, string $state) use ($request, $content, &$cookies): void {
        $page = $request('GET', $path);
        assertTrueValue(str_contains($page->body, 'id="record-status-form"'), 'native status form exists');
        [, , $type, $id] = explode('/', $path);
        $record = $content->find($type, $id);
        $response = $request('POST', $path . '/status', ['_csrf' => $cookies[IdentityService::CSRF_COOKIE], '_revision' => (string) $record->revisionNumber, '_edit_sequence' => (string) $record->editSequence, '_workflow_state' => $state, '_confirmed' => '1']);
        assertSameValue(303, $response->status, 'native status transition');
        assertSameValue($record->body, $content->find($type, $id)->body, 'workflow never edits canonical content');
        assertSameValue($state, $content->find($type, $id)->workflowState, 'workflow persisted');
    };
    $home = $create('home', array_combine(array_map(static fn(string $l): string => 'introduction__' . $l, $package->manifest->locales), array_fill(0, 3, 'Fictional empty studio')));
    $about = $create('about', [], true);
    $settings = $create('site-settings', ['organizationName' => 'Native workflow studio']);
    foreach ([$home, $about, $settings] as $path) {
        $status($path, 'published');
    }
    $fields = [];
    foreach ($package->manifest->locales as $locale) {
        $fields['title__' . $locale] = 'An article from an empty CMS';
        $fields['slug__' . $locale] = 'empty-cms-article';
        $fields['excerpt__' . $locale] = 'Original summary';
    }
    $article = $create('article', $fields, true);
    $id = basename($article);
    $original = $content->find('article', $id);
    $compiler = new ProfilePreviewCompiler($package, $config->mediaRoot, $root . '/schemas');
    $previews = new PreviewService(new MariaDbPreviewRepository($pdo, $package->manifest->designRegistries, $package->checksum, $compiler->preflight(...)), $package->manifest, $clock, $random, new FilesystemPreviewStore($config->mediaRoot . '/previews'));
    $build = static function (bool $drafts, bool $expected) use ($request, &$cookies, $config, $previews): string {
        $queued = $request('POST', '/previews', ['_csrf' => $cookies[IdentityService::CSRF_COOKIE], ...($drafts ? ['include_drafts' => '1'] : [])]);
        assertSameValue(202, $queued->status, 'native exact preview');
        $id = basename(substr($queued->headers()['refresh'][0], strlen('2; url=')));
        assertSameValue($drafts, $previews->find($id)->includeDrafts, 'mode durably frozen');
        assertSameValue(true, ApplicationFactory::previewRunner($config)->runOne('workflow-test')->completed, 'profile compiler handles the empty-authored installation');
        $artifact = $previews->artifact($id, 'en/articles/index.html');
        assertTrueValue($artifact !== null, 'verified index exists');
        assertSameValue($expected, str_contains($artifact->contents, 'An article from an empty CMS'), 'state controls compilation inclusion');
        if ($drafts) {
            try {
                $previews->releaseCandidate($id);
                throw new RuntimeException('draft preview accepted for publication');
            } catch (PreviewNotReleasable) {
            }
        } else {
            assertSameValue($id, $previews->releaseCandidate($id)->id, 'verified published-only candidate is eligible');
        }
        return $id;
    };
    $build(false, false);
    foreach (['/', '/previews'] as $path) {
        assertTrueValue(!str_contains($request('GET', $path)->body, 'The latest preview check needs attention'), 'successful preflight clears the failure report');
        assertTrueValue(!str_contains($request('GET', $path)->body, 'Before your first preview'), 'published startup records clear first-preview guidance');
    }
    $draftPreview = $build(true, true);
    // Even a forged ready-status projection must not erase the artifact's draft marker.
    $mode = $pdo->prepare('UPDATE cms_previews SET include_drafts = :mode WHERE id = :id');
    $mode->execute(['mode' => 0, 'id' => $draftPreview]);
    try {
        $previews->releaseCandidate($draftPreview);
        throw new RuntimeException('artifact draft marker bypassed through database status');
    } catch (PreviewNotReleasable) {
    }
    $mode->execute(['mode' => 1, 'id' => $draftPreview]);
    $status($article, 'published');
    $published = $content->find('article', $id);
    $stale = $request('POST', $article . '/status', ['_csrf' => $cookies[IdentityService::CSRF_COOKIE], '_revision' => '1', '_edit_sequence' => '0', '_workflow_state' => 'archived', '_confirmed' => '1']);
    assertSameValue(409, $stale->status, 'stale mutation cannot replace a later workflow decision');
    // A duplicate audit identity forces failure after the status/revision writes.
    $auditId = $pdo->query('SELECT id FROM cms_audit_events LIMIT 1')->fetchColumn();
    try {
        (new MariaDbRecordRepository($pdo))->transition($published, 'archived', bin2hex($random->bytes(16)), $package->manifest->id, $package->manifest->version, $actor, $auditId, $clock->now());
        throw new RuntimeException('failed audit unexpectedly committed');
    } catch (PDOException) {
    }
    assertSameValue('published', $content->find('article', $id)->workflowState, 'audit failure rolls back state');
    assertSameValue(2, $content->find('article', $id)->revisionNumber, 'audit failure rolls back revision');
    $build(false, true);
    $status($article, 'draft');
    $build(false, false);
    $status($article, 'archived');
    $build(false, false);
    $build(true, false);
    assertTrueValue(str_contains($previews->artifact($draftPreview, 'en/articles/empty-cms-article/index.html')->contents, 'Original content en'), 'older draft-inclusive snapshot remains immutable');
    assertSameValue($original->body, $content->find('article', $id)->body, 'all workflow revisions retain original canonical body');
    assertSameValue(4, (int) $pdo->query("SELECT COUNT(*) FROM cms_record_revisions WHERE record_id = '$id'")->fetchColumn(), 'immutable content history survives all statuses');
    $events = $pdo->query("SELECT actor_user_id, metadata_json FROM cms_audit_events WHERE event_type = 'record_status_changed' ORDER BY occurred_at, id")->fetchAll(PDO::FETCH_ASSOC);
    assertSameValue(6, count($events), 'only successful state changes append audit events');
    foreach ($events as $event) {
        assertSameValue($actor, $event['actor_user_id'], 'every status change attributed');
        $metadata = json_decode($event['metadata_json'], true, flags: JSON_THROW_ON_ERROR);
        assertTrueValue(isset($metadata['previousState'], $metadata['state'], $metadata['revisionNumber']), 'safe transition metadata');
    }
    $list = $request('GET', '/records/article', query: ['status' => 'archived']);
    assertTrueValue(str_contains($list->body, 'An article from an empty CMS'), 'status list filter finds archived records');
    assertTrueValue(!str_contains($request('GET', '/records/article', query: ['status' => 'published'])->body, 'An article from an empty CMS'), 'published filter excludes archived records');
    $export = (new MariaDbExportRepository($pdo))->create(bin2hex($random->bytes(16)), bin2hex($random->bytes(16)), $actor, $package->manifest->id, $package->manifest->version, $package->manifest->locales, $clock->now());
    $record = array_values(array_filter($export->document['records'], static fn(array $r): bool => $r['id'] === $id))[0];
    assertSameValue('archived', $record['workflowState'], 'normalized export preserves selected state');
    fwrite(STDOUT, "PASS empty MariaDB native workflow: authoring, exact draft/published previews, state/history, audit rollback, conflicts, release eligibility and export.\n");
} finally {
    RestoreTestFixture::remove($state);
}
