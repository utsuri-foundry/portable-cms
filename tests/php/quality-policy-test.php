<?php

declare(strict_types=1);

use Utsuri\PortableCms\QualityGate\QualityException;
use Utsuri\PortableCms\QualityGate\QualityFinding;
use Utsuri\PortableCms\QualityGate\QualityPolicy;
use Utsuri\PortableCms\QualityGate\QualityScanner;

require_once dirname(__DIR__, 2) . '/tools/quality-gate/scanner.php';
require_once dirname(__DIR__, 2) . '/tools/quality-gate/policy.php';
require_once __DIR__ . '/assertions.php';

function qualityFixture(): string
{
    $root = sys_get_temp_dir() . '/portable-cms-quality-' . getmypid() . '-' . bin2hex(random_bytes(6));
    foreach (['engine', 'profiles', 'tools', 'tests', 'tasks/projects/fixture/tasks'] as $directory) {
        if (!mkdir($root . '/' . $directory, 0700, true) && !is_dir($root . '/' . $directory)) {
            throw new RuntimeException('Could not create quality fixture');
        }
    }
    qualityWrite($root, 'phpstan.neon.dist', "parameters:\n    level: 6\n");
    qualityWrite(
        $root,
        'tasks/projects/fixture/tasks/remove-suppression.task.md',
        "+++\nid = \"remove-suppression\"\nstate = \"ready\"\n+++\n",
    );
    return $root;
}

function qualityWrite(string $root, string $path, string $contents): void
{
    $absolute = $root . '/' . $path;
    $parent = dirname($absolute);
    if (!is_dir($parent) && !mkdir($parent, 0700, true) && !is_dir($parent)) {
        throw new RuntimeException('Could not create quality fixture directory');
    }
    if (file_put_contents($absolute, $contents, LOCK_EX) === false) {
        throw new RuntimeException('Could not write quality fixture');
    }
}

function removeQualityFixture(string $root): void
{
    $prefix = rtrim(sys_get_temp_dir(), '/') . '/portable-cms-quality-';
    if (!str_starts_with($root, $prefix) || !is_dir($root)) {
        throw new RuntimeException('Refusing to remove an unexpected quality fixture');
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($root);
}

/** @param list<QualityFinding> $findings */
function hasQualityFinding(array $findings, string $rule): bool
{
    foreach ($findings as $finding) {
        if ($finding->rule === $rule) {
            return true;
        }
    }
    return false;
}

$tests = [];

$tests['quality scanner rejects missing strict types and production error suppression'] = static function (): void {
    $root = qualityFixture();
    try {
        qualityWrite($root, 'engine/unsafe.php', "<?php\n@unlink('/tmp/not-real');\n");
        qualityWrite($root, 'tools/unsafe.php', "<?php\ndeclare(strict_types=1);\n@unlink('/tmp/not-real');\n");
        $findings = (new QualityScanner())->scan($root);
        assertTrueValue(hasQualityFinding($findings, 'php.strict-types'), 'missing strict types must fail');
        assertTrueValue(hasQualityFinding($findings, 'php.error-suppression'), 'production error suppression must fail');
        assertTrueValue(count(array_filter($findings, static fn(QualityFinding $finding): bool => $finding->rule === 'php.error-suppression')) === 2, 'Operator tools are production suppression boundaries too');
    } finally {
        removeQualityFixture($root);
    }
};

$tests['quality scanner rejects browser escape hatches and analyzer directives'] = static function (): void {
    $root = qualityFixture();
    try {
        $unsafe = 'ev' . "al('bad');\n" . '// biome-' . "ignore lint: hidden\n";
        qualityWrite($root, 'engine/unsafe.js', $unsafe);
        $findings = (new QualityScanner())->scan($root);
        assertTrueValue(hasQualityFinding($findings, 'javascript.eval'), 'eval must fail');
        assertTrueValue(hasQualityFinding($findings, 'analysis.suppression'), 'analyzer directive must fail');
    } finally {
        removeQualityFixture($root);
    }
};

$tests['quality scanner rejects broad PHPStan baselines'] = static function (): void {
    $root = qualityFixture();
    try {
        qualityWrite($root, 'phpstan.neon.dist', "parameters:\n    ignoreErrors: []\n");
        $findings = (new QualityScanner())->scan($root);
        assertTrueValue(hasQualityFinding($findings, 'analysis.broad-baseline'), 'ignoreErrors must fail');
    } finally {
        removeQualityFixture($root);
    }
};

$tests['quality exceptions are exact expiring and task-backed'] = static function (): void {
    $root = qualityFixture();
    try {
        qualityWrite($root, 'engine/legacy.php', "<?php\ndeclare(strict_types=1);\n@unlink('/tmp/not-real');\n");
        $findings = (new QualityScanner())->scan($root);
        $suppression = array_values(array_filter(
            $findings,
            static fn(QualityFinding $finding): bool => $finding->rule === 'php.error-suppression',
        ))[0];
        $exception = new QualityException(
            'engine/legacy.php',
            'php.error-suppression',
            [$suppression->fingerprint()],
            'fixture',
            'Exact fixture exception.',
            new DateTimeImmutable('2026-10-01'),
            new DateTimeImmutable('2026-10-31'),
            'remove-suppression',
        );
        $remaining = (new QualityPolicy())->apply(
            $findings,
            [$exception],
            $root,
            new DateTimeImmutable('2026-10-02'),
        );
        assertSameValue([], $remaining, 'exact exception should consume exactly one finding');

        try {
            (new QualityPolicy())->apply(
                $findings,
                [$exception],
                $root,
                new DateTimeImmutable('2026-11-01'),
            );
            throw new RuntimeException('expired exception unexpectedly passed');
        } catch (RuntimeException $error) {
            assertTrueValue(str_contains($error->getMessage(), 'expired'), 'expiry failure should be explicit');
        }
    } finally {
        removeQualityFixture($root);
    }
};

$tests['quality policy rejects unused exception fingerprints'] = static function (): void {
    $root = qualityFixture();
    try {
        qualityWrite($root, 'engine/safe.php', "<?php\ndeclare(strict_types=1);\n");
        $exception = new QualityException(
            'engine/safe.php',
            'php.error-suppression',
            [str_repeat('a', 64)],
            'fixture',
            'Exact fixture exception.',
            new DateTimeImmutable('2026-10-01'),
            new DateTimeImmutable('2026-10-31'),
            'remove-suppression',
        );
        $remaining = (new QualityPolicy())->apply(
            (new QualityScanner())->scan($root),
            [$exception],
            $root,
            new DateTimeImmutable('2026-10-02'),
        );
        assertTrueValue(hasQualityFinding($remaining, 'quality.unused-exception'), 'unused exception must fail');
    } finally {
        removeQualityFixture($root);
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

fwrite(STDOUT, sprintf("%d quality policy tests, %d failures\n", count($tests), $failures));
exit($failures === 0 ? 0 : 1);
