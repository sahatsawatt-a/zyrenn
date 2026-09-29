<?php

namespace App\Models\Table;

use App\Models\Concerns\HasRefId;
use App\Models\Concerns\IsFolderTree;
use App\Models\User;
use Database\Factories\Table\TableFolderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $ref_id
 * @property int $user_id
 * @property int|null $parent_id
 * @property string $name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'parent_id'])]
class TableFolder extends Model
{
    /** @use HasFactory<TableFolderFactory> */
    use HasFactory, HasRefId, IsFolderTree;

    /**
     * Get the user that owns the folder.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<Table, $this>
     */
    public function tables(): HasMany
    {
        return $this->hasMany(Table::class, 'folder_id');
    }

    /**
     * Delete the folder with every table and folder inside it.
     *
     * Tables go one by one rather than in a single query, so each takes its
     * rows with it.
     */
    public function deleteTree(): void
    {
        Table::query()->whereIn('folder_id', $this->subtreeIds())->get()->each->delete();

        // Subfolders go with it through the parent_id cascade
        $this->delete();
    }
}
