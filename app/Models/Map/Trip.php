<?php

namespace App\Models\Map;

use App\Events\TripChanged;
use App\Models\Concerns\BelongsToOwner;
use App\Models\Concerns\HasRefId;
use App\Models\Concerns\RecordsEditor;
use App\Support\Live\Live;
use App\Support\Maps\TripDocument;
use Database\Factories\Map\TripFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A trip: when it starts, the flights in and out, the hotels booked, and its
 * days of stops and rests in order -- one document, filed in folders like a
 * board. TripDocument says what that document may hold.
 *
 * @property int $id
 * @property string $ref_id
 * @property int|null $folder_id
 * @property string $title
 * @property array<string, mixed>|null $content
 * @property string|null $plain_text
 * @property int $revision
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['title', 'content'])]
class Trip extends Model
{
    /** @use HasFactory<TripFactory> */
    use BelongsToOwner, HasFactory, HasRefId, RecordsEditor;

    /**
     * Mirror the column defaults so new instances match what the database stores.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'title' => '',
        'revision' => 0,
    ];

    /**
     * Keep the document in its known shape, and its places searchable.
     */
    protected static function booted(): void
    {
        static::saving(function (Trip $trip) {
            if ($trip->isDirty('content')) {
                $trip->content = TripDocument::normalize($trip->content);
                $trip->plain_text = TripDocument::writtenText($trip->content) ?: null;
            }

            if ($trip->exists && $trip->isDirty(['title', 'content', 'folder_id'])) {
                $trip->revision++;
            }
        });

        // Whoever else has it open hears of it: the page loads the new copy,
        // or, holding changes of its own, asks which to keep
        static::saved(function (Trip $trip) {
            if ($trip->wasChanged('revision')) {
                Live::tell(new TripChanged($trip, 'saved'));
            }
        });
        static::deleted(fn (Trip $trip) => Live::tell(new TripChanged($trip, 'deleted')));
    }

    /**
     * How many days the trip has, for the list.
     */
    public function dayCount(): int
    {
        return count($this->content['days'] ?? []);
    }

    /**
     * The first day's date, YYYY-MM-DD, when one is set.
     */
    public function startDate(): ?string
    {
        return ($this->content['startDate'] ?? '') ?: null;
    }

    /**
     * A short piece of the places around the first match of $query, for search results.
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
            'revision' => 'integer',
        ];
    }

    /**
     * Get the folder the trip is filed in, if any.
     *
     * @return BelongsTo<TripFolder, $this>
     */
    public function folder(): BelongsTo
    {
        return $this->belongsTo(TripFolder::class, 'folder_id');
    }
}
