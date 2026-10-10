<?php

namespace App\Mcp\Tools\Workflows;

use App\Mcp\Tools\Concerns\ListsFolders;
use App\Mcp\Tools\WorkflowTool;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('List every workflow folder as a path (e.g. "Shop/Bots"), with how many workflows sit directly in it. Workflow folders are their own tree. Pass a path as "folder" to list-workflows, create-workflow or update-workflow.')]
class ListWorkflowFolders extends WorkflowTool
{
    use ListsFolders;
}
