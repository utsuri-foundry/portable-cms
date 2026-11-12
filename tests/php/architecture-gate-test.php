<?php

declare(strict_types=1);

use Utsuri\PortableCms\ArchitectureGate\ArchitectureScanner;
use Utsuri\PortableCms\ArchitectureGate\GateReport;
use Utsuri\PortableCms\ArchitectureGate\PolicyLoader;

require_once dirname(__DIR__, 2) . '/tools/architecture-gate/policy.php';
require_once dirname(__DIR__, 2) . '/tools/architecture-gate/scanner.php';
require_once __DIR__ . '/assertions.php';

/** @return array{root: string, policy: array<string, mixed>, exceptions: array<string, mixed>} */
function architectureFixture(): array
{
    $root = sys_get_temp_dir() . '/portable-cms-architecture-' . getmypid() . '-' . bin2hex(random_bytes(6));
    foreach (['src/a', 'src/b', 'tests', 'tasks/projects/fixture/tasks'] as $directory) {
        if (!mkdir($root . '/' . $directory, 0700, true) && !is_dir($root . '/' . $directory)) {
            throw new RuntimeException('Could not create architecture fixture');
        }
    }
    fixtureWrite($root, 'README.md', "# Fixture\n");
    fixtureWrite($root, 'src/a/item.js', "export const item = 1;\n");
    fixtureWrite($root, 'src/b/item.js', "export const other = 2;\n");
    fixtureWrite($root, 'tests/check.sh', "#!/bin/sh\ntrue\n");

    return [
        'root' => $root,
        'policy' => [
            'schema' => 1,
            'sourceRoots' => ['src', 'tests'],
            'sourceExtensions' => ['php', 'js', 'sh'],
            'ignoredDirectories' => ['vendor'],
            'layers' => [
                [
                    'name' => 'a',
                    'paths' => ['src/a'],
                    'mayDependOn' => [],
                    'namespacePrefixes' => ['Fixture\\A\\'],
                ],
                [
                    'name' => 'b',
                    'paths' => ['src/b'],
                    'mayDependOn' => [],
                    'namespacePrefixes' => ['Fixture\\B\\'],
                ],
                [
                    'name' => 'tests',
                    'paths' => ['tests'],
                    'mayDependOn' => ['a', 'b'],
                    'namespacePrefixes' => [],
                ],
            ],
            'sourceBudgets' => [
                'production' => ['warning' => 3, 'maximum' => 5],
                'facade' => ['warning' => 2, 'maximum' => 4],
                'test' => ['warning' => 5, 'maximum' => 8],
            ],
            'classification' => [
                'testPathPrefixes' => ['tests'],
                'facadeFiles' => ['src/a/index.php'],
                'facadeDirectories' => [],
            ],
            'forbiddenModuleNames' => ['common', 'helpers', 'misc', 'utils'],
            'requiredDocuments' => ['README.md'],
        ],
        'exceptions' => ['schema' => 1, 'exceptions' => []],
    ];
}

function fixtureWrite(string $root, string $path, string $bytes): void
{
    $absolute = $root . '/' . $path;
    $parent = dirname($absolute);
    if (!is_dir($parent) && !mkdir($parent, 0700, true) && !is_dir($parent)) {
        throw new RuntimeException("Could not create fixture directory {$path}");
    }
    if (file_put_contents($absolute, $bytes, LOCK_EX) === false) {
        throw new RuntimeException("Could not write fixture {$path}");
    }
}

/**
 * @param array<string, mixed> $policy
 * @param array<string, mixed> $exceptions
 */
