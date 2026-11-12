<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Content\ProfilePackage;
use Utsuri\PortableCms\Conventional\Database\MigrationRunner;
use Utsuri\PortableCms\Conventional\Export\ExportCapacity;
use Utsuri\PortableCms\Conventional\Export\MariaDbExportRows;
use Utsuri\PortableCms\Conventional\Export\NormalizedExport;
use Utsuri\PortableCms\Conventional\Identity\AdministratorBootstrap;
use Utsuri\PortableCms\Conventional\Identity\SystemClock;
use Utsuri\PortableCms\Conventional\Identity\SystemRandomSource;
use Utsuri\PortableCms\Conventional\Restore\FilesystemRestoreStore;
use Utsuri\PortableCms\Conventional\Restore\MariaDbRestoreRepository;
use Utsuri\PortableCms\Conventional\Restore\RestorePlan;
use Utsuri\PortableCms\Conventional\Restore\RestoreService;
use Utsuri\PortableCms\Conventional\Transfer\ExportWorkspace;
use Utsuri\PortableCms\Conventional\Transfer\OperatorContext;

require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/bootstrap.php';
require_once __DIR__ . '/assertions.php';
require_once __DIR__ . '/restore-fixture.php';

/** Capture large progress output without pipe deadlocks; all processes use argument vectors.
 * @param list<string> $command
 * @return array{string, string}
 */
function transferProcess(array $command, int $expected): array
{
    $out = tmpfile();
    $err = tmpfile();
    if ($out === false || $err === false) {
        throw new RuntimeException('Isolated operator output streams are required');
    }
    try {
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => $out, 2 => $err], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Installed PHP operator could not start');
        }
        fclose($pipes[0]);
        $status = proc_close($process);
        rewind($out);
        rewind($err);
        $stdout = (string) stream_get_contents($out, 1048576);
        $stderr = (string) stream_get_contents($err, 1048576);
        assertSameValue($expected, $status, 'installed operator exit status: ' . $stdout . $stderr);
        return [$stdout, $stderr];
    } finally {
        fclose($out);
        fclose($err);
    }
}

/** @param list<string> $arguments
 * @return array<string, mixed>
 */
function transferOperator(string $runtime, string $tool, array $arguments, int $expected = 0): array
{
    [$stdout, $stderr] = transferProcess([PHP_BINARY, '-d', 'memory_limit=256M', $runtime . '/tools/' . $tool, ...$arguments], $expected);
    $lines = explode("\n", trim($stdout === '' ? $stderr : $stdout));
    $value = json_decode($lines[count($lines) - 1], true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($value)) {
        throw new RuntimeException('An operator report is required');
    }
    return $value;
}

/** @param list<string> $migrations
 * @return array{PDO, string, string}
 */
