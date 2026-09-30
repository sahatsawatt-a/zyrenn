<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Tables\TableProblem;
use App\Models\Owner;
use App\Models\Table\Table;
use App\Models\Table\TableColumn;
use App\Models\Table\TableFolder;
use App\Support\Table\TableStorage;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Base for the table tools shared by both MCP servers. Finding, listing and
 * folders are FiledTool's; tables add their columns and rows, which are
 * written through TableStorage exactly as the grid writes them.
 *
 * A row is given as an object of values keyed by column -- by the column's
 * name, as get-table shows it, or simply by its label.
 *
 * @extends FiledTool<TableFolder, Table>
 */
abstract class TableTool extends FiledTool
{
    /** Most rows get-table returns in one go. */
    protected const MAX_ROWS = 500;

    /** The colours a new choice is given in turn, as the grid names them. */
    private const TONES = ['amber', 'sky', 'purple', 'emerald', 'fuchsia', 'indigo', 'rose', 'cyan'];

    protected function noun(): string
    {
        return 'table';
    }

    /**
     * @return HasMany<TableFolder, covariant Model&Owner>
     */
    protected function folders(Owner $owner): HasMany
    {
        return $owner->tableFolders();
    }

    /**
     * @return HasMany<Table, covariant Model&Owner>
     */
    protected function things(Owner $owner): HasMany
    {
        return $owner->tables();
    }

    protected function searchIn(): array
    {
        return ['title' => 'title'];
    }

    /**
     * @param  Table  $thing
     */
    protected function details(Model $thing): array
    {
        return [
            // The id column is always there; the ones added are what count
            'column_count' => max(0, $thing->columns()->count() - 1),
            'row_count' => TableStorage::count($thing),
        ];
    }

    /**
     * The table's columns as a client reads them, or just those named.
     *
     * @param  list<string>|null  $only  column names
     * @return list<array<string, mixed>>
     */
    protected function describeColumns(Table $table, ?array $only = null): array
    {
        return array_values($table->columns
            ->filter(fn (TableColumn $column) => $only === null || $column->is_primary || in_array($column->name, $only, true))
            ->map(fn (TableColumn $column) => array_filter([
                'name' => $column->name,
                'label' => $column->label,
                'type' => $column->type,
                'choices' => $this->choices($column) ?: null,
            ], fn ($value) => $value !== null))
            ->all());
    }

    /**
     * Columns given by name or label (any case), as their names.
     *
     * @param  list<string>  $given
     * @return list<string>
     *
     * @throws TableProblem when one is not a column of the table
     */
    protected function columnNames(Table $table, array $given): array
    {
        $columns = $table->columns->reject(fn (TableColumn $column) => $column->is_primary);

        return array_map(function (string $key) use ($columns) {
            $column = $columns->first(fn (TableColumn $column) => $column->name === $key)
                ?? $columns->first(fn (TableColumn $column) => Str::lower($column->label) === Str::lower($key));

            return $column->name ?? throw new TableProblem("This table has no column \"{$key}\". Its columns are: ".$columns->pluck('label')->join(', ').'.');
        }, $given);
    }

    /**
     * Schema for columns to add.
     */
    protected function columnsArgument(JsonSchema $schema, string $description): Type
    {
        return $schema->array()->max(50)->items($schema->object([
            'label' => $schema->string()->max(120)->description('What the column is called; its name is made from this.')->required(),
            'type' => $schema->string()->enum(TableStorage::TYPES)->description('What it holds: varchar (a line of text, the default), text (long text), integer, numeric, boolean, select (one choice), multi_select (several choices), date (YYYY-MM-DD), email, url, phone, currency, percent, rating (1-5) or user (a person\'s name).'),
            'choices' => $schema->array()->max(100)->items($schema->string()->max(120))->description('For select and multi_select: the choices to offer. Values written later that are not among them are added.'),
        ]))->description($description);
    }

