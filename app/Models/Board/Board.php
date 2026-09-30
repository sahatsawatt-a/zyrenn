<?php

namespace App\Models\Board;

use App\Models\Concerns\BelongsToOwner;
use App\Models\Concerns\HasRefId;
use App\Models\Concerns\RecordsEditor;
use Database\Factories\Board\BoardFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An endless canvas of shapes, notes and connectors, filed in folders like a note.
 *
 * @property int $id
 * @property string $ref_id
 * @property int|null $folder_id
 * @property string $title
 * @property array<string, mixed>|null $content
 * @property string|null $plain_text
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['title', 'content'])]
class Board extends Model
{
    /** @use HasFactory<BoardFactory> */
    use BelongsToOwner, HasFactory, HasRefId, RecordsEditor;

    /**
     * Mirror the column defaults so new instances match what the database stores.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'title' => '',
    ];

    /**
     * Keep the searchable text in step with what is written on the board.
     */
    protected static function booted(): void
    {
        static::saving(function (Board $board) {
            if ($board->isDirty('content')) {
                $board->plain_text = $board->writtenText() ?: null;
            }
        });
    }

    /**
     * Every label on the board, as one searchable string.
     */
    public function writtenText(): string
    {
        $items = $this->content['items'] ?? [];

        if (! is_array($items)) {
            return '';
        }

        $labels = array_filter(
            array_map(
                fn ($item) => is_array($item) ? trim((string) ($item['text'] ?? '')) : '',
                $items,
            ),
            fn (string $label) => $label !== '',
        );

        return implode(' · ', $labels);
    }

    /**
     * How many things are on the board, for the list.
     */
    public function itemCount(): int
    {
        $items = $this->content['items'] ?? [];

        return is_array($items) ? count($items) : 0;
    }

    /**
     * A short piece of the labels around the first match of $query, for search results.
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
        ];
    }

    /**
     * Get the folder the board is filed in, if any.
     *
     * @return BelongsTo<BoardFolder, $this>
     */
    public function folder(): BelongsTo
    {
        return $this->belongsTo(BoardFolder::class, 'folder_id');
    }
}
