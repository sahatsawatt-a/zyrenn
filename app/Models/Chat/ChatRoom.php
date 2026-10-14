<?php

namespace App\Models\Chat;

use App\Events\ChatMessagePosted;
use App\Events\ChatRoomChanged;
use App\Models\Concerns\HasRefId;
use App\Models\Connection;
use App\Models\Owner;
use App\Models\Project;
use App\Models\Ticket\Ticket;
use App\Models\User;
use App\Support\Connections\Services;
use App\Support\Live\Live;
use App\Support\Workflow\Inbound\ChatMessages;
use Database\Factories\Chat\ChatRoomFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
 * - customer: a project's chat with someone outside, linked to their chat on
 *   a messaging service; everyone in the project may open it, and it says
 *   where it stands, who has taken it and what it is tagged
 *
 * Nothing in the messages depends on which, so the same room can sit behind
 * something else later, such as a ticket pointing at its room.
 *
 * @property int $id
 * @property string $ref_id
 * @property string $kind
 * @property string|null $status
 * @property int|null $assigned_to
 * @property list<string>|null $tags
 * @property int|null $contact_id
 * @property bool $ai_answers
 * @property int|null $user_id
 * @property int|null $project_id
 * @property int|null $created_by
 * @property string|null $direct_key
 * @property string $title
 * @property int|null $ai_connection_id
 * @property string|null $model
 * @property string|null $system_prompt
 * @property string|null $external_key
 * @property array{service: string, connection: string, chat: string, platform?: string}|null $link
 * @property string|null $picture
 * @property array<string, string>|null $details
 * @property list<string>|null $details_kept
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

    public const CUSTOMER = 'customer';

    /** Where a customer's chat stands: to be answered, answered and waiting on them, or done with. */
    public const OPEN = 'open';

    public const WAITING = 'waiting';

    public const CLOSED = 'closed';

    public const STATUSES = [self::OPEN, self::WAITING, self::CLOSED];

    /** The services a room can be linked to a chat on. */
    public const LINKS = ['telegram', 'line', 'whatsapp', 'instagram', 'channel'];

    /** The services that take an answer only for so many hours after the customer last wrote. */
    public const ANSWER_HOURS = ['whatsapp' => 24, 'instagram' => 24];

    /** How many tags a chat takes, and how long one may be. */
    public const MAX_TAGS = 12;

    public const MAX_TAG = 30;

    /** The Drive folder files sent in chats are kept in. */
    public const DRIVE_FOLDER = 'Chats';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'kind' => self::PERSONAL,
        'title' => '',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['link' => 'array', 'details' => 'array', 'details_kept' => 'array', 'tags' => 'array', 'ai_answers' => 'boolean'];
    }

    /**
     * What the room is linked to outside, as its page says it -- "Telegram ·
     * Vertex_SKBot" -- or null for a room that isn't. Never the chat's id.
     *
     * @return array{service: string, through: string|null}|null
     */
    public function linkShown(): ?array
    {
        if ($this->link === null) {
            return null;
        }

        $owner = $this->project_id !== null ? $this->project : $this->user;

        return [
            // A custom channel is shown as the platform it stands for
            'service' => $this->link['platform'] ?? Services::get($this->link['service'])['label'] ?? $this->link['service'],
            'through' => $owner?->connections()->where('ref_id', $this->link['connection'])->value('name'),
        ];
    }

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
        $query->whereIn('kind', [self::DIRECT, self::GROUP]);
    }

    /**
     * A project's chats with customers.
     *
     * @param  Builder<self>  $query
     */
    public function scopeCustomers(Builder $query, Project $project): void
    {
        $query->where('kind', self::CUSTOMER)->where('project_id', $project->id);
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
     * @return BelongsTo<Connection, $this>
     */
    public function aiConnection(): BelongsTo
    {
        return $this->belongsTo(Connection::class, 'ai_connection_id');
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

    /** How many details a room keeps, and how long a name and a value may be. */
    public const MAX_DETAILS = 40;

    public const DETAIL_NAME = '/^[A-Za-z_][A-Za-z0-9_]{0,39}$/';

    public const MAX_DETAIL = 500;

    /**
     * A picture's address as a room may show it: a web address over https,
     * or a file's own address in the Drive, and nothing else -- it is drawn
     * by everyone who opens the room.
     */
    public static function pictureAt(?string $url): ?string
    {
        $url = trim((string) $url);

        return match (true) {
            preg_match('#^/drive/files/[A-Za-z0-9]{6,16}$#', $url) === 1 => $url,
            mb_strlen($url) <= 1000 && str_starts_with($url, 'https://') && filter_var($url, FILTER_VALIDATE_URL) !== false => $url,
            default => null,
        };
    }

    /**
     * Keep things about the room, by name, over what is kept already: a
     * name given again is set to what it is now, one given empty is left as
     * it was -- so a detail that could not be found this time is not lost.
     * What is kept this way is a workflow's to set: it is marked, and is
     * not changed by hand (noteByHand).
     *
     * @param  array<string, mixed>  $given
     * @return bool whether anything changed
     */
    public function note(array $given, ?string $picture = null): bool
    {
        $details = $this->details ?? [];
        $kept = $this->details_kept ?? [];

        foreach ($given as $name => $value) {
            $value = is_scalar($value) ? trim((string) $value) : '';

            if ($value !== '' && preg_match(self::DETAIL_NAME, (string) $name) === 1 && (isset($details[$name]) || count($details) < self::MAX_DETAILS)) {
                $details[$name] = mb_substr($value, 0, self::MAX_DETAIL);
                $kept[] = (string) $name;
            }
        }

        $kept = array_values(array_unique($kept));
        $picture = self::pictureAt($picture) ?? $this->picture;

        if ($details === ($this->details ?? []) && $kept === ($this->details_kept ?? []) && $picture === $this->picture) {
            return false;
        }

        $this->forceFill(['details' => $details ?: null, 'details_kept' => $kept ?: null, 'picture' => $picture])->save();

        return true;
    }

    /**
     * The details as someone has set them by hand: the whole list of what
     * is theirs to set. What a workflow keeps stays as the workflow has it,
     * whatever the list says of it.
     *
     * @param  array<string, mixed>  $noted
     */
    public function noteByHand(array $noted): void
    {
        $kept = array_intersect_key($this->details ?? [], array_flip($this->details_kept ?? []));
        $own = [];

        foreach ($noted as $name => $value) {
            $value = is_scalar($value) ? trim((string) $value) : '';

            if ($value !== '' && ! isset($kept[$name])) {
                $own[(string) $name] = mb_substr($value, 0, self::MAX_DETAIL);
            }
        }

        $this->forceFill(['details' => [...$kept, ...$own] ?: null])->save();
    }

    /**
     * Get what was said last.
     *
     * @return HasOne<ChatMessage, $this>
     */
    public function latestMessage(): HasOne
    {
        return $this->hasOne(ChatMessage::class)->latestOfMany();
    }

    /**
     * Whose Drive a file sent here is kept in, so everyone in the room can
     * open it: one's own for one's own chat, the project's for a group.
     * Two people's own chat has no Drive both can open.
     */
    public function driveOwner(): ?Owner
    {
        return match ($this->kind) {
            self::PERSONAL => $this->user,
            self::GROUP, self::CUSTOMER => $this->project,
            default => null,
        };
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
            // A customer's chat is the whole project's: nobody joins it
            self::CUSTOMER => $this->project?->roleOf($user) !== null,
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
     * Whether the user answers for the project a customer's chat is in:
     * its owners and editors, who may write in it, take it and tag it.
     */
    public function answeredBy(User $user): bool
    {
        return $this->kind === self::CUSTOMER
            && in_array($this->project?->roleOf($user), [Project::OWNER, Project::EDITOR], true);
    }

    /**
     * Get the customer a chat is with, whichever service it is on.
     *
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * Get the tickets made from what was asked in a customer's chat.
     *
     * @return HasMany<Ticket, $this>
     */
    public function askedTickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'asked_in_room_id')->orderBy('number');
    }

    /**
     * Get who has taken a customer's chat.
     *
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * Make a project's room its chat with a customer: open, and everyone's
     * in the project. A room that is one already is left as it stands.
     */
    public function becomeCustomers(): void
    {
        if ($this->kind === self::GROUP && $this->project_id !== null) {
            $this->forceFill(['kind' => self::CUSTOMER, 'status' => $this->status ?? self::OPEN])->save();
        }

        // Someone of their own, until they are found to be someone already known
        if ($this->kind === self::CUSTOMER && $this->contact_id === null) {
            Contact::startFor($this);
        }
    }

    /**
     * Where a customer's chat stands after something is said in it: what
     * comes from outside opens it, and an answer from here leaves it
     * waiting on them. A note among the project changes nothing, and a
     * closed chat stays closed until they write again.
     */
    public function movedBy(ChatMessage $message): void
    {
        if ($this->kind !== self::CUSTOMER || $message->isInternal()) {
            return;
        }

        $next = match (true) {
            $message->isFromOutside() => self::OPEN,
            $this->status === self::OPEN => self::WAITING,
            default => $this->status,
        };

        if ($next !== $this->status) {
            $this->forceFill(['status' => $next])->save();
        }
    }

    /**
     * Tags as a chat keeps them: short, lower-case words, each once.
     *
     * @return list<string>
     */
    public static function tagsOf(mixed $given): array
    {
        $given = is_string($given) ? explode(',', $given) : (is_array($given) ? $given : []);

        return array_values(collect($given)
            ->filter(fn ($tag) => is_scalar($tag))
            ->map(fn ($tag) => mb_substr(mb_strtolower(trim((string) preg_replace('/\s+/', ' ', (string) $tag))), 0, self::MAX_TAG))
            ->filter()
            ->unique()
            ->take(self::MAX_TAGS)
            ->all());
    }

    /**
     * Say something in the room as a person, and tell whoever else is in
     * it at once -- and, for a customer's chat, everyone in its project
     * where it now stands. Workflows listening hear it as it is kept.
     *
     * @param  array<string, mixed>  $meta  a file, a place, whether it is a note
     */
    public function say(User $by, string $content, array $meta = []): ChatMessage
    {
        $said = $this->messages()->create([
            'role' => ChatMessage::USER,
            'user_id' => $by->id,
            'content' => $content,
            'meta' => array_filter($meta) ?: null,
        ]);

        // Everyone else in a shared room sees it at once, wherever they are
        if ($this->kind !== self::PERSONAL) {
            $said->load('author:id,name');
            Live::tell(new ChatMessagePosted($this, $said));
        }

        // An answer leaves a customer's chat waiting on them: the inbox and the others who have it open follow
        if ($this->kind === self::CUSTOMER && ! $said->isInternal()) {
            Live::tell(new ChatRoomChanged($this->refresh()));
        }

        return $said;
    }

    /**
     * Until when the service a chat is linked to takes an answer: so many
     * hours from the last thing the customer wrote. Null for a service that
     * sets no such time, and for a chat nothing has come into yet.
     */
    public function answerUntil(): ?Carbon
    {
        $hours = self::ANSWER_HOURS[$this->link['service'] ?? ''] ?? null;

        if ($hours === null) {
            return null;
        }

        $heard = $this->messages()->where('role', ChatMessage::WORKFLOW)->where('meta->outside', true)->reorder()->max('created_at');

        return $heard === null ? null : Carbon::parse($heard)->addHours($hours);
    }

    /**
     * Where a customer's chat stands, as its page and the inbox show it.
     *
     * @return array{status: string|null, assignee: array{id: int, name: string}|null, tags: list<string>, ai: bool}
     */
    public function desk(): array
    {
        return [
            'status' => $this->status,
            'assignee' => $this->assignee?->only(['id', 'name']),
            'tags' => $this->tags ?? [],
            'ai' => (bool) $this->ai_answers,
        ];
    }

    /**
     * What a workflow reads of the room, as trigger.room.
     *
     * @return array<string, mixed>
     */
    public function heardAs(): array
    {
        return [
            'ref' => $this->ref_id,
            'title' => $this->title,
            'key' => $this->external_key,
            'link' => $this->link,
            // What is kept with the room: its picture, and details by name
            'picture' => $this->picture,
            'details' => $this->details ?? [],
            // A customer's chat says where it stands, who has taken it, and its tags
            'kind' => $this->kind,
            'status' => $this->status,
            'assignee' => $this->assignee?->only(['id', 'name', 'email']),
            'tags' => $this->tags ?? [],
            // Whether AI answers here: switched on, and nobody has taken the chat
            'ai' => $this->kind === self::CUSTOMER && $this->ai_answers && $this->assigned_to === null,
            // Who they are on every service: name, key, notes, and each of their chats
            'contact' => $this->contact?->card(),
        ];
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
            self::CUSTOMER => $this->project?->members()->orderBy('name')->get()->toBase() ?? collect(),
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

        // Workflows set to hear a room being read: a linked chat's other end can be told
        ChatMessages::read($this, $user, $messageId);
    }

    /**
     * The model the agent answers with: the room's own, or its connection's.
     */
    public function agentModel(): ?string
    {
        return $this->model ?: $this->aiConnection?->setting('default_model');
    }

    /**
     * Whether something answers in this room. Only one's own room has an agent:
     * it answers with that person's key.
     */
    /**
     * Whether rooms may have agents at all (CHAT_AGENTS). Off, AI answers
     * come from workflows; a room's connection, model and instructions are
     * kept, unused, for when they are on again.
     */
    public static function agentsOn(): bool
    {
        return (bool) config('services.chat.agents', false);
    }

    public function hasAgent(): bool
    {
        return self::agentsOn()
            && $this->kind === self::PERSONAL && $this->aiConnection?->isAi() === true && $this->agentModel() !== null;
    }
}
