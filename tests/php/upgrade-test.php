<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Upgrade\ProfileManifestChanges;
use Utsuri\PortableCms\Conventional\Upgrade\RuntimeLease;
use Utsuri\PortableCms\Conventional\Upgrade\UpgradeBundle;
use Utsuri\PortableCms\Conventional\Upgrade\UpgradeFailure;
use Utsuri\PortableCms\Conventional\Upgrade\UpgradeFiles;

require_once __DIR__ . '/restore-fixture.php';

/** @return array<string, mixed> */
function upgradeProfileDocument(): array
{
    return json_decode(\Utsuri\PortableCms\Conventional\Content\ProfileManifest::load(dirname(__DIR__, 2) . '/profiles/reference-studio/profile.json')->definitionJson ?? throw new RuntimeException('Missing expanded profile'), true, 32, JSON_THROW_ON_ERROR);
}

$tests['profile upgrade classification explicitly allows presentation but not validation changes'] = static function (): void {
    $old = upgradeProfileDocument();
    $cases = [
        'version' => static function (array &$new): void {
            $new['version'] = '1.0.1';
        },
        'assets' => static function (array &$new): void {
            $new['assets'][0]['sha256'] = str_repeat('a', 64);
        },
        'renderers' => static function (array &$new): void {
            $new['renderers'][0]['entrypoint'] = 'renderers/new.php';
        },
        'navigation' => static function (array &$new): void {
            $new['navigation'][0]['label'] = 'Selected Works';
        },
        'designRegistries' => static function (array &$new): void {
            ++$new['designRegistries']['typography'][0]['size']['max'];
        },
        'recordTypes/project/label' => static function (array &$new): void {
            $new['recordTypes'][0]['label'] = 'Works';
        },
        'recordTypes/project/fields/title/label' => static function (array &$new): void {
            $new['recordTypes'][0]['fields'][0]['label'] = 'Work title';
        },
        'singletons/home/label' => static function (array &$new): void {
            $new['singletons'][0]['label'] = 'Front page';
        },
        'taxonomyNamespaces/discipline/label' => static function (array &$new): void {
            $new['taxonomyNamespaces'][0]['label'] = 'Topics';
        },
    ];
    foreach ($cases as $path => $change) {
        $new = $old;
        $change($new);
        $classified = ProfileManifestChanges::classify($old, $new);
        assertSameValue([$path], $classified->neutral, 'one explicit neutral path: ' . $path);
        assertSameValue([], $classified->affecting, 'presentation never requires a content mapping: ' . $path);
        assertSameValue([], $classified->affectedTypes(), 'no affected record types: ' . $path);
    }
    $new = $old;
    $new['version'] = '1.0.1';
    ++$new['recordTypes'][0]['fields'][0]['settings']['maximumLength'];
    $classified = ProfileManifestChanges::classify($old, $new);
    assertSameValue(['version'], $classified->neutral, 'mixed releases still list their neutral changes');
    assertSameValue([['path' => 'recordTypes/project/fields/title/settings/maximumLength', 'recordTypes' => ['project']]], $classified->affecting, 'field limits are content-affecting even when relaxed');
    assertSameValue(['project'], $classified->affectedTypes(), 'classification identifies the changed type');
    assertSameValue([], ProfileManifestChanges::classify($old, $old)->affecting, 'same manifest has no content changes');
    assertSameValue([], ProfileManifestChanges::classify(null, null)->neutral, 'no installed package is an unchanged state');
};

