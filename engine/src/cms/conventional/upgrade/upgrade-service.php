<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Upgrade;

use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Content\ProfilePackage;
use Utsuri\PortableCms\Conventional\Database\MigrationRunner;
use Utsuri\PortableCms\Conventional\Database\TablePrefix;
use Utsuri\PortableCms\Conventional\HostIo;
use Utsuri\PortableCms\Conventional\RuntimeConfiguration;

/**
 * The commit point is one private pointer replacement. Schema and profile changes run in a
 * new table set of the same database, in a separate database, or (when the installation
 * chooses it) in place behind a maintenance marker with the verified backup as the way back.
 */
final class UpgradeService
{
    private string $privateRoot;
    private string $stateRoot;
    private string $cmsRoot;
    /** The serving table set's connection that holds the migration lock during an operation. */
    private ?\PDO $lockedConnection = null;

    /** @param \Closure(string): void|null $checkpoint Injected interruption boundary for acceptance. */
    public function __construct(private readonly string $configPath, string $cmsRoot, private readonly \DateTimeImmutable $now, private readonly ?\Closure $checkpoint = null, private readonly ?string $launcherSource = null)
    {
        $this->privateRoot = UpgradeFiles::root(dirname($configPath));
        $this->cmsRoot = UpgradeFiles::root($cmsRoot);
        $this->stateRoot = $this->privateRoot . '/upgrade';
        if (UpgradeFiles::within($this->privateRoot, $this->cmsRoot) || UpgradeFiles::within($this->cmsRoot, $this->privateRoot)) {
            throw new UpgradeFailure('upgrade_storage_must_be_private');
        }
        RuntimeConfiguration::load($configPath, $this->cmsRoot);
        UpgradeFiles::path($this->privateRoot, 'upgrade/active.json');
    }

    /** @return array<string, mixed> */
    public function dryRun(UpgradeBundle $bundle): array
    {
        $this->privateBundle($bundle);
        $active = $this->active();
        $configuration = RuntimeConfiguration::load($active['config'], $this->cmsRoot);
        $currentBuild = EngineBuild::installed($active['runtime'], $active['engineVersion']);
        if ($currentBuild->version !== $active['engineVersion']) {
            throw new UpgradeFailure('installed_engine_version_mismatch');
        }
        $profile = $configuration->profileRoot === null ? null : ProfilePackage::load($configuration->profileRoot, $currentBuild->version, allowLegacyProfile: true);
        $report = UpgradePlan::report($bundle, $configuration->connect(), $profile, $active['engineVersion'], $currentBuild);
        $report['noOp'] = $this->sameRuntime($bundle, $active) && $report['pendingMigrations'] === [] && $report['manifestChanges'] === [] && !$report['identityMigration'];
        $bytes = (new DatabaseBackup($configuration->connect()))->bytes();
        $report['database'] = ['mode' => $configuration->databaseUpgradeMode, 'bytes' => $bytes['database'], 'tableSetBytes' => $bytes['tableSet'],
            'maximumBytes' => $configuration->databaseMaximumBytes,
            'tableSetFits' => $configuration->databaseMaximumBytes === null ? null : $bytes['database'] + $bytes['tableSet'] <= $configuration->databaseMaximumBytes];
        $report['rollback'] = !$report['databaseChanged'] ? 'later_content_preserved_in_unchanged_database' : match ($configuration->databaseUpgradeMode) {
            RuntimeConfiguration::UPGRADE_IN_PLACE => 'backup_restored_in_place; later_changes_require_explicit_compatible_mapping',
            default => 'previous_tables_retained; later_changes_require_explicit_compatible_mapping',
        };
        return $report;
    }

