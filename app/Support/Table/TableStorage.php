<?php

namespace App\Support\Table;

use App\Models\Table\Table;
use App\Models\Table\TableColumn;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Where a table's rows actually live: a real database table of their own,
 * `user_table_<ref_id>`, with one column for each of the table's columns.
 *
 * This is the only place that changes a schema, and no identifier it writes
 * ever comes from a request. A table's name is its ref_id; a column's name is
 * made here from its label and checked against a strict pattern before any
 * statement is built from it.
 */
class TableStorage
{
    /** The kinds of column a table can have, as the grid names them. */
    public const TYPES = [
        'varchar', 'text', 'integer', 'numeric', 'boolean', 'select',
        'multi_select', 'date', 'email', 'url', 'phone', 'currency',
        'percent', 'rating', 'user',
    ];

    /**
     * How each kind is stored. Kinds that share a storage can be switched
     * between freely; any other change would mean converting every value.
     */
    private const STORAGE = [
        'varchar' => 'string', 'email' => 'string', 'url' => 'string',
        'phone' => 'string', 'select' => 'string', 'user' => 'string',
        'text' => 'text',
        'integer' => 'integer', 'percent' => 'integer', 'rating' => 'integer',
        'numeric' => 'decimal', 'currency' => 'decimal',
        'boolean' => 'boolean',
        'date' => 'date',
        'multi_select' => 'json',
    ];

    /** Names the database already gives every row. */
    private const RESERVED = ['id', 'created_at', 'updated_at'];

    /** A name safe to build a statement from, in every database this runs on. */
    private const SAFE_NAME = '/^[a-z][a-z0-9_]{0,47}$/';

    /** The database table a table's rows are kept in. */
    public static function physicalName(Table $table): string
    {
        return 'user_table_'.$table->ref_id;
    }

    /**
     * Makes the table its rows will be kept in, and the one column every table
     * has: the row's own number, which cannot be edited, hidden or removed.
     */
    public static function create(Table $table): void
    {
        Schema::create(self::physicalName($table), function (Blueprint $blueprint) {
            $blueprint->id();
            $blueprint->timestamps();
        });

        $column = $table->columns()->make([
            'label' => 'ID',
            'type' => 'integer',
            'width' => 70,
            'sort_order' => 0,
        ]);
        $column->name = 'id';
        $column->is_primary = true;
        $column->save();
    }

    /** Throws the rows away with the table. */
    public static function drop(Table $table): void
    {
        Schema::dropIfExists(self::physicalName($table));
    }

    /**
     * A column name for a new label: snake_case, starting with a letter, not
     * one the database keeps for itself, and not already taken on this table.
     */
    public static function nameFor(Table $table, string $label): string
    {
        // Anything that is not a letter or digit becomes a single underscore
        $base = Str::of($label)->ascii()->lower()
            ->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')
            ->limit(40, '')->rtrim('_')->toString();

        if ($base === '' || ! ctype_alpha($base[0])) {
            $base = 'column_'.$base;
        }

        $taken = $table->columns()->pluck('name')->all();
        $name = $base;

        for ($copy = 2; in_array($name, [...$taken, ...self::RESERVED], true); $copy++) {
            $name = $base.'_'.$copy;
        }

        return self::assertSafe($name);
    }

    /**
     * Gives the table a new column, last in line: named here from its label,
     * described in table_columns, and added to every row.
     *
     * @param  array<string, mixed>  $attributes  label and type, and any of width and options_meta
     */
    public static function newColumn(Table $table, array $attributes): TableColumn
    {
        return DB::transaction(function () use ($table, $attributes) {
            $column = $table->columns()->make([
                'width' => 180,
                ...$attributes,
                'sort_order' => (int) $table->columns()->max('sort_order') + 1,
            ]);
            $column->name = self::nameFor($table, $column->label);
            $column->save();

            self::addColumn($table, $column->name, $column->type);

            return $column;
        });
    }

