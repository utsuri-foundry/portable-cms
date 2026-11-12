<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Database;

/**
 * The CMS database connection. Every statement passes through the installation's table
 * prefix, so engine SQL keeps its canonical names and stored migrations keep their bytes.
 */
final class DatabaseConnection extends \PDO
{
    /** @param array<int, mixed> $options */
    public function __construct(string $dsn, string $username, string $password, public readonly TablePrefix $tablePrefix, array $options = [])
    {
        parent::__construct($dsn, $username, $password, $options);
    }

    /** @param array<int, mixed> $options */
    public function prepare(string $query, array $options = []): \PDOStatement|false
    {
        return parent::prepare($this->tablePrefix->sql($query), $options);
    }

    public function exec(string $statement): int|false
    {
        return parent::exec($this->tablePrefix->sql($statement));
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): \PDOStatement|false
    {
        return $fetchMode === null
            ? parent::query($this->tablePrefix->sql($query))
            : parent::query($this->tablePrefix->sql($query), $fetchMode, ...$fetchModeArgs);
    }
}
