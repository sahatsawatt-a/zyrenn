<?php

namespace App\Models\Chat;

use App\Models\User;
use App\Support\Chat\ChatMarkdown;
use Database\Factories\Chat\ChatMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Touches;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One thing said in a room. The role is the one models understand: "user"
 * for a person, with user_id saying which, and "assistant" for the agent.
 *
 * @property int $id
 * @property int $chat_room_id
 * @property string $role
 * @property int|null $user_id
 * @property string $content
 * @property array<string, mixed>|null $meta
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['role', 'user_id', 'content', 'meta'])]
// A room is as recent as what was last said in it
#[Touches(['room'])]
class ChatMessage extends Model
{
    /** @use HasFactory<ChatMessageFactory> */
    use HasFactory;

    public const USER = 'user';

    public const ASSISTANT = 'assistant';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['meta' => 'array'];
    }

    /**
     * Get the room it was said in.
     *
     * @return BelongsTo<ChatRoom, $this>
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(ChatRoom::class, 'chat_room_id');
    }

    /**
     * Get the person who said it, when a person did.
     *
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * What the page shows of it: an agent's words come as the note editor's
     * document too, so they are drawn the way a note is.
     *
     * @return array<string, mixed>
     */
    public function toChat(): array
    {
        return [
            'id' => $this->id,
            'role' => $this->role,
            // Who said it, when a person did -- someone since gone, too: the page shows the others' names
            'author' => $this->role !== self::USER ? null : [
                'id' => $this->user_id,
                'name' => $this->author->name ?? 'Deleted user',
            ],
            'content' => $this->content,
            'doc' => $this->role === self::ASSISTANT ? ChatMarkdown::toDoc($this->content) : null,
            'error' => $this->meta['error'] ?? null,
            'created_at' => $this->created_at,
        ];
    }
}
