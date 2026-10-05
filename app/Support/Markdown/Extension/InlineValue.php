<?php

namespace App\Support\Markdown\Extension;

use League\CommonMark\Node\Inline\AbstractInline;

/**
 * A live value in a note, {{ trip("Shanghai").total_cost }}: only its formula
 * is kept; what it comes to is worked out each time the note is read.
 */
final class InlineValue extends AbstractInline
{
    public function __construct(public readonly string $expression)
    {
        parent::__construct();
    }
}
