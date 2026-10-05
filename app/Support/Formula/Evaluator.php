<?php

namespace App\Support\Formula;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Works out a tree from the Parser against a Scope.
 *
 * Values are numbers (floats), text, true/false, dates (DateTimeImmutable at
 * midnight UTC), null for an empty cell, records (arrays with names for keys,
 * or Members) and lists. An empty cell counts as 0 in sums and as "" in text,
 * and the aggregates skip it.
 *
 * Lists go element by element, so a column of another table can be worked on
 * whole: sum(budget.thb * rate), sum(if(budget.day = 5, budget.thb, 0)).
 */
final class Evaluator
{
    /** Enough for a few thousand rows worked through a dozen operators */
    private const MAX_STEPS = 200_000;

    private int $steps = 0;

    private function __construct(private Scope $scope) {}

    /**
     * @param  array<string, mixed>  $tree
     *
     * @throws FormulaError
     */
    public static function evaluate(array $tree, Scope $scope): mixed
    {
        return (new self($scope))->value($tree);
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function value(array $node): mixed
    {
        if (++$this->steps > self::MAX_STEPS) {
            throw new FormulaError('The formula takes too long to work out.');
        }

        return match ($node['node']) {
            'literal' => $node['value'],
            'name' => $this->scope->lookup($node['name']),
            'member' => self::member($this->value($node['object']), $node['name']),
            'call' => $this->call($node),
            'negate' => self::each($this->value($node['operand']), fn ($value) => -self::number($value)),
            'not' => self::each($this->value($node['operand']), fn ($value) => ! self::truthy($value)),
            'binary' => $this->binary($node),
            default => throw new FormulaError('A formula part of an unknown kind.'),
        };
    }

    private static function member(mixed $object, string $name): mixed
    {
        if ($object instanceof Members) {
            return $object->member($name);
        }

        if (is_array($object) && ! array_is_list($object)) {
            foreach ($object as $key => $value) {
                if (mb_strtolower((string) $key) === mb_strtolower($name)) {
                    return $value;
                }
            }

            throw new FormulaError("There is no \"{$name}\" here.");
        }

        throw new FormulaError(self::kind($object)." has no parts, so it has no \"{$name}\".");
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function binary(array $node): mixed
    {
        $op = $node['op'];
        $left = $this->value($node['left']);

        // A single true or false settles it, without working out the rest
        if ($op === 'and' && ! is_array($left) && ! self::truthy($left)) {
            return false;
        }

        if ($op === 'or' && ! is_array($left) && self::truthy($left)) {
            return true;
        }

        $right = $this->value($node['right']);

        return self::zip($left, $right, fn ($a, $b) => self::operate($op, $a, $b));
    }

    private static function operate(string $op, mixed $a, mixed $b): mixed
    {
        return match ($op) {
            'and' => self::truthy($a) && self::truthy($b),
            'or' => self::truthy($a) || self::truthy($b),
            '&' => self::text($a).self::text($b),
            '=' => self::compare($a, $b) === 0,
            '!=' => self::compare($a, $b) !== 0,
            '<' => self::compare($a, $b) < 0,
            '<=' => self::compare($a, $b) <= 0,
            '>' => self::compare($a, $b) > 0,
            '>=' => self::compare($a, $b) >= 0,
            '+' => self::add($a, $b),
            '-' => self::subtract($a, $b),
            '*' => self::number($a) * self::number($b),
            '/' => self::divide(self::number($a), self::number($b)),
            '%' => self::number($b) == 0.0 ? throw new FormulaError('Dividing by zero.') : fmod(self::number($a), self::number($b)),
            '^' => self::power(self::number($a), self::number($b)),
            default => throw new FormulaError("\"{$op}\" is not an operator."),
        };
    }

    /**
     * A date and a number of days make a later date; two dates, the days between.
     */
    private static function add(mixed $a, mixed $b): mixed
    {
        if ($a instanceof DateTimeImmutable && $b instanceof DateTimeImmutable) {
            throw new FormulaError('Two dates can\'t be added; take one from the other for the days between.');
        }

        if ($a instanceof DateTimeImmutable || $b instanceof DateTimeImmutable) {
            [$date, $days] = $a instanceof DateTimeImmutable ? [$a, $b] : [$b, $a];

            return self::shift($date, self::number($days));
        }

        return self::number($a) + self::number($b);
    }

    private static function subtract(mixed $a, mixed $b): mixed
    {
        if ($a instanceof DateTimeImmutable && $b instanceof DateTimeImmutable) {
            return (float) $b->diff($a)->format('%r%a');
        }

        if ($a instanceof DateTimeImmutable) {
            return self::shift($a, -self::number($b));
        }

        return self::number($a) - self::number($b);
    }

    private static function shift(DateTimeImmutable $date, float $days): DateTimeImmutable
    {
        $whole = (int) round($days);

        return $date->modify(($whole >= 0 ? '+' : '').$whole.' days');
    }

    private static function divide(float $a, float $b): float
    {
        if ($b == 0.0) {
            throw new FormulaError('Dividing by zero.');
        }

        return $a / $b;
    }

    private static function power(float $a, float $b): float
    {
        $result = $a ** $b;

        if (is_nan($result) || is_infinite($result)) {
            throw new FormulaError("{$a} ^ {$b} has no answer.");
        }

        return $result;
    }

    /**
     * Below zero when $a comes first. Numbers by size, dates by day, text by
     * its letters; text that reads as a number is taken as one, so a choice
     * of "5" equals 5.
     */
    private static function compare(mixed $a, mixed $b): int
    {
        if ($a instanceof DateTimeImmutable || $b instanceof DateTimeImmutable) {
            return self::date($a) <=> self::date($b);
        }

        $numeric = fn ($value) => $value === null || is_bool($value) || is_float($value) || is_int($value)
            || (is_string($value) && is_numeric(trim($value)));

        if ($numeric($a) && $numeric($b)) {
            return self::number($a) <=> self::number($b);
        }

        return strcmp(self::text($a), self::text($b));
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function call(array $node): mixed
    {
        $callee = $node['callee'];

        // A part of something: trip.day(5)
        if ($callee['node'] === 'member') {
            $object = $this->value($callee['object']);

            if (! $object instanceof Members) {
                throw new FormulaError(self::kind($object)." has no \"{$callee['name']}\" to call.");
            }

            return $object->call($callee['name'], array_values(array_map(fn ($arg) => $this->value($arg), $node['args'])));
        }

        $name = mb_strtolower($callee['name']);

        // Only the branch taken is worked out, unless the choice is a list
        if ($name === 'if') {
            return $this->choose($node['args']);
        }

        $args = array_values(array_map(fn ($arg) => $this->value($arg), $node['args']));
        $own = $this->scope instanceof Callables ? $this->scope->callable($name) : null;

        return $own !== null ? $own($args) : Functions::call($name, $args);
    }

    /**
     * @param  list<array<string, mixed>>  $args
     */
    private function choose(array $args): mixed
    {
        if (count($args) < 2 || count($args) > 3) {
            throw new FormulaError('if needs a test, a value when true, and optionally one when false: if(test, yes, no).');
        }

        $test = $this->value($args[0]);

        if (! is_array($test) || ! array_is_list($test)) {
            $branch = self::truthy($test) ? $args[1] : ($args[2] ?? null);

            return $branch === null ? null : $this->value($branch);
        }

        $yes = $this->value($args[1]);
        $no = isset($args[2]) ? $this->value($args[2]) : null;

        return self::zip(self::zip($test, $yes, fn ($t, $y) => [$t, $y]), $no, fn ($pair, $n) => self::truthy($pair[0]) ? $pair[1] : $n);
    }

    // ---------------------------------------------------------- values

    /**
     * Two values put together element by element when either is a list.
     */
    public static function zip(mixed $a, mixed $b, callable $join): mixed
    {
        $aList = is_array($a) && array_is_list($a);
        $bList = is_array($b) && array_is_list($b);

        if (! $aList && ! $bList) {
            return $join($a, $b);
        }

        if ($aList && $bList && count($a) !== count($b)) {
            throw new FormulaError('Two lists of different lengths ('.count($a).' and '.count($b).') can\'t be put together.');
        }

        $length = $aList ? count($a) : count($b);
        $out = [];

        for ($i = 0; $i < $length; $i++) {
            $out[] = $join($aList ? $a[$i] : $a, $bList ? $b[$i] : $b);
        }

        return $out;
    }

    public static function each(mixed $value, callable $change): mixed
    {
        return is_array($value) && array_is_list($value) ? array_map($change, $value) : $change($value);
    }

    public static function number(mixed $value): float
    {
        return match (true) {
            $value === null => 0.0,
            is_bool($value) => $value ? 1.0 : 0.0,
            is_int($value), is_float($value) => (float) $value,
            is_string($value) && trim($value) === '' => 0.0,
            is_string($value) && is_numeric(trim($value)) => (float) trim($value),
            default => throw new FormulaError('Expected a number but got '.self::kind($value).'.'),
        };
    }

    public static function truthy(mixed $value): bool
    {
        return match (true) {
            $value === null => false,
            is_bool($value) => $value,
            is_int($value), is_float($value) => $value != 0,
            is_string($value) => trim($value) !== '' && mb_strtolower(trim($value)) !== 'false',
            default => throw new FormulaError('Expected true or false but got '.self::kind($value).'.'),
        };
    }

    public static function date(mixed $value): DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }

        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}/', trim($value), $match)) {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $match[0], new DateTimeZone('UTC'));

            if ($date !== false && $date->format('Y-m-d') === $match[0]) {
                return $date;
            }
        }

