<?php

namespace App\Support\Formula;

/**
 * Reads a formula into a tree the Evaluator works out.
 *
 * The language is a spreadsheet's, kept small enough to be written again in
 * TypeScript when the browser needs to:
 *
 *     cny * rate                    a column times a parameter
 *     [Cost (THB)] / people         a name with spaces, in brackets
 *     if(kind = "estimate", 0, cost)
 *     sum(budget.thb)               another table's column, as a list
 *     trip.day(5).date + 1          members, called or not
 *
 * Operators, loosest first: or; and; not; = != < <= > >=; &; + -; * / %;
 * ^; a leading minus. Every node is an array with its "node" kind.
 */
final class Parser
{
    public const MAX_LENGTH = 2000;

    private const MAX_DEPTH = 64;

    /** @var list<array{type: string, value: mixed, at: int}> */
    private array $tokens = [];

    private int $position = 0;

    private int $depth = 0;

    /**
     * @return array<string, mixed>
     *
     * @throws FormulaError when it can't be read
     */
    public static function parse(string $source): array
    {
        if (mb_strlen($source) > self::MAX_LENGTH) {
            throw new FormulaError('A formula can be at most '.self::MAX_LENGTH.' characters long.');
        }

        $parser = new self;
        $parser->tokens = self::tokenize($source);

        if ($parser->peek()['type'] === 'end') {
            throw new FormulaError('The formula is empty.');
        }

        $tree = $parser->expression();
        $left = $parser->peek();

        if ($left['type'] !== 'end') {
            throw new FormulaError('Unexpected '.self::describe($left).' at character '.($left['at'] + 1).'.');
        }

        return $tree;
    }

    /**
     * @return list<array{type: string, value: mixed, at: int}>
     */
    private static function tokenize(string $source): array
    {
        $tokens = [];
        $length = strlen($source);
        $at = 0;

        while ($at < $length) {
            $char = $source[$at];

            if (ctype_space($char)) {
                $at++;

                continue;
            }

            if (ctype_digit($char) || ($char === '.' && $at + 1 < $length && ctype_digit($source[$at + 1]))) {
                $number = preg_match('/\d*\.?\d+(?:[eE][+-]?\d+)?|\d+\.?/A', $source, $match, 0, $at) ? $match[0] : $char;
                $tokens[] = ['type' => 'number', 'value' => (float) $number, 'at' => $at];
                $at += strlen($number);

                continue;
            }

            if ($char === '"' || $char === "'") {
                $end = strpos($source, $char, $at + 1);

                if ($end === false) {
                    throw new FormulaError('A text starting at character '.($at + 1).' is never closed with '.$char.'.');
                }

                $tokens[] = ['type' => 'string', 'value' => substr($source, $at + 1, $end - $at - 1), 'at' => $at];
                $at = $end + 1;

                continue;
            }

            // A name with spaces or symbols in it: [Cost (THB)]
            if ($char === '[') {
                $end = strpos($source, ']', $at + 1);

                if ($end === false) {
                    throw new FormulaError('A name starting at character '.($at + 1).' is never closed with ].');
                }

                $name = trim(substr($source, $at + 1, $end - $at - 1));

                if ($name === '') {
                    throw new FormulaError('There is an empty name, [], at character '.($at + 1).'.');
                }

                $tokens[] = ['type' => 'name', 'value' => $name, 'at' => $at];
                $at = $end + 1;

                continue;
            }

            if (preg_match('/[\p{L}_][\p{L}\p{N}_]*/uA', $source, $match, 0, $at)) {
                $word = $match[0];
                $lower = mb_strtolower($word);
                $tokens[] = match ($lower) {
                    'true', 'false' => ['type' => 'bool', 'value' => $lower === 'true', 'at' => $at],
                    'and', 'or', 'not' => ['type' => 'op', 'value' => $lower, 'at' => $at],
                    default => ['type' => 'name', 'value' => $word, 'at' => $at],
                };
                $at += strlen($word);

                continue;
            }

            foreach (['<=', '>=', '!=', '<>', '==', '+', '-', '*', '/', '%', '^', '&', '=', '<', '>', '(', ')', ',', '.'] as $symbol) {
                if (substr_compare($source, $symbol, $at, strlen($symbol)) === 0) {
                    $tokens[] = ['type' => 'op', 'value' => match ($symbol) {
                        '<>' => '!=',
                        '==' => '=',
                        default => $symbol,
                    }, 'at' => $at];
                    $at += strlen($symbol);

                    continue 2;
                }
            }

            throw new FormulaError('"'.mb_substr(substr($source, $at), 0, 1).'" at character '.($at + 1).' means nothing in a formula.');
        }

        $tokens[] = ['type' => 'end', 'value' => null, 'at' => $length];

        return $tokens;
    }