    /**
     * Schema for rows to write.
     */
    protected function rowsArgument(JsonSchema $schema, string $description): Type
    {
        return $schema->array()->max(1000)->items(
            $schema->object()->description('Values keyed by column label or name, e.g. {"Owner": "Ada", "Budget": 300}. A multi_select takes a list.'),
        )->description($description);
    }

    /**
     * Validation rules for columns to add, under the given key.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function columnRules(string $key): array
    {
        return [
            $key => ['nullable', 'array', 'max:50'],
            "{$key}.*.label" => ['required', 'string', 'max:120'],
            "{$key}.*.type" => ['nullable', 'string', Rule::in(TableStorage::TYPES)],
            "{$key}.*.choices" => ['nullable', 'array', 'max:100'],
            "{$key}.*.choices.*" => ['string', 'max:120'],
        ];
    }

    /**
     * Adds columns to the table, last in line.
     *
     * @param  list<array{label: string, type?: string|null, choices?: list<string>|null}>  $columns
     */
    protected function addColumns(Table $table, array $columns): void
    {
        foreach ($columns as $column) {
            TableStorage::newColumn($table, [
                'label' => $column['label'],
                'type' => $column['type'] ?? 'varchar',
                'options_meta' => ['options' => $this->options($column['choices'] ?? [])],
            ]);
        }

        $table->unsetRelation('columns');
    }

    /**
     * A row's values keyed by column name, from values keyed by name or label.
     * Choices a select doesn't offer yet are added to it, as the grid does.
     *
     * @param  array<array-key, mixed>  $given
     * @return array<string, mixed>
     *
     * @throws TableProblem when a key matches no column
     */
    protected function rowValues(Table $table, array $given): array
    {
        $columns = $table->columns->reject(fn (TableColumn $column) => $column->is_primary);
        $values = [];

        foreach ($given as $key => $value) {
            $column = $columns->first(fn (TableColumn $column) => $column->name === $key)
                ?? $columns->first(fn (TableColumn $column) => Str::lower($column->label) === Str::lower((string) $key));

            if (! $column) {
                throw new TableProblem("This table has no column \"{$key}\". Its columns are: ".$columns->pluck('label')->join(', ').'.');
            }

            if ($column->type === 'multi_select' && ! is_array($value)) {
                $value = $value === null || $value === '' ? [] : [$value];
            }

            if (in_array($column->type, ['select', 'multi_select'], true)) {
                $this->offer($column, array_filter((array) $value, 'is_string'));
            }

            $values[$column->name] = $value;
        }

        return $values;
    }

    /**
     * The choices a select column offers.
     *
     * @return list<string>
     */
    private function choices(TableColumn $column): array
    {
        return array_column($column->toGrid()['options'], 'value');
    }

    /**
     * Makes sure a select column offers each of these choices.
     *
     * @param  array<array-key, string>  $wanted
     */
    private function offer(TableColumn $column, array $wanted): void
    {
        $missing = array_values(array_diff(array_unique($wanted), $this->choices($column)));

        if ($missing === []) {
            return;
        }

        $settings = $column->toGrid();
        $meta = $column->options_meta ?? [];
        $meta = array_is_list($meta) ? ['options' => $meta] : $meta;
        $meta['options'] = [...$settings['options'], ...$this->options($missing, count($settings['options']))];

        $column->options_meta = $meta;
        $column->save();
    }

    /**
     * Choices as the grid keeps them, each with an id and a colour of its own.
     *
     * @param  array<array-key, string>  $values
     * @return list<array{id: string, value: string, color: string}>
     */
    private function options(array $values, int $after = 0): array
    {
        $values = array_values($values);

        return array_map(fn (string $value, int $index) => [
            'id' => Str::lower(Str::random(8)),
            'value' => $value,
            'color' => self::TONES[($after + $index) % count(self::TONES)],
        ], $values, array_keys($values));
    }
}
