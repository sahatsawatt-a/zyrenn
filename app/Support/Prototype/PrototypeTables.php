<?php

namespace App\Support\Prototype;

use App\Models\Board\Board;
use App\Models\Table\Table;
use App\Models\Table\TableColumn;
use App\Models\User;
use App\Support\Board\BoardActions;
use App\Support\Table\TableStorage;
use Illuminate\Support\Collection;

/**
 * Project tables as a prototype's database (phase 3): the tables its items
 * name (BoardActions::tables) as Play reads them, each row's values by its
 * column's label, as the grid gives them; and finding one Play may write to.
 */
final class PrototypeTables
{
    /** The most rows of a table sent to Play: as many as a repeat may show. */
    public const MOST_ROWS = BoardActions::ROWS[1];

    /**
     * The tables the prototype's items name, as Play reads them: only its
     * own project's, only those the player may see, the first rows of each.
     *
     * @return array<string, array{title: string, columns: list<array{label: string, type: string}>, rows: list<array<string, mixed>>}>
     */
    public static function forPlay(Board $board, User $player): array
    {
        $refs = BoardActions::tables($board->content['items'] ?? []);

        if ($refs === [] || $board->project_id === null) {
            return [];
        }

        $tables = Table::query()
            ->where('project_id', $board->project_id)
            ->whereIn('ref_id', $refs)
            ->with('columns')
            ->get()
            ->filter(fn (Table $table) => $player->can('view', $table));

        $shown = [];

        foreach ($tables as $table) {
            $shown[$table->ref_id] = [
                'title' => $table->title,
                'columns' => array_values(self::columns($table)->map(fn (TableColumn $column) => ['label' => $column->label, 'type' => $column->type])->all()),
                'rows' => array_map(fn (array $row) => self::byLabel($table, $row), TableStorage::page($table, self::MOST_ROWS)['rows']),
            ];
        }

        return $shown;
    }

    /**
     * One of the prototype's own project's tables that its items name, or
     * null when it is not one of those.
     */
    public static function find(Board $board, string $ref): ?Table
    {
        if ($board->project_id === null || ! in_array($ref, BoardActions::tables($board->content['items'] ?? []), true)) {
            return null;
        }

        return Table::query()->where('project_id', $board->project_id)->where('ref_id', $ref)->with('columns')->first();
    }

    /**
     * A row as the grid reads it, keyed by column name, made one keyed by
     * label -- its id kept as it is.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public static function byLabel(Table $table, array $row): array
    {
        $shown = ['id' => $row['id'] ?? null];

        foreach (self::columns($table) as $column) {
            $shown[$column->label] = $row[$column->name] ?? null;
        }

        return $shown;
    }

    /**
     * The columns a row shows: all but the id.
     *
     * @return Collection<int, TableColumn>
     */
    private static function columns(Table $table): Collection
    {
        return $table->columns->reject(fn (TableColumn $column) => $column->is_primary)->values();
    }
}
