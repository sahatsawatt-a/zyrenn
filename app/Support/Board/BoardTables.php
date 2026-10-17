<?php

namespace App\Support\Board;

use App\Models\Owner;
use App\Models\Table\Table;
use App\Models\Table\TableColumn;

/**
 * The tables a prototype may use as its database: its owner's own (a
 * project's, as prototypes are), found by ref_id or title, with their columns
 * by label, any case. Read once, when a board's items are written over MCP,
 * so an action or a repeat naming a table or a column can be checked.
 */
final class BoardTables
{
    /**
     * @param  array<string, array{ref_id: string, title: string, columns: list<string>}>  $tables  by ref_id
     */
    private function __construct(private array $tables) {}

    /** The owner's tables and their shown columns: two queries. */
    public static function of(Owner $owner): self
    {
        $tables = $owner->tables()->get(['id', 'ref_id', 'title']);
        $columns = TableColumn::query()
            ->whereIn('table_id', $tables->pluck('id'))
            ->where('hidden', false)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['table_id', 'label'])
            ->groupBy('table_id');

        return new self($tables->mapWithKeys(fn (Table $table) => [$table->ref_id => [
            'ref_id' => $table->ref_id,
            'title' => $table->title,
            'columns' => array_values($columns->get($table->id, collect())->map(fn (TableColumn $column): string => $column->label)->all()),
        ]])->all());
    }

    /**
     * A table by its ref_id, or failing that its title, any case.
     *
     * @return array{ref_id: string, title: string, columns: list<string>}|null
     */
    public function find(string $name): ?array
    {
        if (isset($this->tables[$name])) {
            return $this->tables[$name];
        }

        foreach ($this->tables as $table) {
            if (mb_strtolower(trim($table['title'])) === mb_strtolower(trim($name))) {
                return $table;
            }
        }

        return null;
    }

    /**
     * A column of a table by its label, any case: the label as the table has it.
     *
     * @param  array<string, mixed>  $table  as find() gives it
     */
    public static function column(array $table, string $label): ?string
    {
        foreach (is_array($table['columns'] ?? null) ? $table['columns'] : [] as $column) {
            $column = (string) $column;

            if (mb_strtolower(trim($column)) === mb_strtolower(trim($label))) {
                return $column;
            }
        }

        return null;
    }
}
