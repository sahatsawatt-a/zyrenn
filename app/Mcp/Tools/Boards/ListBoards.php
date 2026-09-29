<?php

namespace App\Mcp\Tools\Boards;

use App\Mcp\Tools\BoardTool;
use App\Mcp\Tools\Concerns\ListsThings;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('List boards, most recently edited first. Optionally narrow to one folder, or search titles and the labels written on the boards.')]
class ListBoards extends BoardTool
{
    use ListsThings;
}
