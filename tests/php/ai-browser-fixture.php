<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Ai\AssistancePolicy;
use Utsuri\PortableCms\Conventional\Ai\AssistanceRunner;
use Utsuri\PortableCms\Conventional\Ai\AssistanceService;
use Utsuri\PortableCms\Conventional\Ai\LibraryAssistanceImageSource;
use Utsuri\PortableCms\Conventional\Ai\MariaDbAssistanceRepository;
use Utsuri\PortableCms\Conventional\Content\MariaDbSiteLanguages;
use Utsuri\PortableCms\Conventional\Content\ProfilePackage;
use Utsuri\PortableCms\Conventional\Identity\SystemClock;
use Utsuri\PortableCms\Conventional\Identity\SystemRandomSource;
use Utsuri\PortableCms\Conventional\Media\FilesystemMediaStore;
use Utsuri\PortableCms\Conventional\Media\MariaDbMediaLibrary;
use Utsuri\PortableCms\Conventional\Media\MediaLibrary;
use Utsuri\PortableCms\Conventional\RuntimeConfiguration;

require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/bootstrap.php';
require_once __DIR__ . '/assertions.php';
require_once __DIR__ . '/ai-fake-provider.php';
$tests = [];
require_once __DIR__ . '/ai-assistance-test.php';
$path = $argv[1] ?? '';
$action = $argv[2] ?? '';
if ($action === 'setup') {
    $config = json_decode((string) file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
    $config['aiAssistance'] = aiTestPolicyDocument();
    file_put_contents($path, json_encode($config, JSON_THROW_ON_ERROR), LOCK_EX);
    exit;
}
$configuration = RuntimeConfiguration::load($path);
$profile = ProfilePackage::load(dirname(__DIR__, 2) . '/profiles/reference-studio')->manifest;
$pdo = $configuration->connect();
if ($action === 'facts') {
    fwrite(STDOUT, json_encode(['revisions' => (int) $pdo->query('SELECT COUNT(*) FROM cms_record_revisions')->fetchColumn(),
        'terms' => (int) $pdo->query("SELECT COUNT(*) FROM cms_records WHERE type_id IN ('discipline', 'theme')")->fetchColumn()], JSON_THROW_ON_ERROR));
    exit;
}
// As the production runner does, the worker reads the profile with the languages the installation added.
$profile = (new MariaDbSiteLanguages($pdo))->installed($profile);
$clock = new SystemClock();
$random = new SystemRandomSource();
$repository = new MariaDbAssistanceRepository($pdo, $profile);
$service = new AssistanceService($repository, AssistancePolicy::from(aiTestPolicyDocument()), $profile, $clock, $random);
$media = new MediaLibrary(new MariaDbMediaLibrary($pdo), new FilesystemMediaStore($configuration->mediaRoot), $clock, $random);
$worker = new AssistanceRunner($repository, $service, new AiFakeProvider(), new LibraryAssistanceImageSource($media), $clock, $random);
assertSameValue(true, $worker->runOne(), 'one queued AI job is processed by the test-only provider');