    /**
     * @return array<string, mixed>
     */
    private function expression(int $loosest = 0): array
    {
        if (++$this->depth > self::MAX_DEPTH) {
            throw new FormulaError('The formula is nested too deeply.');
        }

        $left = $this->prefix();

        while (true) {
            $token = $this->peek();
            $binding = $token['type'] === 'op' ? self::binding($token['value']) : null;

            if ($binding === null || $binding <= $loosest) {
                break;
            }

            $this->position++;
            // ^ groups from the right: 2 ^ 3 ^ 2 is 2 ^ 9
            $right = $this->expression($token['value'] === '^' ? $binding - 1 : $binding);
            $left = ['node' => 'binary', 'op' => $token['value'], 'left' => $left, 'right' => $right];
        }

        $this->depth--;

        return $left;
    }

    private static function binding(string $op): ?int
    {
        return match ($op) {
            'or' => 1,
            'and' => 2,
            '=', '!=', '<', '<=', '>', '>=' => 4,
            '&' => 5,
            '+', '-' => 6,
            '*', '/', '%' => 7,
            '^' => 9,
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function prefix(): array
    {
        $token = $this->next();

        $node = match (true) {
            in_array($token['type'], ['number', 'string', 'bool'], true) => ['node' => 'literal', 'value' => $token['value']],
            $token['type'] === 'name' => ['node' => 'name', 'name' => $token['value']],
            $token['value'] === '(' => $this->group(),
            $token['value'] === '-' => ['node' => 'negate', 'operand' => $this->expression(8)],
            $token['value'] === '+' => $this->expression(8),
            $token['value'] === 'not' => ['node' => 'not', 'operand' => $this->expression(3)],
            default => throw new FormulaError('Expected a value but found '.self::describe($token).' at character '.($token['at'] + 1).'.'),
        };

        return $this->postfix($node);
    }

    /**
     * @return array<string, mixed>
     */
    private function group(): array
    {
        $inner = $this->expression();
        $this->expect(')');

        return $inner;
    }

    /**
     * Calls and members after a value: f(x), trip.day(5).cost
     *
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private function postfix(array $node): array
    {
        while (true) {
            $token = $this->peek();

            if ($token['type'] === 'op' && $token['value'] === '(') {
                if ($node['node'] !== 'name' && $node['node'] !== 'member') {
                    throw new FormulaError('Only a function can be called, at character '.($token['at'] + 1).'.');
                }

                $this->position++;
                $node = ['node' => 'call', 'callee' => $node, 'args' => $this->arguments()];

                continue;
            }

            if ($token['type'] === 'op' && $token['value'] === '.') {
                $this->position++;
                $name = $this->next();

                if ($name['type'] !== 'name') {
                    throw new FormulaError('Expected a name after "." at character '.($name['at'] + 1).'.');
                }

                $node = ['node' => 'member', 'object' => $node, 'name' => $name['value']];

                continue;
            }

            return $node;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function arguments(): array
    {
        $args = [];

        if ($this->peek()['value'] === ')' && $this->peek()['type'] === 'op') {
            $this->position++;

            return $args;
        }

        while (true) {
            $args[] = $this->expression();
            $token = $this->next();

            if ($token['type'] === 'op' && $token['value'] === ')') {
                return $args;
            }

            if ($token['type'] !== 'op' || $token['value'] !== ',') {
                throw new FormulaError('Expected "," or ")" but found '.self::describe($token).' at character '.($token['at'] + 1).'.');
            }
        }
    }

    private function expect(string $op): void
    {
        $token = $this->next();

        if ($token['type'] !== 'op' || $token['value'] !== $op) {
            throw new FormulaError('Expected "'.$op.'" but found '.self::describe($token).' at character '.($token['at'] + 1).'.');
        }
    }

    /**
     * @return array{type: string, value: mixed, at: int}
     */
    private function peek(): array
    {
        return $this->tokens[$this->position];
    }

    /**
     * @return array{type: string, value: mixed, at: int}
     */
    private function next(): array
    {
        $token = $this->tokens[$this->position];

        if ($token['type'] !== 'end') {
            $this->position++;
        }

        return $token;
    }

    /**
     * @param  array{type: string, value: mixed, at: int}  $token
     */
    private static function describe(array $token): string
    {
        return match ($token['type']) {
            'end' => 'the end of the formula',
            'string' => 'the text "'.$token['value'].'"',
            'bool' => $token['value'] ? 'true' : 'false',
            'number' => (string) $token['value'],
            default => '"'.$token['value'].'"',
        };
    }
}
