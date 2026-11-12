<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\ArchitectureGate;

use DateTimeImmutable;
use RuntimeException;

final readonly class GateFinding
{
    public function __construct(
        public string $severity,
        public string $code,
        public string $path,
        public string $message,
    ) {}
}

final class GateReport
{
    /** @var list<GateFinding> */
    private array $findings = [];

    public function add(string $severity, string $code, string $path, string $message): void
    {
        $this->findings[] = new GateFinding($severity, $code, $path, $message);
    }

    /** @return list<GateFinding> */
    public function findings(): array
    {
        $findings = $this->findings;
        usort($findings, static function (GateFinding $left, GateFinding $right): int {
            $severity = ['error' => 0, 'warning' => 1];
            return [$severity[$left->severity], $left->path, $left->code, $left->message]
                <=> [$severity[$right->severity], $right->path, $right->code, $right->message];
        });
        return $findings;
    }

    public function errorCount(): int
    {
        return count(array_filter(
            $this->findings,
            static fn(GateFinding $finding): bool => $finding->severity === 'error',
        ));
    }

    public function warningCount(): int
    {
        return count(array_filter(
            $this->findings,
            static fn(GateFinding $finding): bool => $finding->severity === 'warning',
        ));
    }
}

final class ArchitectureScanner
{
    private string $root;

    public function __construct(
        string $root,
        private readonly ArchitectureConfiguration $configuration,
        private readonly string $today,
    ) {
        $resolved = realpath($root);
        if ($resolved === false || !is_dir($resolved)) {
            throw new RuntimeException('Architecture root must be an existing directory');
        }
        if (!$this->validDate($today)) {
            throw new RuntimeException('Architecture gate date must use YYYY-MM-DD');
        }
        $this->root = rtrim(str_replace('\\', '/', $resolved), '/');
    }

    public function run(): GateReport
    {
        $report = new GateReport();
        $this->checkRequiredDocuments($report);
        $this->checkLayerDirectories($report);
        $sources = $this->sourceFiles($report);
        $owners = [];
        foreach ($sources as $source) {
            $owner = $this->ownerFor($source, $report);
            if ($owner !== null) {
                $owners[$source] = $owner;
            }
        }
        $activeExceptions = $this->activeExceptions($sources, $report);
        foreach ($sources as $source) {
            $this->checkModuleName($source, $report);
            $this->checkSourceBudget($source, $activeExceptions, $report);
        }
        foreach ($owners as $source => $owner) {
            $this->checkDependencies($source, $owner, $report);
        }
        return $report;
    }

    private function checkRequiredDocuments(GateReport $report): void
    {
        foreach ($this->configuration->policy->requiredDocuments as $path) {
            if (!is_file($this->absolute($path)) || is_link($this->absolute($path))) {
                $report->add('error', 'required-document', $path, 'required document is missing');
            }
        }
    }

    private function checkLayerDirectories(GateReport $report): void
    {
        foreach ($this->configuration->policy->layers as $layer) {
            foreach ($layer->paths as $path) {
                if (!is_dir($this->absolute($path)) || is_link($this->absolute($path))) {
                    $report->add(
                        'error',
                        'layer-path',
                        $path,
                        "layer {$layer->name} owns a directory that does not exist",
                    );
                }
            }
        }
    }

    /** @return list<string> */
    private function sourceFiles(GateReport $report): array
    {
        $sources = [];
        foreach ($this->configuration->policy->sourceRoots as $root) {
            $absolute = $this->absolute($root);
            if (!is_dir($absolute)) {
                $report->add('error', 'source-root', $root, 'source root does not exist');
            } elseif (is_link($absolute)) {
                $report->add('error', 'source-root', $root, 'source root must not be a symbolic link');
            }
        }
        $this->visit($this->root, '', $sources);
        $sources = array_values(array_unique($sources, SORT_STRING));
        sort($sources, SORT_STRING);
        return $sources;
    }

