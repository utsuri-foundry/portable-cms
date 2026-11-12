<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Design;

final class MariaDbRegistryRepository implements RegistryRepository
{
    public function __construct(private readonly \PDO $pdo) {}

    public function load(RegistryDocument $defaults, ?int $revision = null): RegistryDocument
    {
        if ($revision === 0) {
            return $defaults;
        }
        $sql = $revision === null
            ? 'SELECT document_json FROM cms_design_registries WHERE profile_id = :profile'
            : 'SELECT document_json FROM cms_design_registry_revisions WHERE profile_id = :profile AND revision = :revision';
        $query = $this->pdo->prepare($sql);
        $parameters = ['profile' => $defaults->profileId()];
        if ($revision !== null) {
            $parameters['revision'] = $revision;
        }
        $query->execute($parameters);
        $json = $query->fetchColumn();
        if ($json === false) {
            if ($revision !== null) {
                throw new \InvalidArgumentException('Requested design revision is unavailable.');
            }
            return $defaults;
        }
        $result = RegistryDocument::fromDocument(json_decode((string) $json, true, 32, JSON_THROW_ON_ERROR));
        if ($result->profileId() !== $defaults->profileId() || ($revision !== null && $result->revision() !== $revision)) {
            throw new \RuntimeException('Stored design identity differs.');
        }
        return $result;
    }

    public function save(RegistryDocument $intent, string $actor, string $auditId, \DateTimeImmutable $now): RegistryDocument
    {
        foreach ([$actor, $auditId] as $id) {
            if (preg_match('/\A[a-f0-9]{32}\z/', $id) !== 1) {
                throw new \InvalidArgumentException('Design audit identity is invalid.');
            }
        }
        $this->pdo->beginTransaction();
        try {
            $create = $this->pdo->prepare('INSERT IGNORE INTO cms_design_registries (profile_id, revision, document_json) VALUES (:profile, 0, :document)');
            $create->execute(['profile' => $intent->profileId(), 'document' => self::json($intent->withRevision(0))]);
            $lock = $this->pdo->prepare('SELECT revision FROM cms_design_registries WHERE profile_id = :profile FOR UPDATE');
            $lock->execute(['profile' => $intent->profileId()]);
            if ((int) $lock->fetchColumn() !== $intent->revision()) {
                throw new RegistryConflict($this->load($intent->withRevision(0)));
            }
            $next = $intent->withRevision($intent->revision() + 1);
            $json = self::json($next);
            $update = $this->pdo->prepare('UPDATE cms_design_registries SET revision = :next, document_json = :document WHERE profile_id = :profile AND revision = :expected');
            $update->execute(['next' => $next->revision(), 'document' => $json, 'profile' => $intent->profileId(), 'expected' => $intent->revision()]);
            if ($update->rowCount() !== 1) {
                throw new \RuntimeException('Design compare-and-set failed.');
            }
            $time = $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
            $history = $this->pdo->prepare('INSERT INTO cms_design_registry_revisions (profile_id, revision, document_json, actor_user_id, created_at) VALUES (:profile, :revision, :document, :actor, :time)');
            $history->execute(['profile' => $next->profileId(), 'revision' => $next->revision(), 'document' => $json, 'actor' => $actor, 'time' => $time]);
            $audit = $this->pdo->prepare("INSERT INTO cms_audit_events (id, actor_user_id, event_type, metadata_json, occurred_at) VALUES (:id, :actor, 'website_design_saved', :metadata, :time)");
            $audit->execute(['id' => $auditId, 'actor' => $actor, 'metadata' => json_encode(['profileId' => $next->profileId(), 'revision' => $next->revision(), 'sha256' => $next->checksum()], JSON_THROW_ON_ERROR), 'time' => $time]);
            $this->pdo->commit();
            return $next;
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    private static function json(RegistryDocument $document): string
    {
        return json_encode($document->document(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }
}
