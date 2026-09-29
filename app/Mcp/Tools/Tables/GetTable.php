<?php

namespace App\Mcp\Tools\Tables;

use App\Mcp\Tools\Concerns\GetsThing;
use App\Mcp\Tools\TableTool;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Get a table: its columns (name, label, type, and the choices of a select) and its rows, each with its "id" and a value per column name -- the first 500 rows; "row_count" says how many there are. Use a row\'s id with update-table to change or delete it.')]
class GetTable extends TableTool
{
    use GetsThing;
}
