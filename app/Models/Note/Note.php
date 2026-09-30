<?php

namespace App\Models\Note;

use App\Events\Deleted;
use App\Models\Concerns\BelongsToOwner;
use App\Models\Concerns\HasRefId;
use App\Models\Concerns\RecordsEditor;
use App\Support\Live\Collab;
use App\Support\Live\Live;
use App\Support\TiptapMarkdown;
use Database\Factories\Note\NoteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $ref_id
 * @property int|null $folder_id
 * @property string $title
 * @property array<string, mixed>|null $content
 * @property string|null $plain_text
 * @property string|null $ydoc the collaboration server's shared state, base64
 * @property bool $is_wide
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['title', 'content', 'is_wide'])]
#[Hidden(['ydoc'])]
class Note extends Model
{
    /** What the people editing a note share live, besides where it is filed. */
    private const SHARED = ['content', 'title', 'is_wide'];

    /** @use HasFactory<NoteFactory> */
    use BelongsToOwner, HasFactory, HasRefId, RecordsEditor;

    /**
     * Mirror the column defaults so new instances match what the database stores.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'title' => '',
        'is_wide' => false,
    ];

    /**
     * Keep the searchable text copy of the body in step with the content.
     */
    protected static function booted(): void
    {
        static::saving(function (Note $note) {
            if ($note->isDirty('content')) {
                $note->plain_text = $note->content ? TiptapMarkdown::toMarkdown($note->content) : null;
            }

            // Changed from elsewhere -- MCP, a rename from the list -- rather than
            // by the collaboration server: its shared state no longer says the same
            if ($note->exists && $note->isDirty(self::SHARED) && ! $note->isDirty('ydoc')) {
                $note->ydoc = null;
            }
        });

        // ...so whoever has it open is sent the change
        static::saved(function (Note $note) {
            if ($note->ydoc === null && $note->wasChanged(self::SHARED)) {
                Collab::replace($note, Collab::changes($note));
            }
        });

        static::deleted(fn (Note $note) => Live::tell(new Deleted($note)));
    }

    /**
     * A short piece of the body around the first match of $query, for search results.
     */
    public function snippet(string $query, int $radius = 70): ?string
    {
        $text = preg_replace('/\s+/u', ' ', (string) $this->plain_text);
        $at = mb_stripos($text, $query);

        if ($at === false) {
            return null;
        }

        $start = max(0, $at - $radius);
        $piece = mb_substr($text, $start, mb_strlen($query) + $radius * 2);

        return ($start > 0 ? '…' : '').trim($piece).($start + mb_strlen($piece) < mb_strlen($text) ? '…' : '');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'content' => 'array',
            'is_wide' => 'boolean',
        ];
    }

    /**
     * Get the folder the note is filed in, if any.
     *
     * @return BelongsTo<NoteFolder, $this>
     */
    public function folder(): BelongsTo
    {
        return $this->belongsTo(NoteFolder::class, 'folder_id');
    }
}
