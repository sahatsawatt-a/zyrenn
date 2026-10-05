<?php

namespace App\Support\Table;

use App\Models\Table\Table;
use App\Models\Table\TableColumn;
use App\Support\Formula\Evaluator;
use App\Support\Formula\Formula;
use App\Support\Formula\FormulaError;
use App\Support\Formula\Functions;
use App\Support\Formula\NamedValues;
use App\Support\Formula\References;
use App\Support\Formula\Scope;

/**
 * A table's formulas: its formula columns, worked out for each row as rows
 * are read, its parameters, and the totals under its columns.
 *
 * In a formula a column is named by its name (cost_thb) or, in brackets, by
 * its label ([Cost (THB)]); a parameter by its name. A formula column can use
 * another, but not, by any way round, itself.
 */
final class TableFormulas
{
    /** What a parameter can be called: a name a formula can write bare */
    public const PARAMETER_NAME = '/^[\p{L}_][\p{L}\p{N}_]{0,39}$/u';

    public const SUMMARIES = ['sum', 'avg', 'min', 'max', 'count'];

    public const MAX_PARAMETERS = 50;

    /**
     * The rows with each formula column's value: what it works out to, or
     * {error} saying why it doesn't.
     *
     * @param  list<array<string, mixed>>  $rows  as the grid reads them
     * @return list<array<string, mixed>>
     */
    public static function apply(Table $table, array $rows): array
    {
        $formulas = self::formulaColumns($table);

        if ($formulas === [] || $rows === []) {
            return $rows;
        }

        $parameters = self::parameters($table);

        return array_map(function (array $row) use ($table, $formulas, $parameters) {
            $worked = self::work($table, $row, $parameters);

            foreach ($formulas as $column) {
                $row[$column->name] = $worked($column->name);
            }

            return $row;
        }, $rows);
    }

    /**
     * What's wrong with an expression for a formula column, or null: it can't
     * be read, names something the table doesn't have, or comes back round to
     * the column it is for.
     */
    public static function problem(Table $table, string $expression, ?string $for = null): ?string
    {
        try {
            $names = Formula::names($expression);
        } catch (FormulaError $error) {
            return $error->getMessage();
        }

        $known = self::known($table);

        foreach ($names as $name) {
            if (! isset($known[$name])) {
                return "Nothing in this table is called \"{$name}\": name a column, a [Column label] or a parameter.";
            }
        }

        if ($for === null) {
            return null;
        }

        // Each formula column and what it names, with this one as it would be
        $uses = [];

        foreach (self::formulaColumns($table) as $column) {
            $uses[$column->name] = self::namesIn(self::expressionOf($column));
        }

        $uses[$for] = $names;
        $columnOf = self::columnNames($table);

        $loop = self::loopFrom($for, $uses, $columnOf);

        return $loop === null ? null : 'This formula comes back round to itself: '.implode(' → ', $loop).'.';
    }

    /**
     * Each summed column's total over every row, by column name.
     *
     * @param  list<array<string, mixed>>|null  $rows  every row as the grid reads it, when already at hand
     * @return array<string, mixed>
     */
    public static function totals(Table $table, ?array $rows = null): array
    {
        $summed = $table->columns->filter(fn (TableColumn $column) => self::summaryOf($column) !== null);

        if ($summed->isEmpty()) {
            return [];
        }

        $rows ??= TableStorage::rows($table);
        $totals = [];

        foreach ($summed as $column) {
            $values = array_values(array_filter(
                array_map(fn (array $row) => $row[$column->name] ?? null, $rows),
                fn ($value) => ! is_array($value) || array_is_list($value),
            ));

            try {
                $totals[$column->name] = Formula::shown(Functions::call((string) self::summaryOf($column), [$values]));
            } catch (FormulaError $error) {
                $totals[$column->name] = ['error' => $error->getMessage()];
            }
        }

        return $totals;
    }

    /**
     * The table's parameters, for a formula: a date as a date, a number as a
     * number -- and around them, the trips and tables of the same owner that
     * trip("…") and table("…") reach.
     */
    public static function parameters(Table $table): Scope
    {
        $values = [];

        foreach ($table->parameters ?? [] as $parameter) {
            $values[(string) $parameter['name']] = self::readable($parameter['value'] ?? null);
        }

        return new NamedValues($values, new References($table->owner()));
    }

    /**
     * Parameters as they are kept: names that can be written bare, each once,
     * none a column's; values that are numbers, text, true/false or dates.
     *
     * @param  list<array{name?: mixed, value?: mixed}>  $given
     * @return list<array{name: string, value: mixed}>
     *
     * @throws FormulaError naming what is wrong
     */
    public static function cleanParameters(Table $table, array $given): array
    {
        if (count($given) > self::MAX_PARAMETERS) {
            throw new FormulaError('A table can have at most '.self::MAX_PARAMETERS.' parameters.');
        }

        $columns = self::known($table, withParameters: false);
        $seen = [];
        $clean = [];

        foreach ($given as $parameter) {
            $name = trim((string) ($parameter['name'] ?? ''));
            $value = $parameter['value'] ?? null;
            $key = mb_strtolower($name);

            if (! preg_match(self::PARAMETER_NAME, $name)) {
                throw new FormulaError("\"{$name}\" can't be a parameter's name: use letters, digits and _, starting with a letter.");
            }

            if (isset($seen[$key])) {
                throw new FormulaError("There are two parameters called \"{$name}\".");
            }

            if (isset($columns[$key])) {
                throw new FormulaError("\"{$name}\" is already a column of this table.");
            }

            if (! is_null($value) && ! is_scalar($value)) {
                throw new FormulaError("The parameter \"{$name}\" must be a number, text, true or false, or a date.");
            }

            $seen[$key] = true;
            $clean[] = ['name' => $name, 'value' => is_string($value) ? mb_substr($value, 0, 500) : $value];
        }

        return $clean;
    }

