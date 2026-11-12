<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Compiler\HoldingPageCompiler;
use Utsuri\PortableCms\Conventional\Compiler\PublicUrlContext;
use Utsuri\PortableCms\Conventional\Content\ProfilePackage;
use Utsuri\PortableCms\Conventional\Preview\PreviewSnapshot;
use Utsuri\PortableCms\Conventional\Publication\WebsitePublication;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/bootstrap.php';

if (PHP_SAPI !== 'cli' || count($argv) !== 2) {
    throw new RuntimeException('A new isolated output directory is required');
}
$repository = dirname(__DIR__, 2);
$package = ProfilePackage::load($repository . '/profiles/reference-studio');
$settings = WebsitePublication::defaults($package->manifest->id, $package->manifest->locales)->document();
foreach ($settings['translations'] as &$translation) {
    $translation['contact'] = 'hello@example.test';
}
unset($translation);
$snapshot = new PreviewSnapshot($package->manifest->id, $package->manifest->version, $package->manifest->locales, [], new DateTimeImmutable('2026-10-06T12:00:00Z'), profileChecksum: $package->checksum, publicUrlContext: PublicUrlContext::from(['formatVersion' => 1, 'baseUrl' => 'https://studio.example']), holdingPage: WebsitePublication::from($settings));
(new HoldingPageCompiler($repository . '/schemas', dirname($argv[1]), $package))->compile($snapshot, $argv[1]);
fwrite(STDOUT, "Built Reference Studio's holding page with its packaged font for the unchanged public quality bar.\n");
