<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Content;

use PDO;

final class MariaDbTerminologyRepository implements TerminologyRepository
{
    public function __construct(private readonly PDO $pdo)
    {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }

    public function load(string $profileId): EditorialTerminology
    {
        $query = $this->pdo->prepare('SELECT revision, labels_json FROM cms_editorial_terminology WHERE profile_id = :profile');
        $query->execute(['profile' => $profileId]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return new EditorialTerminology($profileId, 0, []);
        }
        return EditorialTerminology::fromDocument(['formatVersion' => 1, 'profileId' => $profileId,
            'revision' => (int) $row['revision'], 'labels' => json_decode((string) $row['labels_json'], true, 8, JSON_THROW_ON_ERROR)]);
    }

    public function save(EditorialTerminology $intent, string $actor, string $auditId, \DateTimeImmutable $now): EditorialTerminology
    {
        foreach ([$actor, $auditId] as $id) {
            if (preg_match('/\A[a-f0-9]{32}\z/', $id) !== 1) {
                throw new \InvalidArgumentException('Terminology actor or audit identity is invalid');
            }
        }
        $this->pdo->beginTransaction();
        try {
            $create = $this->pdo->prepare("INSERT IGNORE INTO cms_editorial_terminology (profile_id, revision, labels_json) VALUES (:profile, 0, '[]')");
            $create->execute(['profile' => $intent->profileId]);
            $lock = $this->pdo->prepare('SELECT revision FROM cms_editorial_terminology WHERE profile_id = :profile FOR UPDATE');
            $lock->execute(['profile' => $intent->profileId]);
            if ((int) $lock->fetchColumn() !== $intent->revision) {
                throw new TerminologyConflict($this->load($intent->profileId));
            }
            $next = new EditorialTerminology($intent->profileId, $intent->revision + 1, $intent->labels);
            $json = json_encode($intent->labels, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $update = $this->pdo->prepare('UPDATE cms_editorial_terminology SET revision = :next, labels_json = :labels WHERE profile_id = :profile AND revision = :expected');
            $update->execute(['next' => $next->revision, 'labels' => $json, 'profile' => $intent->profileId, 'expected' => $intent->revision]);
            if ($update->rowCount() !== 1) {
                throw new \RuntimeException('Terminology compare-and-set failed');
            }
            $time = $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
            $history = $this->pdo->prepare('INSERT INTO cms_editorial_terminology_revisions (profile_id, revision, labels_json, actor_user_id, created_at) VALUES (:profile, :revision, :labels, :actor, :time)');
            $history->execute(['profile' => $intent->profileId, 'revision' => $next->revision, 'labels' => $json, 'actor' => $actor, 'time' => $time]);
            $audit = $this->pdo->prepare("INSERT INTO cms_audit_events (id, actor_user_id, event_type, metadata_json, occurred_at) VALUES (:id, :actor, 'editorial_terminology_saved', :metadata, :time)");
            $audit->execute(['id' => $auditId, 'actor' => $actor, 'metadata' => json_encode(['profileId' => $intent->profileId, 'revision' => $next->revision], JSON_THROW_ON_ERROR), 'time' => $time]);
            $this->pdo->commit();
            return $next;
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }
}