function transferDatabase(string $name, array $migrations): array
{
    $dsn = getenv($name);
    if (!is_string($dsn) || $dsn === '') {
        throw new RuntimeException('Isolated transfer database is required');
    }
    $pdo = new PDO($dsn, 'portable', 'integration-secret', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
    (new MigrationRunner($pdo))->apply($migrations);
    $actor = (new AdministratorBootstrap($pdo, new SystemClock(), new SystemRandomSource()))->create('transfer@example.test', 'Transfer administrator', 'fictional transfer acceptance password');
    return [$pdo, $actor, $dsn];
}

function transferConfiguration(string $path, string $dsn, string $media, string $profile): void
{
    file_put_contents($path, json_encode(['origin' => 'http://localhost:4181', 'database' => ['dsn' => $dsn, 'username' => 'portable', 'password' => 'integration-secret'], 'throttleSecretBase64' => base64_encode(str_repeat('t', 32)), 'storage' => ['mediaRoot' => $media], 'profileRoot' => $profile, 'development' => ['allowInsecureLoopback' => true]], JSON_THROW_ON_ERROR));
    chmod($path, 0600);
}

$root = dirname(__DIR__, 2);
$state = sys_get_temp_dir() . '/portable-transfer-integration-' . bin2hex(random_bytes(8));
mkdir($state, 0700);
$migrations = glob($root . '/engine/migrations/*.sql');
if ($migrations === false) {
    throw new RuntimeException('Migration inventory required');
}
sort($migrations, SORT_STRING);
$clock = new SystemClock();
$random = new SystemRandomSource();
try {
    foreach (['source', 'destination', 'resume', 'legacy', 'seed', 'handover', 'partial', 'unsealed', 'public', 'unauthorized', 'memory'] as $directory) {
        mkdir($state . '/' . $directory, 0700);
    }
    transferProcess([PHP_BINARY, $root . '/tools/build-conventional-cms-bundle.php', '--output=' . $state . '/bundle', '--private-root=/srv/portable-transfer/private', '--profile-root=' . $root . '/profiles/reference-studio'], 0);
    $runtime = $state . '/bundle/private/runtime';
    $profile = ProfilePackage::load($root . '/profiles/reference-studio');
    $installed = ProfilePackage::load($state . '/bundle/private/profile');
    assertSameValue($profile->checksum, $installed->checksum, 'the independent installed profile is exact');
    [$source, $actor, $sourceDsn] = transferDatabase('CMS_TEST_TRANSFER_SOURCE_DSN', $migrations);
    [$destination, , $destinationDsn] = transferDatabase('CMS_TEST_TRANSFER_DESTINATION_DSN', $migrations);
    [$resume, , $resumeDsn] = transferDatabase('CMS_TEST_TRANSFER_RESUME_DSN', $migrations);
    [$legacy, , $legacyDsn] = transferDatabase('CMS_TEST_TRANSFER_LEGACY_DSN', $migrations);
    foreach (['source' => $sourceDsn, 'destination' => $destinationDsn, 'resume' => $resumeDsn, 'legacy' => $legacyDsn] as $name => $dsn) {
        transferConfiguration($state . '/' . $name . '.json', $dsn, $state . '/' . $name, $installed->root);
    }
    $document = RestoreTestFixture::document($profile);
    $snapshot = json_decode((string) file_get_contents($profile->root . '/fixtures/snapshot.json'), true, 64, JSON_THROW_ON_ERROR);
    $texts = array_column($snapshot['media'], 'text', 'id');
    $document['designRegistries'] = $profile->manifest->designRegistries->document();
    $document['designRegistries']['fonts'][0]['faces'][0]['origin'] = 'upload';
    $font = $document['designRegistries']['fonts'][0]['faces'][0];
    mkdir($state . '/seed/blobs', 0700);
    foreach ($profile->manifest->assets as $asset) {
        if ($asset->checksum === $font['sha256']) {
            copy($profile->root . '/' . $asset->path, $state . '/seed/blobs/' . $font['sha256']);
        }
    }
    foreach ($document['media'] as &$media) {
        $media['text'] = $texts[$media['id']];
        copy($profile->root . '/fixtures/media/' . $media['id'] . '-display_1600_webp.webp', $state . '/seed/blobs/' . $media['original']['sha256']);
    }
    unset($media);
    $export = new NormalizedExport($document);
    file_put_contents($state . '/seed/export.json', $export->json());
    $seed = new FilesystemRestoreStore($state . '/seed');
    $plan = new RestorePlan($export, hash('sha256', $export->json()), $installed, $clock, $random);
    assertSameValue('restored', (new RestoreService(new MariaDbRestoreRepository($source, $migrations), new FilesystemRestoreStore($state . '/source'), $clock))->apply($plan, $seed, $actor, $plan->sha256, 64)['status'], 'source fixture uses the real authoritative restore path');
    $common = ['--public-root=' . $state . '/public', '--administrator=transfer@example.test'];
    $exportArgs = ['--config=' . $state . '/source.json', '--output=' . $state . '/handover', ...$common, '--source-paused', '--maximum-files=1'];
    $noPause = array_values(array_filter($exportArgs, static fn(string $arg): bool => $arg !== '--source-paused'));
    assertSameValue('explicit_source_mutation_pause_required', transferOperator($runtime, 'conventional-portable-export.php', $noPause, 1)['reason'], 'the retention/pause protocol must be acknowledged before allocation');
    assertSameValue([], iterator_to_array(new FilesystemIterator($state . '/handover')), 'no unacknowledged source workspace writes');
    $packaged = transferOperator($runtime, 'conventional-portable-export.php', $exportArgs);
    assertSameValue('packaged', $packaged['status'], 'one source command completes every batch without a browser export');
    assertTrueValue(is_file($state . '/handover/package.json'), 'completion manifest appears last');
    assertTrueValue(is_file($state . '/handover/content/blobs/' . $font['sha256']), 'uploaded font bytes are in the package');
    assertSameValue(1, (int) $source->query("SELECT COUNT(*) FROM cms_audit_events WHERE event_type = 'chunked_export_captured' AND actor_user_id = '" . $actor . "'")->fetchColumn(), 'source capture is attributed once');
    assertSameValue($packaged['packageSha256'], transferOperator($runtime, 'conventional-portable-export.php', $exportArgs)['packageSha256'], 'source replay retains one immutable capture');
    $intentBytes = (string) file_get_contents($state . '/handover/intent.json');
    $intent = json_decode($intentBytes, true, 8, JSON_THROW_ON_ERROR);
    assertTrueValue(isset($intent['sourceDatabaseSha256']) && !str_contains($intentBytes, $sourceDsn), 'working source identity binds database and table prefix without storing the DSN');
    $intent['sourceDatabaseSha256'] = str_repeat('0', 64);
    file_put_contents($state . '/handover/intent.json', json_encode($intent, JSON_THROW_ON_ERROR));
    assertSameValue('source_export_intent_mismatch', transferOperator($runtime, 'conventional-portable-export.php', $exportArgs, 1)['reason'], 'a different source database identity cannot adopt the workspace');
    file_put_contents($state . '/handover/intent.json', $intentBytes);
    $restoreArgs = ['--config=' . $state . '/destination.json', '--package=' . $state . '/handover', ...$common, '--maximum-files=1'];
    $dry = transferOperator($runtime, 'conventional-portable-restore.php', $restoreArgs);
    assertSameValue('compatible', $dry['status'], 'destination defaults to a verified read-only plan');
    assertSameValue($packaged['packageSha256'], $dry['packageSha256'], 'confirmation binds the exact full completion manifest');
    assertSameValue(0, (int) $destination->query('SELECT COUNT(*) FROM cms_restore_state')->fetchColumn(), 'dry run creates no restore journal');
    assertSameValue([], iterator_to_array(new FilesystemIterator($state . '/destination')), 'dry run creates no destination files or locks');
    assertSameValue('package_confirmation_mismatch', transferOperator($runtime, 'conventional-portable-restore.php', [...$restoreArgs, '--apply', '--confirm=' . str_repeat('0', 64)], 1)['reason'], 'apply needs exact package confirmation');
    $manifestBytes = (string) file_get_contents($state . '/handover/package.json');
    $changed = json_decode($manifestBytes, true, 16, JSON_THROW_ON_ERROR);
    $changed['profile']['packageSha256'] = str_repeat('f', 64);
    file_put_contents($state . '/handover/package.json', json_encode($changed, JSON_THROW_ON_ERROR));
    assertSameValue('exact_profile_package_mismatch', transferOperator($runtime, 'conventional-portable-restore.php', $restoreArgs, 1)['reason'], 'same profile name/version does not bypass the exact package checksum');
    file_put_contents($state . '/handover/package.json', $manifestBytes);
    $changed = json_decode($manifestBytes, true, 16, JSON_THROW_ON_ERROR);
    $changed['engineVersion'] = '9.0.0';
    file_put_contents($state . '/handover/package.json', json_encode($changed, JSON_THROW_ON_ERROR));
    assertSameValue('package_engine_compatibility_requires_review', transferOperator($runtime, 'conventional-portable-restore.php', $restoreArgs, 1)['reason'], 'engine incompatibility is not guessed away');
    file_put_contents($state . '/handover/package.json', $manifestBytes);
    $envelope = json_decode((string) file_get_contents($state . '/handover/content/export.json'), true, 32, JSON_THROW_ON_ERROR);
    $chunkPath = $state . '/handover/content/chunks/' . $envelope['collections']['records'][0]['sha256'] . '.jsonl';
    $chunkBytes = (string) file_get_contents($chunkPath);
    unlink($chunkPath);
    assertSameValue('rejected', transferOperator($runtime, 'conventional-portable-restore.php', $restoreArgs, 1)['status'], 'missing chunks never reach a restore journal');
    file_put_contents($chunkPath, 'changed');
    assertSameValue('rejected', transferOperator($runtime, 'conventional-portable-restore.php', $restoreArgs, 1)['status'], 'changed chunks never reach a restore journal');
    file_put_contents($chunkPath, $chunkBytes);
    $blob = $state . '/handover/content/blobs/' . $document['media'][0]['original']['sha256'];
    $blobBytes = (string) file_get_contents($blob);
    unlink($blob);
    assertSameValue('blocked', transferOperator($runtime, 'conventional-portable-restore.php', $restoreArgs, 1)['status'], 'missing media blocks the plan');
    file_put_contents($blob, 'changed');
    assertSameValue('blocked', transferOperator($runtime, 'conventional-portable-restore.php', $restoreArgs, 1)['status'], 'changed media blocks the plan');
    file_put_contents($blob, $blobBytes);
    $apply = [...$restoreArgs, '--apply', '--confirm=' . $dry['packageSha256']];
    assertSameValue('restored', transferOperator($runtime, 'conventional-portable-restore.php', $apply)['status'], 'one destination invocation completes every preparation batch');
    assertSameValue('already_restored', transferOperator($runtime, 'conventional-portable-restore.php', $apply)['status'], 'repeated completion verifies existing authoritative content');
    unlink($blob);
    assertSameValue('blocked', transferOperator($runtime, 'conventional-portable-restore.php', $restoreArgs, 1)['status'], 'a completed destination cannot hide a broken source inventory in a new read-only plan');
    file_put_contents($blob, $blobBytes);
    foreach (ExportCapacity::COLLECTIONS as $kind) {
        $before = iterator_to_array((new MariaDbExportRows($source))->rows($kind, ExportCapacity::limit($kind)), false);
        $after = iterator_to_array((new MariaDbExportRows($destination))->rows($kind, ExportCapacity::limit($kind)), false);
        assertSameValue($before, $after, 'every normalized row survives: ' . $kind);
    }
    $resumeArgs = ['--config=' . $state . '/resume.json', '--package=' . $state . '/handover', ...$common, '--maximum-files=1', '--maximum-batches=1', '--apply', '--confirm=' . $dry['packageSha256']];
    $previous = 0;
    $report = ['status' => 'not_started'];
    for ($attempt = 0; $attempt < $dry['files']; $attempt++) {
        $report = transferOperator($runtime, 'conventional-portable-restore.php', $resumeArgs, $attempt + 1 === $dry['files'] ? 0 : 2);
        assertTrueValue($report['preparedFiles'] > $previous, 'the identical command resumes beyond the durable prefix');
        $previous = $report['preparedFiles'];
    }
    assertSameValue('restored', $report['status'], 'bounded batch exhaustion eventually completes');
    $legacyArgs = ['--config=' . $state . '/legacy.json', '--package=' . $state . '/seed', ...$common];
    assertSameValue('legacy_exact_profile_checksum_required', transferOperator($runtime, 'conventional-portable-restore.php', $legacyArgs, 1)['reason'], 'legacy input cannot invent its missing source profile checksum');
    $legacyArgs[] = '--legacy-profile-sha256=' . $profile->checksum;
    $legacyDry = transferOperator($runtime, 'conventional-portable-restore.php', $legacyArgs);
    assertSameValue('restored', transferOperator($runtime, 'conventional-portable-restore.php', [...$legacyArgs, '--apply', '--confirm=' . $legacyDry['packageSha256'], '--maximum-files=1'])['status'], 'supported legacy v1 input also completes bounded batches in one invocation');
    $partialArgs = ['--config=' . $state . '/source.json', '--output=' . $state . '/partial', ...$common, '--source-paused', '--maximum-files=1', '--maximum-batches=1'];
    $partial = transferOperator($runtime, 'conventional-portable-export.php', $partialArgs, 2);
    assertTrueValue(!is_file($state . '/partial/package.json') && !is_file($state . '/partial/content/export.json'), 'incomplete bytes never present a completed handover');
    for ($attempt = 1; $attempt < $partial['files']; $attempt++) {
        $completed = transferOperator($runtime, 'conventional-portable-export.php', $partialArgs, $attempt + 1 === $partial['files'] ? 0 : 2);
        assertSameValue($partial['exportSha256'], $completed['exportSha256'], 'the exact captured snapshot survives retries');
    }
    $occupied = ['--config=' . $state . '/destination.json', '--package=' . $state . '/partial', ...$common];
    assertSameValue('rejected', transferOperator($runtime, 'conventional-portable-restore.php', $occupied, 1)['status'], 'a different package cannot take over an occupied destination');
    $context = OperatorContext::load(['config' => $state . '/source.json', 'public-root' => $state . '/public', 'administrator' => 'transfer@example.test'], $root);
    $unsealed = new FilesystemRestoreStore($state . '/unsealed');
    $content = $unsealed->exclusive(fn(): FilesystemRestoreStore => (new ExportWorkspace($unsealed))->prepare($context, $clock, 120));
    $content->directories('chunks');
    file_put_contents($content->path('chunks/' . str_repeat('a', 64) . '.jsonl'), 'interrupted owned capture');
    $recoverArgs = ['--config=' . $state . '/source.json', '--output=' . $state . '/unsealed', ...$common, '--source-paused'];
    file_put_contents($content->path('foreign.txt'), 'must not be deleted');
    assertSameValue('unexpected_unsealed_capture_file', transferOperator($runtime, 'conventional-portable-export.php', $recoverArgs, 1)['reason'], 'capture recovery validates the complete owned namespace before cleanup');
    assertSameValue('must not be deleted', file_get_contents($content->path('foreign.txt')), 'unknown files remain untouched');
    assertTrueValue(is_file($content->path('chunks/' . str_repeat('a', 64) . '.jsonl')), 'refused recovery has not partially cleaned the workspace');
    unlink($content->path('foreign.txt'));
    assertSameValue('packaged', transferOperator($runtime, 'conventional-portable-export.php', $recoverArgs)['status'], 'owned unsealed capture chunks recover without touching source files');
    $publicArgs = ['--config=' . $state . '/source.json', '--output=' . $state . '/public', ...$common, '--source-paused'];
    assertSameValue('storage_must_be_outside_document_root', transferOperator($runtime, 'conventional-portable-export.php', $publicArgs, 1)['reason'], 'unsafe public output fails before allocation');
    $badActorArgs = ['--config=' . $state . '/source.json', '--output=' . $state . '/unauthorized', '--public-root=' . $state . '/public', '--administrator=absent@example.test', '--source-paused'];
    assertSameValue('administrator_required', transferOperator($runtime, 'conventional-portable-export.php', $badActorArgs, 1)['reason'], 'operator authentication precedes workspace changes');
    assertSameValue([], iterator_to_array(new FilesystemIterator($state . '/unauthorized')), 'failed authority leaves the output untouched');
    $memoryArgs = ['--config=' . $state . '/source.json', '--output=' . $state . '/memory', ...$common, '--source-paused'];
    [, $memoryError] = transferProcess([PHP_BINARY, '-d', 'memory_limit=64M', $runtime . '/tools/conventional-portable-export.php', ...$memoryArgs], 1);
    assertTrueValue(str_contains($memoryError, 'insufficient_chunked_export_memory_budget'), 'memory qualification fails safely before decoding large collections');
    assertTrueValue(!is_file($state . '/memory/package.json'), 'resource failure cannot seal a completed handover');
    assertSameValue('packaged', transferOperator($runtime, 'conventional-portable-export.php', $memoryArgs)['status'], 'the owned workspace resumes after host qualification is corrected');
    $captureBytes = (string) file_get_contents($state . '/handover/content/capture.json');
    unlink($state . '/handover/content/capture.json');
    assertSameValue('completed_workspace_capture_missing', transferOperator($runtime, 'conventional-portable-export.php', $exportArgs, 1)['reason'], 'a completed package cannot be recaptured when local capture state disappears');
    assertSameValue($chunkBytes, file_get_contents($chunkPath), 'completed package chunks are never deleted by capture recovery');
    file_put_contents($state . '/handover/content/capture.json', $captureBytes);
    fwrite(STDOUT, "PASS installed portable operators: attributed complete capture, exact profile/engine/inventory proof, read-only plan, one-invocation v1/v2 completion, replay, bounded resume, private fonts/media, tampering, missing bytes, unsafe output and owned capture recovery.\n");
} finally {
    RestoreTestFixture::remove($state);
}
