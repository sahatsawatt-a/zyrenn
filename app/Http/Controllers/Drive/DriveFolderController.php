<?php

namespace App\Http\Controllers\Drive;

use App\Http\Controllers\FolderController;
use App\Models\Drive\DriveFolder;

/**
 * The folders files are kept in.
 */
class DriveFolderController extends FolderController
{
    protected function folderModel(): string
    {
        return DriveFolder::class;
    }

    /**
     * Drive folders are never addressed by path, so their names may hold "/".
     *
     * @return list<string>
     */
    protected function nameRules(): array
    {
        return ['required', 'string', 'max:255'];
    }
}
