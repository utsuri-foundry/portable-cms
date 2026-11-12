<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Upgrade;

use PDO;
use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Content\ProfilePackage;

final readonly class UpgradeBundle
{
    /** @param array<string, array{sha256: string, bytes: int}> $files */
    private function __construct(public string $root, public string $checksum, public string $engineVersion, public array $files, public ?ProfilePackage $profile, public EngineBuild $engineBuild) {}

    public static function load(string $root): self
    {
        $root = UpgradeFiles::root($root);
        $manifest = UpgradeFiles::json($root . '/manifest.json');
        $actual = UpgradeFiles::inventory($root);
        unset($actual['manifest.json']);
        if (($manifest['version'] ?? null) !== 1 || ($manifest['files'] ?? null) !== $actual || !isset($actual['private/runtime/engine/src/cms/conventional/bootstrap.php'], $actual['private/runtime/tools/conventional-upgrade.php'])) {
            throw new UpgradeFailure('bundle_inventory_mismatch');
        }
        $version = $manifest['engineVersion'] ?? null;
        if (!is_string($version) || !ProfileManifest::semanticVersion($version)) {
            throw new UpgradeFailure('bundle_engine_version_missing');
        }
        $build = EngineBuild::fromDocument(['version' => $version, 'sourceRevision' => $manifest['sourceRevision'] ?? null, 'sourceDirty' => $manifest['sourceDirty'] ?? null]);
        $installedBuild = EngineBuild::installed($root . '/private/runtime', $version);
        if ($installedBuild->version !== $build->version) {
            throw new UpgradeFailure('bundle_engine_version_mismatch');
        }
        if ($installedBuild->document() !== $build->document()) {
            throw new UpgradeFailure('bundle_engine_source_mismatch');
        }
        $profile = is_dir($root . '/private/profile') ? ProfilePackage::load($root . '/private/profile', $version) : null;
        return new self($root, (string) hash_file('sha256', $root . '/manifest.json'), $version, $actual, $profile, $build);
    }

    /** @return list<string> */
    public function migrations(): array
    {
        $paths = glob($this->root . '/private/runtime/engine/migrations/*.sql');
        if ($paths === false) {
            throw new UpgradeFailure('bundle_migrations_missing');
        }
        sort($paths, SORT_STRING);
        return $paths;
    }
}

