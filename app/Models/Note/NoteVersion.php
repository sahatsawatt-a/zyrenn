<?php

namespace App\Models\Note;

use App\Models\Concerns\HasRefId;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $ref_id
 * @property int $note_id
 * @property string $title
 * @property array<string, mixed>|null $content
 * @property CarbonInterface|null $pinned_at immutable, as the app makes every date
 * @property string|null $label
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['title', 'content', 'pinned_at', 'label'])]
class NoteVersion extends Model
{
    use HasRefId;

    /**
     * Unpinned versions kept per note; older ones are pruned.
     */
    public const KEEP_UNPINNED = 50;

    /**
     * Minutes between automatic snapshots while a note is being edited.
     */
    public const INTERVAL_MINUTES = 10;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'content' => 'array',
            'pinned_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Note, $this>
     */
    public function note(): BelongsTo
    {
        return $this->belongsTo(Note::class);
    }
}
