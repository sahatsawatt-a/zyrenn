<?php

namespace App\Support\Workflow;

use App\Support\Formula\Evaluator;
use App\Support\Formula\Formula;
use App\Support\Formula\FormulaError;
use App\Support\Formula\Scope;
use DateTimeInterface;

/**
 * Text with {{ formulas }} in it -- "Order {{ input.id }} came to {{ input.total }}" --
 * the way a note's chips are written. Each formula is the spreadsheet
 * language of App\Support\Formula.
 */
final class Template
{
    /**
     * The formulas in a template, in order.
     *
     * @return list<string>
     *
     * @throws FormulaError when a {{ is never closed
     */
    public static function formulas(string $template): array
    {
        return array_values(array_map(
            fn (array $piece) => $piece['formula'],
            array_filter(self::pieces($template), 'is_array'),
        ));
    }

    /**
     * The template in order: its text, and its formulas.
     *
     * @return list<string|array{formula: string}>
     *
     * @throws FormulaError when a {{ is never closed
     */
    public static function pieces(string $template): array
    {
        $pieces = [];
        $at = 0;

        while (($open = strpos($template, '{{', $at)) !== false) {
            $close = strpos($template, '}}', $open + 2);

            if ($close === false) {
                throw new FormulaError('A {{ at character '.($open + 1).' is never closed with }}.');
            }

            if ($open > $at) {
                $pieces[] = substr($template, $at, $open - $at);
            }

            $pieces[] = ['formula' => trim(substr($template, $open + 2, $close - $open - 2))];
            $at = $close + 2;
        }

        if ($at < strlen($template)) {
            $pieces[] = substr($template, $at);
        }

        return $pieces;
    }

    /**
     * A template worked out. One that is a single formula and nothing else
     * keeps its answer as it is -- a number, a list, a record -- so a value can
     * be carried from one step to the next; anything else becomes text.
     *
     * @throws FormulaError when a formula can't be read or worked out
     */
    public static function render(string $template, Scope $scope): mixed
    {
        $pieces = self::pieces($template);

        if (count($pieces) === 1 && is_array($pieces[0])) {
            return self::value(self::evaluate($pieces[0]['formula'], $scope));
        }

        $text = '';

        foreach ($pieces as $piece) {
            $text .= is_string($piece) ? $piece : self::text(self::value(self::evaluate($piece['formula'], $scope)));
        }

        return $text;
    }

    /**
     * A formula's answer as a step keeps it: plain JSON values.
     */
    public static function value(mixed $value): mixed
    {
        return match (true) {
            $value instanceof DateTimeInterface => $value->format('Y-m-d'),
            is_float($value) && ! is_finite($value) => null,
            // A whole number reads as one: 10, not 10.0
            is_float($value) && floor($value) === $value && abs($value) < 1e15 => (int) $value,
            is_array($value) => array_map(self::value(...), $value),
            is_object($value) => null,
            default => $value,
        };
    }

    /**
     * A value as it reads inside text: a record or list as JSON.
     */
    public static function text(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'true' : 'false',
            is_float($value) => Evaluator::numberText($value),
            is_array($value) => (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            default => (string) $value,
        };
    }

    private static function evaluate(string $formula, Scope $scope): mixed
    {
        if ($formula === '') {
            throw new FormulaError('There is an empty {{ }}.');
        }

        return Formula::evaluate($formula, $scope);
    }

    /**
     * Every formula in a template, read.
     *
     * @return list<array<string, mixed>>
     *
     * @throws FormulaError when one can't be read
     */
    public static function parse(string $template): array
    {
        return array_map(function (string $formula) {
            if ($formula === '') {
                throw new FormulaError('There is an empty {{ }}.');
            }

            return Formula::parse($formula);
        }, self::formulas($template));
    }

    /**
     * The steps a read formula names with node("key").
     *
     * @param  array<string, mixed>  $tree
     * @return list<string>
     */
    public static function nodesNamed(array $tree): array
    {
        $named = [];

        $walk = function (mixed $branch) use (&$walk, &$named): void {
            if (! is_array($branch)) {
                return;
            }

            if (($branch['node'] ?? null) === 'call'
                && ($branch['callee']['node'] ?? null) === 'name'
                && ($branch['callee']['name'] ?? null) === 'node'
                && ($branch['args'][0]['node'] ?? null) === 'literal'
                && is_string($branch['args'][0]['value'] ?? null)) {
                $named[] = $branch['args'][0]['value'];
            }

            foreach ($branch as $child) {
                $walk($child);
            }
        };

        $walk($tree);

        return array_values(array_unique($named));
    }
}
