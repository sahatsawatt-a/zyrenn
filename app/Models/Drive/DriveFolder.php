<?php

namespace App\Models\Drive;

use App\Models\Folder;
use Database\Factories\Drive\DriveFolderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DriveFolder extends Folder
{
    /** @use HasFactory<DriveFolderFactory> */
    use HasFactory;

    /**
     * @return HasMany<DriveFile, $this>
     */
    public function files(): HasMany
    {
        return $this->hasMany(DriveFile::class, 'folder_id');
    }

    protected function itemModel(): string
    {
        return DriveFile::class;
    }
}
