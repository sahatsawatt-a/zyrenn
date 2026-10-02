<?php

namespace App\Http\Controllers\Chat;

use App\Http\Controllers\Controller;
use App\Models\Chat\AiConnection;
use App\Models\Chat\ChatMessage;
use App\Models\Chat\ChatRoom;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ChatRoomController extends Controller
{
    /** How much of a room is shown when it opens; earlier messages come on request. */
    public const PAGE = 100;

    /**
     * List the user's own rooms, each answered by an agent of theirs or by
     * nothing, the latest first. Talking with people is kept apart, under messages.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('chats/Index', [
            'rooms' => $this->listed($user, $user->chatRooms()->getQuery()),
            'hasConnections' => $user->aiConnections()->exists(),
        ]);
    }

    /**
     * List the rooms the user shares with people: each person they talk with,
     * and each group they are in, the latest first.
     */
    public function messages(Request $request): Response
    {
        $user = $request->user();

        $shared = collect($this->listed($user, ChatRoom::query()->visibleTo($user)->shared()));

        return Inertia::render('messages/Index', [
            'direct' => $shared->where('kind', ChatRoom::DIRECT)->values(),
            'groups' => $shared->where('kind', ChatRoom::GROUP)->values(),
            'people' => $this->reachable($user),
            // Where a group can be started or joined
            'projects' => $user->projects()->orderBy('name')->get()->map(fn (Project $project) => $project->only(['ref_id', 'name']))->values(),
        ]);
    }

    /**
     * Start a room of one's own, answered by the connection the user made last, and open it.
     */
    public function store(Request $request): RedirectResponse
    {
        $room = new ChatRoom;
        $room->user_id = $request->user()->id;
        $room->ai_connection_id = $request->user()->aiConnections()->latest('id')->value('id');
        $room->save();

        return to_route('chats.show', $room);
    }

    /**
     * Open the room the user shares with someone, made the first time either
     * asks. Only someone they are in a project with can be reached.
     */
    public function direct(Request $request): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'user' => ['required', 'integer', Rule::in($this->reachable($user)->pluck('id'))],
        ]);

        $room = ChatRoom::between($user, User::query()->whereKey($validated['user'])->firstOrFail());

        return to_route('chats.show', $room);
    }

    /**
     * A project's groups: the ones the user is in, and the ones they may join.
     */
    public function project(Request $request, Project $project): Response
    {
        $user = $request->user();
        $mine = $user->id;

        $groups = $project->chatRooms()
            ->where('kind', ChatRoom::GROUP)
            ->withCount('members')
            ->withExists(['members as joined' => fn (Builder $member) => $member->whereKey($mine)])
            ->withUnreadFor($user)
            ->latest('updated_at')
            ->latest('id')
            ->get()
            ->map(fn (ChatRoom $room) => [
                'ref_id' => $room->ref_id,
                'title' => $room->title,
                'members' => $room->members_count,
                'joined' => (bool) $room->joined,
                // What one is not in is not one's to have read
                'unread' => $room->joined ? $room->unread : 0,
                'updated_at' => $room->updated_at,
            ])
            ->values();

        return Inertia::render('chats/Groups', [
            'groups' => $groups,
            // Who a new group can take in: the others in the project
            'people' => $project->members()->whereKeyNot($mine)->orderBy('name')->get()
                ->map(fn (User $person) => $person->only(['id', 'name', 'email']))
                ->values(),
        ]);
    }

    /**
     * Start a group in the project, with people chosen from it, and open it.
     */
    public function startGroup(Request $request, Project $project): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:80'],
            'people' => ['array', 'max:200'],
            // Only someone in the same project can be taken in
            'people.*' => ['integer', 'distinct', Rule::in($project->members()->pluck('users.id'))],
        ]);

        $room = ChatRoom::startGroup($project, $user, trim($validated['title']), $validated['people'] ?? []);

        return to_route('chats.show', $room);
    }

    /**
     * Join a group of one's project.
     */
    public function join(Request $request, ChatRoom $room): RedirectResponse
    {
        Gate::authorize('join', $room);

        $room->members()->syncWithoutDetaching([$request->user()->id]);

        return to_route('chats.show', $room);
    }

    /**
     * Leave a group; it stays for the others, and can be joined again.
     */
    public function leave(Request $request, ChatRoom $room): RedirectResponse
    {
        Gate::authorize('leave', $room);

        $room->members()->detach($request->user()->id);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('You left the group.')]);

        return to_route('projects.chat', $room->project);
    }

    /**
     * Show the room: the latest of what was said, who is in it, and how it is set up.
     */
    public function show(Request $request, ChatRoom $room): Response
    {
        Gate::authorize('view', $room);

        $user = $request->user();
        $latest = $room->messages()->with('author:id,name')->reorder('id', 'desc')->limit(self::PAGE + 1)->get();
        $shown = $latest->take(self::PAGE)->reverse()->values();

        if ($shown->isNotEmpty()) {
            $room->markRead($user, $shown->last()->id);
        }

        $own = $room->kind === ChatRoom::PERSONAL;

        return Inertia::render('chats/Show', [
            'room' => [
                ...$room->only(['ref_id', 'kind', 'model', 'system_prompt']),
                'title' => $room->titleFor($user),
                'connection' => $room->aiConnection?->ref_id,
                'ready' => $room->hasAgent(),
                // One's own room is set up by its owner; a group is renamed and deleted by who started it
                'mine' => $own && $room->user_id === $user->id,
                'manage' => Gate::allows('update', $room),
                'leave' => Gate::allows('leave', $room),
                'project' => $room->kind === ChatRoom::GROUP ? $room->project?->only(['ref_id', 'name']) : null,
            ],
            'messages' => $shown->map(fn (ChatMessage $message) => $message->toChat()),
            'hasEarlier' => $latest->count() > self::PAGE,
            'people' => $own ? [] : $room->people()->map(fn (User $person) => $person->only(['id', 'name']))->values(),
            'connections' => $own ? $user->aiConnections()->orderBy('name')->get()
                ->map(fn (AiConnection $connection) => [
                    ...$connection->only(['ref_id', 'name', 'kind', 'default_model']),
                    'label' => $connection->label(),
                ])
                ->values() : [],
        ]);
    }

    /**
     * What was said before a message, for scrolling back.
     */
    public function earlier(Request $request, ChatRoom $room): JsonResponse
    {
        Gate::authorize('view', $room);

        $before = $request->integer('before');
        $page = $room->messages()->with('author:id,name')
            ->when($before > 0, fn (Builder $older) => $older->where('id', '<', $before))
            ->reorder('id', 'desc')
            ->limit(self::PAGE + 1)
            ->get();

        return response()->json([
            'messages' => $page->take(self::PAGE)->reverse()->values()->map(fn (ChatMessage $message) => $message->toChat()),
            'hasEarlier' => $page->count() > self::PAGE,
        ]);
    }

    /**
     * Note how far the user has read, as messages arrive while the room is open.
     */
    public function read(Request $request, ChatRoom $room): JsonResponse
    {
        Gate::authorize('view', $room);

        $validated = $request->validate(['message' => ['required', 'integer', 'min:1']]);

        // Never past what is in the room
        $last = (int) $room->messages()->where('id', '<=', $validated['message'])->max('id');

        if ($last > 0) {
            $room->markRead($request->user(), $last);
        }

        return response()->json(['unread' => ChatRoom::unreadTotal($request->user())]);
    }

    /**
     * Rename a room, or change the agent that answers in one's own.
     */
    public function update(Request $request, ChatRoom $room): RedirectResponse
    {
        Gate::authorize('update', $room);

        // A group is only ever renamed: no agent answers among people
        if ($room->kind === ChatRoom::GROUP) {
            $room->update($request->validate(['title' => ['required', 'string', 'max:80']]));

            return back();
        }

        $validated = $request->validate([
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            // A connection is only ever one of the user's own
            'connection' => ['sometimes', 'nullable', 'string', Rule::exists('ai_connections', 'ref_id')->where('user_id', $request->user()->id)],
            'model' => ['sometimes', 'nullable', 'string', 'max:255'],
            'system_prompt' => ['sometimes', 'nullable', 'string', 'max:10000'],
        ]);

        if (array_key_exists('title', $validated)) {
            $validated['title'] = (string) $validated['title'];
        }

        if (array_key_exists('connection', $validated)) {
            $validated['ai_connection_id'] = $validated['connection']
                ? $request->user()->aiConnections()->where('ref_id', $validated['connection'])->value('id')
                : null;
            unset($validated['connection']);
        }

        $room->update($validated);

        return back();
    }

    /**
     * Delete one's own room or a group one started, and everything said in it.
     */
    public function destroy(ChatRoom $room): RedirectResponse
    {
        Gate::authorize('delete', $room);

        $project = $room->kind === ChatRoom::GROUP ? $room->project : null;
        $room->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Chat deleted.')]);

        return $project ? to_route('projects.chat', $project) : to_route('chats.index');
    }

    /**
     * The rooms as their lists show them.
     *
     * @param  Builder<ChatRoom>  $rooms
     * @return array<int, array<string, mixed>>
     */
    private function listed(User $user, Builder $rooms): array
    {
        return $rooms
            ->with(['aiConnection:id,name', 'members:id,name', 'project:id,name'])
            ->withCount('messages')
            ->withUnreadFor($user)
            ->latest('updated_at')
            ->latest('id')
            ->get()
            ->map(fn (ChatRoom $room) => [
                'ref_id' => $room->ref_id,
                'kind' => $room->kind,
                'title' => $room->titleFor($user),
                'project' => $room->project?->name,
                'updated_at' => $room->updated_at,
                'messages' => $room->messages_count,
                'unread' => $room->kind === ChatRoom::PERSONAL ? 0 : $room->unread,
                'agent' => $room->hasAgent() ? $room->aiConnection->name.' · '.$room->agentModel() : null,
            ])
            ->values()
            ->all();
    }

    /**
     * Who the user can start a conversation with: everyone they share a project with.
     *
     * @return Collection<int, array{id: int, name: string, email: string}>
     */
    private function reachable(User $user): Collection
    {
        return User::query()
            ->whereKeyNot($user->id)
            ->whereHas('projects', fn (Builder $project) => $project->whereIn('projects.id', $user->projects()->select('projects.id')))
            ->orderBy('name')
            ->get(['id', 'name', 'email'])
            ->map(fn (User $person) => ['id' => $person->id, 'name' => $person->name, 'email' => $person->email])
            ->toBase();
    }
}