    /** @param list<string> $sources */
    private function visit(string $directory, string $relative, array &$sources): void
    {
        $entries = scandir($directory);
        if ($entries === false) {
            throw new RuntimeException("Could not read source directory {$relative}");
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $absolute = $directory . '/' . $entry;
            if (is_link($absolute)) {
                continue;
            }
            $path = $relative === '' ? $entry : $relative . '/' . $entry;
            if (is_dir($absolute)) {
                if (in_array($entry, $this->configuration->policy->ignoredDirectories, true)) {
                    continue;
                }
                $this->visit($absolute, $path, $sources);
                continue;
            }
            if (!is_file($absolute)) {
                continue;
            }
            $extension = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
            if (in_array($extension, $this->configuration->policy->sourceExtensions, true)) {
                $sources[] = $path;
            }
        }
    }

    private function ownerFor(string $path, GateReport $report): ?LayerPolicy
    {
        $matches = [];
        $longest = -1;
        foreach ($this->configuration->policy->layers as $layer) {
            foreach ($layer->paths as $prefix) {
                if (!$this->pathOwnedBy($path, $prefix)) {
                    continue;
                }
                $length = strlen($prefix);
                if ($length > $longest) {
                    $matches = [$layer->name => $layer];
                    $longest = $length;
                } elseif ($length === $longest) {
                    $matches[$layer->name] = $layer;
                }
            }
        }
        if ($matches === []) {
            $report->add('error', 'unowned-source', $path, 'source file has no architecture owner');
            return null;
        }
        if (count($matches) > 1) {
            $report->add('error', 'ambiguous-owner', $path, 'source file has several equally specific owners');
            return null;
        }
        return array_values($matches)[0];
    }

    /**
     * @param list<string> $sources
     * @return array<string, PolicyException>
     */
    private function activeExceptions(array $sources, GateReport $report): array
    {
        $active = [];
        foreach ($this->configuration->exceptions->exceptions as $exception) {
            $valid = true;
            if (!$this->validDate($exception->created) || !$this->validDate($exception->expires)) {
                $report->add('error', 'exception-date', $exception->path, 'exception dates must be real YYYY-MM-DD dates');
                $valid = false;
            } elseif ($exception->created > $exception->expires) {
                $report->add('error', 'exception-date-order', $exception->path, 'exception expiry precedes creation');
                $valid = false;
            } elseif ($exception->created > $this->today) {
                $report->add('error', 'future-exception', $exception->path, 'exception creation date is in the future');
                $valid = false;
            } elseif ($exception->expires < $this->today) {
                $report->add('error', 'expired-exception', $exception->path, "exception expired on {$exception->expires}");
                $valid = false;
            }
            if (!in_array($exception->path, $sources, true)) {
                $report->add('error', 'exception-path', $exception->path, 'exception must name an existing source file');
                $valid = false;
            } else {
                $class = $this->sourceClass($exception->path);
                $maximum = $this->configuration->policy->sourceBudgets[$class]->maximum;
                if ($exception->ceiling <= $maximum) {
                    $report->add(
                        'error',
                        'exception-ceiling',
                        $exception->path,
                        "exception ceiling must exceed the {$class} maximum {$maximum}",
                    );
                    $valid = false;
                }
                $lineCount = $this->lineCount($exception->path);
                if ($lineCount <= $maximum) {
                    $report->add(
                        'error',
                        'unnecessary-exception',
                        $exception->path,
                        "source has {$lineCount} lines and does not exceed the {$class} maximum {$maximum}",
                    );
                    $valid = false;
                } elseif ($lineCount > $exception->ceiling) {
                    $report->add(
                        'error',
                        'exception-ceiling-exceeded',
                        $exception->path,
                        "source has {$lineCount} lines above exception ceiling {$exception->ceiling}",
                    );
                    $valid = false;
                }
            }
            if (!$this->hasOpenRemovalTask($exception->removalTask)) {
                $report->add(
                    'error',
                    'exception-removal-task',
                    $exception->path,
                    "exception removal task {$exception->removalTask} is missing or terminal",
                );
                $valid = false;
            }
            if ($valid) {
                $active[$exception->path . ':' . $exception->rule] = $exception;
            }
        }
        return $active;
    }

