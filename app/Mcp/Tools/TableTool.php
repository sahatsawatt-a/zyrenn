<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Tables\TableProblem;
use App\Models\Owner;
use App\Models\Table\Table;
use App\Models\Table\TableColumn;
use App\Models\Table\TableFolder;
use App\Support\Formula\FormulaError;
use App\Support\Formula\Parser;
use App\Support\Table\TableFormulas;
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
                'expression' => $column->type === 'formula' ? TableFormulas::expressionOf($column) : null,
                'summary' => TableFormulas::summaryOf($column),
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
            'type' => $schema->string()->enum(TableStorage::TYPES)->description('What it holds: varchar (a line of text, the default), text (long text), integer, numeric, boolean, select (one choice), multi_select (several choices), date (YYYY-MM-DD), email, url, phone, currency, percent, rating (1-5), user (a person\'s name) or location (a place: {lat, lng, label}, or a GeoJSON Point), or formula (worked out from the rest of the row: give its "expression").'),
            'choices' => $schema->array()->max(100)->items($schema->string()->max(120))->description('For select and multi_select: the choices to offer. Values written later that are not among them are added.'),
            'expression' => $schema->string()->max(Parser::MAX_LENGTH)->description(self::EXPRESSION_HELP),
            'summary' => $schema->string()->enum(TableFormulas::SUMMARIES)->description('What the footer shows under this column, over every row: sum, avg, min, max or count.'),
        ]))->description($description);
    }

    /** How a formula is written, for the schemas that take one */
    protected const EXPRESSION_HELP = 'For a formula column: how its value is worked out from the rest of the row, '
        .'like a spreadsheet\'s. Name a column by its name or, in brackets, its label, and a parameter by its name: '
        .'cny * rate, round([Cost (THB)] / people), if(kind = "estimate", 0, cost), date(start) + day - 1, '
        .'text(date, "D j M"). Functions: if, sum, avg, min, max, count, round, floor, ceil, abs, coalesce, '
        .'text, upper, lower, len, date, today, year, month, day, weekday, days.';

    /**
     * Schema for parameters to set.
     */
    protected function parametersArgument(JsonSchema $schema): Type
    {
        return $schema->object()->description('Parameters to set: named values the formulas share, e.g. {"rate": 5, "people": 2, "start": "2026-12-03"}. A number, text, true/false, or a date as YYYY-MM-DD; null takes one away. The others are kept.');
    }

    /**
     * Sets parameters by name, keeping the rest; null takes one away.
     *
     * @param  array<string, mixed>  $given
     *
     * @throws TableProblem when one can't be a parameter
     */
    protected function setParameters(Table $table, array $given): void
    {
        // Name => value, in the order they are shown; a name matches whatever its case
        $values = array_column($table->parameters ?? [], 'value', 'name');

        foreach ($given as $name => $value) {
            $name = (string) $name;
            $same = array_values(array_filter(array_keys($values), fn ($kept) => mb_strtolower((string) $kept) === mb_strtolower($name)));
            $key = $same[0] ?? $name;

            if ($value === null) {
                unset($values[$key]);
            } else {
                $values[$key] = $value;
            }
        }

        $parameters = array_map(fn ($name, $value) => ['name' => (string) $name, 'value' => $value], array_keys($values), array_values($values));

        try {
            $table->parameters = TableFormulas::cleanParameters($table, $parameters);
        } catch (FormulaError $problem) {
            throw new TableProblem($problem->getMessage());
        }
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
            "{$key}.*.expression" => ['nullable', 'string', 'max:'.Parser::MAX_LENGTH],
            "{$key}.*.summary" => ['nullable', Rule::in(TableFormulas::SUMMARIES)],
        ];
    }

    /**
     * Adds columns to the table, last in line.
     *
     * @param  list<array{label: string, type?: string|null, choices?: list<string>|null, expression?: string|null, summary?: string|null}>  $columns
     *
     * @throws TableProblem when a formula can't be worked out
     */
    protected function addColumns(Table $table, array $columns): void
    {
        foreach ($columns as $column) {
            $type = $column['type'] ?? 'varchar';

            // A formula may name a column added just before it
            $table->unsetRelation('columns');
            $this->checkExpression($table, $column['label'], $type, $column['expression'] ?? null);

            TableStorage::newColumn($table, [
                'label' => $column['label'],
                'type' => $type,
                'options_meta' => array_filter([
                    'options' => $this->options($column['choices'] ?? []),
                    'expression' => $type === 'formula' ? $column['expression'] : null,
                    'summary' => $column['summary'] ?? null,
                ], fn ($value) => $value !== null),
            ]);
        }

        $table->unsetRelation('columns');
    }

    /**
     * A formula column needs a formula this table can work out.
     *
     * @throws TableProblem
     */
    protected function checkExpression(Table $table, string $label, string $type, ?string $expression, ?string $for = null): void
    {
        if ($type !== 'formula') {
            return;
        }

        $problem = blank($expression)
            ? 'A formula column needs an "expression", e.g. cny * rate.'
            : TableFormulas::problem($table, (string) $expression, $for);

        if ($problem !== null) {
            throw new TableProblem("The formula for \"{$label}\": {$problem}");
        }
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

            if ($column->type === 'formula') {
                throw new TableProblem("\"{$column->label}\" is worked out by its formula; change what it is worked out from instead.");
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
