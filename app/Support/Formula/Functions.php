<?php

namespace App\Support\Formula;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The functions a formula can call -- these and no others. Names ignore case.
 *
 * The aggregates (sum, avg, min, max, count) take lists or any number of
 * values and skip empty ones; the rest go element by element over a list.
 * if() is the Evaluator's own, as only the branch taken is worked out.
 */
final class Functions
{
    /** What each takes, for messages and for the help an editor shows */
    public const SIGNATURES = [
        'if' => 'if(test, yes, no)',
        'sum' => 'sum(values…)',
        'avg' => 'avg(values…)',
        'min' => 'min(values…)',
        'max' => 'max(values…)',
        'count' => 'count(values…)',
        'round' => 'round(number, places)',
        'floor' => 'floor(number)',
        'ceil' => 'ceil(number)',
        'abs' => 'abs(number)',
        'filter' => 'filter(list, tests)',
        'coalesce' => 'coalesce(values…)',
        'number' => 'number(text)',
        'text' => 'text(value, format)',
        'upper' => 'upper(text)',
        'lower' => 'lower(text)',
        'len' => 'len(text or list)',
        'date' => 'date("2026-12-03") or date(year, month, day)',
        'today' => 'today()',
        'year' => 'year(date)',
        'month' => 'month(date)',
        'day' => 'day(date)',
        'weekday' => 'weekday(date)',
        'days' => 'days(from, to)',
    ];

    /**
     * @param  list<mixed>  $args
     *
     * @throws FormulaError
     */
    public static function call(string $name, array $args): mixed
    {
        return match ($name) {
            'sum' => array_sum(array_map(Evaluator::number(...), self::flat($args))),
            'avg' => self::average(self::flat($args)),
            'min' => self::extreme(self::flat($args), -1),
            'max' => self::extreme(self::flat($args), 1),
            'count' => (float) count(self::flat($args)),
            'round' => self::round($args),
            'floor' => Evaluator::each(self::one($name, $args), fn ($value) => floor(Evaluator::number($value))),
            'ceil' => Evaluator::each(self::one($name, $args), fn ($value) => ceil(Evaluator::number($value))),
            'abs' => Evaluator::each(self::one($name, $args), fn ($value) => abs(Evaluator::number($value))),
            'filter' => self::filter($args),
            'coalesce' => self::coalesce($args),
            'number' => Evaluator::each(self::one($name, $args), Evaluator::number(...)),
            'text' => self::text($args),
            'upper' => Evaluator::each(self::one($name, $args), fn ($value) => mb_strtoupper(Evaluator::text($value))),
            'lower' => Evaluator::each(self::one($name, $args), fn ($value) => mb_strtolower(Evaluator::text($value))),
            'len' => self::length(self::one($name, $args)),
            'date' => self::date($args),
            'today' => Evaluator::date((new DateTimeImmutable('now', new DateTimeZone((string) config('app.timezone'))))->format('Y-m-d')),
            'year' => Evaluator::each(self::one($name, $args), fn ($value) => (float) Evaluator::date($value)->format('Y')),
            'month' => Evaluator::each(self::one($name, $args), fn ($value) => (float) Evaluator::date($value)->format('n')),
            'day' => Evaluator::each(self::one($name, $args), fn ($value) => (float) Evaluator::date($value)->format('j')),
            'weekday' => Evaluator::each(self::one($name, $args), fn ($value) => Evaluator::date($value)->format('D')),
            'days' => self::days($args),
            default => throw new FormulaError("There is no function called \"{$name}\"."),
        };
    }

    /**
     * Every value given, lists opened up, empty ones left out.
     *
     * @param  list<mixed>  $args
     * @return list<mixed>
     */
    private static function flat(array $args): array
    {
        $values = [];

        foreach ($args as $arg) {
            foreach (is_array($arg) && array_is_list($arg) ? $arg : [$arg] as $value) {
                if ($value !== null && $value !== '') {
                    $values[] = $value;
                }
            }
        }

        return $values;
    }

    /**
     * @param  list<mixed>  $values
     */
    private static function average(array $values): ?float
    {
        return $values === [] ? null : array_sum(array_map(Evaluator::number(...), $values)) / count($values);
    }

