<?php

namespace App\Mcp\Tools\Tables;

use App\Mcp\Tools\Concerns\ListsFolders;
use App\Mcp\Tools\TableTool;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('List every table folder as a path (e.g. "Finance/2026"), with how many tables sit directly in it. Table folders are their own tree, separate from note, board and Drive folders. Pass a path as "folder" to list-tables, create-table or update-table.')]
class ListTableFolders extends TableTool
{
    use ListsFolders;
}
