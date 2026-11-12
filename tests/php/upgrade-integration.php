<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\ApplicationFactory;
use Utsuri\PortableCms\Conventional\Compiler\FrozenSnapshot;
use Utsuri\PortableCms\Conventional\Content\ProfilePackage;
use Utsuri\PortableCms\Conventional\Database\DatabaseConnection;
use Utsuri\PortableCms\Conventional\Database\MigrationRunner;
use Utsuri\PortableCms\Conventional\Database\TablePrefix;
use Utsuri\PortableCms\Conventional\Identity\AdministratorBootstrap;
use Utsuri\PortableCms\Conventional\Identity\IdentityService;
use Utsuri\PortableCms\Conventional\Identity\MariaDbIdentityRepository;
use Utsuri\PortableCms\Conventional\Identity\SystemClock;
use Utsuri\PortableCms\Conventional\Identity\SystemRandomSource;
use Utsuri\PortableCms\Conventional\RuntimeConfiguration;
use Utsuri\PortableCms\Conventional\Upgrade\DatabaseBackup;
use Utsuri\PortableCms\Conventional\Upgrade\UpgradeBundle;
use Utsuri\PortableCms\Conventional\Upgrade\UpgradeChecks;
use Utsuri\PortableCms\Conventional\Upgrade\UpgradeFailure;
use Utsuri\PortableCms\Conventional\Upgrade\UpgradeFiles;
use Utsuri\PortableCms\Conventional\Upgrade\UpgradeService;

require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/bootstrap.php';
require_once dirname(__DIR__, 2) . '/tools/development/reference-studio-seeder.php';
require_once dirname(__DIR__, 2) . '/tools/development/reference-media-seeder.php';
require_once __DIR__ . '/assertions.php';
require_once __DIR__ . '/restore-fixture.php';
require_once __DIR__ . '/publication-apache-server.php';

/**
 * @param list<string> $arguments
 * @return array{code: int, out: string, err: string}
 */
function upgradeCommand(array $arguments): array
{
    $process = proc_open([PHP_BINARY, ...$arguments], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Upgrade test command failed to start');
    }
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['code' => proc_close($process), 'out' => (string) $out, 'err' => (string) $err];
}