    /** @return array<string, mixed> */
    public function backup(string $destination): array
    {
        return $this->locked(function () use ($destination): array {
            $parent = UpgradeFiles::root(dirname($destination));
            if (UpgradeFiles::within($parent, $this->cmsRoot) || UpgradeFiles::within($parent, $this->stateRoot) || file_exists($destination) || is_link($destination)) {
                throw new UpgradeFailure('backup_destination_unsafe_or_exists');
            }
            $active = $this->active();
            $configuration = RuntimeConfiguration::load($active['config'], $this->cmsRoot);
            if (UpgradeFiles::within($parent, $configuration->mediaRoot) || UpgradeFiles::within($destination, $active['runtime'])
                || ($configuration->profileRoot !== null && UpgradeFiles::within($destination, $configuration->profileRoot))) {
                throw new UpgradeFailure('backup_destination_overlaps_source');
            }
            if ($configuration->publicationRoot !== null && UpgradeFiles::within($parent, $configuration->publicationRoot)) {
                throw new UpgradeFailure('backup_destination_must_be_private');
            }
            $public = UpgradeChecks::publicRelease($configuration);
            HostIo::required(fn() => mkdir($destination, 0700));
            (new DatabaseBackup($configuration->connect()))->capture($destination . '/database');
            UpgradeFiles::copy($configuration->mediaRoot, $destination . '/media', UpgradeFiles::inventory($configuration->mediaRoot));
            UpgradeFiles::copy($active['runtime'], $destination . '/release/runtime', UpgradeFiles::inventory($active['runtime']));
            if ($configuration->profileRoot !== null) {
                UpgradeFiles::copy($configuration->profileRoot, $destination . '/release/profile', UpgradeFiles::inventory($configuration->profileRoot));
            }
            UpgradeFiles::copy($this->cmsRoot, $destination . '/cms', UpgradeFiles::inventory($this->cmsRoot));
            HostIo::createPrivateFile($destination . '/cms.json', (string) file_get_contents($active['config']));
            $inventory = UpgradeFiles::inventory($destination);
            $document = ['formatVersion' => 1, 'createdAt' => $this->now->format(DATE_ATOM), 'active' => $active, 'cmsRoot' => $this->cmsRoot, 'mediaRoot' => $configuration->mediaRoot, 'publicReleaseSha256' => $public, 'files' => $inventory];
            UpgradeFiles::writeJson($destination . '/backup.json', $document);
            $this->verifyBackup($destination, false);
            // Install the stable dispatcher only after the legacy release/configuration have a verified backup.
            $this->launcher($active);
            return ['status' => 'backup_verified', 'backupSha256' => hash_file('sha256', $destination . '/backup.json'), 'createdAt' => $document['createdAt']];
        });
    }

