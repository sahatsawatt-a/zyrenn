<?php

namespace App\Support\Formula;

use DateTimeImmutable;

/**
 * A formula, read once and worked out as often as it is asked: a column's
 * for every row, a note's chip each time it is shown.
 */
final class Formula
{
    private const CACHED = 500;

    /** @var array<string, array<string, mixed>> */
    private static array $read = [];

    /**
     * @return array<string, mixed>
     *
     * @throws FormulaError when it can't be read
     */
    public static function parse(string $source): array
    {
        if (! isset(self::$read[$source])) {
            if (count(self::$read) >= self::CACHED) {
                self::$read = [];
            }

            self::$read[$source] = Parser::parse($source);
        }

        return self::$read[$source];
    }

    /**
     * @throws FormulaError
     */
    public static function evaluate(string $source, Scope $scope): mixed
    {
        return Evaluator::evaluate(self::parse($source), $scope);
    }

    /**
     * An answer as JSON carries it and a page shows it: a date as YYYY-MM-DD,
     * a list as its text, a number without floating point's noise.
     */
    public static function shown(mixed $value): mixed
    {
        return match (true) {
            $value instanceof DateTimeImmutable => $value->format('Y-m-d'),
            is_float($value) => is_finite($value) ? (float) Evaluator::numberText($value) : null,
            is_array($value) => Evaluator::text($value),
            default => $value,
        };
    }

    /**
     * What a formula's answer reads as, or why there isn't one.
     *
     * @return array{value: mixed, text: string, error: string|null}
     */
    public static function attempt(string $source, Scope $scope): array
    {
        try {
            $value = self::evaluate($source, $scope);

            return ['value' => self::shown($value), 'text' => Evaluator::text($value), 'error' => null];
        } catch (FormulaError $problem) {
            return ['value' => null, 'text' => '', 'error' => $problem->getMessage()];
        }
    }

    /**
     * The names a formula looks up in its scope, lower-cased, each once --
     * not the parts after a dot, which belong to what comes before it.
     *
     * @return list<string>
     *
     * @throws FormulaError when it can't be read
     */
    public static function names(string $source): array
    {
        $names = [];
        $walk = function (array $node) use (&$walk, &$names): void {
            $parts = match ($node['node']) {
                'member' => [$node['object']],
                'call' => [...($node['callee']['node'] === 'member' ? [$node['callee']['object']] : []), ...$node['args']],
                'negate', 'not' => [$node['operand']],
                'binary' => [$node['left'], $node['right']],
                default => [],
            };

            if ($node['node'] === 'name') {
                $names[mb_strtolower($node['name'])] = true;
            }

            foreach ($parts as $part) {
                $walk($part);
            }
        };

        $walk(self::parse($source));

        return array_keys($names);
    }
}
