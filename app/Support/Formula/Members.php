<?php

namespace App\Support\Formula;

/**
 * A value with parts reached by a dot -- another table (budget.thb is its
 * column as a list), a trip (trip.day(5).cost). A plain record, an array
 * with names for keys, needs nothing of this.
 */
interface Members
{
    /**
     * @throws FormulaError when it has no such part
     */
    public function member(string $name): mixed;

    /**
     * @param  list<mixed>  $args
     *
     * @throws FormulaError when it has no such part, or the arguments don't fit
     */
    public function call(string $name, array $args): mixed;
}
