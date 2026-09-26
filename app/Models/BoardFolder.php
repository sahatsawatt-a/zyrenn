<?php

namespace App\Models;

use App\Models\Concerns\HasRefId;
use App\Models\Concerns\IsFolderTree;
use Database\Factories\BoardFolderFactory;
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
class BoardFolder extends Model
{
    /** @use HasFactory<BoardFolderFactory> */
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
     * @return HasMany<Board, $this>
     */
    public function boards(): HasMany
    {
        return $this->hasMany(Board::class, 'folder_id');
    }

    /**
     * Delete the folder with every board and folder inside it.
     */
    public function deleteTree(): void
    {
        Board::query()->whereIn('folder_id', $this->subtreeIds())->delete();

        // Subfolders go with it through the parent_id cascade
        $this->delete();
    }
}
