<?php

namespace App\Models\Board;

use App\Events\Deleted;
use App\Models\Concerns\BelongsToOwner;
use App\Models\Concerns\HasRefId;
use App\Models\Concerns\RecordsEditor;
use App\Models\Concerns\TellsLists;
use App\Models\Prototype\PrototypeComment;
use App\Models\Prototype\PrototypePlay;
use App\Support\Live\Collab;
use App\Support\Live\Live;
use Database\Factories\Board\BoardFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An endless canvas of shapes, notes and connectors, filed in folders like a note.
 *
 * @property int $id
 * @property string $ref_id
 * @property int|null $folder_id
 * @property string $title
 * @property string $kind a board, or a prototype: screens at a device's size
 * @property array{device: string, start: string|null, data?: string}|null $prototype a prototype's own settings: its device, the screen Play opens on, and where Play's saves go
 * @property array<string, mixed>|null $content
 * @property string|null $plain_text
 * @property string|null $ydoc the collaboration server's shared state, base64
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['title', 'content'])]
#[Hidden(['ydoc'])]
class Board extends Model
{
    public const BOARD = 'board';

    /** Clickable screens to show someone before anything is built: frames at a device's size. */
    public const PROTOTYPE = 'prototype';

    /** What a prototype may be made for. */
    public const DEVICES = ['phone', 'tablet', 'desktop'];

    /**
     * What Play's saves and deletes change: each play's own copy of the
     * tables (the default), or the project's tables themselves.
     */
    public const PROTOTYPE_DATA = ['play', 'tables'];

    /** What its lists show of it: other saves, made while it is edited, aren't told. */
    public const LISTED_BY = ['title', 'folder_id'];

    /** What the people editing a board share live, besides where it is filed. */
    private const SHARED = ['content', 'title'];

    /** @use HasFactory<BoardFactory> */
    use BelongsToOwner, HasFactory, HasRefId, RecordsEditor, TellsLists;

    /**
     * Mirror the column defaults so new instances match what the database stores.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'title' => '',
        'kind' => self::BOARD,
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

            // Changed from elsewhere -- MCP, a rename from the list -- rather than
            // by the collaboration server: its shared state no longer says the same
            if ($board->exists && $board->isDirty(self::SHARED) && ! $board->isDirty('ydoc')) {
                $board->ydoc = null;
            }
        });

        // ...so whoever has it open is sent the change
        static::saved(function (Board $board) {
            if ($board->ydoc === null && $board->wasChanged(self::SHARED)) {
                Collab::replace($board, Collab::changes($board));
            }
        });

        static::deleted(fn (Board $board) => Live::tell(new Deleted($board)));
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
                // (with the names of a table's columns or a class's members)
                fn ($item) => is_array($item) ? trim(implode(' ', [
                    trim((string) ($item['text'] ?? '')),
                    ...array_column(is_array($item['rows'] ?? null) ? $item['rows'] : [], 'name'),
                ])) : '',
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
            'prototype' => 'array',
        ];
    }

    public function isPrototype(): bool
    {
        return $this->kind === self::PROTOTYPE;
    }

    /**
     * A prototype's comments, pinned to its screens.
     *
     * @return HasMany<PrototypeComment, $this>
     */
    public function prototypeComments(): HasMany
    {
        return $this->hasMany(PrototypeComment::class);
    }

    /**
     * Each time a prototype was played.
     *
     * @return HasMany<PrototypePlay, $this>
     */
    public function prototypePlays(): HasMany
    {
        return $this->hasMany(PrototypePlay::class);
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
