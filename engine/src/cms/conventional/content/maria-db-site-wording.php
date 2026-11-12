<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Content;

/** The current site wording and every saved revision of it, with an audit event per save. */
final readonly class MariaDbSiteWording implements SiteWordingRepository
{
    public function __construct(private \PDO $pdo) {}

    public function load(ProfileManifest $profile): SiteWording
    {
        return $this->find($profile) ?? SiteWording::defaults($profile);
    }

    /** The saved wording, or null before anyone has saved it. */
    public function find(ProfileManifest $profile): ?SiteWording
    {
        $query = $this->pdo->prepare('SELECT document_json FROM cms_site_wording WHERE profile_id = :profile');
        $query->execute(['profile' => $profile->id]);
        $json = $query->fetchColumn();
        return is_string($json) ? SiteWording::stored(json_decode($json, true, 8, JSON_THROW_ON_ERROR), $profile) : null;
    }

    public function save(SiteWording $next, int $expected, array $changed, string $actorId, string $auditId, \DateTimeImmutable $now): void
    {
        $document = json_encode($next->document(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $time = $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        $this->pdo->beginTransaction();
        try {
            if ($expected === 0) {
                $seed = $this->pdo->prepare('INSERT IGNORE INTO cms_site_wording (profile_id, revision, document_json) VALUES (:profile, 0, :document)');
                $seed->execute(['profile' => $next->profileId, 'document' => json_encode(['formatVersion' => 1, 'profileId' => $next->profileId, 'revision' => 0, 'values' => []], JSON_THROW_ON_ERROR)]);
            }
            $update = $this->pdo->prepare('UPDATE cms_site_wording SET revision = :next, document_json = :document WHERE profile_id = :profile AND revision = :expected');
            $update->execute(['next' => $next->revision, 'document' => $document, 'profile' => $next->profileId, 'expected' => $expected]);
            if ($update->rowCount() !== 1) {
                throw new SiteWordingConflict();
            }
            $history = $this->pdo->prepare('INSERT INTO cms_site_wording_revisions (profile_id, revision, document_json, actor_user_id, created_at) VALUES (:profile, :revision, :document, :actor, :time)');
            $history->execute(['profile' => $next->profileId, 'revision' => $next->revision, 'document' => $document, 'actor' => $actorId, 'time' => $time]);
            $audit = $this->pdo->prepare("INSERT INTO cms_audit_events (id, actor_user_id, event_type, metadata_json, occurred_at) VALUES (:id, :actor, 'site_wording_saved', :metadata, :time)");
            $audit->execute(['id' => $auditId, 'actor' => $actorId, 'metadata' => json_encode(['profileId' => $next->profileId, 'revision' => $next->revision, 'keys' => array_slice($changed, 0, 200)], JSON_THROW_ON_ERROR), 'time' => $time]);
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }
}