    /** @return array<string, mixed> */
    public function apply(UpgradeBundle $bundle, string $backup, string $confirmation, ?string $standbyConfig): array
    {
        return $this->locked(function () use ($bundle, $backup, $confirmation, $standbyConfig): array {
            $this->privateBundle($bundle);
            if (!hash_equals($bundle->checksum, $confirmation)) {
                throw new UpgradeFailure('bundle_confirmation_required');
            }
            $previous = $this->active();
            $report = $this->dryRun($bundle);
            if ($report['blocked']) {
                throw new UpgradeFailure('content_requires_declared_migration');
            }
            $saved = $this->verifyBackup($backup, true);
            if ($saved['active'] !== $previous) {
                throw new UpgradeFailure('backup_for_different_release');
            }
            $oldConfiguration = RuntimeConfiguration::load($previous['config'], $this->cmsRoot);
            $oldDatabase = new DatabaseBackup($oldConfiguration->connect());
            $databaseBackup = UpgradeFiles::json($backup . '/database/database.json');
            if ($oldDatabase->identity() !== $databaseBackup['identity'] || $oldDatabase->fingerprint() !== $databaseBackup['fingerprint']
                || UpgradeFiles::inventory($oldConfiguration->mediaRoot) !== UpgradeFiles::inventory($backup . '/media')
                || UpgradeFiles::inventory($previous['runtime']) !== UpgradeFiles::inventory($backup . '/release/runtime')
                || ($oldConfiguration->profileRoot !== null && UpgradeFiles::inventory($oldConfiguration->profileRoot) !== UpgradeFiles::inventory($backup . '/release/profile'))
                || UpgradeChecks::publicRelease($oldConfiguration) !== $saved['publicReleaseSha256']) {
                throw new UpgradeFailure('backup_stale_or_installed_bytes_changed');
            }
            if ($report['noOp']) {
                return ['status' => 'no_op', 'bundleSha256' => $bundle->checksum];
            }
            $existing = is_file($this->stateRoot . '/journal.json') ? UpgradeFiles::json($this->stateRoot . '/journal.json') : null;
            if ($existing !== null && !in_array($existing['status'] ?? '', ['failed', 'rolled_back', 'active'], true)) {
                throw new UpgradeFailure('upgrade_already_pending_recover_first');
            }
            if (($existing['status'] ?? null) === 'active') {
                if ($previous !== ($existing['candidate'] ?? null)) {
                    throw new UpgradeFailure('upgrade_pointer_conflict');
                }
                $history = $this->stateRoot . '/history';
                if (!is_dir($history)) {
                    HostIo::required(fn() => mkdir($history, 0700));
                }
                $path = UpgradeFiles::path($history, $previous['releaseId'] . '.json');
                if (is_file($path) && UpgradeFiles::json($path) !== $existing) {
                    throw new UpgradeFailure('upgrade_history_conflict');
                }
                UpgradeFiles::writeJson($path, $existing);
            }
            $id = hash('sha256', $bundle->checksum . (string) hash_file('sha256', $backup . '/backup.json'));
            $stage = $this->stateRoot . '/releases/' . $id;
            $candidateConfig = UpgradeFiles::json($previous['config']);
            // The installation chooses its mode; an upgrade never falls back to another one.
            $mode = $report['databaseChanged'] ? $oldConfiguration->databaseUpgradeMode : null;
            if ($standbyConfig !== null && $mode !== RuntimeConfiguration::UPGRADE_SEPARATE_DATABASE) {
                throw new UpgradeFailure('standby_database_requires_separate_database_mode');
            }
            $staged = null;
            $tableSet = null;
            if ($mode === RuntimeConfiguration::UPGRADE_SEPARATE_DATABASE) {
                if ($standbyConfig === null) {
                    throw new UpgradeFailure('empty_private_standby_configuration_required');
                }
                $standbyConfiguration = RuntimeConfiguration::load($standbyConfig, $this->cmsRoot);
                $staged = new DatabaseBackup($standbyConfiguration->connect());
                if ($staged->identity() === $oldDatabase->identity() || $staged->schemas() !== []) {
                    throw new UpgradeFailure('standby_database_must_be_distinct_and_empty');
                }
                $candidateConfig['database'] = UpgradeFiles::json($standbyConfig)['database'];
            } elseif ($mode === RuntimeConfiguration::UPGRADE_TABLE_SET) {
                $bytes = $oldDatabase->bytes();
                if ($oldConfiguration->databaseMaximumBytes !== null && $bytes['database'] + $bytes['tableSet'] > $oldConfiguration->databaseMaximumBytes) {
                    throw new UpgradeFailure('database_quota_insufficient_for_table_set');
                }
                $tableSet = 'u' . substr($id, 0, 12) . '_';
                $staged = new DatabaseBackup($oldConfiguration->connect(new TablePrefix($tableSet)));
                if ($tableSet === $oldConfiguration->tablePrefix->value || $staged->schemas() !== []) {
                    throw new UpgradeFailure('table_set_must_be_new_and_empty');
                }
                $candidateConfig['database']['tablePrefix'] = $tableSet;
            }
            $journal = ['formatVersion' => 1, 'status' => 'preparing', 'bundleSha256' => $bundle->checksum, 'backup' => $backup,
                'backupSha256' => hash_file('sha256', $backup . '/backup.json'), 'previous' => $previous, 'report' => $report, 'stage' => $stage,
                'databaseMode' => $mode, 'tableSet' => $tableSet, 'standbyIdentity' => $staged?->identity()];
            $this->journal($journal);
            try {
                UpgradeFiles::copy($bundle->root, $stage . '/bundle', UpgradeFiles::inventory($bundle->root));
                $stagedBundle = UpgradeBundle::load($stage . '/bundle');
                $candidateConfig['profileRoot'] = $bundle->profile === null ? null : $stage . '/bundle/private/profile';
                UpgradeFiles::writeJson($stage . '/cms.json', $candidateConfig);
                if ($staged !== null) {
                    $staged->restore($backup . '/database');
                    $this->checkpoint('after-copy');
                }
                if ($mode === RuntimeConfiguration::UPGRADE_IN_PLACE) {
                    // Requests stay closed from here until the upgrade settles or the backup is restored.
                    $this->maintenance(true);
                }
                if ($mode !== null) {
                    $configuration = RuntimeConfiguration::load($stage . '/cms.json', $this->cmsRoot);
                    // In place, the serving table set's lock is already held by this operation's connection.
                    $migrationConnection = $mode === RuntimeConfiguration::UPGRADE_IN_PLACE ? $this->lockedConnection : $configuration->connect();
                    (new MigrationRunner($migrationConnection ?? throw new UpgradeFailure('upgrade_lock_unavailable')))->apply($stagedBundle->migrations(), $this->now);
                    UpgradeChecks::migrateContent($stagedBundle, $configuration->connect(), $report, $this->now);
                    $this->checkpoint('after-migrate');
                }
                $configuration = RuntimeConfiguration::load($stage . '/cms.json', $this->cmsRoot);
                UpgradeChecks::cms($stagedBundle, $configuration, $mode !== null, $stage . '/validation-site');
                if (UpgradeChecks::publicRelease($configuration) !== $saved['publicReleaseSha256']) {
                    throw new UpgradeFailure('active_public_release_changed');
                }
                $candidate = ['releaseId' => $id, 'runtime' => $stage . '/bundle/private/runtime', 'config' => $stage . '/cms.json', 'assets' => $stage . '/bundle/public/assets', 'engineVersion' => $bundle->engineVersion];
                $journal['candidate'] = $candidate;
                $journal['candidateConfigSha256'] = hash_file('sha256', $stage . '/cms.json');
                $journal['activeDurableFingerprint'] = (new DatabaseBackup($configuration->connect()))->durableFingerprint();
                $journal['status'] = 'ready';
                $this->journal($journal);
                $this->checkpoint('before-switch');
                UpgradeFiles::writeJson($this->stateRoot . '/active.json', $candidate);
                $this->checkpoint('after-switch');
                $journal['status'] = 'active';
                $this->journal($journal);
                $this->maintenance(false);
                return ['status' => 'upgraded', 'releaseId' => $id, 'bundleSha256' => $bundle->checksum, 'databaseMode' => $mode, 'tableSet' => $tableSet];
            } catch (\Throwable $error) {
                // Isolated modes need no compensation: the atomic pointer is still old or already checked/new.
                if ($this->active()['releaseId'] === $previous['releaseId']) {
                    $journal['errorCode'] = $error instanceof UpgradeFailure ? $error->codeName : 'upgrade_preparation_failed';
                    if ($mode === RuntimeConfiguration::UPGRADE_IN_PLACE) {
                        // A failed restore leaves the marker and this status for recovery to finish.
                        $journal['status'] = 'restoring';
                        $this->journal($journal);
                        $this->restoreServing($journal);
                    } elseif ($tableSet !== null && $staged !== null) {
                        // The candidate table set never served; free its space for the next attempt.
                        $staged->drop();
                    }
                    $journal['status'] = 'failed';
                    $this->journal($journal);
                    $this->maintenance(false);
                }
                throw $error;
            }
        });
    }

