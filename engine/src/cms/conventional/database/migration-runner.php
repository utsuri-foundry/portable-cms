<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Database;

use PDO;
use Throwable;
use Utsuri\PortableCms\Conventional\HostIo;

final readonly class MigrationResult
{
    public function __construct(
        public int $applied,
        public int $skipped,
    ) {}
}

final class MigrationRunner
{
    public function __construct(private readonly PDO $pdo)
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        $this->pdo->exec("SET time_zone = '+00:00'");
        $this->pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');
    }

    /** @param list<string> $paths */
    public function apply(array $paths, ?\DateTimeImmutable $now = null): MigrationResult
    {
        return self::locked($this->pdo, fn(): MigrationResult => $this->applyLocked($paths, $now));
    }

    /**
     * @template T
     * @param \Closure(): T $operation
     * @return T
     */
    public static function locked(PDO $pdo, \Closure $operation): mixed
    {
        // Table sets sharing a database migrate independently; the canonical set keeps its original lock.
        $prefix = TablePrefix::of($pdo);
        $scope = (string) $pdo->query('SELECT DATABASE()')->fetchColumn() . ($prefix->isCanonical() ? '' : "\0" . $prefix->value);
        $name = 'portable-cms-migration-' . substr(hash('sha256', $scope), 0, 40);
        $statement = $pdo->prepare('SELECT GET_LOCK(:name, 0)');
        $statement->execute(['name' => $name]);
        if ((int) $statement->fetchColumn() !== 1) {
            throw new \RuntimeException('Migration lock is busy or unavailable');
        }
        try {
            return $operation();
        } finally {
            $release = $pdo->prepare('SELECT RELEASE_LOCK(:name)');
            $release->execute(['name' => $name]);
        }
    }

    /** @param list<string> $paths */
    private function applyLocked(array $paths, ?\DateTimeImmutable $now): MigrationResult
    {
        $this->ensureLedger();
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $applied = 0;
        $skipped = 0;

        foreach ($paths as $path) {
            $id = pathinfo($path, PATHINFO_FILENAME);
            if (preg_match('/\A[0-9]{4}-[a-z0-9-]+\z/', $id) !== 1) {
                throw new \InvalidArgumentException('Migration filename is not canonical');
            }
            $sql = HostIo::attempt(fn() => file_get_contents($path))->value;
            if (!is_string($sql)) {
                throw new \RuntimeException('Migration file could not be read');
            }
            $checksum = hash('sha256', $sql);
            $existing = $this->migration($id);
            if ($existing !== null && $existing['status'] === 'complete') {
                if (!hash_equals($existing['checksum'], $checksum)) {
                    throw new \RuntimeException('Completed migration checksum changed');
                }
                $skipped++;
                continue;
            }

            $startedAt = self::databaseTime($now);
            $statement = $this->pdo->prepare(<<<'SQL'
INSERT INTO cms_migrations (id, checksum, status, started_at, completed_at, failed_at, error_code)
VALUES (:id, :checksum, 'running', :started_at, NULL, NULL, NULL)
ON DUPLICATE KEY UPDATE
    checksum = VALUES(checksum),
    status = 'running',
    started_at = VALUES(started_at),
    completed_at = NULL,
    failed_at = NULL,
    error_code = NULL
SQL);
            $statement->execute(['id' => $id, 'checksum' => $checksum, 'started_at' => $startedAt]);

            try {
                foreach ($this->statements($sql) as $migrationStatement) {
                    $this->pdo->exec($migrationStatement);
                }
                $complete = $this->pdo->prepare(<<<'SQL'
UPDATE cms_migrations
SET status = 'complete', completed_at = :completed_at
WHERE id = :id AND checksum = :checksum
SQL);
                $complete->execute([
                    'completed_at' => self::databaseTime($now),
                    'id' => $id,
                    'checksum' => $checksum,
                ]);
                $applied++;
            } catch (Throwable $error) {
                $failed = $this->pdo->prepare(<<<'SQL'
UPDATE cms_migrations
SET status = 'failed', failed_at = :failed_at, error_code = 'migration_failed'
WHERE id = :id AND checksum = :checksum
SQL);
                $failed->execute([
                    'failed_at' => self::databaseTime($now),
                    'id' => $id,
                    'checksum' => $checksum,
                ]);
                throw $error;
            }
        }

        return new MigrationResult($applied, $skipped);
    }

    private function ensureLedger(): void
    {
        $this->pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS cms_migrations (
    id VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    checksum CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    started_at DATETIME(6) NOT NULL,
    completed_at DATETIME(6) NULL,
    failed_at DATETIME(6) NULL,
    error_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    PRIMARY KEY (id),
    CONSTRAINT cms_migrations_status_check CHECK (status IN ('running', 'complete', 'failed'))
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
SQL);
    }

    /** @return array{checksum: string, status: string}|null */
    private function migration(string $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT checksum, status FROM cms_migrations WHERE id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        return ['checksum' => (string) $row['checksum'], 'status' => (string) $row['status']];
    }

    /** @return list<string> */
    private function statements(string $sql): array
    {
        $parts = explode('-- cms:statement', $sql);
        $statements = [];
        foreach ($parts as $part) {
            $statement = trim($part);
            if ($statement !== '') {
                $statements[] = $statement;
            }
        }
        if ($statements === []) {
            throw new \RuntimeException('Migration contains no statements');
        }
        return $statements;
    }

    private static function databaseTime(\DateTimeImmutable $time): string
    {
        return $time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
