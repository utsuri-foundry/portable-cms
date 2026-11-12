<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Publication;

final readonly class MariaDbWebsitePublication implements WebsitePublicationRepository
{
    public function __construct(private \PDO $pdo) {}

    public function load(string $profileId, array $locales): WebsitePublication
    {
        $query = $this->pdo->prepare('SELECT document_json FROM cms_website_publication WHERE profile_id = :profile');
        $query->execute(['profile' => $profileId]);
        $json = $query->fetchColumn();
        // An upgrade must not turn an already activated website into a holding page.
        $existingLive = $this->pdo->query('SELECT p.build_kind FROM cms_publication_state s JOIN cms_releases r ON r.id = s.active_release JOIN cms_previews p ON p.id = r.preview_id WHERE s.singleton_id = 1')->fetchColumn();
        // Languages added or removed since the settings were saved keep the others unchanged.
        return is_string($json) ? WebsitePublication::from(json_decode($json, true, 16, JSON_THROW_ON_ERROR))->within($locales) : WebsitePublication::defaults($profileId, $locales, $existingLive === 'website');
    }

    public function save(WebsitePublication $next, int $expected, string $actorId, string $auditId, \DateTimeImmutable $now): void
    {
        $document = $next->document();
        $this->pdo->beginTransaction();
        try {
            if ($expected === 0) {
                $seed = $this->pdo->prepare('INSERT IGNORE INTO cms_website_publication (profile_id, revision, document_json) VALUES (:profile, 0, :document)');
                $seed->execute(['profile' => $document['profileId'], 'document' => json_encode($document, JSON_THROW_ON_ERROR)]);
            }
            $update = $this->pdo->prepare('UPDATE cms_website_publication SET revision = :next, document_json = :document WHERE profile_id = :profile AND revision = :expected');
            $update->execute(['next' => $next->revision(), 'document' => json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 'profile' => $document['profileId'], 'expected' => $expected]);
            if ($update->rowCount() !== 1) {
                throw new PublicationConflict();
            }
            if ($document['iconMediaId'] !== null) {
                $icon = $this->pdo->prepare("SELECT m.status, d.mime, d.width, d.height FROM cms_media m JOIN cms_media_derivatives d ON d.media_id = m.id AND d.variant = '960-webp' WHERE m.id = :id FOR UPDATE");
                $icon->execute(['id' => $document['iconMediaId']]);
                $facts = $icon->fetch(\PDO::FETCH_ASSOC);
                if (!is_array($facts) || !\Utsuri\PortableCms\Conventional\Content\WebsiteIconSelection::eligible((string) $facts['status'], (string) $facts['mime'], (int) $facts['width'], (int) $facts['height'])) {
                    throw new PublicationFailure('holding_icon_not_ready');
                }
            }
            foreach (array_unique(array_filter($next->administrationBranding->document())) as $id) {
                $logo = $this->pdo->prepare("SELECT m.status, m.detected_type, d.mime, d.width, d.height, d.byte_size FROM cms_media m JOIN cms_media_derivatives d ON d.media_id = m.id AND d.variant = '960-webp' WHERE m.id = :id FOR UPDATE");
                $logo->execute(['id' => $id]);
                $facts = $logo->fetch(\PDO::FETCH_ASSOC);
                if (!is_array($facts) || $facts['mime'] !== 'image/webp' || !AdministrationBranding::eligible((string) $facts['status'], (string) $facts['detected_type'], (int) $facts['width'], (int) $facts['height'], (int) $facts['byte_size'])) {
                    throw new PublicationFailure('administration_logo_not_ready');
                }
            }
            $audit = $this->pdo->prepare('INSERT INTO cms_audit_events (id, actor_user_id, event_type, metadata_json, occurred_at) VALUES (:id, :actor, :event, :metadata, :time)');
            $audit->execute(['id' => $auditId, 'actor' => $actorId, 'event' => 'website_publication_saved',
                'metadata' => json_encode(['profileId' => $document['profileId'], 'revision' => $next->revision(), 'mode' => $next->mode()], JSON_THROW_ON_ERROR),
                'time' => $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u')]);
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }
}
