<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Content\MariaDbSiteLanguages;
use Utsuri\PortableCms\Conventional\Content\ProfilePackage;
use Utsuri\PortableCms\Conventional\Export\ChunkedExport;
use Utsuri\PortableCms\Conventional\Export\ChunkedExportWriter;
use Utsuri\PortableCms\Conventional\Export\MariaDbExportRepository;
use Utsuri\PortableCms\Conventional\Identity\SystemClock;
use Utsuri\PortableCms\Conventional\Identity\SystemRandomSource;
use Utsuri\PortableCms\Conventional\Restore\FilesystemRestoreStore;
use Utsuri\PortableCms\Conventional\RuntimeConfiguration;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/bootstrap.php';
$config = RuntimeConfiguration::load($argv[1]);
if (!$config->allowInsecureLoopback || $config->profileRoot === null) {
    throw new RuntimeException('The fictional loopback browser installation is required');
}
$pdo = $config->connect();
$actor = $pdo->query("SELECT id FROM cms_users WHERE login_name = 'admin@portable.test' AND role IN ('owner', 'administrator') AND status = 'active'")->fetchColumn();
if (!is_string($actor)) {
    throw new RuntimeException('The fictional browser administrator is required');
}
$profile = (new MariaDbSiteLanguages($pdo))->installed(ProfilePackage::load($config->profileRoot)->manifest);
$store = new FilesystemRestoreStore($argv[2]);
$clock = new SystemClock();
$random = new SystemRandomSource();
// Match the former browser v1 proof: validate the complete snapshot and graph.
// This shared fixture deliberately contains incomplete historical content; byte
// packaging/semantic restore is proved separately with valid retained-link history.
$repository = new MariaDbExportRepository($pdo, $profile->designRegistries, $config->publicUrlContext, wordingProfile: $profile);
$manifest = $repository->capture(new ChunkedExportWriter($store), bin2hex($random->bytes(16)), bin2hex($random->bytes(16)), $actor, $profile->id, $profile->version, $profile->locales, $clock->now());
$export = new ChunkedExport($store, $manifest);
fwrite(STDOUT, json_encode([
    'records' => iterator_to_array($export->rows('records'), false),
    'revisions' => iterator_to_array($export->rows('revisions'), false),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
