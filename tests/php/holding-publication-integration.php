<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\ApplicationFactory;
use Utsuri\PortableCms\Conventional\Compiler\FrozenSnapshot;
use Utsuri\PortableCms\Conventional\Compiler\PublicUrlContext;
use Utsuri\PortableCms\Conventional\Content\ProfilePackage;
use Utsuri\PortableCms\Conventional\Database\MigrationRunner;
use Utsuri\PortableCms\Conventional\Http\Request;
use Utsuri\PortableCms\Conventional\Identity\AdministratorBootstrap;
use Utsuri\PortableCms\Conventional\Identity\IdentityService;
use Utsuri\PortableCms\Conventional\Identity\SessionContext;
use Utsuri\PortableCms\Conventional\Identity\SystemClock;
use Utsuri\PortableCms\Conventional\Identity\SystemRandomSource;
use Utsuri\PortableCms\Conventional\Preview\FilesystemPreviewStore;
use Utsuri\PortableCms\Conventional\Preview\MariaDbPreviewRepository;
use Utsuri\PortableCms\Conventional\Preview\PreviewNotDeletable;
use Utsuri\PortableCms\Conventional\Preview\PreviewService;
use Utsuri\PortableCms\Conventional\Preview\ProfilePreviewCompiler;
use Utsuri\PortableCms\Conventional\Publication\FilesystemPublicationTarget;
use Utsuri\PortableCms\Conventional\Publication\MariaDbPublicationRepository;
use Utsuri\PortableCms\Conventional\Publication\PublicationFailure;
use Utsuri\PortableCms\Conventional\Publication\PublicationService;
use Utsuri\PortableCms\Conventional\RuntimeConfiguration;

require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/bootstrap.php';
require_once dirname(__DIR__, 2) . '/tools/development/reference-studio-seeder.php';
require_once dirname(__DIR__, 2) . '/tools/development/reference-media-seeder.php';
require_once __DIR__ . '/assertions.php';
require_once __DIR__ . '/publication-apache-server.php';

function settleHoldingPublication(PublicationService $publication): void
{
    for ($i = 0; $i < 100 && $publication->head()->pendingId !== null; ++$i) {
        $publication->runBatch(8);
    }
    assertSameValue(null, $publication->head()->pendingId, 'bounded publication must settle');
}

