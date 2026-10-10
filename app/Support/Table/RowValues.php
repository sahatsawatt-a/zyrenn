<?php

namespace App\Support\Table;

use App\Models\Table\Table;
use App\Models\Table\TableColumn;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Values for a table's row given the way a person or a program writes them:
 * keyed by a column's name or its label, a single choice where several may
 * go. Choices a select doesn't offer yet are added to it, as the grid does.
 * Used by the MCP table tools and by workflows alike.
 */
final class RowValues
{
    /** The colours new choices take, in turn. */
    public const TONES = ['amber', 'sky', 'purple', 'emerald', 'fuchsia', 'indigo', 'rose', 'cyan'];

    /**
     * A row's values keyed by column name.
     *
     * @param  array<array-key, mixed>  $given
     * @return array<string, mixed>
     *
     * @throws RuntimeException when a key matches no column, or one worked out by a formula
     */
    public static function of(Table $table, array $given): array
    {
        $columns = $table->columns->reject(fn (TableColumn $column) => $column->is_primary);
        $values = [];

        foreach ($given as $key => $value) {
            $column = self::column($table, (string) $key);

            if (! $column) {
                throw new RuntimeException("This table has no column \"{$key}\". Its columns are: ".$columns->pluck('label')->join(', ').'.');
            }

            if ($column->type === 'formula') {
                throw new RuntimeException("\"{$column->label}\" is worked out by its formula; change what it is worked out from instead.");
            }

            if ($column->type === 'multi_select' && ! is_array($value)) {
                $value = $value === null || $value === '' ? [] : [$value];
            }

            if (in_array($column->type, ['select', 'multi_select'], true)) {
                self::offer($column, array_filter((array) $value, 'is_string'));
            }

            $values[$column->name] = $value;
        }

        return $values;
    }

    /**
     * A column by its name, or else by its label in any case. Never the id.
     */
    public static function column(Table $table, string $key): ?TableColumn
    {
        $columns = $table->columns->reject(fn (TableColumn $column) => $column->is_primary);

        return $columns->first(fn (TableColumn $column) => $column->name === $key)
            ?? $columns->first(fn (TableColumn $column) => Str::lower($column->label) === Str::lower($key));
    }

    /**
     * The choices a select column offers.
     *
     * @return list<string>
     */
    public static function choices(TableColumn $column): array
    {
        return array_column($column->toGrid()['options'], 'value');
    }

    /**
     * Makes sure a select column offers each of these choices.
     *
     * @param  array<array-key, string>  $wanted
     */
    public static function offer(TableColumn $column, array $wanted): void
    {
        $missing = array_values(array_diff(array_unique($wanted), self::choices($column)));

        if ($missing === []) {
            return;
        }

        $settings = $column->toGrid();
        $meta = $column->options_meta ?? [];
        $meta = array_is_list($meta) ? ['options' => $meta] : $meta;
        $meta['options'] = [...$settings['options'], ...self::options($missing, count($settings['options']))];

        $column->options_meta = $meta;
        $column->save();
    }

    /**
     * Choices as the grid keeps them, each with an id and a colour of its own.
     *
     * @param  array<array-key, string>  $values
     * @return list<array{id: string, value: string, color: string}>
     */
    public static function options(array $values, int $after = 0): array
    {
        $values = array_values($values);

        return array_map(fn (string $value, int $index) => [
            'id' => Str::lower(Str::random(8)),
            'value' => $value,
            'color' => self::TONES[($after + $index) % count(self::TONES)],
        ], $values, array_keys($values));
    }
}
