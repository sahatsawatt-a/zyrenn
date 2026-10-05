<?php

namespace App\Support\Formula;

use App\Models\Map\Trip;
use App\Models\Owner;
use App\Models\Table\Table;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The things a formula can reach beyond its own table: trip("Shanghai") and
 * table("Budget"), by title (any case) or ref_id -- only among what belongs
 * to the same owner, the user or the project, so a formula never shows what
 * its reader couldn't open.
 */
final class References implements Callables, Scope
{
    /** @var array<string, Members> found so far, so a formula over many rows looks each up once */
    private array $found = [];

    public function __construct(private Owner $owner) {}

    public function lookup(string $name): mixed
    {
        throw new FormulaError("Nothing is called \"{$name}\".");
    }

    public function callable(string $name): ?Closure
    {
        return match ($name) {
            'trip' => fn (array $args) => $this->find('trip', array_values($args)),
            'table' => fn (array $args) => $this->find('table', array_values($args)),
            default => null,
        };
    }

    /**
     * @param  list<mixed>  $args
     */
    private function find(string $kind, array $args): Members
    {
        if (count($args) !== 1 || ! is_string($args[0]) || trim($args[0]) === '') {
            throw new FormulaError("{$kind} takes one name, its title or ref_id: {$kind}(\"Shanghai\").");
        }

        $named = trim($args[0]);
        $key = $kind.':'.mb_strtolower($named);

        return $this->found[$key] ??= match ($kind) {
            'trip' => new TripValues($this->named($this->owner->trips()->getQuery(), $named, 'trip')),
            default => new TableValues($this->named($this->owner->tables()->getQuery(), $named, 'table')),
        };
    }

    /**
     * @template T of Model
     *
     * @param  Builder<T>  $things
     * @return T
     */
    private function named(Builder $things, string $named, string $kind): Model
    {
        $found = (clone $things)->where('ref_id', $named)->first()
            ?? (clone $things)->whereRaw('lower(title) = ?', [mb_strtolower($named)])->orderBy('id')->first();

        return $found ?? throw new FormulaError("There is no {$kind} called \"{$named}\" here.");
    }
}
