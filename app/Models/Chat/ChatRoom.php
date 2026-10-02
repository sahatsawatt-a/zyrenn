<?php

namespace App\Models\Chat;

use App\Models\Concerns\HasRefId;
use App\Models\Project;
use App\Models\User;
use Database\Factories\Chat\ChatRoomFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A conversation: messages in order, and who may read and add to them.
 *
 * - personal: someone's own (user_id), optionally answered by an agent of theirs
 * - direct: between two people, the room's members
 * - group: one of a project's, named, with the people in it chosen from the
 *   project; anyone else in the project may see it and join
 *
 * Nothing in the messages depends on which, so the same room can sit behind
 * something else later, such as a ticket pointing at its room.
 *
 * @property int $id
 * @property string $ref_id
 * @property string $kind
 * @property int|null $user_id
 * @property int|null $project_id
 * @property int|null $created_by
 * @property string|null $direct_key
 * @property string $title
 * @property int|null $ai_connection_id
 * @property string|null $model
 * @property string|null $system_prompt
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read int $unread from withUnreadFor()
 * @property-read int $messages_count
 * @property-read int $members_count
 * @property-read bool $joined from withExists(), where a list asks whether the user is in it
 */
#[Fillable(['title', 'ai_connection_id', 'model', 'system_prompt'])]
class ChatRoom extends Model
{
    /** @use HasFactory<ChatRoomFactory> */
    use HasFactory, HasRefId;

    public const PERSONAL = 'personal';

    public const DIRECT = 'direct';

    public const GROUP = 'group';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'kind' => self::PERSONAL,
        'title' => '',
    ];

    /**
     * The room two people talk in, made the first time either asks for it.
     */
    public static function between(User $one, User $other): self
    {
        $key = collect([$one->id, $other->id])->sort()->implode(':');
        $existing = fn () => self::query()->where('direct_key', $key)->first();

        if ($room = $existing()) {
            return $room;
        }

        try {
            return DB::transaction(function () use ($key, $one, $other) {
                $room = new self;
                $room->kind = self::DIRECT;
                $room->direct_key = $key;
                $room->save();
                $room->members()->attach(array_unique([$one->id, $other->id]));

                return $room;
            });
        } catch (UniqueConstraintViolationException) {
            // Both opened it at once; the other one made it
            return $existing() ?? throw new \RuntimeException('The room between them could not be made.');
        }
    }

    /**
     * Start a group in a project, with whoever started it and the people they chose.
     *
     * @param  iterable<int>  $people  ids of others in the project
     */
    public static function startGroup(Project $project, User $by, string $title, iterable $people = []): self
    {
        return DB::transaction(function () use ($project, $by, $title, $people) {
            $room = new self;
            $room->kind = self::GROUP;
            $room->project_id = $project->id;
            $room->created_by = $by->id;
            $room->title = $title;
            $room->save();
            $room->members()->attach(collect($people)->push($by->id)->unique()->values()->all());

            return $room;
        });
    }

    /**
     * The rooms the user is in, whichever kind.
     *
     * @param  Builder<self>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->where(fn (Builder $in) => $in
            ->where('user_id', $user->id)
            ->orWhere(fn (Builder $shared) => $shared
                ->whereHas('members', fn (Builder $member) => $member->whereKey($user->id))
                // Someone who has left a project has left its groups too
                ->where(fn (Builder $where) => $where
                    ->whereNull('project_id')
                    ->orWhereIn('project_id', $user->projects()->select('projects.id')))));
    }

    /**
     * Rooms talked in with other people: the direct ones and the groups.
     *
     * @param  Builder<self>  $query
     */
    public function scopeShared(Builder $query): void
    {
        $query->where('kind', '!=', self::PERSONAL);
    }

    /**
     * How many messages from others the user has yet to read in each room, as "unread".
     *
     * @param  Builder<self>  $query
     */
    public function scopeWithUnreadFor(Builder $query, User $user): void
    {
        $query->withCount(['messages as unread' => fn (Builder $messages) => self::unreadBy($messages, $user)]);
    }

    /**
     * How many messages from others the user has yet to read, in every room they share.
     */
    public static function unreadTotal(User $user): int
    {
        return self::unreadBy(
            ChatMessage::query()->whereIn('chat_room_id', self::query()->visibleTo($user)->shared()->select('chat_rooms.id')),
            $user,
        )->count();
    }

    /**
     * @param  Builder<ChatMessage>  $messages
     * @return Builder<ChatMessage>
     */
    private static function unreadBy(Builder $messages, User $user): Builder
    {
        return $messages
            ->where(fn (Builder $from) => $from->whereNull('chat_messages.user_id')->orWhere('chat_messages.user_id', '!=', $user->id))
            ->whereRaw(
                'chat_messages.id > coalesce((select last_read_message_id from chat_reads where chat_reads.chat_room_id = chat_messages.chat_room_id and chat_reads.user_id = ?), 0)',
                [$user->id],
            );
    }

    /**
     * Get the user whose own room it is, for a personal one.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the project a group belongs to.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Get the people of a direct room or a group.
     *
     * @return BelongsToMany<User, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'chat_room_members')->withTimestamps();
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
     * Whether the user is in the room.
     */
    public function includes(User $user): bool
    {
        return match ($this->kind) {
            self::PERSONAL => $this->user_id === $user->id,
            self::DIRECT => $this->members()->whereKey($user->id)->exists(),
            self::GROUP => $this->members()->whereKey($user->id)->exists() && $this->inProject($user),
            default => false,
        };
    }

    /**
     * Whether the user is in the group's project, and so may see the group and join it.
     */
    public function inProject(User $user): bool
    {
        return $this->kind === self::GROUP && $this->project?->roleOf($user) !== null;
    }

    /**
     * Get who started a group.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Everyone in the room.
     *
     * @return Collection<int, User>
     */
    public function people(): Collection
    {
        return match ($this->kind) {
            self::PERSONAL => collect([$this->user])->filter()->values(),
            self::DIRECT, self::GROUP => $this->members()->orderBy('name')->get()->toBase(),
            default => collect(),
        };
    }

    /**
     * What the room is called for the user: its own title, the other person,
     * or the project.
     */
    public function titleFor(User $user): string
    {
        return match ($this->kind) {
            self::DIRECT => $this->members->firstWhere('id', '!=', $user->id)->name ?? 'Only you',

            default => $this->title,
        };
    }

    /**
     * Note that the user has read the room up to the message, never back.
     */
    public function markRead(User $user, int $messageId): void
    {
        DB::table('chat_reads')->upsert(
            [['chat_room_id' => $this->id, 'user_id' => $user->id, 'last_read_message_id' => $messageId, 'created_at' => now(), 'updated_at' => now()]],
            ['chat_room_id', 'user_id'],
            [
                'last_read_message_id' => DB::raw('case when chat_reads.last_read_message_id > excluded.last_read_message_id then chat_reads.last_read_message_id else excluded.last_read_message_id end'),
                'updated_at' => now(),
            ],
        );
    }

    /**
     * The model the agent answers with: the room's own, or its connection's.
     */
    public function agentModel(): ?string
    {
        return $this->model ?: $this->aiConnection?->default_model;
    }

    /**
     * Whether something answers in this room. Only one's own room has an agent:
     * it answers with that person's key.
     */
    public function hasAgent(): bool
    {
        return $this->kind === self::PERSONAL && $this->aiConnection !== null && $this->agentModel() !== null;
    }
}
