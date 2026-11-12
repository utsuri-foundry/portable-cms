<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Content;

final readonly class MariaDbSiteLanguages implements SiteLanguagesRepository
{
    public function __construct(private \PDO $pdo) {}

    public function load(ProfileManifest $profile): SiteLanguages
    {
        return $this->find($profile) ?? SiteLanguages::defaults($profile);
    }

    /** The saved choice, or null before anyone has saved one. */
    public function find(ProfileManifest $profile): ?SiteLanguages
    {
        $query = $this->pdo->prepare('SELECT document_json FROM cms_site_languages WHERE profile_id = :profile');
        $query->execute(['profile' => $profile->id]);
        $json = $query->fetchColumn();
        return is_string($json) ? SiteLanguages::stored(json_decode($json, true, 8, JSON_THROW_ON_ERROR), $profile) : null;
    }

    /**
     * The saved choice for languages a caller already knows, such as an installed profile's, or null before anyone has saved one.
     * @param list<string> $locales
     */
    public function scoped(string $profileId, array $locales): ?SiteLanguages
    {
        $query = $this->pdo->prepare('SELECT document_json FROM cms_site_languages WHERE profile_id = :profile');
        $query->execute(['profile' => $profileId]);
        $json = $query->fetchColumn();
        return is_string($json) ? SiteLanguages::scoped(json_decode($json, true, 8, JSON_THROW_ON_ERROR), $profileId, $locales) : null;
    }

    /** The profile package's manifest with the languages this installation added. */
    public function installed(ProfileManifest $profile): ProfileManifest
    {
        // Without an envelope nothing can have been added, and the choice need not be read.
        return $profile->envelope === null ? $profile : $this->load($profile)->installed($profile);
    }

    public function save(SiteLanguages $next, int $expected, string $actorId, string $auditId, \DateTimeImmutable $now, string $event = 'site_languages_saved', array $detail = []): void
    {
        $document = $next->document();
        $this->pdo->beginTransaction();
        try {
            if ($expected === 0) {
                $seed = $this->pdo->prepare('INSERT IGNORE INTO cms_site_languages (profile_id, revision, document_json) VALUES (:profile, 0, :document)');
                $seed->execute(['profile' => $next->profileId, 'document' => json_encode($document, JSON_THROW_ON_ERROR)]);
            }
            $update = $this->pdo->prepare('UPDATE cms_site_languages SET revision = :next, document_json = :document WHERE profile_id = :profile AND revision = :expected');
            $update->execute(['next' => $next->revision, 'document' => json_encode($document, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 'profile' => $next->profileId, 'expected' => $expected]);
            if ($update->rowCount() !== 1) {
                throw new SiteLanguagesConflict();
            }
            $audit = $this->pdo->prepare('INSERT INTO cms_audit_events (id, actor_user_id, event_type, metadata_json, occurred_at) VALUES (:id, :actor, :event, :metadata, :time)');
            $audit->execute(['id' => $auditId, 'actor' => $actorId, 'event' => $event,
                'metadata' => json_encode([...$detail, 'profileId' => $next->profileId, 'revision' => $next->revision, 'primary' => $next->primary, 'states' => $next->states], JSON_THROW_ON_ERROR),
                'time' => $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u')]);
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function hasText(ProfileManifest $profile, string $locale): bool
    {
        // Every stored document naming the language is read by its structure; the text filter only narrows the scan.
        $named = '%"' . $locale . '"%';
        $revisions = $this->pdo->prepare('SELECT r.type_id, v.body_json FROM cms_record_revisions v JOIN cms_records r ON r.id = v.record_id WHERE v.body_json LIKE :named');
        $revisions->execute(['named' => $named]);
        while (($row = $revisions->fetch(\PDO::FETCH_ASSOC)) !== false) {
            $type = $profile->type((string) $row['type_id']);
            $body = json_decode((string) $row['body_json'], true, 64, JSON_THROW_ON_ERROR);
            if ($type !== null && is_array($body) && LanguageText::record($type->fields, $body, $locale)) {
                $revisions->closeCursor();
                return true;
            }
        }
        $documents = [
            'media' => 'SELECT text_json FROM cms_media WHERE text_json LIKE :named',
            'wording' => 'SELECT document_json FROM cms_site_wording WHERE document_json LIKE :named UNION ALL SELECT document_json FROM cms_site_wording_revisions WHERE document_json LIKE :again',
            'holding' => 'SELECT document_json FROM cms_website_publication WHERE document_json LIKE :named',
        ];
        foreach ($documents as $kind => $sql) {
            $query = $this->pdo->prepare($sql);
            $query->execute(str_contains($sql, ':again') ? ['named' => $named, 'again' => $named] : ['named' => $named]);
            foreach ($query->fetchAll(\PDO::FETCH_COLUMN) as $json) {
                $document = json_decode((string) $json, true, 16, JSON_THROW_ON_ERROR);
                if (match ($kind) {
                    'media' => LanguageText::media($document, $locale), 'wording' => LanguageText::wording($document, $locale), default => LanguageText::holding($document, $locale)
                }) {
                    return true;
                }
            }
        }
        return false;
    }
}
