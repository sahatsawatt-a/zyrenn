<?php

namespace App\Models\Prototype;

use App\Models\Board\Board;
use App\Models\Concerns\HasRefId;
use App\Models\Ticket\Ticket;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What someone said about a prototype, pinned to a spot on one of its
 * screens: the screen's frame, and where on it from 0 to 1 across and down,
 * so it stays on the spot however big the screen is drawn. Resolved once
 * dealt with; made a ticket when it is work to do.
 *
 * @property int $id
 * @property string $ref_id
 * @property int $board_id
 * @property int|null $user_id
 * @property string $frame_id
 * @property float $x
 * @property float $y
 * @property string $body
 * @property CarbonInterface|null $resolved_at
 * @property int|null $ticket_id
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable(['frame_id', 'x', 'y', 'body'])]
class PrototypeComment extends Model
{
    use HasRefId;

    protected function casts(): array
    {
        return [
            'x' => 'float',
            'y' => 'float',
            'resolved_at' => 'datetime',
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

    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /**
     * As the screens show it.
     *
     * @return array<string, mixed>
     */
    public function shown(): array
    {
        $ticket = $this->getRelationValue('ticket');

        return [
            'ref_id' => $this->ref_id,
            'screen' => $this->frame_id,
            'x' => $this->x,
            'y' => $this->y,
            'body' => $this->body,
            'by' => $this->getRelationValue('user')?->only(['id', 'name']),
            'resolved' => $this->resolved_at !== null,
            'ticket' => $ticket instanceof Ticket ? ['number' => $ticket->number, 'status' => $ticket->status] : null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