function upgradeTestReset(PDO $pdo): void
{
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    // Every table set, including candidates left by earlier cases.
    foreach ($pdo->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')->fetchAll(PDO::FETCH_COLUMN) as $table) {
        $pdo->exec('DROP TABLE `' . $table . '`');
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
}

function upgradeTestManifest(string $bundle): UpgradeBundle
{
    $manifest = UpgradeFiles::json($bundle . '/manifest.json');
    $files = UpgradeFiles::inventory($bundle);
    unset($files['manifest.json']);
    $manifest['files'] = $files;
    UpgradeFiles::writeJson($bundle . '/manifest.json', $manifest);
    return UpgradeBundle::load($bundle);
}

/** @param \Closure(): mixed $operation */
function upgradeRefused(string $expected, Closure $operation): void
{
    try {
        $operation();
    } catch (UpgradeFailure $error) {
        assertSameValue($expected, $error->codeName, 'refusal must be explicit and stable');
        return;
    }
    throw new RuntimeException('Upgrade unexpectedly allowed: ' . $expected);
}

/**
 * @param list<string> $headers
 * @return array{status: int, body: string}
 */
function upgradeWebRequest(string $url, string $method = 'GET', array $headers = [], string $body = ''): array
{
    $context = stream_context_create(['http' => ['method' => $method, 'header' => implode("\r\n", ['Content-Type: application/json', ...$headers]), 'content' => $body, 'timeout' => 90, 'ignore_errors' => true, 'follow_location' => 0]]);
    $responseHeaders = [];
    $bytes = \Utsuri\PortableCms\Conventional\HostIo::attempt(static function () use ($url, $context, &$responseHeaders) {
        $http_response_header = [];
        $bytes = file_get_contents($url, false, $context);
        $responseHeaders = $http_response_header;
        return $bytes;
    })->value;
    $status = isset($responseHeaders[0]) && preg_match('/\s(\d{3})\s/', $responseHeaders[0], $matches) === 1 ? (int) $matches[1] : 0;
    return ['status' => $status, 'body' => is_string($bytes) ? $bytes : ''];
}

/** Real installed dispatcher proof; authenticate without loading a second profile package in this process. */
function upgradeInstallationDetails(string $root, string $origin): void
{
    $active = UpgradeFiles::json($root . '/private/upgrade/active.json');
    $configuration = RuntimeConfiguration::load($active['config']);
    $identity = new IdentityService(new MariaDbIdentityRepository($configuration->connect()), new SystemClock(), new SystemRandomSource(), $configuration->throttleSecret);
    $login = $identity->login($identity->openSession(null, null), 'upgrade@example.test', 'test-upgrade-password', '127.0.0.1');
    assertTrueValue($login->authenticated, 'installed administrator signs in');
    $pairs = [IdentityService::SESSION_COOKIE . '=' . $login->session->sessionToken, IdentityService::CSRF_COOKIE . '=' . $login->session->csrfToken];
    $details = upgradeWebRequest($origin . '/settings/installation', headers: ['Cookie: ' . implode('; ', $pairs)]);
    assertSameValue(200, $details['status'], 'active dispatcher serves private installation details');
    assertTrueValue(str_contains($details['body'], '<dd>' . $active['releaseId'] . '</dd>'), 'details identify the selected CMS release, including rollback');
}

$repository = dirname(__DIR__, 2);
$dsn = getenv('CMS_TEST_UPGRADE_DSN');
$standbyDsn = getenv('CMS_TEST_UPGRADE_STANDBY_DSN');
if (!is_string($dsn) || !is_string($standbyDsn) || $dsn === '' || $standbyDsn === '') {
    throw new RuntimeException('Two isolated upgrade databases are required');
}
$pdo = new PDO($dsn, 'portable', 'integration-secret', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
$standby = new PDO($standbyDsn, 'portable', 'integration-secret', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
$state = sys_get_temp_dir() . '/portable-upgrade-' . bin2hex(random_bytes(8));
mkdir($state, 0700);
$clock = new SystemClock();
$random = new SystemRandomSource();
$now = $clock->now();
$baseBundle = $state . '/base-bundle';
$build = upgradeCommand([$repository . '/tools/build-conventional-cms-bundle.php', '--output=' . $baseBundle, '--private-root=' . $state . '/base/private', '--profile-root=' . $repository . '/profiles/reference-studio']);
assertSameValue(0, $build['code'], 'fixture bundle builds: ' . $build['err']);
$paths = glob($repository . '/engine/migrations/*.sql');
sort($paths, SORT_STRING);
(new MigrationRunner($pdo))->apply($paths);
$actor = (new AdministratorBootstrap($pdo, $clock, $random))->create('upgrade@example.test', 'Upgrade Operator', 'test-upgrade-password');
$package = ProfilePackage::load($baseBundle . '/private/profile');
ReferenceStudioDevelopmentSeeder::load($repository . '/profiles/reference-studio', $repository . '/schemas/portable-cms-snapshot-v1.schema.json')->seed($pdo, $actor);
// The rendering fixture deliberately contains one incomplete draft; this installation starts with valid saved drafts.
$pdo->exec("UPDATE cms_record_revisions SET body_json = JSON_SET(body_json, '$.title.en', 'Light Workshop') WHERE record_id = '10000000000000000000000000000002'");
mkdir($state . '/base-media', 0700);
mkdir($state . '/base-public', 0700);
$snapshot = FrozenSnapshot::load($repository . '/profiles/reference-studio/fixtures/snapshot.json', $repository . '/schemas/portable-cms-snapshot-v1.schema.json', $package);
ReferenceMediaSeeder::seed($pdo, ProfilePackage::load($repository . '/profiles/reference-studio'), $snapshot, $actor, $state . '/base-media');

/** @return array<string, mixed> */
function upgradeTestConfiguration(string $dsn, string $media, string $profile, string $public, string $mode = 'table_set', ?int $maximumBytes = null): array
{
    $database = ['dsn' => $dsn, 'username' => 'portable', 'password' => 'integration-secret', 'upgradeMode' => $mode];
    if ($maximumBytes !== null) {
        $database['maximumBytes'] = $maximumBytes;
    }
    return ['origin' => 'https://cms.example.test', 'database' => $database,
        'storage' => ['mediaRoot' => $media], 'throttleSecretBase64' => base64_encode(str_repeat('u', 32)), 'mediaWakeStrategy' => 'cron_only',
        'profileRoot' => $profile, 'publicUrlContext' => ['formatVersion' => 1, 'baseUrl' => 'https://studio.example'], 'publicationRoot' => $public];
}

UpgradeFiles::writeJson($state . '/base.json', upgradeTestConfiguration($dsn, $state . '/base-media', $package->root, $state . '/base-public'));
$configuration = RuntimeConfiguration::load($state . '/base.json');
ApplicationFactory::mediaRunner($configuration)->runBounded('upgrade-fixture', 10, 60);
// Build/approve through ordinary application boundaries, never fabricate an active artifact.
$compiler = new \Utsuri\PortableCms\Conventional\Preview\ProfilePreviewCompiler($package, $configuration->mediaRoot, $repository . '/schemas');
$previewService = new \Utsuri\PortableCms\Conventional\Preview\PreviewService(
    new \Utsuri\PortableCms\Conventional\Preview\MariaDbPreviewRepository($pdo, $package->manifest->designRegistries, $package->checksum, $compiler->preflight(...), $configuration->publicUrlContext),
    $package->manifest,
    $clock,
    $random,
    new \Utsuri\PortableCms\Conventional\Preview\FilesystemPreviewStore($configuration->mediaRoot . '/previews'),
);
$publication = ApplicationFactory::publication($configuration, $package->manifest, $previewService, $pdo);
$session = new \Utsuri\PortableCms\Conventional\Identity\SessionContext('session', 'authenticated', $actor, 'upgrade@example.test', 'Upgrade Operator', 'administrator', 'token', 'csrf', $clock->now()->modify('+1 hour'), false);
// New installations start in coming soon mode; the baseline is an already live website.
$publication->holding->settings->save([...$publication->holding->settings->load()->document(), 'mode' => 'live', 'revision' => 1], 0, $session, false);
$preview = $previewService->create($actor, false);
assertTrueValue(ApplicationFactory::previewRunner($configuration)->runOne('upgrade-fixture')->completed, 'baseline preview compiles');
$publication->approve($preview->id, null, $session);
for ($batch = 0; $batch < 100 && $publication->head()->pendingId !== null; ++$batch) {
    $publication->runBatch(8);
}
assertSameValue(null, $publication->head()->pendingId, 'baseline publication settles');
UpgradeChecks::publicRelease($configuration);
$baselineArtifact = $publication->artifact($preview->id);
$baselineRelease = (new \Utsuri\PortableCms\Conventional\Publication\MariaDbPublicationRepository($pdo))->find($publication->head()->activeId ?? throw new RuntimeException('Missing baseline release')) ?? throw new RuntimeException('Missing baseline release');
(new DatabaseBackup($pdo))->capture($state . '/base-database');

/** @return array{service: UpgradeService, root: string, backup: string, bundle: UpgradeBundle, config: string, standbyConfig: string} */
$fixture = static function (string $name, string $mode = 'table_set', ?int $maximumBytes = null) use ($state, $baseBundle, $pdo, $standby, $dsn, $standbyDsn, $now, $repository, $baselineArtifact, $baselineRelease): array {
    upgradeTestReset($pdo);
    upgradeTestReset($standby);
    (new DatabaseBackup($pdo))->restore($state . '/base-database');
    $root = $state . '/' . $name;
    mkdir($root, 0700);
    mkdir($root . '/private', 0700);
    UpgradeFiles::copy($baseBundle . '/private/runtime', $root . '/private/runtime', UpgradeFiles::inventory($baseBundle . '/private/runtime'));
    UpgradeFiles::copy($baseBundle . '/private/profile', $root . '/private/profile', UpgradeFiles::inventory($baseBundle . '/private/profile'));
    UpgradeFiles::copy($baseBundle . '/public', $root . '/cms', UpgradeFiles::inventory($baseBundle . '/public'));
    UpgradeFiles::copy($state . '/base-media', $root . '/private/media', UpgradeFiles::inventory($state . '/base-media'));
    UpgradeFiles::copy($state . '/base-public', $root . '/public', UpgradeFiles::inventory($state . '/base-public'));
    UpgradeFiles::copy($baseBundle, $root . '/incoming', UpgradeFiles::inventory($baseBundle));
    $config = $root . '/private/cms.json';
    $standbyConfig = $root . '/private/standby.json';
    UpgradeFiles::writeJson($config, upgradeTestConfiguration($dsn, $root . '/private/media', $root . '/private/profile', $root . '/public', $mode, $maximumBytes));
    UpgradeFiles::writeJson($standbyConfig, upgradeTestConfiguration($standbyDsn, $root . '/private/media', $root . '/private/profile', $root . '/public'));
    // Each copied sandbox is a separate fictional installation. Bind its verified baseline to
    // its own roots; production workers deliberately refuse implicit target relocation.
    $target = new \Utsuri\PortableCms\Conventional\Publication\FilesystemPublicationTarget($root . '/public', $root . '/private/media/publication', hash_hmac('sha256', 'publication-target-v1', str_repeat('u', 32), true));
    $release = new \Utsuri\PortableCms\Conventional\Publication\Release($baselineRelease->id, $baselineRelease->previewId, $baselineRelease->previousId, $baselineRelease->status, $baselineRelease->artifactChecksum, $baselineRelease->actorId, null, $target->identity());
    $receipt = $target->receipt($release, $baselineArtifact, substr(hash('sha256', 'upgrade-fixture:' . $name), 0, 32));
    $target->authenticate($receipt, $release, $baselineArtifact);
    $pdo->prepare('UPDATE cms_releases SET target_sha256 = :target, receipt_json = :receipt WHERE id = :id')->execute(['target' => $target->identity(), 'receipt' => $receipt, 'id' => $release->id]);
    $service = new UpgradeService($config, $root . '/cms', $now, launcherSource: (string) file_get_contents($repository . '/tools/conventional-release-launcher.php'));
    return ['service' => $service, 'root' => $root, 'backup' => $root . '/backup', 'bundle' => UpgradeBundle::load($root . '/incoming'), 'config' => $config, 'standbyConfig' => $standbyConfig];
};

/** @return list<string> The table set names in the serving database. */
$tableSets = static fn(): array => $pdo->query("SELECT DISTINCT SUBSTRING_INDEX(TABLE_NAME, '_', 1) AS prefix FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY prefix")->fetchAll(PDO::FETCH_COLUMN);

try {
    $incompatible = $fixture('engine-minimum');
    $profilePath = $incompatible['bundle']->root . '/private/profile/profile.json';
    $profileDocument = json_decode((string) file_get_contents($profilePath), false, 64, JSON_THROW_ON_ERROR);
    $versionParts = explode('.', \Utsuri\PortableCms\Conventional\Content\ProfileManifest::ENGINE_VERSION);
    $versionParts[2] = (string) ((int) $versionParts[2] + 1);
    $requiredEngine = implode('.', $versionParts);
    $profileDocument->compatibility->engine->minimum = $requiredEngine;
    file_put_contents($profilePath, json_encode($profileDocument, JSON_THROW_ON_ERROR));
    $manifest = UpgradeFiles::json($incompatible['bundle']->root . '/manifest.json');
    $manifest['files'] = UpgradeFiles::inventory($incompatible['bundle']->root);
    unset($manifest['files']['manifest.json']);
    UpgradeFiles::writeJson($incompatible['bundle']->root . '/manifest.json', $manifest);
    $beforeRefusal = (new DatabaseBackup($pdo))->fingerprint();
    $rejected = upgradeCommand([$incompatible['bundle']->root . '/private/runtime/tools/conventional-upgrade.php', '--action=dry-run', '--bundle=' . $incompatible['bundle']->root, '--config=' . $incompatible['config'], '--cms-root=' . $incompatible['root'] . '/cms']);
    assertSameValue(1, $rejected['code'], 'the packaged operator refuses a newer profile minimum');
    $diagnostic = json_decode($rejected['err'], true, flags: JSON_THROW_ON_ERROR);
    assertSameValue(['profile_engine_incompatible', $requiredEngine, \Utsuri\PortableCms\Conventional\Content\ProfileManifest::ENGINE_VERSION], [$diagnostic['error'], $diagnostic['minimumEngineVersion'], $diagnostic['runningEngineVersion']], 'operator JSON names both required and running engines');
    assertSameValue($beforeRefusal, (new DatabaseBackup($pdo))->fingerprint(), 'incompatible profiles leave the installed database exact');
    fwrite(STDOUT, "PASS packaged upgrade minimum-engine refusal: named versions, safe operator JSON and unchanged serving data\n");

    $legacy = $fixture('legacy-engine-range');
    $oldProfilePath = $legacy['root'] . '/private/profile/profile.json';
    $oldProfile = json_decode((string) file_get_contents($oldProfilePath), false, 64, JSON_THROW_ON_ERROR);
    $oldProfile->version = '1.1.0';
    $oldProfile->compatibility->engine->minimum = '0.1.2';
    file_put_contents($oldProfilePath, json_encode($oldProfile, JSON_THROW_ON_ERROR));
    $oldEnginePath = $legacy['root'] . '/private/runtime/engine/src/cms/conventional/content/profile.php';
    file_put_contents($oldEnginePath, str_replace("ENGINE_VERSION = '" . \Utsuri\PortableCms\Conventional\Content\ProfileManifest::ENGINE_VERSION . "'", "ENGINE_VERSION = '0.1.2'", (string) file_get_contents($oldEnginePath)));
    UpgradeFiles::writeJson($legacy['root'] . '/private/runtime/engine-version.json', ['version' => '0.1.2']);
    $pdo->exec("UPDATE cms_record_revisions SET profile_version = '1.1.0'");
    $authoredSql = 'SELECT id, record_id, revision_number, body_json, created_by, created_at FROM cms_record_revisions ORDER BY id';
    $authored = $pdo->query($authoredSql)->fetchAll(PDO::FETCH_ASSOC);
    $rangeReport = $legacy['service']->dryRun($legacy['bundle']);
    assertSameValue(['compatibility', 'version'], $rangeReport['contentNeutralChanges'], 'correcting only the engine requirement is a content-neutral profile release');
    assertSameValue([false, false], [$rangeReport['blocked'], $rangeReport['declaredContentMigration']], 'the new version requirement needs no invented content mapping');
    assertSameValue(['0.1.2', \Utsuri\PortableCms\Conventional\Content\ProfileManifest::ENGINE_VERSION, null, null], [$rangeReport['engine']['from'], $rangeReport['engine']['to'], $rangeReport['engine']['fromSourceRevision'], $rangeReport['engine']['fromSourceDirty']], 'a legacy serving engine reports its own version and explicitly unknown source');
    $legacy['service']->backup($legacy['backup']);
    $legacyApplied = upgradeCommand([$legacy['bundle']->root . '/private/runtime/tools/conventional-upgrade.php', '--action=apply', '--bundle=' . $legacy['bundle']->root, '--config=' . $legacy['config'], '--cms-root=' . $legacy['root'] . '/cms', '--backup=' . $legacy['backup'], '--confirm-bundle=' . $legacy['bundle']->checksum]);
    assertSameValue(0, $legacyApplied['code'], 'the packaged legacy engine/range upgrade succeeds without a content mapping: ' . $legacyApplied['out'] . $legacyApplied['err']);
    assertSameValue('upgraded', json_decode($legacyApplied['out'], true, flags: JSON_THROW_ON_ERROR)['status'], 'the new engine and corrected profile range activate together');
    $active = UpgradeFiles::json($legacy['root'] . '/private/upgrade/active.json');
    $upgradedDatabase = RuntimeConfiguration::load($active['config'])->connect();
    assertSameValue($authored, $upgradedDatabase->query($authoredSql)->fetchAll(PDO::FETCH_ASSOC), 'authored values, identities, revision numbers and attribution remain exact');
    $legacyRollback = upgradeCommand([$legacy['bundle']->root . '/private/runtime/tools/conventional-upgrade.php', '--action=rollback', '--config=' . $legacy['config'], '--cms-root=' . $legacy['root'] . '/cms']);
    assertSameValue(0, $legacyRollback['code'], 'the packaged rollback retains isolated profile code: ' . $legacyRollback['out'] . $legacyRollback['err']);
    assertSameValue('0.1.2', UpgradeFiles::json($legacy['root'] . '/private/upgrade/active.json')['engineVersion'], 'rollback selects the original legacy engine identity');
    fwrite(STDOUT, "PASS legacy 0.1.2 to current engine: corrected profile range, unknown legacy source, no content mapping, exact authored history and rollback\n");

    $case = $fixture('noop');
    $passwordFile = $case['root'] . '/private/install-password';
    \Utsuri\PortableCms\Conventional\HostIo::createPrivateFile($passwordFile, 'integration-secret');
    foreach ([null, 'table_set', 'in_place', 'separate_database'] as $mode) {
        $generatedConfig = $case['root'] . '/private/generated-' . ($mode ?? 'default') . '.json';
        $created = upgradeCommand([
            $repository . '/tools/conventional-create-install-config.php',
            '--runtime-output=' . $generatedConfig, '--runtime-config-path=' . $generatedConfig,
            '--gate-output=' . $generatedConfig . '.gate', '--token-output=' . $generatedConfig . '.token',
            '--origin=https://cms.example.test', '--dsn=' . $dsn, '--username=portable',
            '--database-password-file=' . $passwordFile, '--error-log=' . $case['root'] . '/private/errors.log',
            ...($mode !== null ? ['--upgrade-mode=' . $mode, '--database-maximum-bytes=1073741824'] : []),
        ]);
        assertSameValue(0, $created['code'], 'installation policy generation succeeds: ' . $created['err']);
        // Add the installed profile and publication paths through ordinary installation setup.
        $document = UpgradeFiles::json($generatedConfig);
        $installed = UpgradeFiles::json($case['config']);
        foreach (['profileRoot', 'publicUrlContext', 'publicationRoot'] as $key) {
            $document[$key] = $installed[$key];
        }
        UpgradeFiles::writeJson($generatedConfig, $document);
        $beforePolicyProbe = (new DatabaseBackup($pdo))->fingerprint();
        $policyFiles = UpgradeFiles::inventory($case['root']);
        $probe = upgradeCommand([$case['bundle']->root . '/private/runtime/tools/conventional-upgrade.php', '--action=dry-run', '--bundle=' . $case['bundle']->root, '--config=' . $generatedConfig, '--cms-root=' . $case['root'] . '/cms']);
        assertSameValue(0, $probe['code'], 'packaged upgrade accepts generated installation policy: ' . $probe['err']);
        $policy = json_decode($probe['out'], true, flags: JSON_THROW_ON_ERROR)['database'];
        assertSameValue([$mode ?? 'table_set', $mode !== null ? 1_073_741_824 : null], [$policy['mode'], $policy['maximumBytes']], 'dry run reports the generated mode and quota');
        assertSameValue($beforePolicyProbe, (new DatabaseBackup($pdo))->fingerprint(), 'policy dry run changes no rows');
        assertSameValue($policyFiles, UpgradeFiles::inventory($case['root']), 'policy dry run changes no files');
    }
    fwrite(STDOUT, "PASS generated installation modes and quotas reach packaged upgrade dry run\n");
    $before = (new DatabaseBackup($pdo))->fingerprint();
    $files = UpgradeFiles::inventory($case['root']);
    $dry = upgradeCommand([$case['bundle']->root . '/private/runtime/tools/conventional-upgrade.php', '--action=dry-run', '--bundle=' . $case['bundle']->root, '--config=' . $case['config'], '--cms-root=' . $case['root'] . '/cms']);
    assertSameValue(0, $dry['code'], 'packaged dry run works: ' . $dry['err']);
    assertSameValue(true, json_decode($dry['out'], true, flags: JSON_THROW_ON_ERROR)['noOp'], 'same installed release is a no-op');
    assertSameValue($before, (new DatabaseBackup($pdo))->fingerprint(), 'dry run changes no rows');
    assertSameValue($files, UpgradeFiles::inventory($case['root']), 'dry run changes no files');
    upgradeRefused('fresh_verified_backup_required', fn() => $case['service']->apply($case['bundle'], $case['backup'], $case['bundle']->checksum, null));
    $case['service']->backup($case['backup']);
    $staleClock = new UpgradeService($case['config'], $case['root'] . '/cms', $now->modify('+2 hours'));
    upgradeRefused('fresh_verified_backup_required', fn() => $staleClock->apply($case['bundle'], $case['backup'], $case['bundle']->checksum, null));
    upgradeRefused('bundle_confirmation_required', fn() => $case['service']->apply($case['bundle'], $case['backup'], str_repeat('0', 64), null));
    assertSameValue('no_op', $case['service']->apply($case['bundle'], $case['backup'], $case['bundle']->checksum, null)['status'], 'no-op still requires backup');
    $sql = $case['backup'] . '/database/cms_users.jsonl';
    file_put_contents($sql, "tampered\n", FILE_APPEND);
    upgradeRefused('backup_verification_failed', fn() => $case['service']->apply($case['bundle'], $case['backup'], $case['bundle']->checksum, null));
    $case = $fixture('noop-in-place', 'in_place');
    $case['service']->backup($case['backup']);
    assertSameValue('no_op', $case['service']->apply($case['bundle'], $case['backup'], $case['bundle']->checksum, null)['status'], 'an in-place installation has no-op upgrades too');
    assertTrueValue(!is_file($case['root'] . '/private/upgrade/maintenance.json'), 'a no-op never closes the CMS');
    fwrite(STDOUT, "PASS upgrade dry run, packaged no-op in both modes and corrupted backup refusal\n");

    $contentRows = static function (PDO $database): array {
        $rows = [];
        foreach (['cms_records', 'cms_record_revisions', 'cms_record_relationship_index'] as $table) {
            $rows[$table] = $database->query('SELECT * FROM ' . $table . ' ORDER BY 1, 2')->fetchAll(PDO::FETCH_ASSOC);
        }
        return $rows;
    };
    foreach (['assets', 'version', 'renderers', 'labels', 'design', 'field-groups'] as $change) {
        $case = $fixture('neutral-' . $change, $change === 'assets' ? 'in_place' : 'table_set');
        $before = $contentRows($pdo);
        $profile = $case['bundle']->root . '/private/profile';
        // Preserve empty JSON objects in field settings when editing this schema-valid package.
        $document = json_decode((string) file_get_contents($profile . '/profile.json'), false, 32, JSON_THROW_ON_ERROR);
        if ($change === 'field-groups') {
            // The serving installation uses the equivalent inline v2 model; incoming keeps v3 references.
            file_put_contents($case['root'] . '/private/profile/profile.json', $case['bundle']->profile->manifest->definitionJson);
            assertSameValue(2, UpgradeFiles::json($case['root'] . '/private/profile/profile.json')['formatVersion'], 'serving package uses inline v2 fields');
        } elseif ($change === 'assets') {
            $index = array_search('style', array_column($document->assets, 'role'), true);
            assertTrueValue(is_int($index), 'fixture has a reviewed stylesheet');
            $asset = $profile . '/' . $document->assets[$index]->path;
            file_put_contents($asset, "\n/* reviewed presentation-only release */\n", FILE_APPEND);
            $document->assets[$index]->byteSize = filesize($asset);
            $document->assets[$index]->sha256 = hash_file('sha256', $asset);
        } elseif ($change === 'version') {
            $document->version = '1.1.2';
            // A declaration retained from a prior content release must not run on a neutral one.
            UpgradeFiles::writeJson($profile . '/upgrade.json', ['formatVersion' => 1, 'fromVersion' => '0.9.0', 'toVersion' => '1.0.0', 'entrypoint' => 'src/retired-upgrade.php']);
            file_put_contents($profile . '/src/retired-upgrade.php', '<?php return static function (): never { throw new RuntimeException("Retired mapping must not run"); };');
        } elseif ($change === 'renderers') {
            $renderer = $document->renderers[0]->entrypoint;
            $document->renderers[0]->entrypoint = 'renderers/reviewed-release.php';
            file_put_contents($profile . '/renderers/reviewed-release.php', (string) file_get_contents($profile . '/' . $renderer) . "\n// reviewed renderer release\n");
        } elseif ($change === 'labels') {
            $document->recordTypes[0]->label = 'Works';
            $document->recordTypes[0]->fields[0]->label = 'Work title';
            $document->navigation[0]->label = 'Selected works';
        } else {
            ++$document->designRegistries->typography[0]->size->max;
        }
        file_put_contents($profile . '/profile.json', json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
        $bundle = upgradeTestManifest($case['bundle']->root);
        $fingerprint = (new DatabaseBackup($pdo))->fingerprint();
        $files = UpgradeFiles::inventory($case['root']);
        $report = $case['service']->dryRun($bundle);
        assertSameValue([false, false, [], []], [$report['blocked'], $report['declaredContentMigration'], $report['contentNeedingMigration'], $report['contentAffectingChanges']], 'neutral release requires no declaration: ' . $change);
        if ($change === 'field-groups') {
            assertSameValue([], $report['manifestChanges'], 'adopting group references alone changes no expanded definition');
            assertSameValue(false, $report['noOp'], 'authored package integrity still distinguishes the releases');
        } else {
            assertTrueValue($report['contentNeutralChanges'] !== [], 'dry run names presentation changes: ' . $change);
        }
        assertSameValue($fingerprint, (new DatabaseBackup($pdo))->fingerprint(), 'neutral dry run changes no rows');
        assertSameValue($files, UpgradeFiles::inventory($case['root']), 'neutral dry run changes no files');
        $case['service']->backup($case['backup']);
        $applied = upgradeCommand([$bundle->root . '/private/runtime/tools/conventional-upgrade.php', '--action=apply', '--bundle=' . $bundle->root, '--config=' . $case['config'], '--cms-root=' . $case['root'] . '/cms', '--backup=' . $case['backup'], '--confirm-bundle=' . $bundle->checksum]);
        assertSameValue(0, $applied['code'], 'packaged neutral profile upgrades and compiles: ' . $change . ': ' . $applied['out'] . $applied['err']);
        $result = json_decode($applied['out'], true, flags: JSON_THROW_ON_ERROR);
        $candidate = $result['tableSet'] === null ? $pdo : new DatabaseConnection($dsn, 'portable', 'integration-secret', new TablePrefix($result['tableSet']), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
        $expected = $before;
        foreach ($expected['cms_record_revisions'] as &$revision) {
            $revision['profile_version'] = $bundle->profile->manifest->version;
        }
        unset($revision);
        assertSameValue($expected, $contentRows($candidate), 'neutral upgrade preserves every record, body, revision identity, timestamp, slug and relationship; only contract-version tags advance: ' . $change);
        if ($change === 'field-groups') {
            assertSameValue(false, $report['databaseChanged'], 'authoring syntax alone performs no database migration');
            assertSameValue(null, $result['tableSet'], 'the same content table set keeps serving');
            assertSameValue(0, (int) $candidate->query("SELECT COUNT(*) FROM cms_audit_events WHERE event_type IN ('operator_profile_upgrade', 'operator_engine_upgrade')")->fetchColumn(), 'no content migration or retag audit is fabricated');
            $active = UpgradeFiles::json($case['root'] . '/private/upgrade/active.json');
            $activeConfig = RuntimeConfiguration::load($active['config'], $case['root'] . '/cms');
            assertSameValue(3, UpgradeFiles::json($activeConfig->profileRoot . '/profile.json')['formatVersion'], 'activation serves the authored v3 package');
        } else {
            $metadata = json_decode((string) $candidate->query("SELECT metadata_json FROM cms_audit_events WHERE event_type = 'operator_profile_upgrade'")->fetchColumn(), true, flags: JSON_THROW_ON_ERROR);
            assertSameValue(0, $metadata['records'], 'operator audit records zero rewritten records');
            assertSameValue($report['contentNeutralChanges'], $metadata['contentNeutralChanges'], 'operator audit retains the classification');
        }
        $export = (new \Utsuri\PortableCms\Conventional\Export\MariaDbExportRepository($candidate, designDefaults: $bundle->profile->manifest->designRegistries))->create(
            bin2hex($random->bytes(16)),
            bin2hex($random->bytes(16)),
            $actor,
            $bundle->profile->manifest->id,
            $bundle->profile->manifest->version,
            $bundle->profile->manifest->locales,
            $now,
        );
        new \Utsuri\PortableCms\Conventional\Restore\RestorePlan($export, hash('sha256', $export->json()), $bundle->profile, $clock, $random);
        $case['service']->rollback();
        assertSameValue($before, $contentRows($pdo), 'neutral rollback preserves original content');
        if ($change === 'field-groups') {
            $active = UpgradeFiles::json($case['root'] . '/private/upgrade/active.json');
            $activeConfig = RuntimeConfiguration::load($active['config'], $case['root'] . '/cms');
            assertSameValue(2, UpgradeFiles::json($activeConfig->profileRoot . '/profile.json')['formatVersion'], 'rollback serves the original inline authoring contract');
        }
    }
    fwrite(STDOUT, "PASS declaration-free asset, version, renderer, label, design and inline-v2-to-group-v3 releases preserve authored content/history, ignore retired mappings, compile, audit, remain restorable and roll back\n");

    $failing = "-- cms:statement\nCREATE TABLE cms_upgrade_proof (id INT PRIMARY KEY) ENGINE=InnoDB;\n-- cms:statement\nSIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'fixture failure';\n";
    $proof = "-- cms:statement\nCREATE TABLE cms_upgrade_proof (id INT PRIMARY KEY) ENGINE=InnoDB;\n";
    $withMigration = static function (array $case, string $sql): UpgradeBundle {
        file_put_contents($case['bundle']->root . '/private/runtime/engine/migrations/9998-upgrade-proof.sql', $sql);
        return upgradeTestManifest($case['bundle']->root);
    };
    $backupFingerprint = static fn(array $case): string => UpgradeFiles::json($case['backup'] . '/database/database.json')['fingerprint'];
    $maintenance = static fn(array $case): bool => is_file($case['root'] . '/private/upgrade/maintenance.json');
    $journal = static fn(array $case): array => UpgradeFiles::json($case['root'] . '/private/upgrade/journal.json');
    $activeRelease = static fn(array $case): string => UpgradeFiles::json($case['root'] . '/private/upgrade/active.json')['releaseId'];

    foreach (['table_set', 'in_place', 'separate_database'] as $mode) {
        $case = $fixture('failure-' . $mode, $mode);
        $case['service']->backup($case['backup']);
        $bundle = $withMigration($case, $failing);
        if ($mode === 'separate_database') {
            upgradeRefused('standby_database_must_be_distinct_and_empty', fn() => $case['service']->apply($bundle, $case['backup'], $bundle->checksum, $case['config']));
        } else {
            upgradeRefused('standby_database_requires_separate_database_mode', fn() => $case['service']->apply($bundle, $case['backup'], $bundle->checksum, $case['standbyConfig']));
        }
        try {
            $case['service']->apply($bundle, $case['backup'], $bundle->checksum, $mode === 'separate_database' ? $case['standbyConfig'] : null);
            throw new RuntimeException('Migration failure was accepted in ' . $mode);
        } catch (PDOException) {
        }
        if ($mode === 'separate_database') {
            assertSameValue('failed', $standby->query("SELECT status FROM cms_migrations WHERE id = '9998-upgrade-proof'")->fetchColumn(), 'existing ledger retains staged failure');
        }
        assertSameValue($backupFingerprint($case), (new DatabaseBackup($pdo))->fingerprint(), 'failed migration leaves the serving tables exact in ' . $mode);
        assertSameValue(['cms'], $tableSets(), 'no candidate table set remains after a failed ' . $mode . ' upgrade');
        assertSameValue('failed', $journal($case)['status'], 'failed ' . $mode . ' upgrade is journaled');
        assertTrueValue(!$maintenance($case), 'the CMS reopens after a failed ' . $mode . ' upgrade');
        assertSameValue('initial', $activeRelease($case), 'failed candidate never activates');
        assertSameValue('rolled_back', $case['service']->rollback()['status'], 'one-step rollback aborts failed staging');
    }
    fwrite(STDOUT, "PASS migration failure in table set, in-place and separate database modes leaves the serving tables exact\n");

    $script = $state . '/interrupt.php';
    file_put_contents($script, <<<'PHP'
<?php
require $argv[1] . '/private/runtime/engine/src/cms/conventional/bootstrap.php';
$service = new Utsuri\PortableCms\Conventional\Upgrade\UpgradeService($argv[2], $argv[3], new DateTimeImmutable('now', new DateTimeZone('UTC')), static function ($point) use ($argv): void { if ($point === $argv[7]) { exit(77); } });
if (($argv[8] ?? 'apply') === 'rollback') {
    $service->rollback();
    exit(0);
}
$bundle = Utsuri\PortableCms\Conventional\Upgrade\UpgradeBundle::load($argv[1]);
$service->apply($bundle, $argv[4], $argv[5], $argv[6] === '' ? null : $argv[6]);
PHP);
    $interrupt = static fn(array $case, UpgradeBundle $bundle, string $mode, string $stop, string $action = 'apply'): array => upgradeCommand([$script, $bundle->root, $case['config'], $case['root'] . '/cms', $case['backup'], $bundle->checksum, $mode === 'separate_database' ? $case['standbyConfig'] : '', $stop, $action]);
    $closed = static fn(array $case): bool => upgradeCommand([$case['root'] . '/private/upgrade/worker.php', '--tool=media', '--max-jobs=1'])['code'] === 1;
    foreach ([['table_set', 'after-copy'], ['table_set', 'before-switch'], ['table_set', 'after-switch'], ['in_place', 'after-migrate'], ['in_place', 'before-switch'], ['in_place', 'after-switch'], ['separate_database', 'before-switch'], ['separate_database', 'after-switch']] as [$mode, $stop]) {
        $case = $fixture($mode . '-' . $stop, $mode);
        $case['service']->backup($case['backup']);
        $bundle = $withMigration($case, $proof);
        $serverRoot = $case['root'] . '/apache';
        mkdir($serverRoot, 0700);
        $server = new PublicationApacheServer($serverRoot, $case['root'] . '/public');
        try {
            $served = $server->get('/en/');
            assertSameValue(200, $served['status'], 'public static site serves before upgrade');
            $result = $interrupt($case, $bundle, $mode, $stop);
            assertSameValue(77, $result['code'], 'actual process death at ' . $mode . ' ' . $stop . ': ' . $result['err'] . $result['out']);
            assertSameValue($served['body'], $server->get('/en/')['body'], 'Apache serves exact active bytes through interruption');
            assertSameValue(in_array($stop, ['after-copy', 'after-migrate'], true) ? 'preparing' : 'ready', $journal($case)['status'], 'journal survives death at ' . $stop);
            if ($mode === 'in_place') {
                assertTrueValue($maintenance($case) && $closed($case), 'the CMS stays closed after an interrupted in-place upgrade');
            }
            $recovered = $case['service']->recover();
            assertSameValue($stop === 'after-switch' ? 'upgraded' : 'previous_release_retained', $recovered['status'], 'recovery follows durable commit point in ' . $mode);
            assertTrueValue(!$maintenance($case), 'recovery reopens the CMS');
            if ($stop !== 'after-switch') {
                assertSameValue($backupFingerprint($case), (new DatabaseBackup($pdo))->fingerprint(), 'recovery leaves or restores the exact serving tables in ' . $mode);
            }
            assertSameValue('rolled_back', $case['service']->rollback()['status'], 'one command restores the verified previous bundle/database');
            assertSameValue('initial', $activeRelease($case), 'rollback points at retained original');
            assertSameValue($backupFingerprint($case), (new DatabaseBackup($pdo))->fingerprint(), 'rollback serves the exact previous tables in ' . $mode);
            assertSameValue($served['body'], $server->get('/en/')['body'], 'rollback never replaces the public release');
            if ($mode === 'table_set') {
                $retired = $journal($case)['tableSet'];
                assertSameValue('table_set_removed', $case['service']->cleanupTableSet($retired, $retired)['status'], 'a retired table set is removed after confirmation');
            }
            assertSameValue(['cms'], $tableSets(), 'only the serving table set remains');
        } finally {
            $server->close();
        }
    }
    fwrite(STDOUT, "PASS interruption while copying, migrating and around activation in every mode, closed CMS, recovery, rollback and continuous Apache delivery\n");

    $case = $fixture('restore-interrupted', 'in_place');
    $case['service']->backup($case['backup']);
    $result = $interrupt($case, $withMigration($case, $failing), 'in_place', 'restore-schema');
    assertSameValue(77, $result['code'], 'process death while restoring after a failed migration: ' . $result['err']);
    assertSameValue('restoring', $journal($case)['status'], 'an interrupted restore is journaled');
    assertTrueValue($maintenance($case) && $closed($case), 'the CMS stays closed while the restore is unfinished');
    assertSameValue(true, $case['service']->recover()['databaseRestored'], 'recovery finishes the restore');
    assertSameValue($backupFingerprint($case), (new DatabaseBackup($pdo))->fingerprint(), 'the serving tables are exact after a resumed restore');
    assertTrueValue(!$maintenance($case), 'the CMS reopens after a resumed restore');

    $case = $fixture('rollback-interrupted', 'in_place');
    $case['service']->backup($case['backup']);
    $bundle = $withMigration($case, $proof);
    $applied = upgradeCommand([$bundle->root . '/private/runtime/tools/conventional-upgrade.php', '--action=apply', '--bundle=' . $bundle->root, '--config=' . $case['config'], '--cms-root=' . $case['root'] . '/cms', '--backup=' . $case['backup'], '--confirm-bundle=' . $bundle->checksum]);
    assertSameValue(0, $applied['code'], 'packaged in-place upgrade works: ' . $applied['out'] . $applied['err']);
    assertSameValue('in_place', json_decode($applied['out'], true, flags: JSON_THROW_ON_ERROR)['databaseMode'], 'in-place upgrade activates');
    assertTrueValue(!$maintenance($case), 'the CMS reopens after a completed in-place upgrade');
    assertSameValue(1, (int) $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cms_upgrade_proof'")->fetchColumn(), 'in place migrates the serving tables');
    $result = $interrupt($case, $bundle, 'in_place', 'restore-schema', 'rollback');
    assertSameValue(77, $result['code'], 'process death while rolling back: ' . $result['err']);
    assertSameValue('rolling_back', $journal($case)['status'], 'an interrupted rollback is journaled');
    assertTrueValue($maintenance($case) && $closed($case), 'the CMS stays closed while the rollback is unfinished');
    assertSameValue('rolled_back', $case['service']->recover()['status'], 'recovery finishes the rollback');
    assertSameValue('initial', $activeRelease($case), 'the finished rollback selects the previous release');
    assertSameValue($backupFingerprint($case), (new DatabaseBackup($pdo))->fingerprint(), 'the finished rollback restores the exact previous tables');
    fwrite(STDOUT, "PASS interrupted in-place restore and rollback keep the CMS closed and resume exactly\n");

    $case = $fixture('quota', 'table_set', 1);
    $case['service']->backup($case['backup']);
    $bundle = $withMigration($case, $proof);
    $report = $case['service']->dryRun($bundle)['database'];
    assertSameValue(['table_set', false], [$report['mode'], $report['tableSetFits']], 'dry run reports that a second table set exceeds the declared quota');
    upgradeRefused('database_quota_insufficient_for_table_set', fn() => $case['service']->apply($bundle, $case['backup'], $bundle->checksum, null));
    assertSameValue(['cms'], $tableSets(), 'a refused table set upgrade creates nothing');
    assertSameValue('initial', $activeRelease($case), 'a refused table set upgrade keeps the release');
    fwrite(STDOUT, "PASS a database quota too small for a second table set refuses before copying\n");

    $case = $fixture('video-field-limits');
    $profile = $case['bundle']->root . '/private/profile';
    $document = json_decode((string) file_get_contents($profile . '/profile.json'), flags: JSON_THROW_ON_ERROR);
    $document->version = '1.1.2';
    foreach ($document->recordTypes as $type) {
        foreach ($type->fields as $field) {
            if (($field->type ?? null) === 'video_reference') {
                $field->settings->maximumDurationSeconds = 15;
                $field->settings->maximumWidth = 1920;
                $field->settings->maximumHeight = 1080;
                $field->settings->sound = 'none';
            }
        }
    }
    file_put_contents($profile . '/profile.json', json_encode($document, JSON_THROW_ON_ERROR));
    unlink($profile . '/upgrade.json');
    $bundle = upgradeTestManifest($case['bundle']->root);
    $before = (new DatabaseBackup($pdo))->fingerprint();
    $report = $case['service']->dryRun($bundle);
    assertSameValue(true, $report['blocked'], 'video limits need an explicit content migration');
    assertSameValue(['project'], $report['affectedRecordTypes'], 'video limits identify the owning type');
    assertSameValue(['10000000000000000000000000000001', '10000000000000000000000000000002'], array_column(array_filter($report['contentNeedingMigration'], static fn(array $record): bool => $record['typeId'] === 'project'), 'id'), 'dry run lists the affected existing records');
    assertSameValue($before, (new DatabaseBackup($pdo))->fingerprint(), 'video limit dry run never rewrites saved content');
    fwrite(STDOUT, "PASS video limit upgrades require a declaration and list affected records without writing\n");

    $case = $fixture('profile-change');
    $case['service']->backup($case['backup']);
    $profile = $case['bundle']->root . '/private/profile';
    $json = str_replace(['"version": "1.1.1"', '"excerpt"'], ['"version": "1.1.2"', '"deck"'], (string) file_get_contents($profile . '/profile.json'));
    file_put_contents($profile . '/profile.json', $json);
    file_put_contents($profile . '/administration-labels.json', str_replace('article.excerpt', 'article.deck', (string) file_get_contents($profile . '/administration-labels.json')));
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($profile . '/src', FilesystemIterator::SKIP_DOTS)) as $file) {
        file_put_contents($file->getPathname(), str_replace("'excerpt'", "'deck'", (string) file_get_contents($file->getPathname())));
    }
    assertTrueValue(unlink($profile . '/upgrade.json'), 'the missing-declaration fixture does not inherit the older identity migration');
    $bundle = upgradeTestManifest($case['bundle']->root);
    $report = $case['service']->dryRun($bundle);
    assertSameValue(true, $report['blocked'], 'changed fields with content need a declaration');
    assertSameValue(['article'], $report['affectedRecordTypes'], 'dry run identifies the changed field type');
    assertSameValue(['contentFeed', 'version'], $report['contentNeutralChanges'], 'mixed dry run lists the reviewed feed projection and version separately from content migration');
    upgradeRefused('content_requires_declared_migration', fn() => $case['service']->apply($bundle, $case['backup'], $bundle->checksum, null));
    UpgradeFiles::writeJson($profile . '/upgrade.json', ['formatVersion' => 1, 'fromVersion' => '1.1.1', 'toVersion' => '1.1.2', 'entrypoint' => 'src/upgrade.php']);
    file_put_contents($profile . '/src/upgrade.php', <<<'PHP'
<?php
return static function (string $type, array $body): array {
    if ($type === 'article' && array_key_exists('excerpt', $body)) {
        $body['deck'] = $body['excerpt'];
        unset($body['excerpt']);
    }
    return $body;
};
PHP);
    $bundle = upgradeTestManifest($case['bundle']->root);
    assertSameValue(false, $case['service']->dryRun($bundle)['blocked'], 'reviewed migration declares the exact profile version pair');
    $applied = upgradeCommand([$bundle->root . '/private/runtime/tools/conventional-upgrade.php', '--action=apply', '--bundle=' . $bundle->root, '--config=' . $case['config'], '--cms-root=' . $case['root'] . '/cms', '--backup=' . $case['backup'], '--confirm-bundle=' . $bundle->checksum]);
    assertSameValue(0, $applied['code'], 'packaged changed-profile upgrade works: ' . $applied['out'] . $applied['err']);
    $result = json_decode($applied['out'], true, flags: JSON_THROW_ON_ERROR);
    assertSameValue(['upgraded', 'table_set'], [$result['status'], $result['databaseMode']], 'changed profile compiles and activates in a new table set');
    $again = upgradeCommand([$bundle->root . '/private/runtime/tools/conventional-upgrade.php', '--action=dry-run', '--bundle=' . $bundle->root, '--config=' . $case['config'], '--cms-root=' . $case['root'] . '/cms']);
    assertSameValue(0, $again['code'], 'retained prior content declaration does not block same-release dry run: ' . $again['out'] . $again['err']);
    assertSameValue(true, json_decode($again['out'], true, flags: JSON_THROW_ON_ERROR)['noOp'], 'same converted profile is a no-op');
    $candidate = new DatabaseConnection($dsn, 'portable', 'integration-secret', new TablePrefix($result['tableSet']), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
    $body = json_decode((string) $candidate->query("SELECT v.body_json FROM cms_record_revisions v JOIN cms_records r ON r.current_revision_id = v.id WHERE r.type_id = 'article' LIMIT 1")->fetchColumn(), true, flags: JSON_THROW_ON_ERROR);
    assertTrueValue(array_key_exists('deck', $body) && !array_key_exists('excerpt', $body), 'declared mapping preserves actual article value');
    $selected = $candidate->query("SELECT id, current_revision_number FROM cms_records WHERE type_id = 'article' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    assertSameValue(2, (int) $selected['current_revision_number'], 'mapped records invalidate pre-upgrade forms');
    $newContent = new \Utsuri\PortableCms\Conventional\Content\ContentService(new \Utsuri\PortableCms\Conventional\Content\MariaDbRecordRepository($candidate), $bundle->profile->manifest, $clock, $random);
    assertTrueValue($newContent->update('article', $selected['id'], 1, [], $actor) instanceof \Utsuri\PortableCms\Conventional\Content\RecordConflict, 'old forms conflict before they can validate or clear new fields');
    assertSameValue(1, (int) $candidate->query("SELECT COUNT(*) FROM cms_audit_events WHERE event_type = 'operator_profile_upgrade' AND actor_user_id IS NULL")->fetchColumn(), 'operator migration has explicit audit provenance');
    assertSameValue($backupFingerprint($case), (new DatabaseBackup($pdo))->fingerprint(), 'the previous table set stays exact for rollback');
    upgradeRefused('serving_table_set_retained', fn() => $case['service']->cleanupTableSet($result['tableSet'], $result['tableSet']));
    upgradeRefused('rollback_table_set_retained', fn() => $case['service']->cleanupTableSet('cms_', 'cms_'));
    upgradeRefused('table_set_cleanup_confirmation_required', fn() => $case['service']->cleanupTableSet('cms_', 'cms'));
    upgradeRefused('table_prefix_invalid', fn() => $case['service']->cleanupTableSet('cms_x_', 'cms_x_'));
    $candidate->exec("UPDATE cms_record_revisions v JOIN cms_records r ON r.current_revision_id = v.id SET v.body_json = JSON_SET(v.body_json, '$.deck.en', 'A later saved edit') WHERE r.type_id = 'article'");
    upgradeRefused('rollback_cannot_preserve_later_content_without_declared_reverse_migration', fn() => $case['service']->rollback());
    assertSameValue($result['releaseId'], $activeRelease($case), 'unsafe rollback retains the new serving installation');
    fwrite(STDOUT, "PASS changed-field profile migration in a new table set, retained table sets, automatic compile check and explicit refusal to lose later edits\n");

    $case = $fixture('engine-only');
    $case['service']->backup($case['backup']);
    $installedVersion = \Utsuri\PortableCms\Conventional\Content\ProfileManifest::ENGINE_VERSION;
    $nextVersionParts = explode('.', $installedVersion);
    $nextVersionParts[2] = (string) ((int) $nextVersionParts[2] + 1);
    $nextVersion = implode('.', $nextVersionParts);
    $newEngine = $case['bundle']->root . '/private/runtime/engine/src/cms/conventional/content/profile.php';
    file_put_contents($newEngine, str_replace("ENGINE_VERSION = '" . $installedVersion . "'", "ENGINE_VERSION = '" . $nextVersion . "'", (string) file_get_contents($newEngine)));
    $nextBuild = ['version' => $nextVersion, 'sourceRevision' => str_repeat('e', 40), 'sourceDirty' => false];
    UpgradeFiles::writeJson($case['bundle']->root . '/private/runtime/engine-version.json', $nextBuild);
    $newManifest = UpgradeFiles::json($case['bundle']->root . '/manifest.json');
    $newManifest['engineVersion'] = $nextVersion;
    $newManifest['sourceRevision'] = $nextBuild['sourceRevision'];
    $newManifest['sourceDirty'] = $nextBuild['sourceDirty'];
    UpgradeFiles::writeJson($case['bundle']->root . '/manifest.json', $newManifest);
    $bundle = upgradeTestManifest($case['bundle']->root);
    $currentBuild = \Utsuri\PortableCms\Conventional\Upgrade\EngineBuild::installed($case['root'] . '/private/runtime', $installedVersion);
    assertSameValue(['from' => $installedVersion, 'to' => $nextVersion, 'fromSourceRevision' => $currentBuild->sourceRevision, 'toSourceRevision' => $nextBuild['sourceRevision'],
        'fromSourceDirty' => $currentBuild->sourceDirty, 'toSourceDirty' => false], $case['service']->dryRun($bundle)['engine'], 'upgrade reports both versions and actual source revisions without using the incoming operator revision for the serving release');
    $applied = upgradeCommand([$bundle->root . '/private/runtime/tools/conventional-upgrade.php', '--action=apply', '--bundle=' . $bundle->root, '--config=' . $case['config'], '--cms-root=' . $case['root'] . '/cms', '--backup=' . $case['backup'], '--confirm-bundle=' . $bundle->checksum]);
    assertSameValue(0, $applied['code'], 'packaged engine-only upgrade works: ' . $applied['out'] . $applied['err']);
    assertSameValue('upgraded', json_decode($applied['out'], true, flags: JSON_THROW_ON_ERROR)['status'], 'engine-only release needs no standby');
    $stale = upgradeCommand([$case['root'] . '/private/runtime/tools/conventional-media-worker.php', '--config=' . $case['config'], '--max-jobs=1']);
    assertSameValue(1, $stale['code'], 'old direct worker refuses after activation');
    $worker = upgradeCommand([$case['root'] . '/private/upgrade/worker.php', '--tool=media', '--max-jobs=1']);
    assertSameValue(0, $worker['code'], 'stable cron dispatcher runs selected runtime: ' . $worker['out'] . $worker['err']);
    $pdo->exec("UPDATE cms_record_revisions v JOIN cms_records r ON r.current_revision_id = v.id SET v.body_json = JSON_SET(v.body_json, '$.excerpt.en', 'A later engine-only edit') WHERE r.type_id = 'article'");
    $later = (new DatabaseBackup($pdo))->durableFingerprint();
    assertSameValue(true, $case['service']->rollback()['laterContentPreserved'], 'unchanged schema/profile retains later content');
    assertSameValue($later, (new DatabaseBackup($pdo))->durableFingerprint(), 'rollback never rewinds a compatible database');
    fwrite(STDOUT, "PASS engine-only rollback preserves post-backup content\n");

    $case = $fixture('web-gate');
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    if (!is_resource($socket)) {
        throw new RuntimeException('CMS loopback port unavailable');
    }
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    if (!is_string($address)) {
        throw new RuntimeException('CMS loopback address unavailable');
    }
    $origin = 'http://' . $address;
    $config = UpgradeFiles::json($case['config']);
    $config['origin'] = $origin;
    $config['development'] = ['allowInsecureLoopback' => true];
    UpgradeFiles::writeJson($case['config'], $config);
    $case['service']->backup($case['backup']);
    $token = str_repeat('c', 64);
    $intent = $case['root'] . '/private/upgrade-request.json';
    $gate = $case['root'] . '/private/upgrade-gate.json';
    $openGate = static function (array $request) use ($intent, $gate, $token): void {
        UpgradeFiles::writeJson($intent, $request);
        UpgradeFiles::writeJson($gate, ['status' => 'open', 'expiresAt' => gmdate(DATE_ATOM, time() + 3600), 'tokenHash' => hash('sha256', $token), 'runtimeConfig' => $intent]);
    };
    $request = ['action' => 'dry-run', 'bundle' => $case['bundle']->root, 'config' => $case['config'], 'cmsRoot' => $case['root'] . '/cms'];
    $openGate($request);
    $router = $case['root'] . '/router.php';
    file_put_contents($router, "<?php\n"
        . '$path = parse_url($_SERVER[\'REQUEST_URI\'], PHP_URL_PATH);' . "\n"
        . 'if ($path === \'/upgrade.php\') { $_SERVER[\'HTTPS\'] = ($_SERVER[\'HTTP_X_TEST_TLS\'] ?? \'\') === \'on\' ? \'on\' : \'off\'; putenv('
        . var_export('CMS_UPGRADE_CONFIG=' . $gate, true) . '); require ' . var_export($case['bundle']->root . '/private/runtime/tools/conventional-upgrade-web.php', true) . '; } else { require '
        . var_export($case['root'] . '/cms/index.php', true) . '; }');
    $server = proc_open([PHP_BINARY, '-S', $address, '-t', $case['root'] . '/cms', $router], [0 => ['file', '/dev/null', 'r'], 1 => ['file', $case['root'] . '/http-out.log', 'w'], 2 => ['file', $case['root'] . '/http-err.log', 'w']], $pipes);
    if (!is_resource($server)) {
        throw new RuntimeException('CMS loopback server failed');
    }
    try {
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $connection = \Utsuri\PortableCms\Conventional\HostIo::attempt(fn() => stream_socket_client('tcp://' . $address, timeout: 0.05))->value;
            if (is_resource($connection)) {
                fclose($connection);
                break;
            }
            usleep(20_000);
        }
        assertSameValue(404, upgradeWebRequest($origin . '/upgrade.php', 'POST')['status'], 'plain HTTP upgrade refused');
        assertSameValue(404, upgradeWebRequest($origin . '/upgrade.php', 'POST', ['X-Test-Tls: on'])['status'], 'missing token refused');
        $authorized = ['X-Test-Tls: on', 'X-CMS-Upgrade-Token: ' . $token];
        assertSameValue(404, upgradeWebRequest($origin . '/upgrade.php', 'GET', $authorized)['status'], 'GET upgrade refused');
        assertSameValue(200, upgradeWebRequest($origin . '/upgrade.php', 'POST', $authorized)['status'], 'private declared dry run succeeds');
        assertSameValue('closed', UpgradeFiles::json($gate)['status'], 'successful request atomically closes its gate');
        assertTrueValue(!isset(UpgradeFiles::json($gate)['tokenHash']), 'closed gate discards token digest');
        assertSameValue(404, upgradeWebRequest($origin . '/upgrade.php', 'POST', $authorized)['status'], 'one-use token cannot be replayed');
        assertSameValue(413, upgradeWebRequest($origin . '/upgrade.php', 'POST', $authorized, str_repeat('x', 513))['status'], 'oversized request refused');
        $oldCss = upgradeWebRequest($origin . '/assets/admin.css');
        assertSameValue(200, $oldCss['status'], 'stable CMS dispatcher serves installed assets');
        upgradeInstallationDetails($case['root'], $origin);
        // Signing in adds an audit entry. Apply must confirm a fresh backup, not the earlier one.
        $case['backup'] = $case['root'] . '/authenticated-backup';
        $case['service']->backup($case['backup']);
        file_put_contents($case['bundle']->root . '/private/runtime/engine/src/cms/conventional/reviewed-release.txt', 'web release fixture');
        file_put_contents($case['bundle']->root . '/public/assets/admin.css', "\n/* reviewed updated asset */\n", FILE_APPEND);
        $bundle = upgradeTestManifest($case['bundle']->root);
        $request['action'] = 'apply';
        $request['backup'] = $case['backup'];
        $request['confirmBundle'] = $bundle->checksum;
        $openGate($request);
        $response = upgradeWebRequest($origin . '/upgrade.php', 'POST', $authorized);
        assertSameValue(200, $response['status'], 'control-panel apply uses the same service: ' . $response['body']);
        $newCss = upgradeWebRequest($origin . '/assets/admin.css');
        assertSameValue(hash_file('sha256', $bundle->root . '/public/assets/admin.css'), hash('sha256', $newCss['body']), 'one pointer chooses matching new CMS assets');
        assertSameValue(200, upgradeWebRequest($origin . '/login')['status'], 'real activated CMS serves login');
        upgradeInstallationDetails($case['root'], $origin);
        $openGate(['action' => 'rollback', 'config' => $case['config'], 'cmsRoot' => $case['root'] . '/cms']);
        $response = upgradeWebRequest($origin . '/upgrade.php', 'POST', $authorized);
        assertSameValue(200, $response['status'], 'control-panel rollback succeeds: ' . $response['body']);
        assertSameValue($oldCss['body'], upgradeWebRequest($origin . '/assets/admin.css')['body'], 'rollback restores matching old CMS assets');
        assertSameValue(200, upgradeWebRequest($origin . '/login')['status'], 'previous CMS serves again');
        upgradeInstallationDetails($case['root'], $origin);
    } finally {
        proc_terminate($server);
        proc_close($server);
    }
    fwrite(STDOUT, "PASS temporary HTTPS gate rules, one-use authorization, bounded intent, native CMS/asset switch and control-panel rollback\n");
} finally {
    RestoreTestFixture::remove($state);
}