$tests['profile upgrade classification keeps new keys and content identities conservative'] = static function (): void {
    $old = upgradeProfileDocument();
    $all = ['about', 'article', 'discipline', 'home', 'landing', 'person', 'project', 'sector', 'site-settings'];
    foreach (['formatVersion', 'compatibility', 'locales', 'defaultLocale', 'capabilities', 'privacy', 'futureContract'] as $key) {
        $new = $old;
        $new[$key] = null;
        $classified = ProfileManifestChanges::classify($old, $new);
        assertSameValue([], $classified->neutral, 'unclassified root changes are not neutral: ' . $key);
        assertSameValue([['path' => $key, 'recordTypes' => $all]], $classified->affecting, 'global changes name every old/new type: ' . $key);
        assertSameValue([$key], $classified->manifestKeys, 'even a newly present null key is a manifest change');
    }
    foreach (['assets', 'navigation', 'renderers'] as $key) {
        $new = $old;
        $new[$key][0]['futureContract'] = true;
        assertSameValue([['path' => $key, 'recordTypes' => $all]], ProfileManifestChanges::classify($old, $new)->affecting, 'new neutral-subtree keys also fail closed');
    }
    $new = $old;
    $new['recordTypes'][0]['futureContract'] = true;
    assertSameValue([['path' => 'recordTypes/project/futureContract', 'recordTypes' => ['project']]], ProfileManifestChanges::classify($old, $new)->affecting, 'unknown type key fails closed');
    $new = $old;
    $new['recordTypes'][0]['fields'][0]['futureContract'] = true;
    assertSameValue(['project'], ProfileManifestChanges::classify($old, $new)->affectedTypes(), 'unknown field keys fail closed');
    $new = $old;
    unset($new['recordTypes'][0]);
    $new['recordTypes'] = array_values($new['recordTypes']);
    assertSameValue([['path' => 'recordTypes/project', 'recordTypes' => ['project']]], ProfileManifestChanges::classify($old, $new)->affecting, 'removing a type retains its affected identity');
    $new = $old;
    $new['recordTypes'][0]['fields'][0]['id'] = 'heading';
    assertSameValue(['project'], ProfileManifestChanges::classify($old, $new)->affectedTypes(), 'renamed fields are model changes');
    $new = $old;
    $new['recordTypes'] = array_reverse($new['recordTypes']);
    assertTrueValue(ProfileManifestChanges::classify($old, $new)->affecting !== [], 'ordering changes are conservative rather than inferred safe');
    assertSameValue($all, ProfileManifestChanges::classify($old, null)->affectedTypes(), 'removing a package affects all installed types');
};

$tests['engine compatibility range updates preserve content only with identical known record contracts'] = static function (): void {
    $old = upgradeProfileDocument();
    $new = $old;
    $new['compatibility']['engine']['minimum'] = '0.3.0';
    $new['compatibility']['engine']['maximumExclusive'] = '0.9.0';
    $classified = ProfileManifestChanges::classify($old, $new);
    assertSameValue(['compatibility'], $classified->neutral, 'an already checked engine range needs no content mapping');
    assertSameValue([], $classified->affecting, 'version declarations cannot rewrite authored data');
    foreach ([['contracts', 'snapshot', 2], ['contracts', 'futureContract', 1], ['engine', 'minimum', 'next'], ['engine', 'futureContract', '0.3.0']] as [$section, $key, $value]) {
        $invalid = $new;
        $invalid['compatibility'][$section][$key] = $value;
        assertTrueValue(ProfileManifestChanges::classify($old, $invalid)->affecting !== [], 'changed contracts and unknown or invalid compatibility facts stay content-affecting');
    }
};