    /**
     * Recover only a committed pointer; unfinished preparation is safely aborted, never guessed complete.
     * @return array<string, mixed>
     */
    public function recover(): array
    {
        return $this->locked(function (): array {
            $journal = UpgradeFiles::json($this->stateRoot . '/journal.json');
            if (($journal['status'] ?? null) === 'rolling_back') {
                return $this->finishInPlaceRollback($journal);
            }
            if (isset($journal['candidate']) && $this->active() === $journal['candidate']) {
                $bundle = UpgradeBundle::load($journal['stage'] . '/bundle');
                if ($bundle->checksum !== $journal['bundleSha256'] || hash_file('sha256', $journal['candidate']['config']) !== ($journal['candidateConfigSha256'] ?? null)) {
                    throw new UpgradeFailure('checked_candidate_changed_recovery_refused');
                }
                $configuration = RuntimeConfiguration::load($journal['candidate']['config'], $this->cmsRoot);
                UpgradeChecks::publicRelease($configuration);
                $journal['status'] = 'active';
                $this->journal($journal);
                $this->maintenance(false);
                return ['status' => 'upgraded', 'recovered' => true];
            }
            if ($this->active() !== $journal['previous']) {
                throw new UpgradeFailure('upgrade_pointer_conflict');
            }
            if (self::mode($journal) === RuntimeConfiguration::UPGRADE_IN_PLACE && ($this->underMaintenance() || !in_array($journal['status'], ['failed', 'rolled_back'], true))) {
                $journal['status'] = 'restoring';
                $this->journal($journal);
                $this->restoreServing($journal);
                $journal['status'] = 'failed';
                $journal['errorCode'] = 'interrupted_in_place_upgrade_backup_restored';
                $this->journal($journal);
                $this->maintenance(false);
                return ['status' => 'previous_release_retained', 'databaseRestored' => true];
            }
            $journal['status'] = 'failed';
            $journal['errorCode'] = 'interrupted_preparation_previous_release_retained';
            $this->journal($journal);
            return ['status' => 'previous_release_retained', 'standbyMayContainInterruptedWork' => $journal['standbyIdentity'] !== null,
                'interruptedTableSet' => $journal['tableSet'] ?? null];
        });
    }

