<?php

namespace App\Http\Controllers\Note;

use App\Http\Controllers\FolderController;
use App\Models\Note\NoteFolder;

/**
 * The folders notes are kept in.
 */
class NoteFolderController extends FolderController
{
    protected function folderModel(): string
    {
        return NoteFolder::class;
    }
}
