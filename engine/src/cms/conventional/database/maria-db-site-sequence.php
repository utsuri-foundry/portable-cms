<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Database;

use PDO;

/** The owning transaction keeps the counter row locked until commit or rollback. */
final readonly class MariaDbSiteSequence
{
    public function __construct(private PDO $pdo) {}

    public function current(): int
    {
        $value = $this->pdo->query('SELECT current_sequence FROM cms_site_sequence WHERE singleton_id = 1')->fetchColumn();
        if ($value === false) {
            throw new \RuntimeException('Site sequence state is missing');
        }
        return SiteSequence::value((int) $value);
    }

    public function revision(string $type, string $record, string $revision, int $number, \DateTimeImmutable $now): int
    {
        $sequence = $this->append('record_revision', $type, $record, $revision, $number, null, null, $now);
        $query = $this->pdo->prepare('UPDATE cms_record_revisions SET site_sequence = :sequence WHERE id = :id AND record_id = :record AND revision_number = :number AND site_sequence IS NULL');
        $query->execute(['sequence' => $sequence, 'id' => $revision, 'record' => $record, 'number' => $number]);
        if ($query->rowCount() !== 1) {
            throw new \LogicException('A site sequence requires a new record revision');
        }
        return $sequence;
    }

    public function removal(string $type, string $record, string $revision, int $number, \DateTimeImmutable $now): int
    {
        return $this->append('record_removed', $type, $record, $revision, $number, null, null, $now);
    }

    /** An unsealed editing revision receives a new cursor for every committed change. */
    public function edit(string $type, string $record, string $revision, int $number, \DateTimeImmutable $now): int
    {
        $sequence = $this->append('record_revision', $type, $record, $revision, $number, null, null, $now);
        $this->pdo->prepare('UPDATE cms_record_revisions SET site_sequence = :sequence WHERE id = :id AND record_id = :record AND revision_number = :number')->execute(['sequence' => $sequence, 'id' => $revision, 'record' => $record, 'number' => $number]);
        return $sequence;
    }

    public function activation(string $release, string $preview, \DateTimeImmutable $now): int
    {
        $sequence = $this->append('release_activated', null, null, null, null, $release, $preview, $now);
        $query = $this->pdo->prepare('UPDATE cms_releases SET site_sequence = :sequence WHERE id = :id AND site_sequence IS NULL');
        $query->execute(['sequence' => $sequence, 'id' => $release]);
        if ($query->rowCount() !== 1) {
            throw new \LogicException('A site sequence requires a new activation');
        }
        return $sequence;
    }

    /** Restore history facts without replaying events or lowering a destination's high-water mark. */
    public function restore(int $source): void
    {
        SiteSequence::value($source);
        $state = $this->locked();
        $next = max($source, (int) $state['current_sequence']);
        $this->pdo->prepare('UPDATE cms_site_sequence SET current_sequence = :current, pruned_through = :floor WHERE singleton_id = 1')->execute(['current' => $next, 'floor' => $next]);
        $this->pdo->exec('DELETE FROM cms_site_changes');
    }

    /** One worker wake removes at most 1,000 expired entries, always a contiguous prefix. */
    public function prune(\DateTimeImmutable $now): int
    {
        if ($this->pdo->inTransaction()) {
            throw new \LogicException('Site change pruning owns its transaction');
        }
        $this->pdo->beginTransaction();
        try {
            $state = $this->locked();
            $cutoff = $now->setTimezone(new \DateTimeZone('UTC'))->modify('-' . SiteSequence::RETENTION_DAYS . ' days')->format('Y-m-d H:i:s.u');
            $rows = $this->pdo->query('SELECT sequence, occurred_at FROM cms_site_changes ORDER BY sequence LIMIT ' . SiteSequence::PRUNE_BATCH)->fetchAll(PDO::FETCH_ASSOC);
            $floor = (int) $state['pruned_through'];
            foreach ($rows as $row) {
                if ($row['occurred_at'] >= $cutoff) {
                    break;
                }
                $floor = (int) $row['sequence'];
            }
            $deleted = $this->discard($floor);
            $this->pdo->prepare('UPDATE cms_site_sequence SET pruned_through = :floor WHERE singleton_id = 1')->execute(['floor' => $floor]);
            $this->pdo->commit();
            return $deleted;
        } catch (\Throwable $error) {
            $this->rollback();
            throw $error;
        }
    }

    private function rollback(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    private function append(string $kind, ?string $type, ?string $record, ?string $revision, ?int $number, ?string $activation, ?string $preview, \DateTimeImmutable $now): int
    {
        $state = $this->locked();
        $current = (int) $state['current_sequence'];
        if ($current >= SiteSequence::MAXIMUM) {
            throw new \OverflowException('Site sequence is exhausted');
        }
        $next = $current + 1;
        $floor = max((int) $state['pruned_through'], $next - SiteSequence::HISTORY_LIMIT);
        $this->pdo->prepare('UPDATE cms_site_sequence SET current_sequence = :next, pruned_through = :floor WHERE singleton_id = 1')->execute(['next' => $next, 'floor' => $floor]);
        $this->pdo->prepare('INSERT INTO cms_site_changes (sequence, kind, record_type, record_id, revision_id, revision_number, activation_id, preview_id, occurred_at) VALUES (:sequence, :kind, :type, :record, :revision, :number, :activation, :preview, :at)')->execute([
            'sequence' => $next, 'kind' => $kind, 'type' => $type, 'record' => $record, 'revision' => $revision, 'number' => $number, 'activation' => $activation, 'preview' => $preview,
            'at' => $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'),
        ]);
        // Hard bound even if cron has not run; normally this deletes zero or one row.
        $this->discard($floor);
        return $next;
    }

    /** @return array<string, mixed> */
    private function locked(): array
    {
        if (!$this->pdo->inTransaction()) {
            throw new \LogicException('Site sequence assignment requires an owning transaction');
        }
        $row = $this->pdo->query('SELECT current_sequence, pruned_through FROM cms_site_sequence WHERE singleton_id = 1 FOR UPDATE')->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new \RuntimeException('Site sequence state is missing');
        }
        return $row;
    }

    private function discard(int $floor): int
    {
        $query = $this->pdo->prepare('DELETE FROM cms_site_changes WHERE sequence <= :floor');
        $query->execute(['floor' => $floor]);
        return $query->rowCount();
    }
}
