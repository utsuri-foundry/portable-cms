<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Upgrade;

use PDO;
use Utsuri\PortableCms\Conventional\ApplicationFactory;
use Utsuri\PortableCms\Conventional\Content\ContentService;
use Utsuri\PortableCms\Conventional\Content\MariaDbRecordRepository;
use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Http\Request;
use Utsuri\PortableCms\Conventional\Identity\SystemClock;
use Utsuri\PortableCms\Conventional\Identity\SystemRandomSource;
use Utsuri\PortableCms\Conventional\Media\FilesystemMediaStore;
use Utsuri\PortableCms\Conventional\Media\MariaDbMediaLibrary;
use Utsuri\PortableCms\Conventional\Media\MediaLibrary;
use Utsuri\PortableCms\Conventional\RuntimeConfiguration;

final class UpgradeChecks
{
    /** @param array<string, mixed> $report */
    public static function migrateContent(UpgradeBundle $bundle, PDO $pdo, array $report, \DateTimeImmutable $now): void
    {
        $migration = $report['contentAffectingChanges'] === [] ? null : UpgradePlan::migration($bundle, $report['profile']['from'], $report['profile']['to']);
        if ($migration === null && $report['contentNeedingMigration'] !== []) {
            throw new UpgradeFailure('content_requires_declared_migration');
        }
        if ($report['contentAffectingChanges'] === [] && $report['pendingMigrations'] === []) {
            // Retag the unchanged contract for normalized restore, without mapping or new revisions.
            $pdo->beginTransaction();
            try {
                WebsiteIdentityUpgrade::migrate($pdo, $bundle->profile->manifest ?? ProfileManifest::gateZero(), $report['identitySources'] ?? []);
                $tag = $pdo->prepare('UPDATE cms_record_revisions SET profile_version = :version WHERE profile_id = :profile AND profile_version <> :previous');
                $tag->execute(['version' => $report['profile']['to'], 'profile' => $report['profile']['id'], 'previous' => $report['profile']['to']]);
                self::audit($bundle, $pdo, $report, $now, 0);
                $pdo->commit();
            } catch (\Throwable $error) {
                $pdo->rollBack();
                throw $error;
            }
            return;
        }
        // Only the isolated database is touched. Original history remains in the verified backup.
        $rows = $pdo->query('SELECT v.id, r.type_id, v.body_json FROM cms_record_revisions v JOIN cms_records r ON r.id = v.record_id ORDER BY v.id LIMIT 5001')->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) > 5000) {
            throw new UpgradeFailure('profile_upgrade_revision_limit');
        }
        $pdo->beginTransaction();
        try {
            WebsiteIdentityUpgrade::migrate($pdo, $bundle->profile->manifest ?? ProfileManifest::gateZero(), $report['identitySources'] ?? []);
            $update = $pdo->prepare('UPDATE cms_record_revisions SET body_json = :body, profile_version = :version WHERE id = :id');
            foreach ($rows as $row) {
                if ($migration === null) {
                    continue;
                }
                $body = json_decode((string) $row['body_json'], true, 32, JSON_THROW_ON_ERROR);
                if (!is_array($body)) {
                    throw new UpgradeFailure('profile_migration_content_invalid');
                }
                $mapped = $migration((string) $row['type_id'], $body);
                if (!is_array($mapped) || ($mapped !== [] && array_is_list($mapped))) {
                    throw new UpgradeFailure('profile_migration_content_invalid');
                }
                $update->execute(['id' => $row['id'], 'version' => $report['profile']['to'], 'body' => json_encode($mapped, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]);
            }
            // Invalidate forms opened before either SQL or profile changes through ordinary revision checks.
            $current = $pdo->query('SELECT r.id, r.type_id, r.current_revision_id, r.published_revision_id, r.current_revision_number, r.updated_by, v.body_json, v.profile_id FROM cms_records r JOIN cms_record_revisions v ON v.id = r.current_revision_id ORDER BY r.id LIMIT 501')->fetchAll(PDO::FETCH_ASSOC);
            if (count($current) > 500) {
                throw new UpgradeFailure('profile_upgrade_record_limit');
            }
            $insert = $pdo->prepare('INSERT INTO cms_record_revisions (id, record_id, revision_number, profile_id, profile_version, body_json, created_by, created_at) VALUES (:id, :record, :number, :profile, :version, :body, :actor, :time)');
            $advance = $pdo->prepare('UPDATE cms_records SET published_revision_id = CASE WHEN published_revision_id = current_revision_id THEN :published ELSE published_revision_id END, current_revision_id = :revision, current_revision_number = :number, updated_at = :time WHERE id = :id');
            $time = $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
            foreach ($current as $record) {
                $revision = substr(hash('sha256', 'installation-upgrade-v1:' . $bundle->checksum . ':' . $record['id'] . ':' . $record['current_revision_id']), 0, 32);
                $number = (int) $record['current_revision_number'] + 1;
                $insert->execute(['id' => $revision, 'record' => $record['id'], 'number' => $number, 'profile' => $record['profile_id'], 'version' => $report['profile']['to'], 'body' => $record['body_json'], 'actor' => $record['updated_by'], 'time' => $time]);
                $advance->execute(['id' => $record['id'], 'revision' => $revision, 'published' => $revision, 'number' => $number, 'time' => $time]);
                (new \Utsuri\PortableCms\Conventional\Database\MariaDbSiteSequence($pdo))->revision($record['type_id'], $record['id'], $revision, $number, $now);
            }
            self::audit($bundle, $pdo, $report, $now, count($current));
            $pdo->commit();
        } catch (\Throwable $error) {
            $pdo->rollBack();
            throw $error;
        }
    }

    /** @param array<string, mixed> $report */
    private static function audit(UpgradeBundle $bundle, PDO $pdo, array $report, \DateTimeImmutable $now, int $records): void
    {
        $audit = $pdo->prepare('INSERT INTO cms_audit_events (id, actor_user_id, event_type, metadata_json, occurred_at) VALUES (:id, NULL, :event, :metadata, :time)');
        $audit->execute(['id' => substr(hash('sha256', 'installation-upgrade-audit:' . $bundle->checksum), 0, 32), 'event' => $report['manifestChanges'] === [] ? 'operator_engine_upgrade' : 'operator_profile_upgrade', 'metadata' => json_encode(['bundleSha256' => $bundle->checksum, 'engine' => $report['engine'], 'fromVersion' => $report['profile']['from'], 'toVersion' => $report['profile']['to'], 'records' => $records, 'contentNeutralChanges' => $report['contentNeutralChanges'], 'contentAffectingChanges' => $report['contentAffectingChanges']], JSON_THROW_ON_ERROR), 'time' => $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u')]);
    }

    public static function cms(UpgradeBundle $bundle, RuntimeConfiguration $configuration, bool $rebuildRelationships = false, ?string $validationRoot = null): void
    {
        $pdo = $configuration->connect();
        $profile = $bundle->profile->manifest ?? ProfileManifest::gateZero();
        if ((\Utsuri\PortableCms\Conventional\DatabaseReport::recordVersions($pdo)['invalidPublished'] ?? 0) !== 0) {
            throw new UpgradeFailure('published_revision_invalid');
        }
        $terminology = (new \Utsuri\PortableCms\Conventional\Content\MariaDbTerminologyRepository($pdo))->load($profile->id);
        \Utsuri\PortableCms\Conventional\Content\AdministrationMenuLabels::assert($profile, $bundle->profile?->administrationLabels, $terminology);
        $clock = new SystemClock();
        $random = new SystemRandomSource();
        $media = new MediaLibrary(new MariaDbMediaLibrary($pdo), new FilesystemMediaStore($configuration->mediaRoot), $clock, $random);
        $design = $profile->designRegistries === null ? null : (new \Utsuri\PortableCms\Conventional\Design\MariaDbRegistryRepository($pdo))->load($profile->designRegistries);
        // Text in languages in preparation or off is never required, as when editors save.
        $languages = (new \Utsuri\PortableCms\Conventional\Content\MariaDbSiteLanguages($pdo))->load($profile);
        // Revisions in languages the installation added are checked as the upgraded profile reads them: an
        // envelope that no longer admits such a language refuses the upgrade instead of hiding its text.
        $profile = $languages->installed($profile);
        $service = new ContentService(new MariaDbRecordRepository($pdo), $profile, $clock, $random, $media, publicLocales: static fn(): array => $languages->public());
        $rows = $pdo->query('SELECT v.id, r.id AS record_id, r.type_id, r.current_revision_id, r.published_revision_id, v.body_json FROM cms_record_revisions v JOIN cms_records r ON r.id = v.record_id ORDER BY v.id LIMIT 5001')->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) > 5000) {
            throw new UpgradeFailure('profile_upgrade_revision_limit');
        }
        foreach ($rows as $row) {
            $body = json_decode((string) $row['body_json'], true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($body)) {
                throw new UpgradeFailure('profile_migration_content_invalid');
            }
            $validated = $service->validateCanonical((string) $row['type_id'], $body, $design);
            if ($rebuildRelationships && in_array($row['id'], [$row['current_revision_id'], $row['published_revision_id']], true)) {
                $delete = $pdo->prepare('DELETE FROM cms_record_relationship_index WHERE record_id = :id AND revision_id = :revision');
                $delete->execute(['id' => $row['record_id'], 'revision' => $row['id']]);
                $insert = $pdo->prepare('INSERT INTO cms_record_relationship_index (record_id, revision_id, field_id, relation_kind, target_record_id, position) VALUES (:record, :revision, :field, :kind, :target, :position)');
                foreach ($validated->relationships as $relationship) {
                    $insert->execute(['record' => $row['record_id'], 'revision' => $row['id'], 'field' => $relationship->fieldId, 'kind' => $relationship->kind, 'target' => $relationship->targetRecordId, 'position' => $relationship->position]);
                }
            }
        }
        if ($bundle->profile !== null) {
            $bundle->profile->siteBuilder();
            if ($validationRoot !== null && $configuration->publicationRoot !== null && is_file($configuration->publicationRoot . '/artifact.json')) {
                $repository = new \Utsuri\PortableCms\Conventional\Preview\MariaDbPreviewRepository($pdo, $profile->designRegistries, $bundle->profile->checksum, publicUrlContext: $configuration->publicUrlContext);
                $snapshot = $repository->freezeSnapshot($profile->id, $profile->version, $profile->locales, $clock->now(), false);
                $compiler = new \Utsuri\PortableCms\Conventional\Preview\ProfilePreviewCompiler($bundle->profile, $configuration->mediaRoot, dirname(__DIR__, 5) . '/schemas');
                $compiler->preflight($snapshot);
                $compiler->compile($snapshot, $validationRoot);
            }
        }
        $response = ApplicationFactory::identity($configuration)->handle(new Request('GET', '/login', remoteAddress: '127.0.0.1'));
        if ($response->status !== 200 || !str_contains($response->body, '<form')) {
            throw new UpgradeFailure('candidate_cms_check_failed');
        }
    }

    /** Check the applied static artifact against its database identity and exact delivery bytes. */
    public static function publicRelease(RuntimeConfiguration $configuration): string
    {
        $pdo = $configuration->connect();
        $state = $pdo->query('SELECT active_release, pending_release FROM cms_publication_state WHERE singleton_id = 1')->fetch(PDO::FETCH_ASSOC);
        if (!is_array($state) || $state['pending_release'] !== null) {
            throw new UpgradeFailure('publication_must_be_settled');
        }
        if ($state['active_release'] === null) {
            if ($configuration->publicationRoot !== null && UpgradeFiles::inventory($configuration->publicationRoot) !== []) {
                throw new UpgradeFailure('unmanaged_public_release');
            }
            return 'no-active-release';
        }
        if ($configuration->publicationRoot === null || $configuration->publicUrlContext === null) {
            throw new UpgradeFailure('public_release_check_unavailable');
        }
        $root = UpgradeFiles::root($configuration->publicationRoot);
        $json = (string) file_get_contents(UpgradeFiles::path($root, 'artifact.json'));
        $statement = $pdo->prepare('SELECT artifact_sha256 FROM cms_releases WHERE id = :id AND status = \'active\'');
        $statement->execute(['id' => $state['active_release']]);
        if ($statement->fetchColumn() !== hash('sha256', $json)) {
            throw new UpgradeFailure('active_public_artifact_changed');
        }
        $document = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        $entries = [];
        foreach ($document['files'] ?? [] as $file) {
            $path = UpgradeFiles::path($root, $file['path']);
            if (!is_file($path) || filesize($path) !== $file['byteSize'] || hash_file('sha256', $path) !== $file['sha256']) {
                throw new UpgradeFailure('active_public_file_changed');
            }
            $entries[] = new \Utsuri\PortableCms\Conventional\Preview\PreviewArtifact($file['path'], $file['mime'], $file['byteSize'], $file['sha256']);
        }
        $entries[] = new \Utsuri\PortableCms\Conventional\Preview\PreviewArtifact('artifact.json', 'application/json', strlen($json), hash('sha256', $json));
        $inventory = new \Utsuri\PortableCms\Conventional\Preview\PreviewBuild($entries);
        $artifact = new \Utsuri\PortableCms\Conventional\Publication\ReleaseArtifact($json, $inventory, $configuration->publicUrlContext, $document['profile']['id'], (string) file_get_contents(dirname(__DIR__, 5) . '/schemas/portable-cms-artifact-v1.schema.json'));
        if (file_get_contents(UpgradeFiles::path($root, '.htaccess')) !== $artifact->rules()) {
            throw new UpgradeFailure('active_public_delivery_changed');
        }
        foreach (array_keys(UpgradeFiles::inventory($root)) as $path) {
            if (preg_match('/\.(?:php[0-9]*|phtml|phar)(?:\.|$)/i', $path) === 1) {
                throw new UpgradeFailure('public_php_refused');
            }
        }
        return $artifact->checksum;
    }
}
