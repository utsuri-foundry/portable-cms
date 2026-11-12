<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Database;

/**
 * The installation's name for its CMS tables. Engine SQL and migrations name tables,
 * constraints and indexes canonically with `cms_`; an installation may keep them under
 * another prefix so several complete table sets can share one database. A prefix is one
 * lowercase word and a final underscore, so no prefix is the start of another.
 */
final readonly class TablePrefix
{
    public const CANONICAL = 'cms_';

    /** Engine identifiers stay within this length so every prefix fits MariaDB's 64. */
    public const MAX_CANONICAL_IDENTIFIER = 48;

    public function __construct(public string $value)
    {
        if (preg_match('/\A[a-z][a-z0-9]{0,14}_\z/', $value) !== 1) {
            throw new \InvalidArgumentException('Database table prefix is invalid');
        }
    }

    public static function canonical(): self
    {
        return new self(self::CANONICAL);
    }

    /** The prefix a connection maps to; plain connections use the canonical names. */
    public static function of(\PDO $pdo): self
    {
        return $pdo instanceof DatabaseConnection ? $pdo->tablePrefix : self::canonical();
    }

    public function isCanonical(): bool
    {
        return $this->value === self::CANONICAL;
    }

    /** Maps the canonical identifiers in trusted engine SQL to this installation's. */
    public function sql(string $sql): string
    {
        if ($this->isCanonical()) {
            return $sql;
        }
        return preg_replace('/(?<![A-Za-z0-9_$-])cms_(?=[a-z0-9_])/', $this->value, $sql)
            ?? throw new \RuntimeException('Database statement could not be prepared');
    }

    /** The canonical name of one of this installation's tables, or null for any other table. */
    public function canonicalName(string $physical): ?string
    {
        if (!str_starts_with($physical, $this->value) || strlen($physical) === strlen($this->value)) {
            return null;
        }
        return self::CANONICAL . substr($physical, strlen($this->value));
    }

    /** A `SHOW CREATE TABLE` statement with its table, constraint, index and referenced names made canonical. */
    public function canonicalSchema(string $create): string
    {
        if ($this->isCanonical()) {
            return $create;
        }
        return preg_replace('/((?:TABLE|CONSTRAINT|REFERENCES|KEY|INDEX) `)' . preg_quote($this->value, '/') . '/', '$1' . self::CANONICAL, $create)
            ?? throw new \RuntimeException('Database schema could not be read');
    }
}
