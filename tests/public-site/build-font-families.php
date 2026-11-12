<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Compiler\CompilationRequest;
use Utsuri\PortableCms\Conventional\Compiler\CompiledRoute;
use Utsuri\PortableCms\Conventional\Compiler\CompilerSource;
use Utsuri\PortableCms\Conventional\Compiler\DiscoveryRoute;
use Utsuri\PortableCms\Conventional\Compiler\FilesystemMediaSource;
use Utsuri\PortableCms\Conventional\Compiler\FrozenSnapshot;
use Utsuri\PortableCms\Conventional\Compiler\ProfileSiteBuild;
use Utsuri\PortableCms\Conventional\Compiler\ProfileSiteBuilder;
use Utsuri\PortableCms\Conventional\Compiler\RouteDiscovery;
use Utsuri\PortableCms\Conventional\Compiler\StaticSiteCompiler;
use Utsuri\PortableCms\Conventional\Content\ProfilePackage;

require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/bootstrap.php';

if (PHP_SAPI !== 'cli' || count($argv) !== 2) {
    throw new RuntimeException('A new isolated output directory is required');
}
$root = dirname(__DIR__, 2);
$package = ProfilePackage::load($root . '/profiles/reference-studio');
$document = json_decode((string) file_get_contents($package->root . '/fixtures/snapshot.json'), true, 64, JSON_THROW_ON_ERROR);
$document['designRegistries'] = json_decode((string) file_get_contents($root . '/schemas/fixtures/contracts/design-families.accepted.json'), true, 32, JSON_THROW_ON_ERROR);
$document['records'] = $document['media'] = [];
$snapshot = FrozenSnapshot::fromDocument($document, $package);
$builder = new class implements ProfileSiteBuilder {
    public function build(FrozenSnapshot $snapshot): ProfileSiteBuild
    {
        $html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>Reading Room | Font family fixture</title><meta name="description" content="An original reading-room fixture proving genuine roman and italic faces through the shared design registry, without profile-specific font declarations.">'
            . '<link rel="canonical" href="https://studio.example/"><link rel="icon" href="./assets/icon.svg" type="image/svg+xml"><link rel="stylesheet" href="./assets/design.css"></head><body class="cms-type-body">'
            . '<main><h1>Reading Room</h1><p id="roman">A place to read, pause and notice the light.</p>'
            . '<p><em id="italic">A second voice, drawn as a genuine italic rather than a mechanically slanted roman.</em></p>'
            . '<p>This fictional acceptance page uses the same compiled font family for both voices. The profile supplies no font declarations and needs no scripts to display its content.</p></main></body></html>';
        return new ProfileSiteBuild([new CompiledRoute('/', $html)], [], [], [], new RouteDiscovery($snapshot->publicUrlContext, [new DiscoveryRoute('/', 'en', 'font-fixture')]));
    }
};
(new StaticSiteCompiler($root . '/schemas/portable-cms-artifact-v1.schema.json', $root . '/schemas/portable-cms-search-v1.schema.json'))->compile(
    $package, $snapshot, $builder, new FilesystemMediaSource($package->root . '/fixtures/media'),
    new CompilationRequest(str_repeat('a', 32), $snapshot->createdAt, CompilerSource::identity()), $argv[1],
);
fwrite(STDOUT, "Built Reading Room with its independent family fixture through the ordinary static compiler.\n");
