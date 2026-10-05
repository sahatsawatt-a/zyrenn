<?php

namespace App\Models\Map;

use App\Models\Folder;
use Database\Factories\Map\TripFolderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TripFolder extends Folder
{
    /** @use HasFactory<TripFolderFactory> */
    use HasFactory;

    /**
     * @return HasMany<Trip, $this>
     */
    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class, 'folder_id');
    }

    protected function itemModel(): string
    {
        return Trip::class;
    }
}
