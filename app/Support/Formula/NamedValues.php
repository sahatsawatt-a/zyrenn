<?php

namespace App\Support\Formula;

/**
 * Names and their values, looked up without regard to case, and if not found
 * here, in the scope around it -- a row's columns, then the table's
 * parameters, then the other things a note or table can name.
 */
final class NamedValues implements Callables, Scope
{
    /** @var array<string, mixed> */
    private array $values = [];

    /**
     * @param  array<string, mixed>  $values
     */
    public function __construct(array $values = [], private ?Scope $outer = null)
    {
        foreach ($values as $name => $value) {
            $this->values[mb_strtolower((string) $name)] = $value;
        }
    }

    public function lookup(string $name): mixed
    {
        $key = mb_strtolower($name);

        if (array_key_exists($key, $this->values)) {
            $value = $this->values[$key];

            // Worked out only when asked for: a formula column another one names
            return $value instanceof \Closure ? $value() : $value;
        }

        if ($this->outer !== null) {
            return $this->outer->lookup($name);
        }

        throw new FormulaError("Nothing is called \"{$name}\".");
    }

    public function callable(string $name): ?\Closure
    {
        return $this->outer instanceof Callables ? $this->outer->callable($name) : null;
    }
}