$tests['profile upgrade classification distinguishes nested group labels from their rules'] = static function (): void {
    $old = upgradeProfileDocument();
    $fieldIndex = array_search('credits', array_column($old['recordTypes'][0]['fields'], 'id'), true);
    assertTrueValue(is_int($fieldIndex), 'fixture has a nested group');
    $new = $old;
    $new['recordTypes'][0]['fields'][$fieldIndex]['settings']['fields'][0]['label'] = 'Team role';
    $classified = ProfileManifestChanges::classify($old, $new);
    assertSameValue(['recordTypes/project/fields/credits/settings/fields/role/label'], $classified->neutral, 'nested label changes are presentation');
    assertSameValue([], $classified->affecting, 'nested labels do not map records');
    $new['recordTypes'][0]['fields'][$fieldIndex]['settings']['fields'][0]['requiredOnDraft'] = true;
    assertSameValue(['project'], ProfileManifestChanges::classify($old, $new)->affectedTypes(), 'nested validation changes affect their containing type');
    $bare = $old;
    unset($bare['recordTypes'][0]['fields'][$fieldIndex]['settings']['presentation'], $bare['recordTypes'][0]['fields'][$fieldIndex]['settings']['itemLabel']);
    $new = $bare;
    $new['recordTypes'][0]['fields'][$fieldIndex]['settings']['presentation'] = 'cards';
    $new['recordTypes'][0]['fields'][$fieldIndex]['settings']['itemLabel'] = 'credit';
    $classified = ProfileManifestChanges::classify($bare, $new);
    assertSameValue(['recordTypes/project/fields/credits/settings/itemLabel', 'recordTypes/project/fields/credits/settings/presentation'], $classified->neutral, 'added group presentation settings are presentation');
    assertSameValue([], $classified->affecting, 'group presentation settings do not map records');
    $changed = $new;
    $changed['recordTypes'][0]['fields'][$fieldIndex]['settings']['presentation'] = 'rows';
    $changed['recordTypes'][0]['fields'][$fieldIndex]['settings']['itemLabel'] = 'team member';
    assertSameValue([], ProfileManifestChanges::classify($new, $changed)->affecting, 'changed group presentation settings do not map records');
    ++$changed['recordTypes'][0]['fields'][$fieldIndex]['settings']['maximumItems'];
    $classified = ProfileManifestChanges::classify($new, $changed);
    assertSameValue(['recordTypes/project/fields/credits/settings/itemLabel', 'recordTypes/project/fields/credits/settings/presentation'], $classified->neutral, 'mixed group changes still list their presentation');
    assertSameValue([['path' => 'recordTypes/project/fields/credits/settings/maximumItems', 'recordTypes' => ['project']]], $classified->affecting, 'group limits stay content-affecting');
    $new = $old;
    $new['designRegistries']['typography'][0]['size']['futureUnit'] = 'em';
    assertTrueValue(ProfileManifestChanges::classify($old, $new)->affecting !== [], 'future design controls are not implicitly classified');
    $new = $old;
    $new['designRegistries']['typography'][0]['id'] = 'replacement-role';
    assertTrueValue(ProfileManifestChanges::classify($old, $new)->affecting !== [], 'removing a design identity can invalidate saved roles and requires a mapping');
    $new = $old;
    unset($new['designRegistries']);
    assertTrueValue(ProfileManifestChanges::classify($old, $new)->affecting !== [], 'removing design validation is not just changing a curve');
    $new = $old;
    $new['designRegistries']['typography'][] = [...$old['designRegistries']['typography'][0], 'id' => 'additional-role'];
    assertSameValue(['designRegistries'], ProfileManifestChanges::classify($old, $new)->neutral, 'additional design identities retain every old reference');
};

/** @return string */
function upgradeFilesFixture(): string
{
    $root = sys_get_temp_dir() . '/portable-upgrade-files-' . bin2hex(random_bytes(8));
    mkdir($root, 0700);
    return $root;
}

$tests['upgrade inventories reject symlinks traversal extra files and forged checksums'] = static function (): void {
    $root = upgradeFilesFixture();
    try {
        mkdir($root . '/bundle/private/runtime/engine/src/cms/conventional', 0700, true);
        mkdir($root . '/bundle/private/runtime/tools', 0700);
        file_put_contents($root . '/bundle/private/runtime/engine/src/cms/conventional/bootstrap.php', '<?php // fixture');
        file_put_contents($root . '/bundle/private/runtime/tools/conventional-upgrade.php', '<?php // fixture');
        $files = UpgradeFiles::inventory($root . '/bundle');
        UpgradeFiles::writeJson($root . '/bundle/manifest.json', ['version' => 1, 'engineVersion' => '0.1.0', 'files' => $files]);
        assertSameValue('0.1.0', UpgradeBundle::load($root . '/bundle')->engineVersion, 'manifest pins the engine version');
        assertSameValue([null, null], [UpgradeBundle::load($root . '/bundle')->engineBuild->sourceRevision, UpgradeBundle::load($root . '/bundle')->engineBuild->sourceDirty], 'legacy manifests stay readable without inventing source provenance');
        foreach (['../escape', 'private/../../escape', '/absolute', 'private/link/file'] as $path) {
            if ($path === 'private/link/file') {
                symlink($root, $root . '/bundle/private/link');
            }
            try {
                UpgradeFiles::path($root . '/bundle', $path);
                throw new RuntimeException('Unsafe upgrade path accepted');
            } catch (UpgradeFailure $error) {
                assertTrueValue(in_array($error->codeName, ['unsafe_upgrade_path', 'upgrade_symlink_refused'], true), 'unsafe paths fail closed');
            }
        }
        unlink($root . '/bundle/private/link');
        file_put_contents($root . '/bundle/undeclared.php', '<?php // unwanted');
        try {
            UpgradeBundle::load($root . '/bundle');
            throw new RuntimeException('Undeclared executable accepted');
        } catch (UpgradeFailure $error) {
            assertSameValue('bundle_inventory_mismatch', $error->codeName, 'additional files cannot evade checksum inventory');
        }
        unlink($root . '/bundle/undeclared.php');
        file_put_contents($root . '/bundle/private/runtime/tools/conventional-upgrade.php', '<?php // changed');
        try {
            UpgradeBundle::load($root . '/bundle');
            throw new RuntimeException('Changed bundle file accepted');
        } catch (UpgradeFailure $error) {
            assertSameValue('bundle_inventory_mismatch', $error->codeName, 'each declared byte is checked');
        }
    } finally {
        RestoreTestFixture::remove($root);
    }
};

