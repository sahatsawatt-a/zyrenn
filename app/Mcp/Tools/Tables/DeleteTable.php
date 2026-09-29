<?php

namespace App\Mcp\Tools\Tables;

use App\Mcp\Tools\Concerns\DeletesThing;
use App\Mcp\Tools\TableTool;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[Description('Permanently delete a table and every row in it.')]
class DeleteTable extends TableTool
{
    use DeletesThing;
}
