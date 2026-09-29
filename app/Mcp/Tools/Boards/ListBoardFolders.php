<?php

namespace App\Mcp\Tools\Boards;

use App\Mcp\Tools\BoardTool;
use App\Mcp\Tools\Concerns\ListsFolders;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('List every board folder as a path (e.g. "Plans/Q3"), with how many boards sit directly in it. Board folders are their own tree, separate from note and Drive folders. Pass a path as "folder" to list-boards, create-board or update-board.')]
class ListBoardFolders extends BoardTool
{
    use ListsFolders;
}