    /**
     * The smallest ($way -1) or largest ($way 1): numbers, or dates.
     *
     * @param  list<mixed>  $values
     */
    private static function extreme(array $values, int $way): mixed
    {
        if ($values === []) {
            return null;
        }

        $dates = $values[0] instanceof DateTimeImmutable;
        $best = null;

        foreach ($values as $value) {
            $value = $dates ? Evaluator::date($value) : Evaluator::number($value);

            if ($best === null || ($value <=> $best) === $way) {
                $best = $value;
            }
        }

        return $best;
    }

    /**
     * @param  list<mixed>  $args
     */
    private static function one(string $name, array $args): mixed
    {
        if (count($args) !== 1) {
            throw new FormulaError(self::SIGNATURES[$name].' takes one value.');
        }

        return $args[0];
    }

    /**
     * @param  list<mixed>  $args
     */
    private static function round(array $args): mixed
    {
        if (count($args) < 1 || count($args) > 2) {
            throw new FormulaError('round takes a number and, optionally, how many places: round(number, places).');
        }

        $places = (int) Evaluator::number($args[1] ?? 0);

        return Evaluator::each($args[0], fn ($value) => round(Evaluator::number($value), $places));
    }

    /**
     * The values whose test, in the same place in the second list, is true.
     *
     * @param  list<mixed>  $args
     * @return list<mixed>
     */
    private static function filter(array $args): array
    {
        if (count($args) !== 2) {
            throw new FormulaError('filter takes a list and a test for each of its values: filter(list, tests).');
        }

        $kept = Evaluator::zip($args[0], $args[1], fn ($value, $test) => Evaluator::truthy($test) ? [$value] : []);

        return is_array($kept) && array_is_list($kept) ? array_values(array_merge(...$kept)) : [];
    }

    /**
     * The first value that isn't empty.
     *
     * @param  list<mixed>  $args
     */
    private static function coalesce(array $args): mixed
    {
        foreach ($args as $arg) {
            if ($arg !== null && $arg !== '') {
                return $arg;
            }
        }

        return null;
    }

    /**
     * A value as text: a date in a PHP date() pattern ("D j M" is Mon 7 Dec),
     * a number to so many places with thousands set apart.
     *
     * @param  list<mixed>  $args
     */
    private static function text(array $args): mixed
    {
        if (count($args) < 1 || count($args) > 2) {
            throw new FormulaError('text takes a value and, optionally, a format: text(value, format).');
        }

        if (! isset($args[1])) {
            return Evaluator::each($args[0], Evaluator::text(...));
        }

        $format = Evaluator::text($args[1]);

        return Evaluator::each($args[0], function ($value) use ($format) {
            if ($value instanceof DateTimeImmutable) {
                return $value->format($format);
            }

            if (! ctype_digit($format)) {
                throw new FormulaError('A number\'s format is how many places to show, e.g. text(cost, 2); a pattern like "'.$format.'" is for dates.');
            }

            return number_format(Evaluator::number($value), (int) $format);
        });
    }

    private static function length(mixed $value): float
    {
        return (float) (is_array($value) && array_is_list($value) ? count($value) : mb_strlen(Evaluator::text($value)));
    }

    /**
     * @param  list<mixed>  $args
     */
    private static function date(array $args): mixed
    {
        if (count($args) === 1) {
            return Evaluator::each($args[0], Evaluator::date(...));
        }

        if (count($args) !== 3) {
            throw new FormulaError('date takes "2026-12-03", or a year, a month and a day: date(2026, 12, 3).');
        }

        [$year, $month, $day] = array_map(fn ($value) => (int) Evaluator::number($value), $args);

        if (! checkdate($month, $day, $year)) {
            throw new FormulaError("{$year}-{$month}-{$day} is not a date.");
        }

        return Evaluator::date(sprintf('%04d-%02d-%02d', $year, $month, $day));
    }

    /**
     * @param  list<mixed>  $args
     */
    private static function days(array $args): mixed
    {
        if (count($args) !== 2) {
            throw new FormulaError('days takes two dates and counts the days from the first to the second: days(from, to).');
        }

        return Evaluator::zip($args[0], $args[1], fn ($from, $to) => (float) Evaluator::date($from)->diff(Evaluator::date($to))->format('%r%a'));
    }
}
