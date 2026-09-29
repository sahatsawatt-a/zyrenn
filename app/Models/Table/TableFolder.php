<?php

namespace App\Models\Table;

use App\Models\Folder;
use Database\Factories\Table\TableFolderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TableFolder extends Folder
{
    /** @use HasFactory<TableFolderFactory> */
    use HasFactory;

    /**
     * @return HasMany<Table, $this>
     */
    public function tables(): HasMany
    {
        return $this->hasMany(Table::class, 'folder_id');
    }

    protected function itemModel(): string
    {
        return Table::class;
    }
}