    /**
     * One command; never discards post-backup content to make rollback appear successful.
     * @return array<string, mixed>
     */
    public function rollback(): array
    {
        return $this->locked(function (): array {
            $journal = UpgradeFiles::json($this->stateRoot . '/journal.json');
            $backup = $this->verifyBackup($journal['backup'], false);
            if (hash_file('sha256', $journal['backup'] . '/backup.json') !== $journal['backupSha256']) {
                throw new UpgradeFailure('rollback_backup_identity_changed');
            }
            if ($this->active() === $journal['previous']) {
                $journal['status'] = 'rolled_back';
                $this->journal($journal);
                return ['status' => 'rolled_back', 'laterContentPreserved' => true];
            }
            if (!isset($journal['candidate']) || $this->active() !== $journal['candidate']) {
                throw new UpgradeFailure('upgrade_pointer_conflict');
            }
            $previous = RuntimeConfiguration::load($journal['previous']['config'], $this->cmsRoot);
            $current = RuntimeConfiguration::load($journal['candidate']['config'], $this->cmsRoot);
            $mode = self::mode($journal);
            $sameDatabase = (new DatabaseBackup($previous->connect()))->identity() === (new DatabaseBackup($current->connect()))->identity();
            if ($mode === RuntimeConfiguration::UPGRADE_IN_PLACE && (new DatabaseBackup($current->connect()))->durableFingerprint() !== $journal['activeDurableFingerprint']) {
                throw new UpgradeFailure('rollback_cannot_preserve_later_content_without_declared_reverse_migration');
            }
            if (!$sameDatabase) {
                $oldDatabase = new DatabaseBackup($previous->connect());
                $facts = UpgradeFiles::json($journal['backup'] . '/database/database.json');
                if ($oldDatabase->fingerprint() !== $facts['fingerprint']) {
                    throw new UpgradeFailure('retained_database_changed_rollback_refused');
                }
                if ((new DatabaseBackup($current->connect()))->durableFingerprint() !== $journal['activeDurableFingerprint']) {
                    throw new UpgradeFailure('rollback_cannot_preserve_later_content_without_declared_reverse_migration');
                }
            }
            if (UpgradeFiles::inventory($journal['previous']['runtime']) !== UpgradeFiles::inventory($journal['backup'] . '/release/runtime')) {
                throw new UpgradeFailure('retained_runtime_changed_rollback_refused');
            }
            if (hash_file('sha256', $journal['previous']['config']) !== hash_file('sha256', $journal['backup'] . '/cms.json')
                || ($previous->profileRoot !== null && UpgradeFiles::inventory($previous->profileRoot) !== UpgradeFiles::inventory($journal['backup'] . '/release/profile'))) {
                throw new UpgradeFailure('retained_configuration_or_profile_changed_rollback_refused');
            }
            $currentPublic = UpgradeChecks::publicRelease($current);
            $previousPublic = UpgradeChecks::publicRelease($previous);
            if ($currentPublic !== $previousPublic || ($mode !== null && $currentPublic !== $backup['publicReleaseSha256'])) {
                throw new UpgradeFailure('public_release_changed_rollback_refused');
            }
            if ($mode === RuntimeConfiguration::UPGRADE_IN_PLACE) {
                $journal['status'] = 'rolling_back';
                $this->journal($journal);
                return $this->finishInPlaceRollback($journal);
            }
            UpgradeFiles::writeJson($this->stateRoot . '/active.json', $journal['previous']);
            $journal['status'] = 'rolled_back';
            $this->journal($journal);
            return ['status' => 'rolled_back', 'laterContentPreserved' => $mode === null];
        });
    }

