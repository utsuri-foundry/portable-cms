<?php

declare(strict_types=1);

use Utsuri\PortableCms\ArchitectureGate\ArchitectureScanner;
use Utsuri\PortableCms\ArchitectureGate\PolicyLoader;

require_once __DIR__ . '/architecture-gate/policy.php';
require_once __DIR__ . '/architecture-gate/scanner.php';

$options = getopt('', ['root::', 'policy::', 'exceptions::', 'today::', 'json']);
$root = is_string($options['root'] ?? null) ? $options['root'] : dirname(__DIR__);
$policyPath = is_string($options['policy'] ?? null)
    ? $options['policy']
    : rtrim($root, DIRECTORY_SEPARATOR) . '/architecture.json';
$exceptionsPath = is_string($options['exceptions'] ?? null)
    ? $options['exceptions']
    : rtrim($root, DIRECTORY_SEPARATOR) . '/architecture-exceptions.json';
$today = is_string($options['today'] ?? null) ? $options['today'] : gmdate('Y-m-d');
$json = array_key_exists('json', $options);

try {
    $configuration = PolicyLoader::load($policyPath, $exceptionsPath);
    $report = (new ArchitectureScanner($root, $configuration, $today))->run();
    if ($json) {
        $findings = array_map(
            static fn($finding): array => [
                'severity' => $finding->severity,
                'code' => $finding->code,
                'path' => $finding->path,
                'message' => $finding->message,
            ],
            $report->findings(),
        );
        fwrite(STDOUT, json_encode([
            'schema' => 1,
            'ok' => $report->errorCount() === 0,
            'errors' => $report->errorCount(),
            'warnings' => $report->warningCount(),
            'findings' => $findings,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    } else {
        foreach ($report->findings() as $finding) {
            fwrite(STDOUT, sprintf(
                "%s %s %s: %s\n",
                strtoupper($finding->severity),
                $finding->code,
                $finding->path,
                $finding->message,
            ));
        }
        fwrite(STDOUT, sprintf(
            "Architecture gate: %d error(s), %d warning(s).\n",
            $report->errorCount(),
            $report->warningCount(),
        ));
    }
    exit($report->errorCount() === 0 ? 0 : 1);
} catch (Throwable $error) {
    if ($json) {
        fwrite(STDOUT, json_encode([
            'schema' => 1,
            'ok' => false,
            'errors' => 1,
            'warnings' => 0,
            'findings' => [[
                'severity' => 'error',
                'code' => 'configuration',
                'path' => '',
                'message' => $error->getMessage(),
            ]],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    } else {
        fwrite(STDERR, "Architecture gate configuration failed: {$error->getMessage()}\n");
    }
    exit(1);
}
