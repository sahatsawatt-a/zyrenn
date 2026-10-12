<?php

namespace App\Support\Workflow;

use App\Support\Formula\Callables;
use App\Support\Formula\FormulaError;
use App\Support\Formula\Scope;
use Closure;

/**
 * What a step's formulas can name while it runs: trigger (what the run was
 * started with), input (what came from the step before it), and
 * node("key") for any step before it that has finished.
 */
final class RunScope implements Callables, Scope
{
    /**
     * @param  array<string, mixed>  $outputs  the output of each step before this one that succeeded, by key
     * @param  array<string, true>  $skipped  the keys of steps before this one that were skipped
     */
    public function __construct(
        private readonly mixed $trigger,
        private readonly mixed $input,
        private readonly array $outputs = [],
        private readonly array $skipped = [],
    ) {}

    /**
     * All a formula could name, at once, for a step that reads it somewhere
     * formulas don't reach: trigger, input, and what every step before this
     * one gave out, by key.
     *
     * @return array{trigger: mixed, input: mixed, steps: array<string, mixed>}
     */
    public function all(): array
    {
        return ['trigger' => $this->trigger, 'input' => $this->input, 'steps' => $this->outputs];
    }

    public function lookup(string $name): mixed
    {
        return match (mb_strtolower($name)) {
            'trigger' => self::record($this->trigger),
            'input' => self::record($this->input),
            default => throw new FormulaError("Nothing is called \"{$name}\". A step can read trigger, input, and node(\"key\")."),
        };
    }

    public function callable(string $name): ?Closure
    {
        if (mb_strtolower($name) !== 'node') {
            return null;
        }

        return function (array $args): mixed {
            $key = $args[0] ?? null;

            if (count($args) !== 1 || ! is_string($key)) {
                throw new FormulaError('node takes the key of a step: node("fetch").');
            }

            if (array_key_exists($key, $this->outputs)) {
                return self::record($this->outputs[$key]);
            }

            if (isset($this->skipped[$key])) {
                throw new FormulaError("node(\"{$key}\") was skipped in this run, so it gave nothing.");
            }

            throw new FormulaError("node(\"{$key}\") is not a step before this one.");
        };
    }

    /**
     * JSON's {} comes back from the database as an empty array, which the
     * formulas would take for an empty list; at the top, it is a record.
     */
    private static function record(mixed $value): mixed
    {
        return $value === [] ? new EmptyRecord : $value;
    }
}