$tests['runtime leases exclude competing upgrades and reject stale workers without global ownership'] = static function (): void {
    $root = upgradeFilesFixture();
    try {
        mkdir($root . '/upgrade', 0700);
        file_put_contents($root . '/upgrade/runtime.lock', '');
        chmod($root . '/upgrade/runtime.lock', 0600);
        file_put_contents($root . '/cms.json', '{}');
        mkdir($root . '/runtime', 0700);
        UpgradeFiles::writeJson($root . '/upgrade/active.json', ['config' => $root . '/cms.json', 'runtime' => $root . '/runtime']);
        $exclusive = fopen($root . '/upgrade/runtime.lock', 'rb');
        if (!is_resource($exclusive)) {
            throw new RuntimeException('Lock fixture unavailable');
        }
        try {
            flock($exclusive, LOCK_EX);
            try {
                RuntimeLease::acquire($root . '/upgrade');
                throw new RuntimeException('Competing runtime passed exclusive upgrade lease');
            } catch (RuntimeException $error) {
                assertSameValue('installation_busy_retry', $error->getMessage(), 'in-flight upgrade visibly blocks CMS/worker writes');
            }
            flock($exclusive, LOCK_UN);
            $lease = RuntimeLease::guard($root . '/cms.json', $root . '/runtime');
            assertTrueValue($lease instanceof RuntimeLease, 'active worker holds an explicit shared lease');
            assertSameValue(false, flock($exclusive, LOCK_EX | LOCK_NB), 'active runtime excludes an upgrade');
            unset($lease);
            assertSameValue(true, flock($exclusive, LOCK_EX | LOCK_NB), 'release lifetime relinquishes the runtime lease');
            flock($exclusive, LOCK_UN);
            // An interrupted in-place upgrade leaves no lock holder, only its durable marker.
            file_put_contents($root . '/upgrade/' . RuntimeLease::MAINTENANCE, '{}');
            try {
                RuntimeLease::acquire($root . '/upgrade');
                throw new RuntimeException('Runtime passed an unfinished in-place upgrade');
            } catch (RuntimeException $error) {
                assertSameValue('installation_busy_retry', $error->getMessage(), 'the maintenance marker keeps CMS and workers closed');
            }
            assertSameValue(true, flock($exclusive, LOCK_EX | LOCK_NB), 'a refused runtime holds no lease');
            flock($exclusive, LOCK_UN);
            unlink($root . '/upgrade/' . RuntimeLease::MAINTENANCE);
            try {
                RuntimeLease::guard($root . '/cms.json', $root . '/obsolete');
                throw new RuntimeException('Stale worker accepted');
            } catch (RuntimeException $error) {
                assertSameValue('stale_runtime_use_stable_worker_dispatcher', $error->getMessage(), 'stale worker cannot write the retained database');
            }
            assertSameValue(true, flock($exclusive, LOCK_EX | LOCK_NB), 'failed guard releases its lease too');
            flock($exclusive, LOCK_UN);
        } finally {
            fclose($exclusive);
        }
    } finally {
        RestoreTestFixture::remove($root);
    }
};
