<?php

namespace App\Http\Controllers\Map;

use App\Http\Controllers\FolderController;
use App\Models\Map\TripFolder;

/**
 * The folders trips are kept in.
 */
class TripFolderController extends FolderController
{
    protected function folderModel(): string
    {
        return TripFolder::class;
    }
}
