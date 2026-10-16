<?php

namespace App\Models\Product;

use App\Models\Chat\ChatMessage;
use App\Models\Concerns\HasRefId;
use App\Models\Concerns\TellsLists;
use App\Models\Project;
use App\Models\Ticket\Ticket;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * What someone asked of the product, kept word for word: the original is
 * never changed, whatever it turns out to mean. It is worked out into
 * requirements -- several, or none -- and says how far that has got.
 *
 * @property int $id
 * @property string $ref_id
 * @property int $project_id
 * @property string $code
 * @property string $original
 * @property string $source
 * @property int|null $chat_message_id
 * @property int|null $ticket_id
 * @property string|null $from_name
 * @property string $status
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ProductRequest extends Model
{
    use HasRefId, TellsLists;

    public const STATUSES = ['new', 'analysing', 'clarifying', 'split', 'rejected', 'done'];

    protected $guarded = [];

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return HasMany<Requirement, $this>
     */
    public function requirements(): HasMany
    {
        return $this->hasMany(Requirement::class)->orderBy('id');
    }

    /**
     * @return BelongsTo<ChatMessage, $this>
     */
    public function chatMessage(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class);
    }

    /**
     * @return BelongsTo<Ticket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }
}
