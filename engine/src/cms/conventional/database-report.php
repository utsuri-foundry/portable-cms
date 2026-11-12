<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional;

use PDO;
use Throwable;
use Utsuri\PortableCms\Conventional\Database\TablePrefix;

require_once __DIR__ . '/database/table-prefix.php';

final class DatabaseReport
{
    /**
     * @param array{dsn?: string|null, username?: string|null, password?: string|null, tablePrefix?: string}|null $configuration
     * @return array<string, mixed>
     */
    public static function collect(?array $configuration): array
    {
        $dsn = trim((string) ($configuration['dsn'] ?? ''));
        if ($dsn === '') {
            return self::unavailable(false, 'not_configured');
        }
        if (!str_starts_with(strtolower($dsn), 'mysql:')) {
            return self::unavailable(true, 'unsupported_driver');
        }

        try {
            $pdo = new PDO(
                $dsn,
                (string) ($configuration['username'] ?? ''),
                (string) ($configuration['password'] ?? ''),
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_STRINGIFY_FETCHES => false,
                ],
            );
            return self::inspect($pdo, new TablePrefix($configuration['tablePrefix'] ?? 'cms_'));
        } catch (Throwable) {
            return self::unavailable(true, 'connection_failed');
        }
    }

    /** @return array<string, mixed> */
    public static function inspect(PDO $pdo, ?TablePrefix $prefix = null): array
    {
        try {
            $version = self::scalar($pdo, 'SELECT VERSION()');
            $versionComment = self::scalar($pdo, 'SELECT @@version_comment');
            $identity = strtolower("{$version} {$versionComment}");
            $engine = str_contains($identity, 'mariadb')
                ? 'mariadb'
                : (str_contains($identity, 'mysql') ? 'mysql' : 'unknown');
            $isolation = self::transactionIsolation($pdo);
            $sqlModes = self::csvList(self::scalar($pdo, 'SELECT @@sql_mode'));
            $transaction = self::inspectTransactionBehavior($pdo);

            return [
                'configured' => true,
                'connected' => true,
                'errorCode' => null,
                'engine' => $engine,
                'serverVersion' => $version,
                'versionComment' => $versionComment,
                'sqlModes' => $sqlModes,
                'serverCharacterSet' => strtolower(self::scalar($pdo, 'SELECT @@character_set_server')),
                'serverCollation' => strtolower(self::scalar($pdo, 'SELECT @@collation_server')),
                'databaseCharacterSet' => strtolower(self::scalar(
                    $pdo,
                    'SELECT DEFAULT_CHARACTER_SET_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = DATABASE()',
                )),
                'databaseCollation' => strtolower(self::scalar(
                    $pdo,
                    'SELECT DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = DATABASE()',
                )),
                'connectionCharacterSet' => strtolower(self::scalar($pdo, 'SELECT @@character_set_connection')),
                'connectionCollation' => strtolower(self::scalar($pdo, 'SELECT @@collation_connection')),
                'transactionIsolation' => strtoupper(str_replace(' ', '-', $isolation)),
                'autocommit' => self::scalar($pdo, 'SELECT @@autocommit') === '1',
                'transactionProbe' => $transaction,
                'recordVersions' => self::recordVersions($pdo, $prefix),
            ];
        } catch (Throwable) {
            return self::unavailable(true, 'inspection_failed');
        }
    }

    /** @return array<string, mixed> */
    private static function unavailable(bool $configured, string $errorCode): array
    {
        return [
            'configured' => $configured,
            'connected' => false,
            'errorCode' => $errorCode,
            'engine' => null,
            'serverVersion' => null,
            'versionComment' => null,
            'sqlModes' => [],
            'serverCharacterSet' => null,
            'serverCollation' => null,
            'databaseCharacterSet' => null,
            'databaseCollation' => null,
            'connectionCharacterSet' => null,
            'connectionCollation' => null,
            'transactionIsolation' => null,
            'autocommit' => null,
            'transactionProbe' => [
                'temporaryTable' => false,
                'rollback' => false,
                'compareAndSet' => false,
                'cleanupComplete' => true,
            ],
        ];
    }

    /** @return array{current: int, published: int, unpublishedChanges: int, invalidPublished: int}|null */
    public static function recordVersions(PDO $pdo, ?TablePrefix $prefix = null): ?array
    {
        try {
            $sql = "SELECT COUNT(*) AS current_count, COALESCE(SUM(r.published_revision_id IS NOT NULL), 0) AS published_count, COALESCE(SUM(r.workflow_state = 'published' AND r.current_revision_id <> r.published_revision_id), 0) AS pending_count, COALESCE(SUM(r.published_revision_id IS NOT NULL AND (v.id IS NULL OR v.revision_number > r.current_revision_number)), 0) AS invalid_count FROM cms_records r LEFT JOIN cms_record_revisions v ON v.id = r.published_revision_id AND v.record_id = r.id";
            $row = $pdo->query($prefix === null ? $sql : $prefix->sql($sql))->fetch(PDO::FETCH_ASSOC);
            return ['current' => (int) $row['current_count'], 'published' => (int) $row['published_count'], 'unpublishedChanges' => (int) $row['pending_count'], 'invalidPublished' => (int) $row['invalid_count']];
        } catch (Throwable) {
            // An uninstalled or older engine has no published-head projection yet.
            return null;
        }
    }

    private static function scalar(PDO $pdo, string $sql): string
    {
        $value = $pdo->query($sql)->fetchColumn();
        if ($value === false || !is_scalar($value)) {
            throw new \RuntimeException('Database probe query returned no scalar');
        }
        return (string) $value;
    }

    private static function transactionIsolation(PDO $pdo): string
    {
        try {
            return self::scalar($pdo, 'SELECT @@transaction_isolation');
        } catch (Throwable) {
            return self::scalar($pdo, 'SELECT @@tx_isolation');
        }
    }

    /** @return list<string> */
    private static function csvList(string $value): array
    {
        if (trim($value) === '') {
            return [];
        }
        $items = array_values(array_filter(array_map('trim', explode(',', strtoupper($value)))));
        $items = array_values(array_unique($items));
        sort($items, SORT_STRING);
        return $items;
    }

    /** @return array{temporaryTable: bool, rollback: bool, compareAndSet: bool, cleanupComplete: bool} */
    private static function inspectTransactionBehavior(PDO $pdo): array
    {
        $result = [
            'temporaryTable' => false,
            'rollback' => false,
            'compareAndSet' => false,
            'cleanupComplete' => true,
        ];
        $table = 'portable_cms_probe_' . bin2hex(random_bytes(6));

        try {
            $pdo->exec("CREATE TEMPORARY TABLE `{$table}` (id INT PRIMARY KEY, revision INT NOT NULL) ENGINE=InnoDB");
            $result['temporaryTable'] = true;

            $pdo->beginTransaction();
            $pdo->exec("INSERT INTO `{$table}` (id, revision) VALUES (1, 1)");
            $pdo->rollBack();
            $result['rollback'] = self::scalar($pdo, "SELECT COUNT(*) FROM `{$table}`") === '0';

            $pdo->beginTransaction();
            $pdo->exec("INSERT INTO `{$table}` (id, revision) VALUES (1, 1)");
            $current = $pdo->exec("UPDATE `{$table}` SET revision = 2 WHERE id = 1 AND revision = 1");
            $stale = $pdo->exec("UPDATE `{$table}` SET revision = 3 WHERE id = 1 AND revision = 1");
            $result['compareAndSet'] = $current === 1 && $stale === 0;
            $pdo->rollBack();
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            try {
                $pdo->exec("DROP TEMPORARY TABLE IF EXISTS `{$table}`");
            } catch (Throwable) {
                $result['cleanupComplete'] = false;
            }
        }

        return $result;
    }
}
