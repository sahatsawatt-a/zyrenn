<?php

namespace App\Support\Formula;

/**
 * What the names in a formula stand for: a row's columns, a table's
 * parameters, other tables and trips by their alias.
 */
interface Scope
{
    /**
     * The value under a name, as it was written (bracketed names keep their
     * spaces). Matching ignores case.
     *
     * @throws FormulaError when nothing is called that
     */
    public function lookup(string $name): mixed;
}