    // ------------------------------------------------------------ inside

    /**
     * Works out a row's formula columns as they are asked for, each once,
     * stopping at one that leads back to itself.
     *
     * @param  array<string, mixed>  $row
     * @return \Closure(string): mixed
     */
    private static function work(Table $table, array $row, Scope $parameters): \Closure
    {
        $done = [];
        $working = [];
        $values = [];

        $worked = function (string $name) use (&$done, &$working, &$values, $parameters, $table): mixed {
            if (array_key_exists($name, $done)) {
                return $done[$name];
            }

            if (isset($working[$name])) {
                throw new FormulaError("\"{$name}\" comes back round to itself.");
            }

            $working[$name] = true;
            /** @var TableColumn $column */
            $column = $table->columns->firstWhere('name', $name);

            try {
                $done[$name] = Formula::shown(Formula::evaluate(self::expressionOf($column), new NamedValues($values, $parameters)));
            } catch (FormulaError $error) {
                $done[$name] = ['error' => $error->getMessage()];
            } finally {
                unset($working[$name]);
            }

            return $done[$name];
        };

        foreach ($table->columns as $column) {
            $value = $column->type === 'formula'
                ? fn () => self::readable($worked($column->name))
                : self::readable($row[$column->name] ?? null, $column->type);

            $values[$column->name] = $value;
            $values[$column->label] ??= $value;
        }

        return $worked;
    }

    /**
     * A value as a formula reads it: a date column's dates as dates, a
     * formula's error as the error it is.
     */
    private static function readable(mixed $value, ?string $type = null): mixed
    {
        if (is_array($value) && isset($value['error']) && count($value) === 1) {
            throw new FormulaError($value['error']);
        }

        if (is_string($value) && ($type === 'date' || ($type === null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)))) {
            return rescue(fn () => Evaluator::date($value), $value, false);
        }

        return is_int($value) ? (float) $value : $value;
    }

    /**
     * @return list<TableColumn>
     */
    private static function formulaColumns(Table $table): array
    {
        return array_values($table->columns->filter(fn (TableColumn $column) => $column->type === 'formula')->all());
    }

    public static function expressionOf(TableColumn $column): string
    {
        $meta = $column->options_meta ?? [];

        return array_is_list($meta) ? '' : (string) ($meta['expression'] ?? '');
    }

    public static function summaryOf(TableColumn $column): ?string
    {
        $meta = $column->options_meta ?? [];
        $summary = array_is_list($meta) ? null : ($meta['summary'] ?? null);

        return in_array($summary, self::SUMMARIES, true) ? $summary : null;
    }

    /**
     * Every name a formula here can use, lower-cased.
     *
     * @return array<string, true>
     */
    private static function known(Table $table, bool $withParameters = true): array
    {
        $known = [];

        foreach ($table->columns as $column) {
            $known[mb_strtolower($column->name)] = true;
            $known[mb_strtolower($column->label)] = true;
        }

        foreach ($withParameters ? $table->parameters ?? [] : [] as $parameter) {
            $known[mb_strtolower((string) $parameter['name'])] = true;
        }

        return $known;
    }

    /**
     * Lower-cased name or label => the column's name.
     *
     * @return array<string, string>
     */
    private static function columnNames(Table $table): array
    {
        $names = [];

        foreach ($table->columns as $column) {
            $names[mb_strtolower($column->name)] = $column->name;
            $names[mb_strtolower($column->label)] ??= $column->name;
        }

        return $names;
    }

    /**
     * @return list<string>
     */
    private static function namesIn(string $expression): array
    {
        try {
            return Formula::names($expression);
        } catch (FormulaError) {
            return [];
        }
    }

    /**
     * The way round from a formula column back to itself, if there is one.
     *
     * @param  array<string, list<string>>  $uses  formula column => the names it uses
     * @param  array<string, string>  $columnOf
     * @param  list<string>  $path
     * @return list<string>|null
     */
    private static function loopFrom(string $start, array $uses, array $columnOf, array $path = []): ?array
    {
        $at = $path === [] ? $start : end($path);

        foreach ($uses[$at] ?? [] as $name) {
            $next = $columnOf[$name] ?? null;

            if ($next === null || ! isset($uses[$next])) {
                continue;
            }

            if ($next === $start) {
                return [$start, ...$path, $start];
            }

            if (! in_array($next, $path, true)) {
                $found = self::loopFrom($start, $uses, $columnOf, [...$path, $next]);

                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }
}
