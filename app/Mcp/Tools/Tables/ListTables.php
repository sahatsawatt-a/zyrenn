<?php

namespace App\Mcp\Tools\Tables;

use App\Mcp\Tools\Concerns\ListsThings;
use App\Mcp\Tools\TableTool;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('List tables, most recently edited first, with how many columns and rows each has. Optionally narrow to one folder, or search titles.')]
class ListTables extends TableTool
{
    use ListsThings;
}