    private function hasOpenRemovalTask(string $taskId): bool
    {
        if (!preg_match('/^[a-z][a-z0-9-]*$/', $taskId)) {
            return false;
        }
        $matches = glob($this->root . '/tasks/projects/*/tasks/' . $taskId . '.task.md');
        if ($matches === false || count($matches) !== 1) {
            return false;
        }
        $contents = file_get_contents($matches[0]);
        if ($contents === false || !preg_match('/^state\s*=\s*"([a-z]+)"\s*$/m', $contents, $state)) {
            return false;
        }
        return !in_array($state[1], ['done', 'cancelled'], true);
    }

    private function checkModuleName(string $path, GateReport $report): void
    {
        $parts = explode('/', $path);
        $last = array_pop($parts);
        if ($last !== null) {
            $parts[] = pathinfo($last, PATHINFO_FILENAME);
        }
        foreach ($parts as $part) {
            if (in_array(strtolower($part), $this->configuration->policy->forbiddenModuleNames, true)) {
                $report->add(
                    'error',
                    'generic-module',
                    $path,
                    "generic module name {$part} hides ownership; use a domain-specific name",
                );
                return;
            }
        }
    }

    /** @param array<string, PolicyException> $activeExceptions */
    private function checkSourceBudget(string $path, array $activeExceptions, GateReport $report): void
    {
        $lines = $this->lineCount($path);
        $class = $this->sourceClass($path);
        $budget = $this->configuration->policy->sourceBudgets[$class];
        if ($lines > $budget->maximum) {
            $key = $path . ':source-size';
            $exception = $activeExceptions[$key] ?? null;
            if ($exception !== null && $lines <= $exception->ceiling) {
                $report->add(
                    'warning',
                    'source-size-exception',
                    $path,
                    "{$lines} lines use exception ceiling {$exception->ceiling} until {$exception->expires}",
                );
                return;
            }
            $report->add(
                'error',
                'source-size-maximum',
                $path,
                "{$lines} lines exceed the {$class} maximum {$budget->maximum}",
            );
        } elseif ($lines > $budget->warning) {
            $report->add(
                'warning',
                'source-size-warning',
                $path,
                "{$lines} lines exceed the {$class} review threshold {$budget->warning}",
            );
        }
    }

    private function sourceClass(string $path): string
    {
        foreach ($this->configuration->policy->classification->testPathPrefixes as $prefix) {
            if ($this->pathOwnedBy($path, $prefix)) {
                return 'test';
            }
        }
        if (in_array($path, $this->configuration->policy->classification->facadeFiles, true)) {
            return 'facade';
        }
        $directory = str_replace('\\', '/', dirname($path));
        if (in_array($directory, $this->configuration->policy->classification->facadeDirectories, true)) {
            return 'facade';
        }
        return 'production';
    }

    private function checkDependencies(string $source, LayerPolicy $sourceLayer, GateReport $report): void
    {
        $bytes = file_get_contents($this->absolute($source));
        if ($bytes === false) {
            throw new RuntimeException("Could not read source file {$source}");
        }
        $targets = [];
        $extension = strtolower(pathinfo($source, PATHINFO_EXTENSION));
        if ($extension === 'js' || $extension === 'mjs') {
            $targets = array_merge($targets, $this->javascriptTargets($source, $bytes));
        } elseif ($extension === 'php') {
            $targets = array_merge($targets, $this->phpPathTargets($source, $bytes));
            $targets = array_merge($targets, $this->phpNamespaceTargets($bytes));
        }

        $reported = [];
        foreach ($targets as $target) {
            $targetLayer = $target['layer'];
            if (
                $targetLayer->name === $sourceLayer->name
                || in_array($targetLayer->name, $sourceLayer->mayDependOn, true)
            ) {
                continue;
            }
            $key = $targetLayer->name . ':' . $target['reference'];
            if (isset($reported[$key])) {
                continue;
            }
            $reported[$key] = true;
            $report->add(
                'error',
                'forbidden-dependency',
                $source,
                "layer {$sourceLayer->name} may not depend on {$targetLayer->name} via {$target['reference']}",
            );
        }
    }

