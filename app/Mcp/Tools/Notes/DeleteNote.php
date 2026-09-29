<?php

namespace App\Mcp\Tools\Notes;

use App\Mcp\Tools\Concerns\DeletesThing;
use App\Mcp\Tools\NoteTool;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[Description('Permanently delete a note.')]
class DeleteNote extends NoteTool
{
    use DeletesThing;
}
