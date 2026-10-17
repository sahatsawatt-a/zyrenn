<?php

namespace App\Models\Prototype;

use App\Models\Board\Board;
use App\Models\Concerns\HasRefId;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One time someone played a prototype: the screens they went through, in
 * order, and each tap that found nothing to follow -- a sign of what they
 * expected to work. `at` is seconds from the start.
 *
 * @property int $id
 * @property string $ref_id
 * @property int $board_id
 * @property int|null $user_id
 * @property CarbonInterface $started_at
 * @property CarbonInterface|null $ended_at
 * @property int|null $width
 * @property int|null $height
 * @property list<array{screen: string, at: float}> $path
 * @property list<array{screen: string, x: float, y: float, at: float}> $misses
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class PrototypePlay extends Model
{
    use HasRefId;

    /** As much of a play as is kept: a long one is cut off, not refused. */
    public const MOST_STEPS = 500;

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'path' => 'array',
            'misses' => 'array',
        ];
    }

    /** @return BelongsTo<Board, $this> */
    public function board(): BelongsTo
    {
        return $this->belongsTo(Board::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** How long it went on, in seconds: to its end, or its last step. */
    public function seconds(): float
    {
        if ($this->ended_at !== null) {
            return max(0.0, round($this->started_at->diffInSeconds($this->ended_at), 1));
        }

        $last = collect([...$this->path, ...$this->misses])->max('at');

        return round((float) ($last ?? 0), 1);
    }

    /**
     * As the Plays panel shows it.
     *
     * @return array<string, mixed>
     */
    public function shown(): array
    {
        return [
            'ref_id' => $this->ref_id,
            'by' => $this->getRelationValue('user')?->only(['id', 'name']),
            'started_at' => $this->started_at->toIso8601String(),
            'seconds' => $this->seconds(),
            'path' => $this->path,
            'misses' => $this->misses,
        ];
    }
}