$root = dirname(__DIR__, 2);
$dsn = getenv('CMS_TEST_HOLDING_DSN');
if (!is_string($dsn) || $dsn === '') {
    throw new RuntimeException('An isolated holding database is required');
}
$pdo = new PDO($dsn, 'portable', 'integration-secret', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
$migrations = glob($root . '/engine/migrations/*.sql');
sort($migrations, SORT_STRING);
(new MigrationRunner($pdo))->apply($migrations);
$clock = new SystemClock();
$random = new SystemRandomSource();
$actorId = (new AdministratorBootstrap($pdo, $clock, $random))->create('admin@portable.test', 'Holding administrator', 'portable-cms-local');
$actor = new SessionContext('session', 'authenticated', $actorId, 'admin@portable.test', 'Holding administrator', 'administrator', 'token', 'csrf', $clock->now()->modify('+1 hour'), false);
$package = ProfilePackage::load($root . '/profiles/reference-studio');
$context = PublicUrlContext::from(['formatVersion' => 1, 'baseUrl' => 'https://studio.example']);
$state = sys_get_temp_dir() . '/portable-holding-integration-' . bin2hex(random_bytes(8));
foreach ([$state, $state . '/media', $state . '/public'] as $path) {
    mkdir($path, 0700);
}
$configuration = new RuntimeConfiguration('https://cms.example.test', $dsn, 'portable', 'integration-secret', str_repeat('s', 32), null, $state . '/media', 'cron_only', profileRoot: $package->root, publicUrlContext: $context, publicationRoot: $state . '/public');
$server = new PublicationApacheServer($state, $state . '/public');

try {
    $compiler = new ProfilePreviewCompiler($package, $configuration->mediaRoot, $root . '/schemas');
    $previews = new PreviewService(new MariaDbPreviewRepository($pdo, $package->manifest->designRegistries, $package->checksum, $compiler->preflight(...), $context), $package->manifest, $clock, $random, new FilesystemPreviewStore($configuration->mediaRoot . '/previews'));
    $publication = ApplicationFactory::publication($configuration, $package->manifest, $previews, $pdo);
    $holding = $publication->holding;
    assertSameValue('coming_soon', $holding->settings->load()->mode(), 'empty installation defaults to Coming soon');
    $build = $holding->create($actorId, 0);
    assertSameValue(true, ApplicationFactory::previewRunner($configuration)->runOne('empty-holding')->completed, 'holding builds with zero authored records');
    assertSameValue([], $previews->list(), 'exact preview list never contains internal holding builds');
    assertSameValue(null, $previews->artifact($build->id, 'index.html'), 'exact preview API cannot read a holding build');
    $first = $publication->approve($build->id, null, $actor);
    assertSameValue(null, $publication->head()->activeId, 'approval alone is not activation');
    settleHoldingPublication($publication);
    $server->verify($publication->artifact($build->id));
    assertSameValue(null, $publication->head()->history, 'holding creates no fabricated live slug authority');
    assertSameValue('coming_soon', $holding->settings->load()->mode(), 'first activation does not switch the legacy default to Live');
    assertSameValue(404, $server->get('/sitemap.xml')['status'], 'holding exposes no sitemap');
    assertSameValue(404, $server->get('/en/projects/')['status'], 'holding exposes no website routes');
    assertTrueValue(str_contains($server->get('/')['body'], 'noindex,follow'), 'served holding page remains noindex');
    $font = array_values(array_filter($package->manifest->assets, static fn($asset): bool => $asset->path === 'assets/studio-sans-latin.woff2'))[0];
    $fontPath = '/assets/holding/' . $font->checksum . '.woff2';
    $servedFont = $server->get($fontPath);
    assertSameValue(200, $servedFont['status'], 'the approved holding release serves its packaged font');
    assertSameValue($font->checksum, hash('sha256', $servedFont['body']), 'publication retains exactly the reviewed font bytes');
    assertTrueValue(str_contains($servedFont['headers']['content-security-policy'] ?? '', "font-src 'self'"), 'the public font needs no CSP relaxation');
    assertSameValue(404, $server->get('/assets/search.js')['status'], 'unused packaged scripts never enter the holding release');

    // The holding page speaks the public languages only, and a change of languages needs a new build.
    $siteLanguages = ApplicationFactory::siteLanguages($package->manifest, $pdo);
    $offStates = array_fill_keys($package->manifest->locales, 'public');
    $offStates[$package->manifest->locales[2]] = 'off';
    $siteLanguages->save($offStates, $package->manifest->defaultLocale, 0, $actor, true);
    try {
        $publication->approve($build->id, $first->id, $actor);
        throw new RuntimeException('A holding build approved after its languages changed');
    } catch (\Utsuri\PortableCms\Conventional\Publication\PublicationFailure $error) {
        assertSameValue('new_holding_build_required', $error->codeName, 'a holding build follows the languages it was built with');
    }
    $shown = $holding->create($actorId, 0);
    assertSameValue(true, ApplicationFactory::previewRunner($configuration)->runOne('languages-holding')->completed, 'holding builds with a language off');
    $active = $publication->approve($shown->id, $first->id, $actor);
    settleHoldingPublication($publication);
    $holdingPage = $server->get('/')['body'];
    $offMessage = \Utsuri\PortableCms\Conventional\Publication\WebsitePublication::defaults($package->manifest->id, $package->manifest->locales)->document()['translations'][2]['message'];
    assertTrueValue(!str_contains($holdingPage, $offMessage) && str_contains($holdingPage, 'lang="' . $package->manifest->defaultLocale . '"'), 'the language that is off says nothing on the holding page');
    $siteLanguages->save(array_fill_keys($package->manifest->locales, 'public'), $package->manifest->defaultLocale, 1, $actor, false);

    $app = ApplicationFactory::identity($configuration);
    $cookies = [];
    $request = static function (string $method, string $path, array $form = [], array $extraHeaders = []) use ($app, &$cookies): \Utsuri\PortableCms\Conventional\Http\Response {
        $response = $app->handle(new Request($method, $path, ['origin' => 'https://cms.example.test', ...$extraHeaders], $form, $cookies));
        foreach ($response->headers()['set-cookie'] ?? [] as $cookie) {
            [$name, $value] = explode('=', explode(';', $cookie, 2)[0], 2);
            $cookies[$name] = rawurldecode($value);
        }
        return $response;
    };
    $privatePath = '/releases/holding/' . $build->id . '/artifact/';
    assertSameValue(303, $request('GET', $privatePath)->status, 'holding inspection requires authentication');
    $request('GET', '/login');
    assertSameValue(303, $request('POST', '/login', ['_csrf' => $cookies[IdentityService::CSRF_COOKIE], 'login' => 'admin@portable.test', 'password' => 'portable-cms-local'])->status, 'native login');
    $page = $request('GET', $privatePath);
    assertSameValue(200, $page->status, 'authenticated editor inspects exact frozen holding bytes');
    assertSameValue(['no-store'], $page->headers()['cache-control'], 'holding inspection remains private');
    $privateFont = $request('GET', $privatePath . ltrim($fontPath, '/'));
    assertSameValue(200, $privateFont->status, 'private holding inspection serves the same verified font');
    assertSameValue($servedFont['body'], $privateFont->body, 'preview and public font bytes are identical');
    assertSameValue(['no-store'], $privateFont->headers()['cache-control'], 'private font delivery remains private');
    assertTrueValue(isset($page->headers()['x-robots-tag']), 'private inspection is nonindexable independently of page markup');
    assertSameValue(404, $request('GET', '/previews/' . $build->id . '/artifact/')->status, 'holding cannot masquerade as a website preview');
    $settingsForm = ['_csrf' => $cookies[IdentityService::CSRF_COOKIE], '_revision' => '0', '_complete' => '1', 'mode' => 'live', 'websiteName' => 'Test studio', 'iconMediaId' => ''];
    foreach ($holding->settings->load()->document()['translations'] as $entry) {
        $settingsForm['message_' . $entry['locale']] = $entry['message'];
        $settingsForm['contact_' . $entry['locale']] = $entry['contact'];
    }
    assertSameValue(403, $request('POST', '/settings/publication', [...$settingsForm, '_csrf' => 'wrong'])->status, 'settings reject invalid CSRF before saving');
    assertSameValue(403, $request('POST', '/settings/publication', $settingsForm, ['origin' => 'https://attacker.example'])->status, 'settings reject cross-origin writes');
    assertSameValue(303, $request('POST', '/settings/publication', $settingsForm)->status, 'native settings use POST redirect GET');
    $conflict = $request('POST', '/settings/publication', [...$settingsForm, 'websiteName' => 'Preserved editor input']);
    assertSameValue(409, $conflict->status, 'stale native settings re-render rather than overwrite');
    assertTrueValue(str_contains($conflict->body, 'Preserved editor input') && str_contains($conflict->body, 'Current saved mode'), 'conflict shows current values and preserves editor input');
    assertSameValue($active->id, $publication->head()->activeId, 'saving Live intent leaves the public holding release untouched');
    $languagesForm = ['_csrf' => $cookies[IdentityService::CSRF_COOKIE], '_revision' => '2', 'primary' => $package->manifest->defaultLocale];
    foreach ($package->manifest->locales as $index => $locale) {
        $languagesForm['state__' . $locale] = $locale === $package->manifest->defaultLocale ? 'public' : ($index === 1 ? 'preparation' : 'off');
    }
    assertSameValue(403, $request('POST', '/settings/languages', [...$languagesForm, '_csrf' => 'wrong'])->status, 'languages reject invalid CSRF before saving');
    assertSameValue(403, $request('POST', '/settings/languages', $languagesForm, ['origin' => 'https://attacker.example'])->status, 'languages reject cross-origin writes');
    $unconfirmed = $request('POST', '/settings/languages', $languagesForm);
    assertTrueValue($unconfirmed->status === 422 && str_contains($unconfirmed->body, 'needs confirmation'), 'turning a language off asks for confirmation');
    assertSameValue(303, $request('POST', '/settings/languages', [...$languagesForm, 'confirmed' => '1'])->status, 'confirmed languages use POST redirect GET');
    assertSameValue(409, $request('POST', '/settings/languages', [...$languagesForm, 'confirmed' => '1'])->status, 'a stale language choice re-renders rather than overwrites');
    assertTrueValue(str_contains($request('GET', '/settings/languages')->body, 'Saved revision 3.'), 'the saved choice is shown with its revision');
    $languagesAudit = json_decode((string) $pdo->query("SELECT metadata_json FROM cms_audit_events WHERE event_type = 'site_languages_saved' ORDER BY occurred_at DESC, id DESC LIMIT 1")->fetchColumn(), true, 8, JSON_THROW_ON_ERROR);
    assertSameValue([3, 'off'], [$languagesAudit['revision'], $languagesAudit['states'][$package->manifest->locales[2]]], 'the language choice is audited with its states');
    $allPublic = ['_csrf' => $cookies[IdentityService::CSRF_COOKIE], '_revision' => '3', 'primary' => $package->manifest->defaultLocale];
    foreach ($package->manifest->locales as $locale) {
        $allPublic['state__' . $locale] = 'public';
    }
    assertSameValue(303, $request('POST', '/settings/languages', $allPublic)->status, 'turning languages on again needs no confirmation');
    $wordingForm = ['_csrf' => $cookies[IdentityService::CSRF_COOKIE], '_revision' => '0', \Utsuri\PortableCms\Conventional\Content\SiteWording::field('page.projects', 'en') => 'Selected work'];
    assertSameValue(403, $request('POST', '/settings/wording', [...$wordingForm, '_csrf' => 'wrong'])->status, 'site wording rejects invalid CSRF before saving');
    assertSameValue(403, $request('POST', '/settings/wording', $wordingForm, ['origin' => 'https://attacker.example'])->status, 'site wording rejects cross-origin writes');
    assertSameValue(303, $request('POST', '/settings/wording', $wordingForm)->status, 'site wording uses POST redirect GET');
    assertSameValue(409, $request('POST', '/settings/wording', $wordingForm)->status, 'a stale site wording re-renders rather than overwrites');
    $wordingPage = $request('GET', '/settings/wording')->body;
    assertTrueValue(str_contains($wordingPage, 'value="Selected work"') && str_contains($wordingPage, 'Saved revision 1.'), 'the saved wording is shown with its revision');
    assertSameValue(1, (int) $pdo->query('SELECT COUNT(*) FROM cms_site_wording_revisions WHERE revision = 1')->fetchColumn(), 'the saved wording is kept as a revision');

    ReferenceStudioDevelopmentSeeder::load($package->root, $root . '/schemas/portable-cms-snapshot-v1.schema.json')->seed($pdo, $actorId);
    $snapshot = FrozenSnapshot::load($package->root . '/fixtures/snapshot.json', $root . '/schemas/portable-cms-snapshot-v1.schema.json', $package);
    ReferenceMediaSeeder::seed($pdo, $package, $snapshot, $actorId, $configuration->mediaRoot);
    ApplicationFactory::mediaRunner($configuration)->runBounded('holding-live-media', 10, 60);
    $livePreview = $previews->create($actorId);
    assertSameValue(true, ApplicationFactory::previewRunner($configuration)->runOne('holding-live-preview')->completed, 'ordinary website previews remain real website builds');
    assertTrueValue(str_contains($previews->artifact($livePreview->id, 'en/index.html')->contents, 'Test studio'), 'the exact website preview uses the engine name rather than a second profile name');
    $live = $publication->approve($livePreview->id, $active->id, $actor);
    settleHoldingPublication($publication);
    $server->verify($publication->artifact($livePreview->id));
    assertSameValue(200, $server->get('/en/projects/')['status'], 'explicit launch activates live routes');
    assertTrueValue(str_contains($server->get('/en/projects/')['body'], 'Test studio'), 'publication retains the same frozen website name');
    assertTrueValue(str_contains($server->get('/en/projects/')['body'], '>Selected work</h1>'), 'the launched website shows the site wording editors wrote');
    assertSameValue(404, $server->get($fontPath)['status'], 'launch removes the obsolete holding font path');
    $history = $publication->head()->history->document();

    $coming = [...$holding->settings->load()->document(), 'mode' => 'coming_soon', 'revision' => 2];
    try {
        $holding->settings->save($coming, 1, $actor, false);
        throw new RuntimeException('Unconfirmed return to holding accepted');
    } catch (PublicationFailure $error) {
        assertSameValue('holding_confirmation_required', $error->codeName, 'live replacement requires consequence acknowledgement');
    }
    $holding->settings->save($coming, 1, $actor, true);
    $queued = $request('POST', '/releases', ['_csrf' => $cookies[IdentityService::CSRF_COOKIE], 'action' => 'build_holding', 'id' => str_repeat('0', 32), '_revision' => '2', 'previous' => $live->id]);
    assertSameValue(303, $queued->status, 'native release page queues a separate internal holding build');
    $backBuild = $holding->builds->list()[0];
    assertSameValue(true, ApplicationFactory::previewRunner($configuration)->runOne('return-holding')->completed, 'return holding build completes');
    assertTrueValue(str_contains($holding->builds->artifact($backBuild->id, 'index.html')->contents, 'Test studio'), 'coming soon uses the same canonical website name');
    $approved = $request('POST', '/releases', ['_csrf' => $cookies[IdentityService::CSRF_COOKIE], 'action' => 'approve', 'id' => $backBuild->id, 'previous' => $live->id, 'confirmed' => '1']);
    assertSameValue(303, $approved->status, 'native approval queues the frozen holding artifact');
    $failingTarget = new FilesystemPublicationTarget($state . '/public', $state . '/media/publication', hash_hmac('sha256', 'publication-target-v1', $configuration->throttleSecret, true), static function (string $path): void {
        if (str_ends_with($path, '.html')) {
            throw new RuntimeException('Controlled holding interruption');
        }
    });
    $interrupted = new PublicationService(new MariaDbPublicationRepository($pdo), $previews, $failingTarget, $context, $package->manifest->id, file_get_contents($root . '/schemas/portable-cms-artifact-v1.schema.json'), $clock, $random, $holding);
    try {
        settleHoldingPublication($interrupted);
        throw new RuntimeException('Failure injection did not reach HTML');
    } catch (RuntimeException $error) {
        assertSameValue('Controlled holding interruption', $error->getMessage(), 'interruption is exercised');
    }
    settleHoldingPublication($publication);
    assertSameValue($live->id, $publication->head()->activeId, 'failed transition preserves previous activation');
    $server->verify($publication->artifact($livePreview->id));
    $back = $publication->approve($backBuild->id, $live->id, $actor);
    settleHoldingPublication($publication);
    $server->verify($publication->artifact($backBuild->id));
    assertSameValue(404, $server->get('/en/projects/')['status'], 'completed return removes obsolete website routes');
    assertSameValue(404, $server->get('/sitemap.xml')['status'], 'completed return removes the old live sitemap');
    assertSameValue($servedFont['body'], $server->get($fontPath)['body'], 'return to holding restores only its named font asset');
    assertSameValue($history, $publication->head()->history->document(), 'return to holding preserves last live slug history for relaunch');
    try {
        $holding->builds->delete($backBuild->id, $actorId);
        throw new RuntimeException('Retained release source deleted');
    } catch (PreviewNotDeletable) {
    }
    $changed = [...$coming, 'revision' => 3, 'websiteName' => 'New holding settings'];
    $holding->settings->save($changed, 2, $actor, false);
    try {
        $publication->approve($backBuild->id, $back->id, $actor);
        throw new RuntimeException('Stale holding settings approved');
    } catch (PublicationFailure $error) {
        assertSameValue('new_holding_build_required', $error->codeName, 'new settings require a new frozen build');
    }
    $rollback = $publication->rollback($live->id, $back->id, $actor);
    settleHoldingPublication($publication);
    assertSameValue($rollback->id, $publication->head()->activeId, 'explicit rollback restores retained website bytes independent of current editorial intent');
    $server->verify($publication->artifact($livePreview->id));
    assertSameValue(2, (int) $pdo->query("SELECT COUNT(*) FROM cms_audit_events WHERE event_type = 'website_publication_saved' AND JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.mode')) = 'coming_soon'")->fetchColumn(), 'attributed mode changes are audited');

    // Administrators add languages within the profile's envelope and remove one only while nothing is written in it.
    $languagesStore = new \Utsuri\PortableCms\Conventional\Content\MariaDbSiteLanguages($pdo);
    $languageForm = static fn(string $action, string $locale): array => ['_csrf' => $cookies[IdentityService::CSRF_COOKIE], '_revision' => (string) $languagesStore->load($package->manifest)->revision, '_action' => $action, 'locale' => $locale];
    assertSameValue(403, $request('POST', '/settings/languages', [...$languageForm('add', 'it'), '_csrf' => 'wrong'])->status, 'adding a language rejects invalid CSRF');
    assertSameValue(403, $request('POST', '/settings/languages', $languageForm('add', 'it'), ['origin' => 'https://attacker.example'])->status, 'adding a language rejects cross-origin writes');
    assertSameValue(303, $request('POST', '/settings/languages', $languageForm('add', 'it'))->status, 'an administrator adds Italian');
    assertSameValue(303, $request('POST', '/settings/languages', $languageForm('add', 'pt'))->status, 'and Portuguese');
    $languagesPage = $request('GET', '/settings/languages')->body;
    assertTrueValue(str_contains($languagesPage, '<legend lang="it">Italiano</legend>') && str_contains($languagesPage, 'name="state__pt" value="preparation" checked'), 'added languages are listed in preparation');
    $installed = $languagesStore->installed($package->manifest);
    assertSameValue([['ca', 'es', 'en', 'it', 'pt'], ['it', 'pt']], [$installed->locales, $installed->addedLocales], 'the installed profile carries the added languages');
    assertSameValue([false, false], [$languagesStore->hasText($installed, 'it'), $languagesStore->hasText($installed, 'pt')], 'nothing is written in a new language');
    $pdo->exec("UPDATE cms_record_revisions SET body_json = JSON_SET(body_json, '$.title.it', 'Archivio della nebbia') WHERE id = (SELECT current_revision_id FROM cms_records WHERE type_id = 'project' ORDER BY id LIMIT 1)");
    assertSameValue(true, $languagesStore->hasText($installed, 'it'), 'a record revision written in Italian is text');
    $kept = $request('POST', '/settings/languages', $languageForm('remove', 'it'));
    assertTrueValue($kept->status === 422 && str_contains($kept->body, 'Italiano already has text'), 'a language with text cannot be removed');
    assertSameValue(303, $request('POST', '/settings/languages', $languageForm('remove', 'pt'))->status, 'a language without text is removed');
    assertSameValue([['site_language_added', 'it'], ['site_language_added', 'pt'], ['site_language_removed', 'pt']], $pdo->query("SELECT event_type, JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.locale')) FROM cms_audit_events WHERE event_type IN ('site_language_added', 'site_language_removed') ORDER BY occurred_at, id")->fetchAll(PDO::FETCH_NUM), 'adding and removing languages is audited');
    assertSameValue(['ca', 'es', 'en', 'it'], $languagesStore->installed($package->manifest)->locales, 'the removed language is gone and the other stays');

    // The owner deletes the current English text once English is off; earlier revisions keep it.
    $installed = $languagesStore->installed($package->manifest);
    $owner = new SessionContext('session', 'authenticated', $actorId, 'admin@portable.test', 'Holding administrator', 'owner', 'token', 'csrf', $clock->now()->modify('+1 hour'), false);
    $choice = $languagesStore->load($package->manifest);
    ApplicationFactory::siteLanguages($installed, $pdo)->save([...$choice->states, 'en' => 'off'], 'ca', $choice->revision, $owner, true);
    $fresh = ApplicationFactory::identity($configuration);
    $send = static function (string $method, string $path, array $form = [], array $extraHeaders = [], array $query = []) use ($fresh, &$cookies): \Utsuri\PortableCms\Conventional\Http\Response {
        return $fresh->handle(new Request($method, $path, ['origin' => 'https://cms.example.test', ...$extraHeaders], $form, $cookies, query: $query));
    };
    $englishText = static function () use ($pdo, $installed): array {
        $current = $history = 0;
        foreach ($pdo->query('SELECT r.type_id, v.body_json, v.id = r.current_revision_id AS current FROM cms_record_revisions v JOIN cms_records r ON r.id = v.record_id')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (\Utsuri\PortableCms\Conventional\Content\LanguageText::record($installed->type((string) $row['type_id'])->fields, json_decode((string) $row['body_json'], true, 64, JSON_THROW_ON_ERROR), 'en')) {
                $row['current'] ? ++$current : ++$history;
            }
        }
        return [$current, $history];
    };
    [$englishRecords, $englishHistory] = $englishText();
    $confirmation = $send('GET', '/settings/languages/text', query: ['locale' => 'en']);
    assertTrueValue(preg_match('/name="records" value="(\d+)"/', $confirmation->body, $count) === 1 && (int) $count[1] === $englishRecords && $englishRecords > 0, 'the confirmation counts the records with English text');
    assertTrueValue(str_contains($confirmation->body, 'Permanently delete the English text of ' . $englishRecords . ' records'), 'the confirmation names the language and the records');
    $deleteForm = ['_csrf' => $cookies[IdentityService::CSRF_COOKIE], '_action' => 'start', 'locale' => 'en', 'records' => (string) $englishRecords, 'confirmed' => '1'];
    assertSameValue(403, $send('POST', '/settings/languages/text', [...$deleteForm, '_csrf' => 'wrong'])->status, 'deleting text rejects invalid CSRF');
    assertSameValue(403, $send('POST', '/settings/languages/text', $deleteForm, ['origin' => 'https://attacker.example'])->status, 'deleting text rejects cross-origin writes');
    $step = $send('POST', '/settings/languages/text', $deleteForm);
    for ($batch = 0; $batch < 20 && !str_ends_with($step->headers()['location'][0] ?? '', '&done=1'); ++$batch) {
        assertSameValue(303, $step->status, 'each batch redirects to the progress page');
        $step = $send('POST', '/settings/languages/text', ['_csrf' => $cookies[IdentityService::CSRF_COOKIE], '_action' => 'continue']);
    }
    assertSameValue('/settings/languages/text?locale=en&done=1', $step->headers()['location'][0] ?? null, 'the deletion completes');
    assertSameValue([0, $englishHistory + $englishRecords], $englishText(), 'no current revision has English text, and earlier revisions keep theirs');
    $mediaText = $pdo->query('SELECT text_json FROM cms_media WHERE text_json IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN);
    assertTrueValue($mediaText !== [] && array_filter($mediaText, static fn(string $json): bool => \Utsuri\PortableCms\Conventional\Content\LanguageText::media(json_decode($json, true, 16, JSON_THROW_ON_ERROR), 'en')) === [], 'media descriptions lose their English text');
    assertSameValue([], array_values(array_filter(ApplicationFactory::siteWording($installed, $pdo)->load()->values, static fn(array $texts): bool => isset($texts['en']))), 'the site wording loses its English text');
    assertSameValue([['language_text_deletion_started', 'en'], ['language_text_deleted', 'en']], $pdo->query("SELECT event_type, JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.locale')) FROM cms_audit_events WHERE event_type LIKE 'language_text_%' ORDER BY occurred_at, id")->fetchAll(PDO::FETCH_NUM), 'the deletion is audited');
    $afterDeletion = (new \Utsuri\PortableCms\Conventional\Export\MariaDbExportRepository($pdo, $package->manifest->designRegistries, $context))->create(bin2hex($random->bytes(16)), bin2hex($random->bytes(16)), $actorId, $package->manifest->id, $package->manifest->version, $installed->locales, $clock->now());
    $currentRevisions = array_column($afterDeletion->document['records'], 'currentRevisionId');
    foreach ($afterDeletion->document['revisions'] as $revision) {
        if (in_array($revision['id'], $currentRevisions, true)) {
            $typeId = array_values(array_filter($afterDeletion->document['records'], static fn(array $record): bool => $record['id'] === $revision['recordId']))[0]['typeId'];
            assertTrueValue(!\Utsuri\PortableCms\Conventional\Content\LanguageText::record($installed->type($typeId)->fields, $revision['body'], 'en'), 'an export made afterwards has no current English values');
        }
    }
    new \Utsuri\PortableCms\Conventional\Restore\RestorePlan($afterDeletion, hash('sha256', $afterDeletion->json()), $package, $clock, $random);
    fwrite(STDOUT, "PASS holding publication: empty defaults, native authenticated controls, private frozen inspection, launch, confirmed return, interruption recovery, obsolete routes, retained rollback, stale settings, added languages and deleted language text.\n");
} finally {
    $server->close();
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($state, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($state);
}
