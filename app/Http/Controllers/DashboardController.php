<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ActsForOwner;
use App\Models\Board\Board;
use App\Models\Chat\ChatRoom;
use App\Models\Note\Note;
use App\Models\Owner;
use App\Models\Project;
use App\Models\Table\Table;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Where things stand: the user's own at /dashboard, a project's at
 * /p/{project}/dashboard -- what is in it, what changed lately and by whom,
 * the to-dos still open in its notes, and the conversations waiting.
 */
class DashboardController extends Controller
{
    use ActsForOwner;

    /** How many of a kind of thing a list on the dashboard shows. */
    private const SHOWN = 8;

    /** How many notes with to-dos in them are looked through, latest first. */
    private const TODO_NOTES = 40;

    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $owner = $this->owner($request);
        $shared = $owner instanceof Project;

        return Inertia::render('dashboard/Index', [
            'counts' => [
                'notes' => $owner->notes()->count(),
                'boards' => $owner->boards()->count(),
                'tables' => $owner->tables()->count(),
                'files' => $owner->driveFiles()->count(),
                'bytes' => (int) $owner->driveFiles()->sum('size'),
            ],
            'recent' => $this->recent([$owner->notes(), $owner->boards(), $owner->tables()], $shared),
            'todos' => $this->todos($owner->notes()),
            'chats' => $this->chats($user, $shared ? $owner : null),
            ...($shared
                ? ['members' => $this->members($owner, $user)]
                : [
                    'yourProjects' => $this->projects($user),
                    'fromProjects' => $this->fromProjects($user),
                ]),
        ]);
    }

    /**
     * The notes, boards and tables changed last, all kinds together.
     *
     * @param  list<HasMany<covariant Model, covariant Model>|Builder<covariant Model>>  $kinds
     * @return Collection<int, array<string, mixed>>
     */
    private function recent(array $kinds, bool $withEditor, bool $withProject = false): Collection
    {
        return collect($kinds)
            ->flatMap(fn (HasMany|Builder $things) => $things
                ->select(['id', 'ref_id', 'title', 'updated_at', 'updated_by', ...($withProject ? ['project_id'] : [])])
                ->with([
                    ...($withEditor ? ['editor:id,name'] : []),
                    ...($withProject ? ['project:id,ref_id,name'] : []),
                ])
                ->latest('updated_at')
                ->latest('id')
                ->limit(self::SHOWN)
                ->get())
            ->sortByDesc(fn (Model $thing) => $thing->getAttribute('updated_at'))
            ->take(self::SHOWN)
            ->map(fn (Model $thing) => [
                'kind' => match (true) {
                    $thing instanceof Note => 'note',
                    $thing instanceof Board => 'board',
                    default => 'table',
                },
                'ref_id' => $thing->getAttribute('ref_id'),
                'title' => $thing->getAttribute('title'),
                'updated_at' => $thing->getAttribute('updated_at'),
                ...($withEditor ? ['edited_by' => $thing->getRelationValue('editor')?->getAttribute('name')] : []),
                ...($withProject ? ['project' => $thing->getRelationValue('project')?->only(['ref_id', 'name'])] : []),
            ])
            ->values();
    }

    /**
     * The to-dos not yet ticked in the notes changed last, with how many
     * there are in all of those notes.
     *
     * @param  HasMany<Note, covariant Model&Owner>  $notes
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    private function todos(HasMany $notes): array
    {
        $open = $notes
            ->select(['id', 'ref_id', 'title', 'content', 'updated_at'])
            ->whereLike('content', '%taskItem%')
            ->latest('updated_at')
            ->limit(self::TODO_NOTES)
            ->get()
            ->flatMap(fn (Note $note) => collect(self::openTasks($note->content ?? []))
                ->map(fn (string $text) => [
                    'text' => $text,
                    'note' => ['ref_id' => $note->ref_id, 'title' => $note->title],
                ]));

        return [
            'items' => $open->take(self::SHOWN * 2)->values()->all(),
            'total' => $open->count(),
        ];
    }

    /**
     * The words of each unticked to-do in a note's content -- the item's own
     * line, not the to-dos nested under it, which are counted on their own.
     *
     * @param  array<string, mixed>  $node
     * @return list<string>
     */
    private static function openTasks(array $node): array
    {
        $found = [];

        if (($node['type'] ?? null) === 'taskItem' && ! ($node['attrs']['checked'] ?? false)) {
            $text = trim(self::text($node['content'][0] ?? []));

            if ($text !== '') {
                $found[] = $text;
            }
        }

        foreach ($node['content'] ?? [] as $child) {
            if (is_array($child)) {
                array_push($found, ...self::openTasks($child));
            }
        }

        return $found;
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private static function text(array $node): string
    {
        return ($node['text'] ?? '').collect($node['content'] ?? [])
            ->map(fn ($child) => is_array($child) ? self::text($child) : '')
            ->implode('');
    }

    /**
     * Conversations with people, latest first: a project's groups the user is
     * in, or every direct room and group of theirs.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function chats(User $user, ?Project $project): Collection
    {
        return ChatRoom::query()
            ->visibleTo($user)
            ->shared()
            ->when($project, fn (Builder $rooms) => $rooms->where('project_id', $project->id))
            ->with(['members:id,name', 'project:id,name'])
            ->withUnreadFor($user)
            ->latest('updated_at')
            ->latest('id')
            ->limit(5)
            ->get()
            ->map(fn (ChatRoom $room) => [
                'ref_id' => $room->ref_id,
                'kind' => $room->kind,
                'title' => $room->titleFor($user),
                'project' => $project ? null : $room->project?->name,
                'unread' => $room->unread,
                'updated_at' => $room->updated_at,
            ]);
    }

    /**
     * Everyone in the project, owners first.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function members(Project $project, User $user): Collection
    {
        return $project->members()
            ->orderByRaw("case project_user.role when 'owner' then 0 when 'editor' then 1 else 2 end")
            ->orderBy('name')
            ->get(['users.id', 'users.name'])
            ->map(fn (User $member) => [
                'name' => $member->name,
                'role' => $member->getRelationValue('pivot')?->getAttribute('role'),
                'is_me' => $member->is($user),
            ]);
    }

    /**
     * The user's projects, with their role, who is in each and when
     * something in it last changed.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function projects(User $user): Collection
    {
        $projects = $user->projects()->withCount('members')->get();
        $ids = $projects->modelKeys();

        // Each kind's latest change per project; the latest of those is the project's
        $changed = collect([Note::class, Board::class, Table::class])
            ->flatMap(fn (string $kind) => $kind::query()
                ->whereIn('project_id', $ids)
                ->groupBy('project_id')
                ->selectRaw('project_id, max(updated_at) as changed_at')
                ->toBase()
                ->get())
            ->groupBy('project_id')
            // Read raw, so without the zone a model's dates are sent with
            ->map(fn (Collection $latest) => Date::parse($latest->max('changed_at')));

        return $projects
            ->map(fn (Project $project) => [
                'ref_id' => $project->ref_id,
                'name' => $project->name,
                'role' => $project->getRelationValue('pivot')?->getAttribute('role'),
                'members' => $project->members_count,
                'changed_at' => $changed->get($project->id),
            ])
            ->sortByDesc(fn (array $project) => $project['changed_at']?->getTimestamp() ?? 0)
            ->values();
    }

    /**
     * What others changed lately in the user's projects.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function fromProjects(User $user): Collection
    {
        $others = fn (string $kind) => $kind::query()
            ->whereIn('project_id', $user->projects()->select('projects.id'))
            ->whereNotNull('updated_by')
            ->where('updated_by', '!=', $user->id);

        return $this->recent([$others(Note::class), $others(Board::class), $others(Table::class)], true, true);
    }
}