    /**
     * Removes a retired table set after explicit confirmation. The serving table set and the
     * latest upgrade's rollback table set are always retained.
     * @return array<string, mixed>
     */
    public function cleanupTableSet(string $prefix, string $confirmation): array
    {
        return $this->locked(function () use ($prefix, $confirmation): array {
            if ($prefix === '' || !hash_equals($prefix, $confirmation)) {
                throw new UpgradeFailure('table_set_cleanup_confirmation_required');
            }
            try {
                $tablePrefix = new TablePrefix($prefix);
            } catch (\InvalidArgumentException) {
                throw new UpgradeFailure('table_prefix_invalid');
            }
            $active = RuntimeConfiguration::load($this->active()['config'], $this->cmsRoot);
            if ($active->tablePrefix->value === $prefix) {
                throw new UpgradeFailure('serving_table_set_retained');
            }
            $journal = is_file($this->stateRoot . '/journal.json') ? UpgradeFiles::json($this->stateRoot . '/journal.json') : null;
            if ($journal !== null && !in_array($journal['status'] ?? '', ['failed', 'rolled_back', 'active'], true)) {
                throw new UpgradeFailure('upgrade_already_pending_recover_first');
            }
            if (($journal['status'] ?? null) === 'active' && RuntimeConfiguration::load($journal['previous']['config'], $this->cmsRoot)->tablePrefix->value === $prefix) {
                throw new UpgradeFailure('rollback_table_set_retained');
            }
            $set = new DatabaseBackup($active->connect($tablePrefix));
            $tables = count($set->schemas());
            if ($tables === 0) {
                throw new UpgradeFailure('table_set_not_found');
            }
            $set->drop();
            return ['status' => 'table_set_removed', 'tablePrefix' => $prefix, 'tables' => $tables];
        });
    }

    /** @param array<string, mixed> $journal */
    private static function mode(array $journal): ?string
    {
        // Journals written before modes existed used a standby database for every database change.
        return $journal['databaseMode'] ?? (($journal['standbyIdentity'] ?? null) !== null ? RuntimeConfiguration::UPGRADE_SEPARATE_DATABASE : null);
    }