    /** Adds the column to the rows, stored the way its kind is stored. */
    public static function addColumn(Table $table, string $name, string $type): void
    {
        self::assertSafe($name);
        $storage = self::storageOf($type);

        Schema::table(self::physicalName($table), function (Blueprint $blueprint) use ($name, $storage) {
            match ($storage) {
                'string' => $blueprint->string($name)->nullable(),
                'text' => $blueprint->text($name)->nullable(),
                'integer' => $blueprint->bigInteger($name)->nullable(),
                'decimal' => $blueprint->decimal($name, 20, 4)->nullable(),
                'boolean' => $blueprint->boolean($name)->nullable(),
                'date' => $blueprint->date($name)->nullable(),
                'json' => $blueprint->jsonb($name)->nullable(),
                default => throw new InvalidArgumentException("No storage called \"{$storage}\"."),
            };
        });
    }

    /** Takes the column off every row. */
    public static function dropColumn(Table $table, string $name): void
    {
        self::assertSafe($name);

        Schema::table(self::physicalName($table), function (Blueprint $blueprint) use ($name) {
            $blueprint->dropColumn($name);
        });
    }

    /** Whether a column could change from one kind to another without converting its values. */
    public static function canChange(string $from, string $to): bool
    {
        return self::storageOf($from) === self::storageOf($to);
    }

    /**
     * A value as the grid sent it, made ready to store in a column of this kind.
     */
    public static function toStored(TableColumn $column, mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return self::storageOf($column->type) === 'string' || $column->type === 'text' ? $value : null;
        }

        return match (self::storageOf($column->type)) {
            'integer' => is_numeric($value) ? (int) round((float) $value) : null,
            'decimal' => is_numeric($value) ? (string) $value : null,
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'date' => rescue(fn () => Carbon::parse((string) $value)->toDateString(), null, false),
            'json' => json_encode(array_values(is_array($value) ? $value : (json_decode((string) $value, true) ?? []))),
            default => is_scalar($value) ? (string) $value : json_encode($value),
        };
    }

    /**
     * A stored value as the grid reads it: numbers as numbers, flags as flags,
     * and a multi-select as the list it is rather than the text it was kept as.
     */
    public static function toGrid(TableColumn $column, mixed $value): mixed
    {
        if ($value === null) {
            return self::storageOf($column->type) === 'json' ? [] : null;
        }

        return match (self::storageOf($column->type)) {
            'integer' => (int) $value,
            'decimal' => (float) $value,
            'boolean' => (bool) $value,
            'json' => is_array($value) ? $value : (json_decode((string) $value, true) ?? []),
            default => $value,
        };
    }

    /** How many rows the table holds. */
    public static function count(Table $table): int
    {
        return DB::table(self::physicalName($table))->count();
    }

    /**
     * Every row of a table, in the order they were added, as the grid reads them.
     *
     * @return list<array<string, mixed>>
     */
    public static function rows(Table $table): array
    {
        return array_values(DB::table(self::physicalName($table))
            ->orderBy('id')
            ->get()
            ->map(fn (object $row) => self::forGrid($table, $row))
            ->all());
    }

    /**
     * One row, as the grid reads it.
     *
     * @return array<string, mixed>|null
     */
    public static function row(Table $table, int $id): ?array
    {
        $found = DB::table(self::physicalName($table))->where('id', $id)->first();

        return $found ? self::forGrid($table, $found) : null;
    }

    /**
     * A stored row with each value turned back into what the grid reads.
     *
     * @return array<string, mixed>
     */
    private static function forGrid(Table $table, object $row): array
    {
        $stored = (array) $row;
        $values = ['id' => (int) $stored['id']];

        foreach ($table->columns as $column) {
            if ($column->name !== 'id') {
                $values[$column->name] = self::toGrid($column, $stored[$column->name] ?? null);
            }
        }

        return $values;
    }

    private static function storageOf(string $type): string
    {
        return self::STORAGE[$type] ?? throw new InvalidArgumentException("No column is of kind \"{$type}\".");
    }

    private static function assertSafe(string $name): string
    {
        if (! preg_match(self::SAFE_NAME, $name) || in_array($name, self::RESERVED, true)) {
            throw new InvalidArgumentException("\"{$name}\" cannot be used as a column name.");
        }

        return $name;
    }
}
