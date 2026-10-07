<?php

namespace App\Support\Note;

use RuntimeException;

/**
 * A change to a note's blocks that can't be made, said so the one asking can
 * put it right: a block that isn't there, a change with no such name.
 */
class NoteBlockProblem extends RuntimeException
{
    public static function missing(string $id): self
    {
        return new self("There is no block \"{$id}\" in this note. get-note lists its blocks and their ids.");
    }
}