        throw new FormulaError('Expected a date but got '.self::kind($value).'.');
    }

    public static function text(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value), is_float($value) => self::numberText((float) $value),
            is_string($value) => $value,
            $value instanceof DateTimeImmutable => $value->format('Y-m-d'),
            is_array($value) && array_is_list($value) => implode(', ', array_map(self::text(...), $value)),
            default => throw new FormulaError(self::kind($value).' can\'t be shown as text.'),
        };
    }

    /**
     * A number as plainly as it can be written: no trailing zeros, and none
     * of floating point's noise (0.1 + 0.2 is 0.3).
     */
    public static function numberText(float $number): string
    {
        if (is_nan($number) || is_infinite($number)) {
            throw new FormulaError('The answer is not a number.');
        }

        $text = rtrim(rtrim(number_format(round($number, 10), 10, '.', ''), '0'), '.');

        return $text === '-0' ? '0' : $text;
    }

    /**
     * What a value is, for a message.
     */
    public static function kind(mixed $value): string
    {
        return match (true) {
            $value === null => 'an empty value',
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value), is_float($value) => 'the number '.self::numberText((float) $value),
            is_string($value) => 'the text "'.mb_strimwidth($value, 0, 40, '…').'"',
            $value instanceof DateTimeImmutable => 'the date '.$value->format('Y-m-d'),
            is_array($value) && array_is_list($value) => 'a list of '.count($value),
            default => 'a record',
        };
    }
}
