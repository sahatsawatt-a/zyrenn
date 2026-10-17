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
use App\Support\Dashboard\Glance;
use App\Support\Dashboard\Previews;
use App\Support\Dashboard\Waiting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Where things stand: the user's own at /dashboard, a project's at
 * /p/{project}/dashboard -- what is in it, what changed lately and by whom,
 * the to-dos still open in its notes, and the conversations waiting.
 *
 * @phpstan-type Recent array{kind: string, ref_id: string, title: mixed, updated_at: mixed, edited_by?: mixed, project?: mixed}
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

        $recent = $this->recent([$owner->notes(), $owner->boards(), $owner->tables()], $shared);

        return Inertia::render('dashboard/Index', [
            // What waits on them, most pressing first, here or everywhere
            'waiting' => Waiting::for($user, $shared ? $owner : null),
            // Each of their projects at a glance; on a project's, that one in full
            'glance' => $shared ? Glance::project($owner) : Glance::projects($user),
            // A small picture of each recent note, board and table
            'previews' => Previews::of($recent->map(fn (array $item) => ['kind' => $item['kind'], 'ref_id' => $item['ref_id']])),
            'counts' => [
                'notes' => $owner->notes()->count(),
                'boards' => $owner->boards()->count(),
                'tables' => $owner->tables()->count(),
                'files' => $owner->driveFiles()->count(),
                'bytes' => (int) $owner->driveFiles()->sum('size'),
            ],
            'recent' => $recent,
            'todos' => $this->todos($owner->notes()),
            'chats' => $this->chats($user, $shared ? $owner : null),
            ...($shared ? ['members' => $this->members($owner, $user)] : []),
        ]);
    }

    /**
     * The notes, boards and tables changed last, all kinds together.
     *
     * @param  list<HasMany<covariant Model, covariant Model>|Builder<covariant Model>>  $kinds
     * @return Collection<int, Recent>
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
                'ref_id' => (string) $thing->getAttribute('ref_id'),
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
     * @return array{items: list<array{text: string, note: array{ref_id: string, title: string}}>, total: int}
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
            'items' => array_values($open->take(self::SHOWN * 2)->all()),
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
        $text = is_string($node['text'] ?? null) ? $node['text'] : '';

        foreach (is_array($node['content'] ?? null) ? $node['content'] : [] as $child) {
            if (is_array($child)) {
                $text .= self::text($child);
            }
        }

        return $text;
    }

    /**
     * Conversations with people, latest first: a project's groups the user is
     * in, or every direct room and group of theirs.
     *
     * @return Collection<int, array{ref_id: string, kind: string, title: string, project: string|null, unread: int, updated_at: Carbon|null}>
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
     * @return Collection<int, array{name: string, role: mixed, is_me: bool}>
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
}
