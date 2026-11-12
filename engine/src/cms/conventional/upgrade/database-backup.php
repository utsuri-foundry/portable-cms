<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Upgrade;

use PDO;
use Utsuri\PortableCms\Conventional\Database\TablePrefix;
use Utsuri\PortableCms\Conventional\HostIo;

/**
 * Exact PHP-only backup of one installation's CMS table set, including binary
 * credentials. Tables and schemas are recorded under their canonical names, so a
 * backup restores into any table prefix; other table sets in the database are ignored.
 */
final readonly class DatabaseBackup
{
    private const MAX_ROWS = 100_000;
    private const MAX_BYTES = 536_870_912;

    private TablePrefix $prefix;

    public function __construct(private PDO $pdo)
    {
        $this->prefix = TablePrefix::of($pdo);
        $pdo->exec("SET time_zone = '+00:00'");
        $pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');
    }

    public function identity(): string
    {
        $identity = $this->pdo->query('SELECT @@hostname AS host, @@port AS port, DATABASE() AS db')->fetch(PDO::FETCH_ASSOC);
        if (!$this->prefix->isCanonical() && is_array($identity)) {
            $identity['tablePrefix'] = $this->prefix->value;
        }
        return UpgradeFiles::digest($identity);
    }

    /** @return array<string, string> Canonical table schemas, with changing auto-increment counters excluded. */
    public function schemas(): array
    {
        $rows = [];
        foreach ($this->pdo->query('SELECT TABLE_NAME, TABLE_TYPE, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $canonical = $this->prefix->canonicalName((string) $row['TABLE_NAME']);
            if ($canonical !== null) {
                $rows[$canonical] = $row;
            }
        }
        if (count($rows) > 128 || (int) $this->pdo->query('SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()')->fetchColumn() !== 0
            || (int) $this->pdo->query('SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE()')->fetchColumn() !== 0
            || (int) $this->pdo->query('SELECT COUNT(*) FROM information_schema.EVENTS WHERE EVENT_SCHEMA = DATABASE()')->fetchColumn() !== 0) {
            throw new UpgradeFailure('unsupported_database_objects');
        }
        $schemas = [];
        foreach ($rows as $name => $row) {
            if ($row['TABLE_TYPE'] !== 'BASE TABLE' || $row['ENGINE'] !== 'InnoDB' || preg_match('/\Acms_[a-z0-9_]+\z/', $name) !== 1) {
                throw new UpgradeFailure('upgrade_requires_dedicated_innodb_database');
            }
            $create = $this->pdo->query('SHOW CREATE TABLE ' . self::identifier($name))->fetch(PDO::FETCH_NUM);
            $schemas[$name] = preg_replace('/ AUTO_INCREMENT=[0-9]+/', '', $this->prefix->canonicalSchema((string) $create[1])) ?? throw new UpgradeFailure('database_schema_invalid');
        }
        return $schemas;
    }

    /** @return array{database: int, tableSet: int} Stored bytes of the whole database and of this table set. */
    public function bytes(): array
    {
        $database = 0;
        $tableSet = 0;
        foreach ($this->pdo->query('SELECT TABLE_NAME, DATA_LENGTH + INDEX_LENGTH AS bytes FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $database += (int) $row['bytes'];
            if ($this->prefix->canonicalName((string) $row['TABLE_NAME']) !== null) {
                $tableSet += (int) $row['bytes'];
            }
        }
        return ['database' => $database, 'tableSet' => $tableSet];
    }

    /** Removes every table of this table set; other table sets in the database are untouched. */
    public function drop(): void
    {
        $tables = array_keys($this->schemas());
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach ($tables as $table) {
                $this->pdo->exec('DROP TABLE ' . self::identifier($table));
            }
        } finally {
            $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    /** @param list<string>|null $only */
    public function fingerprint(?array $only = null): string
    {
        $context = hash_init('sha256');
        $total = 0;
        $count = 0;
        foreach ($this->schemas() as $table => $schema) {
            if ($only !== null && !in_array($table, $only, true)) {
                continue;
            }
            hash_update($context, $table . "\0" . $schema . "\n");
            $this->rows($table, static function (string $line) use ($context, &$total, &$count): void {
                self::limit($line, $total, $count);
                hash_update($context, $line);
            });
        }
        return hash_final($context);
    }

    /** Excludes request/session diagnostics and live previews; includes accounts, revisions, media, jobs and publication. */
    public function durableFingerprint(): string
    {
        return $this->fingerprint(array_values(array_diff(array_keys($this->schemas()), ['cms_sessions', 'cms_login_throttles', 'cms_audit_events', 'cms_live_previews', 'cms_preview_share_files', 'cms_preview_follow_files', 'cms_editor_preview_files'])));
    }

    public function capture(string $directory): void
    {
        if (file_exists($directory)) {
            throw new UpgradeFailure('backup_destination_exists');
        }
        HostIo::required(fn() => mkdir($directory, 0700, true));
        $this->pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->pdo->beginTransaction();
        try {
            $schemas = $this->schemas();
            $total = 0;
            $count = 0;
            foreach ($schemas as $table => $sql) {
                HostIo::createPrivateFile($directory . '/' . $table . '.sql', $sql);
                $path = $directory . '/' . $table . '.jsonl';
                HostIo::createPrivateFile($path, '');
                $stream = HostIo::attempt(fn() => fopen($path, 'ab'))->value;
                if (!is_resource($stream)) {
                    throw new UpgradeFailure('database_backup_write_failed');
                }
                try {
                    $this->rows($table, static function (string $line) use ($stream, &$total, &$count): void {
                        self::limit($line, $total, $count);
                        if (HostIo::attempt(fn() => fwrite($stream, $line))->value !== strlen($line)) {
                            throw new UpgradeFailure('database_backup_write_failed');
                        }
                    });
                    HostIo::required(fn() => fflush($stream));
                } finally {
                    HostIo::required(fn() => fclose($stream));
                }
            }
            UpgradeFiles::writeJson($directory . '/database.json', ['formatVersion' => 1, 'identity' => $this->identity(), 'fingerprint' => $this->fingerprint(), 'durableFingerprint' => $this->durableFingerprint(), 'schemaSha256' => UpgradeFiles::digest($schemas), 'tables' => array_keys($schemas)]);
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    /**
     * Restores only to an explicitly owned empty table set, or replaces a journaled in-place or
     * rollback destination. The checkpoint marks the point where the schema is rebuilt but empty.
     * @param \Closure(string): void|null $checkpoint
     */
    public function restore(string $directory, bool $replace = false, ?\Closure $checkpoint = null): void
    {
        $document = UpgradeFiles::json($directory . '/database.json');
        $tables = $document['tables'] ?? null;
        if (!is_array($tables) || count($tables) > 128 || $tables === []) {
            throw new UpgradeFailure('database_backup_invalid');
        }
        $existing = $this->schemas();
        if (!$replace && $existing !== []) {
            throw new UpgradeFailure('standby_database_must_be_empty');
        }
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            if ($replace) {
                foreach (array_keys($existing) as $table) {
                    $this->pdo->exec('DROP TABLE ' . self::identifier($table));
                }
            }
            foreach ($tables as $table) {
                if (!is_string($table)) {
                    throw new UpgradeFailure('database_backup_invalid');
                }
                self::identifier($table);
                $sql = HostIo::attempt(fn() => file_get_contents(UpgradeFiles::path($directory, $table . '.sql')))->value;
                if (!is_string($sql) || !str_starts_with($sql, 'CREATE TABLE `' . $table . '`')) {
                    throw new UpgradeFailure('database_backup_invalid');
                }
                $this->pdo->exec($sql);
            }
            if ($checkpoint !== null) {
                $checkpoint('restore-schema');
            }
            $this->pdo->beginTransaction();
            foreach ($tables as $table) {
                $stream = HostIo::attempt(fn() => fopen(UpgradeFiles::path($directory, $table . '.jsonl'), 'rb'))->value;
                if (!is_resource($stream)) {
                    throw new UpgradeFailure('database_backup_invalid');
                }
                try {
                    while (($line = fgets($stream, 8_388_609)) !== false) {
                        $row = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
                        if (!is_array($row) || $row === [] || array_is_list($row)) {
                            throw new UpgradeFailure('database_backup_invalid');
                        }
                        $columns = array_map(self::identifier(...), array_keys($row));
                        $values = array_map(static function ($value): ?string {
                            if ($value === null) {
                                return null;
                            }
                            $decoded = is_string($value) ? base64_decode($value, true) : false;
                            return is_string($decoded) ? $decoded : throw new UpgradeFailure('database_backup_invalid');
                        }, array_values($row));
                        $statement = $this->pdo->prepare('INSERT INTO ' . self::identifier($table) . ' (' . implode(',', $columns) . ') VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ')');
                        foreach ($values as $index => $value) {
                            $statement->bindValue($index + 1, $value, $value === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
                        }
                        $statement->execute();
                    }
                } finally {
                    HostIo::required(fn() => fclose($stream));
                }
            }
            if ($this->fingerprint() !== $document['fingerprint']) {
                throw new UpgradeFailure('database_restore_verification_failed');
            }
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        } finally {
            $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    private static function identifier(string $value): string
    {
        if (preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]{0,159}\z/', $value) !== 1) {
            throw new UpgradeFailure('sql_identifier_invalid');
        }
        return '`' . $value . '`';
    }

    /** @param \Closure(string): void $consume */
    private function rows(string $table, \Closure $consume): void
    {
        $keys = $this->pdo->query('SHOW KEYS FROM ' . self::identifier($table) . " WHERE Key_name = 'PRIMARY'")->fetchAll(PDO::FETCH_ASSOC);
        if ($keys === []) {
            throw new UpgradeFailure('database_primary_key_required');
        }
        usort($keys, static fn(array $a, array $b): int => (int) $a['Seq_in_index'] <=> (int) $b['Seq_in_index']);
        $order = implode(',', array_map(static fn(array $key): string => self::identifier((string) $key['Column_name']), $keys));
        // Generated columns are recomputed by SQL; inserting their captured values is invalid.
        $columns = $this->pdo->query('SHOW COLUMNS FROM ' . self::identifier($table))->fetchAll(PDO::FETCH_ASSOC);
        $stored = array_filter($columns, static fn(array $column): bool => !str_contains((string) $column['Extra'], 'GENERATED'));
        $projection = implode(',', array_map(static fn(array $column): string => self::identifier((string) $column['Field']), $stored));
        $this->pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        $statement = null;
        try {
            $statement = $this->pdo->query('SELECT ' . $projection . ' FROM ' . self::identifier($table) . ' ORDER BY ' . $order . ' LIMIT ' . (self::MAX_ROWS + 1));
            while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
                $values = array_map(static fn($value): ?string => $value === null ? null : base64_encode((string) $value), $row);
                $consume(json_encode($values, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
            }
        } finally {
            $statement?->closeCursor();
            $this->pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
        }
    }

    private static function limit(string $line, int &$bytes, int &$rows): void
    {
        if (strlen($line) > 8_388_608 || ($bytes += strlen($line)) > self::MAX_BYTES || ++$rows > self::MAX_ROWS) {
            throw new UpgradeFailure('database_backup_limit');
        }
    }
}
