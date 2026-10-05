<?php

namespace App\Support\Formula;

use App\Models\Table\Table;
use App\Models\Table\TableColumn;
use App\Support\Table\TableStorage;

/**
 * Another table as a formula reads it: each column, by name or label, as the
 * list of its values -- sum(table("Budget").thb),
 * sum(if(table("Budget").day = 5, table("Budget").thb, 0)). Formula columns
 * come worked out, and one that failed for a row fails what reads it, rather
 * than make a total quietly short; two tables that read each other are stopped.
 */
final class TableValues implements Members
{
    /** @var array<int, true> tables being read right now, so one that leads back is caught */
    private static array $reading = [];

    /** @var list<array<string, mixed>>|null */
    private ?array $rows = null;

    public function __construct(private Table $table) {}

    public function member(string $name): mixed
    {
        $column = $this->table->columns->first(fn (TableColumn $column) => mb_strtolower($column->name) === mb_strtolower($name))
            ?? $this->table->columns->first(fn (TableColumn $column) => mb_strtolower($column->label) === mb_strtolower($name))
            ?? throw new FormulaError("\"{$this->table->title}\" has no column \"{$name}\".");

        return array_map(function (array $row) use ($column) {
            $value = $row[$column->name] ?? null;

            if (is_array($value) && isset($value['error']) && count($value) === 1) {
                throw new FormulaError($value['error']);
            }

            return $value;
        }, $this->rows());
    }

    public function call(string $name, array $args): mixed
    {
        throw new FormulaError("A table has no \"{$name}\" to call: name a column, e.g. table(\"{$this->table->title}\").cost.");
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(): array
    {
        if ($this->rows !== null) {
            return $this->rows;
        }

        if (isset(self::$reading[$this->table->id])) {
            throw new FormulaError("\"{$this->table->title}\" comes back round to itself through another table's formulas.");
        }

        self::$reading[$this->table->id] = true;

        try {
            return $this->rows = TableStorage::rows($this->table);
        } finally {
            unset(self::$reading[$this->table->id]);
        }
    }
}