/** Read-only reports never create a ledger, a lock, a backup or a session. */
final class UpgradePlan
{
    /** @return array<string, mixed> */
    public static function report(UpgradeBundle $bundle, PDO $pdo, ?ProfilePackage $current, string $engineVersion, ?EngineBuild $currentBuild = null): array
    {
        $ledger = $pdo->query('SELECT id, checksum, status FROM cms_migrations ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $byId = [];
        foreach ($ledger as $row) {
            if ($row['status'] !== 'complete') {
                throw new UpgradeFailure('installed_migration_incomplete');
            }
            $byId[(string) $row['id']] = (string) $row['checksum'];
        }
        $pending = [];
        foreach ($bundle->migrations() as $path) {
            $id = pathinfo($path, PATHINFO_FILENAME);
            if (isset($byId[$id])) {
                if (hash_file('sha256', $path) !== $byId[$id]) {
                    throw new UpgradeFailure('completed_migration_changed');
                }
                unset($byId[$id]);
            } else {
                $pending[] = $id;
            }
        }
        if ($byId !== [] || version_compare($bundle->engineVersion, $engineVersion, '<')) {
            throw new UpgradeFailure('engine_downgrade_refused');
        }
        $old = $current->manifest ?? ProfileManifest::gateZero();
        $new = $bundle->profile->manifest ?? ProfileManifest::gateZero();
        WebsiteIdentityUpgrade::assertIncoming($new);
        if ($old->id !== $new->id || version_compare($new->version, $old->version, '<')) {
            throw new UpgradeFailure('profile_identity_or_version_incompatible');
        }
        $classified = ProfileManifestChanges::classify(
            $current === null ? null : json_decode($old->definitionJson ?? throw new UpgradeFailure('profile_definition_unavailable'), true, 32, JSON_THROW_ON_ERROR),
            $bundle->profile === null ? null : json_decode($new->definitionJson ?? throw new UpgradeFailure('profile_definition_unavailable'), true, 32, JSON_THROW_ON_ERROR),
        );
        $changes = $classified->manifestKeys;
        $records = [];
        if ($classified->affecting !== []) {
            $rows = $pdo->query('SELECT id, type_id FROM cms_records ORDER BY type_id, id LIMIT 501')->fetchAll(PDO::FETCH_ASSOC);
            if (count($rows) > 500) {
                throw new UpgradeFailure('profile_upgrade_record_limit');
            }
            foreach ($rows as $row) {
                $records[] = ['id' => $row['id'], 'typeId' => $row['type_id']];
            }
        }
        $declared = $classified->affecting === [] ? null : self::migrationPath($bundle, $old->version, $new->version);
        $identitySources = WebsiteIdentityUpgrade::sources($old);
        $identityMigration = WebsiteIdentityUpgrade::needed($pdo, $old, $identitySources);
        $currentBuild ??= new EngineBuild($engineVersion);
        return ['formatVersion' => 1, 'bundleSha256' => $bundle->checksum, 'engine' => ['from' => $engineVersion, 'to' => $bundle->engineVersion,
            'fromSourceRevision' => $currentBuild->sourceRevision, 'toSourceRevision' => $bundle->engineBuild->sourceRevision,
            'fromSourceDirty' => $currentBuild->sourceDirty, 'toSourceDirty' => $bundle->engineBuild->sourceDirty],
            'profile' => ['id' => $old->id, 'from' => $old->version, 'to' => $new->version], 'pendingMigrations' => $pending, 'manifestChanges' => $changes,
            'contentNeutralChanges' => $classified->neutral, 'contentAffectingChanges' => $classified->affecting, 'affectedRecordTypes' => $classified->affectedTypes(),
            'contentNeedingMigration' => $records, 'declaredContentMigration' => $declared !== null,
            'identitySources' => $identitySources, 'identityMigration' => $identityMigration,
            'recordVersions' => \Utsuri\PortableCms\Conventional\DatabaseReport::recordVersions($pdo),
            'publishedRevisions' => (int) $pdo->query("SELECT COUNT(*) FROM cms_records WHERE workflow_state = 'published'")->fetchColumn(),
            'blocked' => $records !== [] && $declared === null, 'databaseChanged' => $pending !== [] || $changes !== [] || $identityMigration];
    }

    /** @return \Closure(string, array<string, mixed>): array<string, mixed>|null */
    public static function migration(UpgradeBundle $bundle, string $from, string $to): ?\Closure
    {
        $entry = self::migrationPath($bundle, $from, $to);
        if ($entry === null) {
            return null;
        }
        $migration = require $entry;
        return $migration instanceof \Closure ? $migration : throw new UpgradeFailure('profile_migration_unavailable');
    }

    private static function migrationPath(UpgradeBundle $bundle, string $from, string $to): ?string
    {
        $path = $bundle->root . '/private/profile/upgrade.json';
        if (!is_file($path)) {
            return null;
        }
        $declaration = UpgradeFiles::json($path);
        if (($declaration['formatVersion'] ?? null) !== 1 || ($declaration['fromVersion'] ?? null) !== $from || ($declaration['toVersion'] ?? null) !== $to
            || !is_string($declaration['entrypoint'] ?? null) || !str_starts_with($declaration['entrypoint'], 'src/') || !str_ends_with($declaration['entrypoint'], '.php')) {
            throw new UpgradeFailure('profile_migration_declaration_invalid');
        }
        $entry = UpgradeFiles::path($bundle->root . '/private/profile', $declaration['entrypoint']);
        if (!is_file($entry)) {
            throw new UpgradeFailure('profile_migration_unavailable');
        }
        return $entry;
    }
}
