<?php

namespace App\Models\Ticket;

use App\Models\Chat\ChatRoom;
use App\Models\Concerns\HasRefId;
use App\Models\Product\ProductElement;
use App\Models\Product\Requirement;
use App\Models\Project;
use App\Models\User;
use Database\Factories\Ticket\TicketFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Something a project has been asked for: a bug to fix, or a feature to add.
 * It says what, about which part, and how far along it is; it is talked over
 * in one of the project's chat groups rather than in comments of its own.
 *
 * @property int $id
 * @property string $ref_id
 * @property int $project_id
 * @property int $number
 * @property string $type
 * @property string $status
 * @property string $priority
 * @property string $title
 * @property string $unit
 * @property string|null $body
 * @property list<array{src: string, name: string}>|null $pictures
 * @property int|null $created_by
 * @property int|null $assigned_to
 * @property int|null $chat_room_id
 * @property int|null $asked_in_room_id
 * @property int|null $asked_in_message_id
 * @property Carbon|null $closed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Project $project
 * @property-read User|null $creator
 * @property-read User|null $assignee
 * @property-read ChatRoom|null $room
 * @property-read ChatRoom|null $askedIn
 */
#[Fillable(['type', 'status', 'priority', 'title', 'unit', 'body', 'pictures', 'assigned_to'])]
class Ticket extends Model
{
    /** @use HasFactory<TicketFactory> */
    use HasFactory, HasRefId;

    public const BUG = 'bug';

    public const FEATURE = 'feature';

    public const TYPES = [self::BUG, self::FEATURE];

    public const OPEN = 'open';

    public const IN_PROGRESS = 'in_progress';

    public const DONE = 'done';

    /** Looked at, and not going to be done. */
    public const DROPPED = 'dropped';

    public const STATUSES = [self::OPEN, self::IN_PROGRESS, self::DONE, self::DROPPED];

    public const PRIORITIES = ['low', 'normal', 'high'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => self::OPEN,
        'priority' => 'normal',
        'unit' => '',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'pictures' => 'array',
            'closed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Closed the moment it is done or dropped, and open again if it comes back
        static::saving(function (Ticket $ticket) {
            if ($ticket->isDirty('status') || ! $ticket->exists) {
                $ticket->closed_at = $ticket->isClosed() ? ($ticket->closed_at ?? Carbon::now()) : null;
            }
        });
    }

    /**
     * A new ticket in the project, given the next number there.
     *
     * @param  array<string, mixed>  $details
     */
    public static function file(Project $project, ?User $by, array $details): self
    {
        return DB::transaction(function () use ($project, $by, $details) {
            // One at a time in a project, so no two take the same number
            Project::query()->whereKey($project->id)->lockForUpdate()->first();

            $ticket = new self($details);
            $ticket->project_id = $project->id;
            $ticket->created_by = $by?->id;
            $ticket->number = ((int) self::query()->where('project_id', $project->id)->max('number')) + 1;
            $ticket->save();

            return $ticket;
        });
    }

    public function isClosed(): bool
    {
        return in_array($this->status, [self::DONE, self::DROPPED], true);
    }

    /**
     * The group it is talked over in, started the first time somebody asks:
     * whoever filed it, whoever it is given to, and whoever asked.
     */
    public function discussion(User $by): ChatRoom
    {
        $room = $this->room;

        if (! $room) {
            $people = array_filter([$this->created_by, $this->assigned_to]);
            $inProject = $this->project->members()->whereKey($people)->pluck('users.id');

            $room = ChatRoom::startGroup($this->project, $by, mb_substr("#{$this->number} {$this->title}", 0, 80), $inProject);
            $this->chat_room_id = $room->id;
            $this->save();
        }

        $room->members()->syncWithoutDetaching([$by->id]);

        return $room;
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Who asked for it.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Who is doing it.
     *
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * @return BelongsTo<ChatRoom, $this>
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(ChatRoom::class, 'chat_room_id');
    }

    /**
     * The customer's chat it was asked in, when it was made from one.
     *
     * @return BelongsTo<ChatRoom, $this>
     */
    public function askedIn(): BelongsTo
    {
        return $this->belongsTo(ChatRoom::class, 'asked_in_room_id');
    }

    /**
     * The parts of the project's product it is about.
     *
     * @return BelongsToMany<ProductElement, $this>
     */
    public function elements(): BelongsToMany
    {
        return $this->belongsToMany(ProductElement::class, 'element_ticket');
    }

    /**
     * The requirements it is work for.
     *
     * @return BelongsToMany<Requirement, $this>
     */
    public function requirements(): BelongsToMany
    {
        return $this->belongsToMany(Requirement::class, 'requirement_ticket');
    }

    /**
     * The ticket as a page, or an MCP client, is given it.
     *
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return [
            ...$this->only(['ref_id', 'number', 'type', 'status', 'priority', 'title', 'unit']),
            'body' => $this->body ?? '',
            'pictures' => $this->pictures ?? [],
            'created_by' => $this->creator?->only(['id', 'name']),
            'assigned_to' => $this->assignee?->only(['id', 'name']),
            'chat' => $this->room?->ref_id,
            // The customer's chat it was asked in
            'asked_in' => $this->askedIn?->only(['ref_id', 'title']),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'closed_at' => $this->closed_at,
        ];
    }
}
