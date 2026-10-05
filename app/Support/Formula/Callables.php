<?php

namespace App\Support\Formula;

use Closure;

/**
 * A scope that brings functions of its own -- trip("Shanghai"), table("Budget")
 * -- which need to know whose things they may look in.
 */
interface Callables
{
    /**
     * The function, given its worked-out arguments, or null for one this
     * scope doesn't have.
     *
     * @return (Closure(list<mixed>): mixed)|null
     */
    public function callable(string $name): ?Closure;
}
