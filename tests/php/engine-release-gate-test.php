<?php

declare(strict_types=1);

use Utsuri\PortableCms\ReleaseGate\ApiSurface;
use Utsuri\PortableCms\ReleaseGate\ReleasePolicy;
use Utsuri\PortableCms\ReleaseGate\ReleaseRepository;

require_once dirname(__DIR__, 2) . '/tools/release-gate/api-surface.php';
require_once dirname(__DIR__, 2) . '/tools/release-gate/policy.php';
require_once dirname(__DIR__, 2) . '/tools/release-gate/repository.php';
require_once __DIR__ . '/assertions.php';

/** @return array{code: int, output: string} */
function releaseGateCommand(string $root, string $base = 'main'): array
{
    $process = proc_open([PHP_BINARY, dirname(__DIR__, 2) . '/tools/check-engine-release.php', '--root=' . $root, '--base=' . $base], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Gate fixture could not start PHP');
    }
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['code' => proc_close($process), 'output' => (string) $output . (string) $error];
}

function releaseGateWrite(string $root, string $path, string $bytes): void
{
    if (!is_dir(dirname($root . '/' . $path))) {
        mkdir(dirname($root . '/' . $path), 0700, true);
    }
    file_put_contents($root . '/' . $path, $bytes);
}

function releaseGateRemove(string $root): void
{
    foreach (new DirectoryIterator($root) as $entry) {
        if ($entry->isDot()) {
            continue;
        }
        if ($entry->isDir() && !$entry->isLink()) {
            releaseGateRemove($entry->getPathname());
        } else {
            unlink($entry->getPathname());
        }
    }
    rmdir($root);
}

$tests = [];
$tests['API fingerprints distinguish declarations from comments implementation and engine version'] = static function (): void {
    $source = '<?php declare(strict_types=1); namespace Fictional; final readonly class Marks { public const ENGINE_VERSION = "0.2.0"; public function item(string $id): string { return $id; } private function detail(): void {} }';
    $fixed = str_replace(['"0.2.0"', 'return $id;', 'private function detail(): void {}'], ['"0.2.1"', 'return trim($id);', 'private function detail(int $x): void { echo $x; }'], $source);
    assertSameValue(ApiSurface::fingerprint($source), ApiSurface::fingerprint($fixed), 'implementation fixes and private declarations permit patch releases');
    foreach ([str_replace('string $id', 'int $id', $source), str_replace('readonly class', 'class', $source), str_replace('public function item', 'protected function item', $source), $source . ' interface Blocks { public function block(int $position): string; }'] as $changed) {
        assertTrueValue(ApiSurface::fingerprint($source) !== ApiSurface::fingerprint($changed), 'changed or added public/protected declarations require a minor version');
    }
    assertTrueValue(ApiSurface::fingerprint('<?php class View { private function __construct(public string $title) {} }') !== ApiSurface::fingerprint('<?php class View { private function __construct(public int $title) {} }'), 'promoted public properties remain part of the API');
    $defaultVersion = '<?php class Profile { public function load(string $engine = self::ENGINE_VERSION): string { return $engine; } }';
    assertTrueValue(ApiSurface::fingerprint($defaultVersion) !== ApiSurface::fingerprint(str_replace('string $engine', 'int $engine', $defaultVersion)), 'methods using the engine constant in a default parameter remain checked');
};

