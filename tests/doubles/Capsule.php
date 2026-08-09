<?php

namespace WHMCS\Database;

/**
 * Enough of WHMCS's query builder to see which rows the module reads and
 * exactly what it writes back. Only the verbs the module actually uses are
 * implemented; anything else should fail loudly rather than pretend.
 */
final class Capsule
{
    /** @var array<string, array<int, object>> table => rows returned by get() */
    public static array $rows = [];

    /** @var array<int, array{table: string, where: array, values: array}> */
    public static array $updates = [];

    public static function reset(): void
    {
        self::$rows = [];
        self::$updates = [];
    }

    public static function table(string $table): FakeQuery
    {
        return new FakeQuery($table);
    }
}

final class FakeQuery
{
    private array $where = [];
    private string $table;

    public function __construct(string $table)
    {
        $this->table = $table;
    }

    public function where($column, $value = null): self
    {
        $this->where[(string) $column] = $value;

        return $this;
    }

    public function whereIn($column, array $values): self
    {
        $this->where[(string) $column] = $values;

        return $this;
    }

    public function get($columns = ['*']): array
    {
        return Capsule::$rows[$this->table] ?? [];
    }

    public function update(array $values): int
    {
        Capsule::$updates[] = ['table' => $this->table, 'where' => $this->where, 'values' => $values];

        return 1;
    }
}
