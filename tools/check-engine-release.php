<?php

declare(strict_types=1);

use Utsuri\PortableCms\ReleaseGate\ReleasePolicy;
use Utsuri\PortableCms\ReleaseGate\ReleaseRepository;

require_once __DIR__ . '/release-gate/api-surface.php';
require_once __DIR__ . '/release-gate/policy.php';
require_once __DIR__ . '/release-gate/repository.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$options = getopt('', ['root:', 'base:']);
$root = is_string($options['root'] ?? null) ? $options['root'] : dirname(__DIR__);
$base = is_string($options['base'] ?? null) ? $options['base'] : 'origin/main';
try {
    $repository = new ReleaseRepository($root);
    $base = trim($repository->git(['rev-parse', '--verify', $base . '^{commit}']));
    $before = ReleasePolicy::version($repository->git(['show', $base . ':' . ReleasePolicy::VERSION_PATH]));
    $after = ReleasePolicy::version((string) file_get_contents($root . '/' . ReleasePolicy::VERSION_PATH));
    $policyPath = 'engine-release-policy.json';
    $policy = json_decode((string) file_get_contents($root . '/' . $policyPath), true, 16, JSON_THROW_ON_ERROR);
    if (($policy['schema'] ?? null) !== 1 || !is_array($policy['profileApiPaths'] ?? null) || !array_is_list($policy['profileApiPaths'])) {
        throw new RuntimeException('Invalid engine release API registry');
    }
    foreach ($policy['profileApiPaths'] as $path) {
        if (!is_string($path) || preg_match('#\Aengine/src/cms/conventional/(?:[a-z0-9-]+/)*[a-z0-9-]+\.php\z#', $path) !== 1 || !is_file($root . '/' . $path)) {
            throw new RuntimeException('Profile API registry entries must name existing engine PHP modules');
        }
    }
    $changes = $repository->changes($base);
    $oldPolicy = isset($changes[$policyPath]['before']) ? json_decode($changes[$policyPath]['before'], true, 16, JSON_THROW_ON_ERROR) : $policy;
    $apiPaths = array_values(array_unique([...($oldPolicy['profileApiPaths'] ?? []), ...$policy['profileApiPaths']]));
    $errors = ReleasePolicy::check($before, $after, $changes, $apiPaths, (string) file_get_contents($root . '/CHANGELOG.md'));
    foreach ($errors as $error) {
        fwrite(STDERR, $error . "\n");
    }
    if ($errors !== []) {
        exit(1);
    }
    fwrite(STDOUT, "Engine release policy passed: {$before} -> {$after}.\n");
} catch (Throwable $error) {
    fwrite(STDERR, 'Engine release check failed: ' . $error->getMessage() . "\n");
    exit(1);
}
