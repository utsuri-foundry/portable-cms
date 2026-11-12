<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Content\ProfileEngineMismatch;
use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Content\ProfilePackage;
use Utsuri\PortableCms\Conventional\Installation\InstallationGate;
use Utsuri\PortableCms\Conventional\Installation\InstallationService;
use Utsuri\PortableCms\Conventional\RuntimeConfiguration;
use Utsuri\PortableCms\Conventional\Upgrade\EngineBuild;
use Utsuri\PortableCms\Conventional\Upgrade\UpgradeBundle;
use Utsuri\PortableCms\Conventional\Upgrade\UpgradeFailure;
use Utsuri\PortableCms\Conventional\Upgrade\UpgradeFiles;

/** @return array{root: string, minimum: string} */
function newerEngineProfileFixture(): array
{
    $root = sys_get_temp_dir() . '/engine-minimum-' . bin2hex(random_bytes(8));
    mkdir($root . '/profile', 0700, true);
    $profile = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/profiles/reference-studio/profile.json'), false, 64, JSON_THROW_ON_ERROR);
    $parts = explode('.', ProfileManifest::ENGINE_VERSION);
    $parts[2] = (string) ((int) $parts[2] + 1);
    $minimum = implode('.', $parts);
    $profile->compatibility->engine->minimum = $minimum;
    file_put_contents($root . '/profile/profile.json', json_encode($profile, JSON_THROW_ON_ERROR));
    return ['root' => $root, 'minimum' => $minimum];
}

$tests['engine build provenance preserves unknown legacy facts and rejects malformed source identities'] = static function (): void {
    assertSameValue(['version' => '0.1.2', 'sourceRevision' => null, 'sourceDirty' => null], EngineBuild::fromDocument(['version' => '0.1.2'])->document(), 'old releases are not assigned the incoming operator revision');
    $document = ['version' => ProfileManifest::ENGINE_VERSION, 'sourceRevision' => str_repeat('a', 40), 'sourceDirty' => false];
    assertSameValue($document, EngineBuild::fromDocument($document)->document(), 'current build provenance is lossless');
    foreach ([['sourceRevision' => 'main'], ['sourceRevision' => str_repeat('g', 40)], ['sourceRevision' => []], ['sourceDirty' => 'false'], ['version' => 'next']] as $invalid) {
        try {
            EngineBuild::fromDocument(array_replace($document, $invalid));
            throw new RuntimeException('Malformed provenance accepted');
        } catch (UpgradeFailure $error) {
            assertTrueValue(str_starts_with($error->codeName, 'engine_build_'), 'source facts fail with a stable safe error');
        }
    }
};

$tests['profile minimum above the running engine refuses manifest and package checks before renderer loading'] = static function (): void {
    $fixture = newerEngineProfileFixture();
    try {
        foreach ([static fn() => ProfileManifest::load($fixture['root'] . '/profile/profile.json'), static fn() => ProfilePackage::load($fixture['root'] . '/profile')] as $check) {
            try {
                $check();
                throw new RuntimeException('Newer profile minimum accepted');
            } catch (ProfileEngineMismatch $error) {
                assertSameValue($fixture['minimum'], $error->minimum, 'the required minimum is explicit');
                assertSameValue(ProfileManifest::ENGINE_VERSION, $error->running, 'the actual running engine is explicit');
                assertTrueValue(str_contains($error->getMessage(), $fixture['minimum']) && str_contains($error->getMessage(), ProfileManifest::ENGINE_VERSION), 'the operator message names both versions');
                assertSameValue('profile_engine_incompatible', $error->document()['error'], 'HTTP and CLI share the safe compatibility projection');
            }
        }
    } finally {
        RestoreTestFixture::remove($fixture['root']);
    }
};

