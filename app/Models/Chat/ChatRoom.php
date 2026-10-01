<?php

namespace App\Models\Chat;

use App\Models\Concerns\HasRefId;
use App\Models\User;
use Database\Factories\Chat\ChatRoomFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A conversation: messages in order, and optionally an agent that answers
 * them. Nothing here is about AI -- the agent is only a connection, a model
 * and a prompt -- so a room can later hold several people, or sit behind
 * something else, such as a ticket pointing at its room.
 *
 * @property int $id
 * @property string $ref_id
 * @property int $user_id
 * @property string $title
 * @property int|null $ai_connection_id
 * @property string|null $model
 * @property string|null $system_prompt
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['title', 'ai_connection_id', 'model', 'system_prompt'])]
class ChatRoom extends Model
{
    /** @use HasFactory<ChatRoomFactory> */
    use HasFactory, HasRefId;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'title' => '',
    ];

    /**
     * Get the user whose room it is.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the connection its agent is reached through, if it has one. Not
     * called "connection": Eloquent keeps the database's name under that.
     *
     * @return BelongsTo<AiConnection, $this>
     */
    public function aiConnection(): BelongsTo
    {
        return $this->belongsTo(AiConnection::class, 'ai_connection_id');
    }

    /**
     * Get what was said, oldest first.
     *
     * @return HasMany<ChatMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class)->orderBy('id');
    }

    /**
     * The model the agent answers with: the room's own, or its connection's.
     */
    public function agentModel(): ?string
    {
        return $this->model ?: $this->aiConnection?->default_model;
    }

    /**
     * Whether something answers in this room.
     */
    public function hasAgent(): bool
    {
        return $this->aiConnection !== null && $this->agentModel() !== null;
    }
}
