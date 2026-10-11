<?php

namespace App\Models\Chat;

use App\Models\Drive\DriveFile;
use App\Models\User;
use App\Support\Chat\ChatMarkdown;
use App\Support\Workflow\Inbound\ChatMessages;
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

    /** Written by a workflow: as itself, or carrying over someone outside's words. */
    public const WORKFLOW = 'workflow';

    /**
     * A message said is heard by the workflows that listen in its room.
     */
    protected static function booted(): void
    {
        static::created(fn (ChatMessage $message) => ChatMessages::arrived($message));
    }

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
     * A place as a message keeps it -- { lat, lng, title, address } -- from
     * whatever names one, or null when it names none: the two numbers have
     * to be somewhere on Earth.
     *
     * @return array{lat: float, lng: float, title: string|null, address: string|null}|null
     */
    public static function placeOf(mixed $given): ?array
    {
        if (! is_array($given) || ! is_numeric($given['lat'] ?? null) || ! is_numeric($given['lng'] ?? null)) {
            return null;
        }

        [$lat, $lng] = [(float) $given['lat'], (float) $given['lng']];

        if (abs($lat) > 90 || abs($lng) > 180) {
            return null;
        }

        $words = fn (string $part, int $most) => is_scalar($given[$part] ?? null) && trim((string) $given[$part]) !== ''
            ? mb_substr(trim((string) $given[$part]), 0, $most)
            : null;

        return ['lat' => $lat, 'lng' => $lng, 'title' => $words('title', 200) ?? $words('name', 200), 'address' => $words('address', 300)];
    }

    /**
     * A line of it for a list of rooms: who said it, to $reader, and the
     * start of what -- or, with no words, what kind of thing it was.
     */
    public function preview(User $reader): string
    {
        $who = match (true) {
            $this->role === self::USER && $this->user_id === $reader->id => 'You',
            $this->role === self::USER => $this->author->name ?? 'Someone',
            $this->role === self::WORKFLOW => (string) ($this->meta['name'] ?? 'Workflow'),
            default => null,
        };
        $what = trim((string) preg_replace('/\s+/', ' ', $this->content));

        if ($what === '') {
            $what = match (true) {
                isset($this->meta['place']) => trim('A place: '.($this->meta['place']['title'] ?? ''), ': '),
                (bool) ($this->meta['sticker'] ?? false) => 'A sticker',
                isset($this->meta['file']) => 'A file',
                default => '',
            };
        }

        return ($who === null ? '' : "{$who}: ").mb_strimwidth($what, 0, 90, '…');
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
            // A workflow's: shown under its name, or the outside sender's, naming who it acted as
            'sender' => $this->role !== self::WORKFLOW ? null : [
                'name' => $this->meta['name'] ?? 'Workflow',
                'outside' => (bool) ($this->meta['outside'] ?? false),
                // One kept with the message itself, from before a room kept its own face
                ...isset($this->meta['picture']) ? ['picture' => $this->meta['picture']] : [],
                'by' => $this->meta['by'] ?? null,
                'workflow' => $this->meta['workflow'] ?? null,
            ],
            'content' => $this->content,
            // A file a workflow put under its words, while it is still in the Drive
            'file' => isset($this->meta['file']) ? DriveFile::where('ref_id', $this->meta['file'])->first()?->card() : null,
            // A place shown on a small map: lat, lng, and what it is called and where
            'place' => self::placeOf($this->meta['place'] ?? null),
            // Shown as a sticker is: small, with nothing round it
            'sticker' => (bool) ($this->meta['sticker'] ?? false),
            // Pictures that stand for some of its words: an outside chat's own emoji
            'emojis' => $this->meta['emojis'] ?? [],
            'doc' => $this->role === self::ASSISTANT ? ChatMarkdown::toDoc($this->content) : null,
            'error' => $this->meta['error'] ?? null,
            'created_at' => $this->created_at,
        ];
    }
}