$tests['installation refuses a newer profile before any database connection or migration'] = static function (): void {
    $fixture = newerEngineProfileFixture();
    $root = $fixture['root'];
    try {
        mkdir($root . '/public', 0700);
        mkdir($root . '/private', 0700);
        file_put_contents($root . '/private/cms.json', '{}');
        chmod($root . '/private/cms.json', 0600);
        $token = str_repeat('a', 64);
        $now = new DateTimeImmutable('2026-10-24T12:00:00Z');
        file_put_contents($root . '/private/install.json', json_encode(['status' => 'open', 'expiresAt' => '2026-10-24T13:00:00Z', 'tokenHash' => hash('sha256', $token), 'runtimeConfig' => $root . '/private/cms.json'], JSON_THROW_ON_ERROR));
        chmod($root . '/private/install.json', 0600);
        $gate = InstallationGate::load($root . '/private/install.json', $root . '/public', $now);
        $configuration = new RuntimeConfiguration('https://cms.example.test', 'not-a-database-driver:fictional', 'fictional', 'fixture', str_repeat('s', 32), null, $root . '/media', 'cron_only', profileRoot: $root . '/profile');
        try {
            (new InstallationService($gate, $configuration, [], new IdentityTestClock($now), new IdentityTestRandom()))->install($token, 'admin@example.test', 'Fictional administrator', 'fixture password', $now);
            throw new RuntimeException('Newer profile reached installation');
        } catch (ProfileEngineMismatch $error) {
            assertSameValue([$fixture['minimum'], ProfileManifest::ENGINE_VERSION], [$error->minimum, $error->running], 'compatibility fails before an impossible PDO connection');
            assertSameValue('open', json_decode((string) file_get_contents($root . '/private/install.json'), true, flags: JSON_THROW_ON_ERROR)['status'], 'refusal does not consume the install gate');
        }
    } finally {
        RestoreTestFixture::remove($root);
    }
};

$tests['upgrade bundle refuses a newer profile minimum and verifies both copies of build provenance'] = static function (): void {
    $fixture = newerEngineProfileFixture();
    $root = $fixture['root'];
    try {
        $runtime = $root . '/bundle/private/runtime';
        mkdir($runtime . '/engine/src/cms/conventional', 0700, true);
        mkdir($runtime . '/tools', 0700);
        file_put_contents($runtime . '/engine/src/cms/conventional/bootstrap.php', '<?php // never executed');
        file_put_contents($runtime . '/tools/conventional-upgrade.php', '<?php // never executed');
        $build = new EngineBuild(ProfileManifest::ENGINE_VERSION, str_repeat('a', 40), false);
        UpgradeFiles::writeJson($runtime . '/engine-version.json', $build->document());
        $manifest = ['version' => 1, 'engineVersion' => $build->version, 'sourceRevision' => $build->sourceRevision, 'sourceDirty' => $build->sourceDirty, 'files' => UpgradeFiles::inventory($root . '/bundle')];
        UpgradeFiles::writeJson($root . '/bundle/manifest.json', $manifest);
        assertSameValue($build->document(), UpgradeBundle::load($root . '/bundle')->engineBuild->document(), 'bundle and installed marker agree on exact provenance');
        rename($root . '/profile', $root . '/bundle/private/profile');
        $files = UpgradeFiles::inventory($root . '/bundle');
        unset($files['manifest.json']);
        $manifest['files'] = $files;
        UpgradeFiles::writeJson($root . '/bundle/manifest.json', $manifest);
        try {
            UpgradeBundle::load($root . '/bundle');
            throw new RuntimeException('Newer minimum reached upgrade checks');
        } catch (ProfileEngineMismatch $error) {
            assertSameValue([$fixture['minimum'], ProfileManifest::ENGINE_VERSION], [$error->minimum, $error->running], 'incoming profile is refused before rendering or database work');
        }
        RestoreTestFixture::remove($root . '/bundle/private/profile');
        UpgradeFiles::writeJson($runtime . '/engine-version.json', (new EngineBuild($build->version, str_repeat('b', 40), false))->document());
        $files = UpgradeFiles::inventory($root . '/bundle');
        unset($files['manifest.json']);
        $manifest['files'] = $files;
        UpgradeFiles::writeJson($root . '/bundle/manifest.json', $manifest);
        try {
            UpgradeBundle::load($root . '/bundle');
            throw new RuntimeException('Disagreeing source revisions accepted');
        } catch (UpgradeFailure $error) {
            assertSameValue('bundle_engine_source_mismatch', $error->codeName, 'recalculating checksums cannot disguise contradictory provenance');
        }
    } finally {
        RestoreTestFixture::remove($root);
    }
};
