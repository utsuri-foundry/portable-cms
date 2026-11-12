<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Database\MigrationRunner;
use Utsuri\PortableCms\Conventional\Identity\AdministratorBootstrap;
use Utsuri\PortableCms\Conventional\Identity\SystemClock;
use Utsuri\PortableCms\Conventional\Identity\SystemRandomSource;
use Utsuri\PortableCms\Conventional\RuntimeConfiguration;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$repository = dirname(__DIR__, 2);
require_once $repository . '/engine/src/cms/conventional/bootstrap.php';
require_once $repository . '/tools/development/reference-studio-seeder.php';
require_once $repository . '/tools/development/reference-media-seeder.php';

$options = getopt('', ['state:', 'socket:', 'port:', 'profile-root:', 'seed:']);
$state = is_string($options['state'] ?? null) ? $options['state'] : '';
$socket = is_string($options['socket'] ?? null) ? $options['socket'] : '';
$port = filter_var($options['port'] ?? null, FILTER_VALIDATE_INT);
// Any verified profile package may be served; only Reference Studio has a development seed.
$profileRoot = realpath(is_string($options['profile-root'] ?? null) ? $options['profile-root'] : $repository . '/profiles/reference-studio');
$seed = is_string($options['seed'] ?? null) ? $options['seed'] : 'reference';
if ($profileRoot === false || !is_file($profileRoot . '/profile.json') || !in_array($seed, ['reference', 'none'], true)
    || ($seed === 'reference' && $profileRoot !== realpath($repository . '/profiles/reference-studio'))) {
    fwrite(STDERR, "A profile package root and a seed of reference (Reference Studio only) or none are required.\n");
    exit(64);
}
if ($state === '' || $socket === '' || !is_int($port) || $port < 1024 || $port > 65_535) {
    fwrite(STDERR, "A state directory, database socket, and unprivileged port are required.\n");
    exit(64);
}
$resolvedState = realpath($state);
if ($resolvedState === false || !is_dir($resolvedState) || is_link($state)) {
    fwrite(STDERR, "The local sandbox state directory is unavailable.\n");
    exit(64);
}

$private = $resolvedState . '/private';
$media = $private . '/media';
foreach ([$private, $media, $resolvedState . '/public'] as $directory) {
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Local private storage could not be created');
    }
    chmod($directory, 0700);
}
$configurationPath = $private . '/cms.json';
$configurationDocument = [
    'profileRoot' => $profileRoot,
    'fontProcessing' => ['sanitizer' => getenv('CMS_LOCAL_FONT_SANITIZER') ?: '/unavailable/ots-sanitize', 'compressor' => getenv('CMS_LOCAL_FONT_COMPRESSOR') ?: '/unavailable/woff2_compress'],
    'origin' => 'http://localhost:' . $port,
    'publicUrlContext' => ['formatVersion' => 1, 'baseUrl' => 'https://reference-studio.example'],
    'publicationRoot' => $resolvedState . '/public',
    'database' => [
        'dsn' => 'mysql:unix_socket=' . $socket . ';dbname=portable_reference;charset=utf8mb4',
        'username' => 'portable',
        'password' => 'portable-local-database',
    ],
    'storage' => ['mediaRoot' => $media],
    'throttleSecretBase64' => base64_encode(hash('sha256', 'portable-cms-local-sandbox', true)),
    'mediaWakeStrategy' => 'cron_only',
    'errorLog' => $private . '/errors.log',
    'development' => ['allowInsecureLoopback' => true],
];
$encoded = json_encode(
    $configurationDocument,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
);
if (file_put_contents($configurationPath, $encoded . "\n", LOCK_EX) === false) {
    throw new RuntimeException('Local runtime configuration could not be written');
}
chmod($configurationPath, 0600);

$configuration = RuntimeConfiguration::load($configurationPath);
$migrationPaths = glob($repository . '/engine/migrations/*.sql');
if ($migrationPaths === false) {
    throw new RuntimeException('Migration files could not be enumerated');
}
sort($migrationPaths, SORT_STRING);
(new MigrationRunner($configuration->connect()))->apply($migrationPaths);

$pdo = $configuration->connect();
$userId = $pdo->query('SELECT id FROM cms_users ORDER BY created_at ASC LIMIT 1')->fetchColumn();
if (!is_string($userId)) {
    $userId = (new AdministratorBootstrap(
        $pdo,
        new SystemClock(),
        new SystemRandomSource(),
    ))->create('admin@portable.test', 'Local administrator', 'portable-cms-local');
}
if ($seed === 'none') {
    fwrite(STDOUT, "Profile sandbox prepared without seed content.\n");
    exit(0);
}
$seeder = ReferenceStudioDevelopmentSeeder::load(
    $repository . '/profiles/reference-studio',
    $repository . '/schemas/portable-cms-snapshot-v1.schema.json',
);
$seeded = $seeder->seed($pdo, $userId);
$package = \Utsuri\PortableCms\Conventional\Content\ProfilePackage::load($repository . '/profiles/reference-studio');
$snapshot = \Utsuri\PortableCms\Conventional\Compiler\FrozenSnapshot::load($repository . '/profiles/reference-studio/fixtures/snapshot.json', $repository . '/schemas/portable-cms-snapshot-v1.schema.json', $package);
ReferenceMediaSeeder::seed($pdo, $package, $snapshot, $userId, $media);

fwrite(STDOUT, $seeded ? "Reference Studio content seeded.\n" : "Existing Reference Studio content preserved.\n");