    /**
     * Restores the verified backup into the serving table set of the previous release behind
     * the maintenance marker; the caller reopens the CMS once the outcome is journaled.
     * Interrupted, it is repeated by recovery from the beginning.
     * @param array<string, mixed> $journal
     */
    private function restoreServing(array $journal): void
    {
        $this->maintenance(true);
        $previous = RuntimeConfiguration::load($journal['previous']['config'], $this->cmsRoot);
        (new DatabaseBackup($previous->connect()))->restore($journal['backup'] . '/database', true, $this->checkpoint);
    }

    /**
     * @param array<string, mixed> $journal
     * @return array<string, mixed>
     */
    private function finishInPlaceRollback(array $journal): array
    {
        $this->restoreServing($journal);
        UpgradeFiles::writeJson($this->stateRoot . '/active.json', $journal['previous']);
        $journal['status'] = 'rolled_back';
        $this->journal($journal);
        $this->maintenance(false);
        return ['status' => 'rolled_back', 'laterContentPreserved' => false, 'databaseRestored' => true];
    }

    private function underMaintenance(): bool
    {
        return file_exists($this->stateRoot . '/' . RuntimeLease::MAINTENANCE);
    }

    private function maintenance(bool $closed): void
    {
        $path = $this->stateRoot . '/' . RuntimeLease::MAINTENANCE;
        if ($closed && !$this->underMaintenance()) {
            UpgradeFiles::writeJson($path, ['formatVersion' => 1, 'since' => $this->now->format(DATE_ATOM)]);
        } elseif (!$closed && $this->underMaintenance()) {
            HostIo::required(fn() => unlink($path));
        }
    }

    /** @return array<string, mixed> */
    private function active(): array
    {
        if (is_file($this->stateRoot . '/active.json')) {
            return UpgradeFiles::json($this->stateRoot . '/active.json');
        }
        $versionPath = $this->privateRoot . '/runtime/engine-version.json';
        $version = is_file($versionPath) ? (UpgradeFiles::json($versionPath)['version'] ?? null) : '0.1.0';
        if (!is_string($version) || !ProfileManifest::semanticVersion($version)) {
            throw new UpgradeFailure('installed_engine_version_invalid');
        }
        return ['releaseId' => 'initial', 'runtime' => $this->privateRoot . '/runtime', 'config' => $this->configPath, 'assets' => $this->cmsRoot . '/assets', 'engineVersion' => $version];
    }

    /** @return array<string, mixed> */
    private function verifyBackup(string $root, bool $fresh): array
    {
        if (!is_dir($root)) {
            throw new UpgradeFailure($fresh ? 'fresh_verified_backup_required' : 'backup_unavailable');
        }
        UpgradeFiles::root($root);
        $document = UpgradeFiles::json($root . '/backup.json');
        $inventory = UpgradeFiles::inventory($root);
        unset($inventory['backup.json']);
        $created = strtotime((string) ($document['createdAt'] ?? ''));
        if (($document['formatVersion'] ?? null) !== 1 || ($document['cmsRoot'] ?? null) !== $this->cmsRoot || ($document['files'] ?? null) !== $inventory) {
            throw new UpgradeFailure('backup_verification_failed');
        }
        if ($fresh && ($created === false || $created > $this->now->getTimestamp() || $created < $this->now->getTimestamp() - 3600)) {
            throw new UpgradeFailure('fresh_verified_backup_required');
        }
        return $document;
    }

    private function privateBundle(UpgradeBundle $bundle): void
    {
        $configuration = RuntimeConfiguration::load($this->configPath, $this->cmsRoot);
        if (UpgradeFiles::within($bundle->root, $this->cmsRoot) || ($configuration->publicationRoot !== null && UpgradeFiles::within($bundle->root, $configuration->publicationRoot))) {
            throw new UpgradeFailure('bundle_must_be_private');
        }
    }

