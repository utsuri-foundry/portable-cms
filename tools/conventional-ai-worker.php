<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\ApplicationFactory;
use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Content\ProfilePackage;
use Utsuri\PortableCms\Conventional\RuntimeConfiguration;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/engine/src/cms/conventional/bootstrap.php';
$options = getopt('', ['config:', 'profile-root::', 'max-jobs::', 'help']);
if (isset($options['help'])) {
    fwrite(STDOUT, "Usage: php tools/conventional-ai-worker.php --config=/private/cms.json [--profile-root=/private/profile] [--max-jobs=1]\n");
    exit(0);
}
$path = getenv('CMS_ACTIVE_CONFIG') ?: ($options['config'] ?? '');
$maximum = filter_var($options['max-jobs'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 3]]);
if (!is_string($path) || $path === '' || !is_int($maximum)) {
    fwrite(STDERR, "A private configuration and bounded worker limit are required.\n");
    exit(64);
}
try {
    $runtimeLease ??= \Utsuri\PortableCms\Conventional\Upgrade\RuntimeLease::guard($path, dirname(__DIR__));
    $configuration = RuntimeConfiguration::load($path);
    $root = $options['profile-root'] ?? $configuration->profileRoot;
    $profile = is_string($root) ? ProfilePackage::load($root)->manifest : ProfileManifest::gateZero();
    $worker = ApplicationFactory::assistanceRunner($configuration, $profile);
    $processed = 0;
    while ($processed < $maximum && $worker->runOne()) {
        $processed++;
    }
    fwrite(STDOUT, 'AI worker processed ' . $processed . " job(s). Inspect private request status for outcomes.\n");
} catch (Throwable) {
    fwrite(STDERR, "AI worker could not complete safely.\n");
    exit(1);
}
