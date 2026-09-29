<?php

namespace App\Http\Controllers\Table;

use App\Http\Controllers\FolderController;
use App\Models\Table\TableFolder;

/**
 * The folders tables are kept in.
 */
class TableFolderController extends FolderController
{
    protected function folderModel(): string
    {
        return TableFolder::class;
    }
}
