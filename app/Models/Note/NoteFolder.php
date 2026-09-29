<?php

namespace App\Models\Note;

use App\Models\Folder;
use Database\Factories\Note\NoteFolderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NoteFolder extends Folder
{
    /** @use HasFactory<NoteFolderFactory> */
    use HasFactory;

    /**
     * @return HasMany<Note, $this>
     */
    public function notes(): HasMany
    {
        return $this->hasMany(Note::class, 'folder_id');
    }

    protected function itemModel(): string
    {
        return Note::class;
    }
}
