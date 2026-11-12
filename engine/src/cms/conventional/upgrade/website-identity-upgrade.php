<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Upgrade;

use PDO;
use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Publication\IdentityUpgradeNote;
use Utsuri\PortableCms\Conventional\Publication\MariaDbWebsitePublication;
use Utsuri\PortableCms\Conventional\Publication\WebsitePublication;

/** Reconciles declared former sources before a profile migration removes their fields. */
final class WebsiteIdentityUpgrade
{
    public static function assertIncoming(ProfileManifest $profile): void
    {
        try {
            \Utsuri\PortableCms\Conventional\Content\WebsiteIdentityProfile::assert($profile);
        } catch (\InvalidArgumentException) {
            throw new UpgradeFailure('website_identity_profile_update_required');
        }
    }

    /** @return list<array{typeId: string, nameField: ?string, iconField: ?string}> */
    public static function sources(ProfileManifest $profile): array
    {
        $sources = [];
        foreach ($profile->types as $type) {
            foreach ($type->fields as $field) {
                $mapping = $field->settings['searchSharing'] ?? [];
                if (isset($mapping['siteNameField'], $mapping['settingsType'])) {
                    $id = $mapping['settingsType'];
                    if ($profile->type($id)?->kind !== 'singleton') {
                        throw new UpgradeFailure('website_identity_source_invalid');
                    }
                    $sources[$id] ??= ['typeId' => $id, 'nameField' => null, 'iconField' => null];
                    if ($sources[$id]['nameField'] !== null && $sources[$id]['nameField'] !== $mapping['siteNameField']) {
                        throw new UpgradeFailure('website_identity_source_ambiguous');
                    }
                    $sources[$id]['nameField'] = $mapping['siteNameField'];
                }
                if ($type->kind === 'singleton' && ($field->settings['purpose'] ?? null) === 'website-icon') {
                    $sources[$type->id] ??= ['typeId' => $type->id, 'nameField' => null, 'iconField' => null];
                    if ($sources[$type->id]['iconField'] !== null) {
                        throw new UpgradeFailure('website_identity_source_ambiguous');
                    }
                    $sources[$type->id]['iconField'] = $field->id;
                }
            }
        }
        ksort($sources, SORT_STRING);
        return array_values($sources);
    }

    /** @param list<array{typeId: string, nameField: ?string, iconField: ?string}> $sources */
    public static function needed(PDO $pdo, ProfileManifest $profile, array $sources): bool
    {
        if ($sources === []) {
            return false;
        }
        $saved = (new MariaDbWebsitePublication($pdo))->load($profile->id, $profile->locales);
        $saved->assertScope($profile->id, $profile->locales);
        if ($saved->identityUpgrade !== null) {
            return false;
        }
        return self::previous($pdo, $sources) !== [];
    }

    /**
     * Runs inside the upgrade's isolated transaction; never opens a nested one.
     * @param list<array{typeId: string, nameField: ?string, iconField: ?string}> $sources
     */
    public static function migrate(PDO $pdo, ProfileManifest $profile, array $sources): void
    {
        $saved = (new MariaDbWebsitePublication($pdo))->load($profile->id, $profile->locales);
        $saved->assertScope($profile->id, $profile->locales);
        if ($saved->identityUpgrade !== null || $sources === []) {
            return;
        }
        $previous = self::previous($pdo, $sources);
        if ($previous === []) {
            return;
        }
        $document = $saved->document();
        $kept = $saved->revision() > 0 ? 'website-settings' : 'profile-settings';
        if ($kept === 'profile-settings') {
            foreach (['name', 'iconMediaId'] as $key) {
                if (count(array_unique(array_filter(array_column($previous, $key), static fn(?string $value): bool => $value !== null))) > 1) {
                    throw new UpgradeFailure('website_identity_source_ambiguous');
                }
            }
            foreach ($previous as $entry) {
                $document['websiteName'] = $entry['name'] ?? $document['websiteName'];
                $document['iconMediaId'] = $entry['iconMediaId'] ?? $document['iconMediaId'];
            }
        }
        $note = new IdentityUpgradeNote($kept, $previous);
        $document['revision'] = $saved->revision() + 1;
        $document['identityUpgrade'] = $note->document();
        $next = WebsitePublication::from($document);
        $write = $pdo->prepare('INSERT INTO cms_website_publication (profile_id, revision, document_json) VALUES (:profile, :revision, :document) ON DUPLICATE KEY UPDATE revision = VALUES(revision), document_json = VALUES(document_json)');
        $write->execute(['profile' => $profile->id, 'revision' => $next->revision(), 'document' => json_encode($next->document(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
    }

    /**
     * @param list<array{typeId: string, nameField: ?string, iconField: ?string}> $sources
     * @return list<array{typeId: string, recordId: string, name: ?string, iconMediaId: ?string}>
     */
    private static function previous(PDO $pdo, array $sources): array
    {
        $previous = [];
        foreach ($sources as $source) {
            $query = $pdo->prepare('SELECT r.id, v.body_json FROM cms_records r JOIN cms_record_revisions v ON v.id = r.current_revision_id WHERE r.type_id = :type ORDER BY r.id LIMIT 2');
            $query->execute(['type' => $source['typeId']]);
            $rows = $query->fetchAll(PDO::FETCH_ASSOC);
            if (count($rows) > 1) {
                throw new UpgradeFailure('website_identity_source_ambiguous');
            }
            foreach ($rows as $row) {
                $body = json_decode((string) $row['body_json'], true, 32, JSON_THROW_ON_ERROR);
                if (!is_array($body)) {
                    throw new UpgradeFailure('website_identity_source_invalid');
                }
                $name = $source['nameField'] === null ? null : ($body[$source['nameField']] ?? null);
                $icons = $source['iconField'] === null ? [] : ($body[$source['iconField']] ?? []);
                if (($name !== null && !is_string($name)) || !is_array($icons) || !array_is_list($icons) || count($icons) > 1
                    || (isset($icons[0]) && !is_string($icons[0]))) {
                    throw new UpgradeFailure('website_identity_source_invalid');
                }
                $name = $name !== null && trim($name) === '' ? null : $name;
                $icon = $icons[0] ?? null;
                if ($name !== null || $icon !== null) {
                    $previous[] = ['typeId' => $source['typeId'], 'recordId' => (string) $row['id'], 'name' => $name, 'iconMediaId' => $icon];
                }
            }
        }
        return $previous;
    }
}