    /** @return list<array{layer: LayerPolicy, reference: string}> */
    private function javascriptTargets(string $source, string $bytes): array
    {
        preg_match_all(
            '/(?:\b(?:import|export)\s+(?:[^\'";]+?\s+from\s+)?|\bimport\s*\(\s*)[\'"]([^\'"]+)[\'"]/',
            $bytes,
            $matches,
        );
        $targets = [];
        foreach ($matches[1] as $specifier) {
            if (!str_starts_with($specifier, '.')) {
                continue;
            }
            $candidate = $this->normalize(dirname($source) . '/' . $specifier);
            foreach ([$candidate, $candidate . '.js', $candidate . '.mjs', $candidate . '/index.js'] as $path) {
                $layer = $this->layerForPath($path);
                if ($layer !== null) {
                    $targets[] = ['layer' => $layer, 'reference' => $specifier];
                    break;
                }
            }
        }
        return $targets;
    }

    /** @return list<array{layer: LayerPolicy, reference: string}> */
    private function phpPathTargets(string $source, string $bytes): array
    {
        $targets = [];
        $tokens = token_get_all($bytes);
        $includeTokens = [T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE];
        for ($index = 0, $count = count($tokens); $index < $count; $index++) {
            $token = $tokens[$index];
            if (!is_array($token) || !in_array($token[0], $includeTokens, true)) {
                continue;
            }
            $literal = null;
            for ($cursor = $index + 1; $cursor < $count; $cursor++) {
                $candidate = $tokens[$cursor];
                if ($candidate === ';') {
                    $index = $cursor;
                    break;
                }
                if (is_array($candidate) && $candidate[0] === T_CONSTANT_ENCAPSED_STRING) {
                    $literal = substr($candidate[1], 1, -1);
                }
            }
            if ($literal === null || $literal === '') {
                continue;
            }
            $rootCandidate = ltrim($literal, '/');
            $path = $this->layerForPath($rootCandidate) !== null
                ? $rootCandidate
                : $this->normalize(dirname($source) . '/' . ltrim($literal, '/'));
            $layer = $this->layerForPath($path);
            if ($layer !== null) {
                $targets[] = ['layer' => $layer, 'reference' => $literal];
            }
        }
        return $targets;
    }

    /** @return list<array{layer: LayerPolicy, reference: string}> */
    private function phpNamespaceTargets(string $bytes): array
    {
        $targets = [];
        foreach (token_get_all($bytes) as $token) {
            if (
                !is_array($token)
                || !in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
            ) {
                continue;
            }
            $name = ltrim($token[1], '\\');
            foreach ($this->configuration->policy->layers as $layer) {
                foreach ($layer->namespacePrefixes as $prefix) {
                    if (str_starts_with($name, $prefix)) {
                        $targets[] = ['layer' => $layer, 'reference' => $prefix];
                    }
                }
            }
        }
        return $targets;
    }

    private function layerForPath(string $path): ?LayerPolicy
    {
        $matches = [];
        $longest = -1;
        foreach ($this->configuration->policy->layers as $layer) {
            foreach ($layer->paths as $prefix) {
                if (!$this->pathOwnedBy($path, $prefix)) {
                    continue;
                }
                $length = strlen($prefix);
                if ($length > $longest) {
                    $matches = [$layer->name => $layer];
                    $longest = $length;
                } elseif ($length === $longest) {
                    $matches[$layer->name] = $layer;
                }
            }
        }
        return count($matches) === 1 ? array_values($matches)[0] : null;
    }

    private function pathOwnedBy(string $path, string $prefix): bool
    {
        return $path === $prefix || str_starts_with($path, $prefix . '/');
    }

    private function normalize(string $path): string
    {
        $parts = [];
        foreach (explode('/', str_replace('\\', '/', $path)) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($parts);
            } else {
                $parts[] = $part;
            }
        }
        return implode('/', $parts);
    }

    private function validDate(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }

    private function lineCount(string $path): int
    {
        $bytes = file_get_contents($this->absolute($path));
        if ($bytes === false) {
            throw new RuntimeException("Could not read source file {$path}");
        }
        return $bytes === '' ? 0 : substr_count($bytes, "\n") + (str_ends_with($bytes, "\n") ? 0 : 1);
    }

    private function absolute(string $relative): string
    {
        return $this->root . '/' . $relative;
    }
}
