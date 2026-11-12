<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Database\MigrationRunner;
use Utsuri\PortableCms\Conventional\RuntimeConfiguration;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/engine/src/cms/conventional/bootstrap.php';

$options = getopt('', ['config:', 'help']);
if (isset($options['help'])) {
    fwrite(STDOUT, "Usage: php tools/conventional-migrate.php --config=/absolute/private/cms.json\n");
    exit(0);
}
$configPath = is_string($options['config'] ?? null) ? $options['config'] : '';
if ($configPath === '') {
    fwrite(STDERR, "A private configuration path is required.\n");
    exit(64);
}

try {
    $runtimeLease = \Utsuri\PortableCms\Conventional\Upgrade\RuntimeLease::guard($configPath, dirname(__DIR__));
    $configuration = RuntimeConfiguration::load($configPath);
    $paths = glob(dirname(__DIR__) . '/engine/migrations/*.sql');
    if ($paths === false) {
        throw new RuntimeException('Migration directory could not be read');
    }
    sort($paths, SORT_STRING);
    $result = (new MigrationRunner($configuration->connect()))->apply($paths);
    fwrite(STDOUT, sprintf("Migrations applied: %d; already complete: %d.\n", $result->applied, $result->skipped));
} catch (Throwable $error) {
    fwrite(STDERR, 'Migration failed (' . $error::class . ").\n");
    exit(1);
}
