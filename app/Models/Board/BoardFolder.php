<?php

namespace App\Models\Board;

use App\Models\Folder;
use Database\Factories\Board\BoardFolderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BoardFolder extends Folder
{
    /** @use HasFactory<BoardFolderFactory> */
    use HasFactory;

    /**
     * @return HasMany<Board, $this>
     */
    public function boards(): HasMany
    {
        return $this->hasMany(Board::class, 'folder_id');
    }

    protected function itemModel(): string
    {
        return Board::class;
    }
}
