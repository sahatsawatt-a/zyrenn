<?php

namespace App\Support\Workflow;

use App\Support\Formula\FormulaError;
use App\Support\Formula\Members;

/**
 * A record with nothing in it -- a step that gave out {} -- so a formula
 * asking it for a part is told there is none, rather than that it is a list.
 */
final class EmptyRecord implements Members
{
    public function member(string $name): mixed
    {
        throw new FormulaError("There is no \"{$name}\" here.");
    }

    public function call(string $name, array $args): mixed
    {
        throw new FormulaError("There is no \"{$name}\" here to call.");
    }
}
