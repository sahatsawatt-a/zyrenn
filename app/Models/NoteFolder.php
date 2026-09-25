<?php

namespace App\Models;

use App\Models\Concerns\HasRefId;
use App\Models\Concerns\IsFolderTree;
use Database\Factories\NoteFolderFactory;
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
class NoteFolder extends Model
{
    /** @use HasFactory<NoteFolderFactory> */
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
     * @return HasMany<Note, $this>
     */
    public function notes(): HasMany
    {
        return $this->hasMany(Note::class, 'folder_id');
    }

    /**
     * Delete the folder with every note and folder inside it.
     */
    public function deleteTree(): void
    {
        Note::query()->whereIn('folder_id', $this->subtreeIds())->delete();

        // Subfolders go with it through the parent_id cascade
        $this->delete();
    }
}
