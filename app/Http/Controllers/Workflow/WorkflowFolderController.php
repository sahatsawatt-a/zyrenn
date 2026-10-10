<?php

namespace App\Http\Controllers\Workflow;

use App\Http\Controllers\FolderController;
use App\Models\Workflow\WorkflowFolder;

/**
 * The folders workflows are kept in.
 */
class WorkflowFolderController extends FolderController
{
    protected function folderModel(): string
    {
        return WorkflowFolder::class;
    }
}