function scanArchitecture(string $root, array $policy, array $exceptions): GateReport
{
    fixtureWrite($root, 'architecture.json', json_encode($policy, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
    fixtureWrite(
        $root,
        'architecture-exceptions.json',
        json_encode($exceptions, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n",
    );
    $configuration = PolicyLoader::load(
        $root . '/architecture.json',
        $root . '/architecture-exceptions.json',
    );
    return (new ArchitectureScanner($root, $configuration, '2026-10-02'))->run();
}

function fixtureLines(int $count): string
{
    return implode("\n", array_fill(0, $count, '// line')) . "\n";
}

function hasArchitectureFinding(GateReport $report, string $code, ?string $path = null): bool
{
    foreach ($report->findings() as $finding) {
        if ($finding->code === $code && ($path === null || $finding->path === $path)) {
            return true;
        }
    }
    return false;
}

function removeArchitectureFixture(string $root): void
{
    $prefix = rtrim(sys_get_temp_dir(), '/') . '/portable-cms-architecture-';
    if (!str_starts_with($root, $prefix) || !is_dir($root)) {
        throw new RuntimeException('Refusing to remove an unexpected fixture path');
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $entry) {
        if ($entry->isLink() || $entry->isFile()) {
            unlink($entry->getPathname());
        } else {
            rmdir($entry->getPathname());
        }
    }
    rmdir($root);
}

$tests = [];

$tests['architecture gate accepts a bounded owned fixture'] = static function (): void {
    $fixture = architectureFixture();
    try {
        $report = scanArchitecture($fixture['root'], $fixture['policy'], $fixture['exceptions']);
        assertSameValue(0, $report->errorCount(), 'bounded fixture should pass');
        assertSameValue(0, $report->warningCount(), 'bounded fixture should not warn');
    } finally {
        removeArchitectureFixture($fixture['root']);
    }
};

$tests['architecture gate warns above the review threshold'] = static function (): void {
    $fixture = architectureFixture();
    try {
        fixtureWrite($fixture['root'], 'src/a/item.js', fixtureLines(4));
        $report = scanArchitecture($fixture['root'], $fixture['policy'], $fixture['exceptions']);
        assertSameValue(0, $report->errorCount(), 'warning-sized source should not fail');
        assertTrueValue(
            hasArchitectureFinding($report, 'source-size-warning', 'src/a/item.js'),
            'warning-sized source should be visible',
        );
    } finally {
        removeArchitectureFixture($fixture['root']);
    }
};

$tests['architecture gate fails above the source maximum'] = static function (): void {
    $fixture = architectureFixture();
    try {
        fixtureWrite($fixture['root'], 'src/a/item.js', fixtureLines(6));
        $report = scanArchitecture($fixture['root'], $fixture['policy'], $fixture['exceptions']);
        assertTrueValue(
            hasArchitectureFinding($report, 'source-size-maximum', 'src/a/item.js'),
            'oversized source must fail',
        );
    } finally {
        removeArchitectureFixture($fixture['root']);
    }
};

$tests['architecture gate accepts a bounded expiring exception with an open removal task'] = static function (): void {
    $fixture = architectureFixture();
    try {
        fixtureWrite($fixture['root'], 'src/a/item.js', fixtureLines(6));
        fixtureWrite(
            $fixture['root'],
            'tasks/projects/fixture/tasks/split-item.task.md',
            "+++\nid = \"split-item\"\nstate = \"ready\"\n+++\n",
        );
        $fixture['exceptions']['exceptions'][] = [
            'path' => 'src/a/item.js',
            'rule' => 'source-size',
            'ceiling' => 7,
            'owner' => 'fixture',
            'reason' => 'Temporary migration seam.',
            'created' => '2026-10-01',
            'expires' => '2026-10-31',
            'removalTask' => 'split-item',
        ];
        $report = scanArchitecture($fixture['root'], $fixture['policy'], $fixture['exceptions']);
        assertSameValue(0, $report->errorCount(), 'valid size exception should prevent failure');
        assertTrueValue(
            hasArchitectureFinding($report, 'source-size-exception', 'src/a/item.js'),
            'active exception must stay visible as a warning',
        );
    } finally {
        removeArchitectureFixture($fixture['root']);
    }
};

$tests['architecture gate rejects an expired exception'] = static function (): void {
    $fixture = architectureFixture();
    try {
        fixtureWrite($fixture['root'], 'src/a/item.js', fixtureLines(6));
        fixtureWrite(
            $fixture['root'],
            'tasks/projects/fixture/tasks/split-item.task.md',
            "+++\nid = \"split-item\"\nstate = \"ready\"\n+++\n",
        );
        $fixture['exceptions']['exceptions'][] = [
            'path' => 'src/a/item.js',
            'rule' => 'source-size',
            'ceiling' => 7,
            'owner' => 'fixture',
            'reason' => 'Expired migration seam.',
            'created' => '2026-09-01',
            'expires' => '2026-10-01',
            'removalTask' => 'split-item',
        ];
        $report = scanArchitecture($fixture['root'], $fixture['policy'], $fixture['exceptions']);
        assertTrueValue(
            hasArchitectureFinding($report, 'expired-exception', 'src/a/item.js'),
            'expired exception must fail independently',
        );
        assertTrueValue(
            hasArchitectureFinding($report, 'source-size-maximum', 'src/a/item.js'),
            'expired exception must not suppress the source maximum',
        );
    } finally {
        removeArchitectureFixture($fixture['root']);
    }
};

$tests['architecture ownership respects path boundaries'] = static function (): void {
    $fixture = architectureFixture();
    try {
        fixtureWrite($fixture['root'], 'src/a-sibling/item.js', "export const rogue = true;\n");
        $report = scanArchitecture($fixture['root'], $fixture['policy'], $fixture['exceptions']);
        assertTrueValue(
            hasArchitectureFinding($report, 'unowned-source', 'src/a-sibling/item.js'),
            'a sibling prefix must not inherit ownership accidentally',
        );
    } finally {
        removeArchitectureFixture($fixture['root']);
    }
};

$tests['architecture gate rejects missing required area contracts'] = static function (): void {
    $fixture = architectureFixture();
    try {
        unlink($fixture['root'] . '/README.md');
        $report = scanArchitecture($fixture['root'], $fixture['policy'], $fixture['exceptions']);
        assertTrueValue(
            hasArchitectureFinding($report, 'required-document', 'README.md'),
            'missing required document must fail',
        );
    } finally {
        removeArchitectureFixture($fixture['root']);
    }
};

$tests['architecture gate scans outside declared roots and ignores vendored source'] = static function (): void {
    $fixture = architectureFixture();
    try {
        fixtureWrite($fixture['root'], 'rogue.php', "<?php\n");
        fixtureWrite($fixture['root'], 'vendor/dependency.php', "<?php\n");
        $report = scanArchitecture($fixture['root'], $fixture['policy'], $fixture['exceptions']);
        assertTrueValue(
            hasArchitectureFinding($report, 'unowned-source', 'rogue.php'),
            'source outside declared roots must still fail as unowned',
        );
        assertTrueValue(
            !hasArchitectureFinding($report, 'unowned-source', 'vendor/dependency.php'),
            'vendored source must be ignored deliberately',
        );
    } finally {
        removeArchitectureFixture($fixture['root']);
    }
};

$tests['architecture gate rejects a forbidden JavaScript dependency'] = static function (): void {
    $fixture = architectureFixture();
    try {
        fixtureWrite($fixture['root'], 'src/a/item.js', "import \"../b/item.js\";\nexport const item = 1;\n");
        $report = scanArchitecture($fixture['root'], $fixture['policy'], $fixture['exceptions']);
        assertTrueValue(
            hasArchitectureFinding($report, 'forbidden-dependency', 'src/a/item.js'),
            'forbidden layer dependency must fail',
        );
    } finally {
        removeArchitectureFixture($fixture['root']);
    }
};

$tests['architecture gate rejects forbidden PHP namespace and include dependencies'] = static function (): void {
    $fixture = architectureFixture();
    try {
        fixtureWrite($fixture['root'], 'src/b/item.php', "<?php\nnamespace Fixture\\B;\nfinal class Item {}\n");
        fixtureWrite(
            $fixture['root'],
            'src/a/item.php',
            "<?php\nuse Fixture\\B\\Item;\nrequire_once __DIR__ . '/../b/item.php';\n",
        );
        $report = scanArchitecture($fixture['root'], $fixture['policy'], $fixture['exceptions']);
        assertTrueValue(
            hasArchitectureFinding($report, 'forbidden-dependency', 'src/a/item.php'),
            'forbidden PHP dependency must fail',
        );
    } finally {
        removeArchitectureFixture($fixture['root']);
    }
};

$tests['architecture exception requires a nonterminal removal task'] = static function (): void {
    $fixture = architectureFixture();
    try {
        fixtureWrite($fixture['root'], 'src/a/item.js', fixtureLines(6));
        fixtureWrite(
            $fixture['root'],
            'tasks/projects/fixture/tasks/split-item.task.md',
            "+++\nid = \"split-item\"\nstate = \"done\"\n+++\n",
        );
        $fixture['exceptions']['exceptions'][] = [
            'path' => 'src/a/item.js',
            'rule' => 'source-size',
            'ceiling' => 7,
            'owner' => 'fixture',
            'reason' => 'The task cannot already be terminal.',
            'created' => '2026-10-01',
            'expires' => '2026-10-31',
            'removalTask' => 'split-item',
        ];
        $report = scanArchitecture($fixture['root'], $fixture['policy'], $fixture['exceptions']);
        assertTrueValue(
            hasArchitectureFinding($report, 'exception-removal-task', 'src/a/item.js'),
            'terminal removal task must invalidate the exception',
        );
    } finally {
        removeArchitectureFixture($fixture['root']);
    }
};

$tests['architecture gate rejects generic dumping-ground modules'] = static function (): void {
    $fixture = architectureFixture();
    try {
        fixtureWrite($fixture['root'], 'src/a/utils.js', "export const hiddenOwnership = true;\n");
        $report = scanArchitecture($fixture['root'], $fixture['policy'], $fixture['exceptions']);
        assertTrueValue(
            hasArchitectureFinding($report, 'generic-module', 'src/a/utils.js'),
            'generic module name must fail',
        );
    } finally {
        removeArchitectureFixture($fixture['root']);
    }
};

$failures = 0;
foreach ($tests as $name => $test) {
    try {
        $test();
        fwrite(STDOUT, "PASS {$name}\n");
    } catch (Throwable $error) {
        $failures++;
        fwrite(STDERR, "FAIL {$name}\n{$error->getMessage()}\n");
    }
}

fwrite(STDOUT, sprintf("%d architecture gate tests, %d failures\n", count($tests), $failures));
exit($failures === 0 ? 0 : 1);
