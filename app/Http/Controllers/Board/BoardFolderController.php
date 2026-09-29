<?php

namespace App\Http\Controllers\Board;

use App\Http\Controllers\FolderController;
use App\Models\Board\BoardFolder;

/**
 * The folders boards are kept in.
 */
class BoardFolderController extends FolderController
{
    protected function folderModel(): string
    {
        return BoardFolder::class;
    }
}