$tests['real Git gate rejects unversioned migrations and API changes and accepts a minor release'] = static function (): void {
    $root = sys_get_temp_dir() . '/engine-release-gate-' . bin2hex(random_bytes(8));
    mkdir($root, 0700);
    try {
        $api = 'engine/src/cms/conventional/compiler/field-marks.php';
        $version = "<?php final class ProfileManifest { public const ENGINE_VERSION = '0.2.0'; }\n";
        releaseGateWrite($root, ReleasePolicy::VERSION_PATH, $version);
        releaseGateWrite($root, $api, '<?php class FieldMarks { public function attribute(string $field): string { return $field; } }');
        releaseGateWrite($root, 'engine-release-policy.json', json_encode(['schema' => 1, 'profileApiPaths' => [$api]], JSON_THROW_ON_ERROR));
        releaseGateWrite($root, 'CHANGELOG.md', "# Releases\n\n## 0.2.0\nInitial fixture.\n");
        $repository = new ReleaseRepository($root);
        $repository->git(['init', '--initial-branch=main']);
        $repository->git(['config', 'user.name', 'Fictional release test']);
        $repository->git(['config', 'user.email', 'release@example.test']);
        $repository->git(['add', '.']);
        $repository->git(['commit', '-m', 'Fictional base']);
        assertSameValue(false, $repository->dirty(), 'clean source builds have explicit clean provenance');
        assertTrueValue(preg_match('/\A[a-f0-9]{40,64}\z/', $repository->revision()) === 1, 'source revisions are full Git object identities');
        assertSameValue(0, releaseGateCommand($root)['code'], 'unchanged source passes against an explicit base');
        releaseGateWrite($root, 'engine/migrations/0002-fixture.sql', 'CREATE TABLE fictional (id INT);');
        assertSameValue(true, $repository->dirty(), 'untracked runtime additions are recorded as a dirty source build');
        $failed = releaseGateCommand($root);
        assertSameValue(1, $failed['code'], 'an untracked migration cannot bypass the gate');
        assertTrueValue(str_contains($failed['output'], '0002-fixture.sql') && str_contains($failed['output'], 'minor'), 'the gate names the migration and required bump');
        unlink($root . '/engine/migrations/0002-fixture.sql');
        releaseGateWrite($root, $api, '<?php class FieldMarks { public function attribute(string $field): string { return $field; } public function itemAttribute(string $item): string { return $item; } }');
        $repository->git(['add', $api]);
        releaseGateWrite($root, ReleasePolicy::VERSION_PATH, str_replace('0.2.0', '0.2.1', $version));
        releaseGateWrite($root, 'CHANGELOG.md', "## 0.2.1\nFixture.\n");
        $failed = releaseGateCommand($root);
        assertSameValue(1, $failed['code'], 'a staged new public method needs more than a patch bump');
        assertTrueValue(str_contains($failed['output'], $api), 'the failure identifies the changed documented API');
        releaseGateWrite($root, 'engine-release-policy.json', '{"schema":1,"profileApiPaths":[]}');
        assertSameValue(1, releaseGateCommand($root)['code'], 'removing the registry entry cannot hide the same API change');
        releaseGateWrite($root, ReleasePolicy::VERSION_PATH, str_replace('0.2.0', '0.3.0', $version));
        releaseGateWrite($root, 'CHANGELOG.md', "## 0.3.0\nAPI and migration notes.\n");
        assertSameValue(0, releaseGateCommand($root)['code'], 'a documented minor release admits the API change');
        assertSameValue(1, releaseGateCommand($root, 'missing-base')['code'], 'a missing base never silently skips the gate');
    } finally {
        releaseGateRemove($root);
    }
};

$tests['release policy enforces fixes changelog and downgrade while documentation remains independent'] = static function (): void {
    $fix = ['engine/src/fix.php' => ['before' => '<?php return 1;', 'after' => '<?php return 2;']];
    assertTrueValue(ReleasePolicy::check('0.2.0', '0.2.0', $fix, [], '') !== [], 'runtime changes require a new version');
    assertSameValue([], ReleasePolicy::check('0.2.0', '0.2.1', $fix, [], "## 0.2.1\nFixed validation.\n"), 'implementation fixes accept a patch');
    assertTrueValue(ReleasePolicy::check('0.2.0', '0.2.1', $fix, [], '') !== [], 'a release needs its changelog entry');
    assertTrueValue(ReleasePolicy::check('0.2.0', '0.1.9', [], [], '') !== [], 'downgrades are refused even without file changes');
    assertSameValue([], ReleasePolicy::check('0.2.0', '0.2.0', ['docs/guide.md' => ['before' => '', 'after' => 'Better examples']], [], ''), 'documentation changes do not consume an engine version');
    assertTrueValue(ReleasePolicy::check('0.2.0', '0.2.1', ['schemas/portable-cms-profile-v4.schema.json' => ['before' => null, 'after' => '{}']], [], "## 0.2.1\n") !== [], 'structural manifest/package changes need a minor release');
};

$failed = 0;
foreach ($tests as $name => $test) {
    try {
        $test();
        fwrite(STDOUT, 'PASS ' . $name . "\n");
    } catch (Throwable $error) {
        $failed++;
        fwrite(STDERR, 'FAIL ' . $name . ': ' . $error->getMessage() . "\n");
    }
}
exit($failed === 0 ? 0 : 1);