    /** @param array<string, mixed> $active */
    private function sameRuntime(UpgradeBundle $bundle, array $active): bool
    {
        $configuration = RuntimeConfiguration::load($active['config'], $this->cmsRoot);
        $oldProfile = $configuration->profileRoot === null ? null : ProfilePackage::load($configuration->profileRoot, $active['engineVersion'], allowLegacyProfile: true);
        return UpgradeFiles::inventory($active['runtime']) === UpgradeFiles::inventory($bundle->root . '/private/runtime')
            && UpgradeFiles::inventory($active['assets']) === UpgradeFiles::inventory($bundle->root . '/public/assets')
            && $oldProfile?->checksum === $bundle->profile?->checksum
            && $oldProfile?->administrationLabels->fingerprint() === $bundle->profile?->administrationLabels->fingerprint();
    }

    /** @param array<string, mixed> $active */
    private function launcher(array $active): void
    {
        if (!is_file($this->stateRoot . '/active.json')) {
            UpgradeFiles::writeJson($this->stateRoot . '/active.json', $active);
        }
        if (!is_file($this->stateRoot . '/release-launcher.php')) {
            if ($this->launcherSource === null) {
                throw new UpgradeFailure('release_dispatcher_source_required');
            }
            HostIo::createPrivateFile($this->stateRoot . '/release-launcher.php', $this->launcherSource);
        }
        // The lease is refreshed so earlier installations also honour the maintenance marker.
        $lease = (string) file_get_contents(__DIR__ . '/runtime-lease.php');
        if (!is_file($this->stateRoot . '/runtime-lease.php')) {
            HostIo::createPrivateFile($this->stateRoot . '/runtime-lease.php', $lease);
        } elseif (hash_file('sha256', $this->stateRoot . '/runtime-lease.php') !== hash('sha256', $lease)) {
            UpgradeFiles::atomic($this->stateRoot . '/runtime-lease.php', $lease);
        }
        $entry = "<?php\n\ndeclare(strict_types=1);\nputenv(" . var_export('CMS_UPGRADE_ROOT=' . $this->stateRoot, true) . ");\nrequire " . var_export($this->stateRoot . '/release-launcher.php', true) . ";\n";
        UpgradeFiles::atomic($this->cmsRoot . '/index.php', $entry);
        HostIo::required(fn() => chmod($this->cmsRoot . '/index.php', 0644));
        UpgradeFiles::atomic($this->cmsRoot . '/.htaccess', "Options -Indexes\nDirectoryIndex index.php\nRewriteEngine On\nRewriteRule ^assets/ index.php [END]\nFallbackResource /index.php\n");
        HostIo::required(fn() => chmod($this->cmsRoot . '/.htaccess', 0644));
        UpgradeFiles::atomic($this->stateRoot . '/worker.php', $entry);
    }

    /**
     * @template T
     * @param \Closure(): T $operation
     * @return T
     */
    private function locked(\Closure $operation): mixed
    {
        if (!is_dir($this->stateRoot)) {
            HostIo::required(fn() => mkdir($this->stateRoot, 0700));
        }
        $path = UpgradeFiles::path($this->stateRoot, 'runtime.lock');
        $stream = HostIo::attempt(fn() => fopen($path, 'c+b'))->value;
        if (!is_resource($stream)) {
            throw new UpgradeFailure('upgrade_lock_unavailable');
        }
        try {
            HostIo::required(fn() => chmod($path, 0600));
            if (!HostIo::attempt(fn() => flock($stream, LOCK_EX | LOCK_NB))->value) {
                throw new UpgradeFailure('installation_busy_retry');
            }
            try {
                $configuration = RuntimeConfiguration::load($this->active()['config'], $this->cmsRoot);
                $this->lockedConnection = $configuration->connect();
                return MigrationRunner::locked($this->lockedConnection, $operation);
            } finally {
                $this->lockedConnection = null;
                HostIo::required(fn() => flock($stream, LOCK_UN));
            }
        } finally {
            HostIo::required(fn() => fclose($stream));
        }
    }

    /** @param array<string, mixed> $journal */
    private function journal(array $journal): void
    {
        UpgradeFiles::writeJson($this->stateRoot . '/journal.json', $journal);
    }

    private function checkpoint(string $point): void
    {
        if ($this->checkpoint !== null) {
            ($this->checkpoint)($point);
        }
    }
}
