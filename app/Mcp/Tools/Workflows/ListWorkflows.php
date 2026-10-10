<?php

namespace App\Mcp\Tools\Workflows;

use App\Mcp\Tools\Concerns\ListsThings;
use App\Mcp\Tools\WorkflowTool;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('List workflows, most recently edited first, with how many steps each has, the version published (null if none) and whether it is switched on. Optionally narrow to one folder, or search titles.')]
class ListWorkflows extends WorkflowTool
{
    use ListsThings;
}
