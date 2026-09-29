<?php

namespace App\Mcp\Tools\Boards;

use App\Mcp\Tools\BoardTool;
use App\Mcp\Tools\Concerns\DeletesThing;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[Description('Permanently delete a board and everything on it.')]
class DeleteBoard extends BoardTool
{
    use DeletesThing;
}
