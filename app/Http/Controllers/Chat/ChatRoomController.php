<?php

namespace App\Http\Controllers\Chat;

use App\Events\ChatRoomChanged;
use App\Http\Controllers\Controller;
use App\Models\Agent\Agent;
use App\Models\Chat\ChatMessage;
use App\Models\Chat\ChatRoom;
use App\Models\Chat\Contact;
use App\Models\Connection;
use App\Models\Drive\DriveFile;
use App\Models\Project;
use App\Models\Ticket\Ticket;
use App\Models\User;
use App\Support\Agents\AgentRunner;
use App\Support\Chat\AiFailed;
use App\Support\Connections\Services;
use App\Support\Live\Live;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ChatRoomController extends Controller
{
    /** How much of a room is shown when it opens; earlier messages come on request. */
    public const PAGE = 100;

    /**
     * List all chat rooms: personal AI chats, direct messages with people,
     * and groups the user is in.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $shared = collect($this->listed($user, ChatRoom::query()->visibleTo($user)->shared()));

        return Inertia::render('chats/Index', [
            'personal' => $this->listed($user, $user->chatRooms()->getQuery()),
            'direct' => $shared->where('kind', ChatRoom::DIRECT)->values(),
            'groups' => $shared->where('kind', ChatRoom::GROUP)->values(),
            'people' => $this->reachable($user),
            'projects' => $user->projects()->orderBy('name')->get()->map(fn (Project $project) => $project->only(['ref_id', 'name']))->values(),
            'hasConnections' => $user->connections()->ai()->exists(),
            'agents' => ChatRoom::agentsOn(),
        ]);
    }

    /**
     * Start a room of one's own, answered by the connection the user made last, and open it.
     */
    public function store(Request $request): RedirectResponse
    {
        $room = new ChatRoom;
        $room->user_id = $request->user()->id;
        // With agents on, answered by the connection made last; off, a room people and workflows write in
        $room->ai_connection_id = ChatRoom::agentsOn() ? $request->user()->connections()->ai()->latest('id')->value('id') : null;
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

    /** How many of a project's chats with customers the inbox lists at once. */
    public const INBOX = 100;

    /**
     * A project's inbox: its chats with customers, wherever each is held --
     * those to be answered first, narrowed by where they stand, the service
     * they came by, who has taken them, and words in their name.
     */
    public function inbox(Request $request, Project $project): Response
    {
        $user = $request->user();
        $filters = $request->validate([
            'status' => ['nullable', Rule::in([...ChatRoom::STATUSES, 'all'])],
            'channel' => ['nullable', Rule::in(ChatRoom::LINKS)],
            'assigned' => ['nullable', 'string', 'max:20'],
            'q' => ['nullable', 'string', 'max:80'],
        ]);
        $status = $filters['status'] ?? ChatRoom::OPEN;
        $assigned = $filters['assigned'] ?? 'anyone';

        // Narrowed by everything but where they stand, so each tab can say how many it holds
        $narrowed = fn () => ChatRoom::query()->customers($project)
            ->when(filled($filters['channel'] ?? null), fn (Builder $rooms) => $rooms->where('link->service', $filters['channel']))
            ->when($assigned === 'me', fn (Builder $rooms) => $rooms->where('assigned_to', $user->id))
            ->when($assigned === 'nobody', fn (Builder $rooms) => $rooms->whereNull('assigned_to'))
            ->when(ctype_digit($assigned), fn (Builder $rooms) => $rooms->where('assigned_to', (int) $assigned))
            ->when(filled($filters['q'] ?? null), function (Builder $rooms) use ($filters) {
                $like = '%'.addcslashes((string) $filters['q'], '%_\\').'%';
                // By the chat's name, or by who it is with
                $rooms->where(fn (Builder $named) => $named->whereLike('title', $like)
                    ->orWhereHas('contact', fn (Builder $contact) => $contact->whereLike('name', $like)));
            });

        $rooms = $narrowed()
            ->when($status !== 'all', fn (Builder $rooms) => $rooms->where('status', $status))
            ->with(['assignee:id,name', 'latestMessage.author:id,name', 'contact.rooms:id,contact_id,link'])
            ->withUnreadFor($user)
            ->latest('updated_at')
            ->latest('id')
            ->limit(self::INBOX + 1)
            ->get();

        return Inertia::render('chats/Inbox', [
            'rooms' => $rooms->take(self::INBOX)->map(fn (ChatRoom $room) => [
                'ref_id' => $room->ref_id,
                'title' => $room->title,
                'updated_at' => $room->updated_at,
                'unread' => $room->unread,
                'last' => $room->latestMessage?->preview($user),
                'picture' => $room->picture,
                'linked' => $room->link['platform'] ?? $room->link['service'] ?? null,
                // Where else they write: their other chats' services
                'also' => $room->contact?->rooms->reject(fn (ChatRoom $other) => $other->is($room))->map(fn (ChatRoom $other) => $other->link['service'] ?? null)->filter()->unique()->values() ?? [],
                ...$room->desk(),
            ])->values(),
            'more' => $rooms->count() > self::INBOX,
            'counts' => $narrowed()->toBase()->selectRaw('status, count(*) as held')->groupBy('status')->pluck('held', 'status'),
            'filters' => ['status' => $status, 'channel' => $filters['channel'] ?? null, 'assigned' => $assigned, 'q' => $filters['q'] ?? ''],
            // The services its chats came by, to narrow by
            'channels' => ChatRoom::query()->customers($project)->toBase()->selectRaw("distinct link->>'service' as service")->pluck('service')->filter()->sort()->values(),
            'people' => self::answering($project),
            // Whether there is a way in yet: a workflow that makes rooms for an outside chat
            'workflows' => $project->workflows()->exists(),
        ]);
    }

    /**
     * Who answers for a project, and so may be given one of its chats with
     * a customer: its owners and editors.
     *
     * @return Collection<int, array{id: int, name: string}>
     */
    private static function answering(Project $project): Collection
    {
        return $project->members()
            ->wherePivotIn('role', [Project::OWNER, Project::EDITOR])
            ->orderBy('name')
            ->get()
            ->map(fn (User $person) => ['id' => $person->id, 'name' => $person->name])
            ->toBase();
    }

    /**
     * The project's other chats with customers that this one could be the
     * same person as, by the chat's name or by who it is with.
     */
    public function sameCandidates(Request $request, ChatRoom $room): JsonResponse
    {
        Gate::authorize('update', $room);
        abort_unless($room->kind === ChatRoom::CUSTOMER, 404);

        $like = '%'.addcslashes((string) $request->string('q')->limit(80, ''), '%_\\').'%';

        $rooms = ChatRoom::query()->customers($room->project)
            ->where(fn (Builder $other) => $other->whereNull('contact_id')->orWhere('contact_id', '!=', $room->contact_id))
            ->where(fn (Builder $named) => $named->whereLike('title', $like)
                ->orWhereHas('contact', fn (Builder $contact) => $contact->whereLike('name', $like)))
            ->with('contact:id,name')
            ->latest('updated_at')
            ->limit(30)
            ->get();

        return response()->json(['rooms' => $rooms->map(fn (ChatRoom $other) => [
            'ref_id' => $other->ref_id,
            'title' => $other->title,
            'service' => $other->link['service'] ?? null,
            'contact' => $other->contact?->name,
        ])->values()]);
    }

    /**
     * This chat is the same person as another of the project's: who it is
     * with takes that one's in, with their other chats and their notes.
     */
    public function sameAs(Request $request, ChatRoom $room): RedirectResponse
    {
        Gate::authorize('update', $room);
        abort_unless($room->kind === ChatRoom::CUSTOMER && $room->contact !== null, 404);

        $other = ChatRoom::query()->customers($room->project)
            ->where('ref_id', (string) $request->validate(['room' => ['required', 'string', 'max:16']])['room'])
            ->first() ?? throw ValidationException::withMessages(['room' => __('That is not one of this project\'s chats with customers.')]);

        $other->contact !== null
            ? $room->contact->absorb($other->contact)
            : $other->forceFill(['contact_id' => $room->contact_id])->save();

        return back();
    }

    /**
     * Not the same person after all: this chat is someone of its own again.
     */
    public function notSame(ChatRoom $room): RedirectResponse
    {
        Gate::authorize('update', $room);
        abort_unless($room->kind === ChatRoom::CUSTOMER && $room->contact !== null, 404);

        $room->contact->release($room);

        return back();
    }

    /**
     * Name who a customer is, for every chat of theirs.
     */
    public function nameContact(Request $request, ChatRoom $room): RedirectResponse
    {
        Gate::authorize('update', $room);
        abort_unless($room->kind === ChatRoom::CUSTOMER && $room->contact !== null, 404);

        $room->contact->update($request->validate(['name' => ['required', 'string', 'max:'.Contact::MAX_NAME]]));

        return back();
    }

    /**
     * A reply an agent writes for a customer's chat, from its knowledge and
     * the conversation so far, for a person to read, change and send -- or
     * not. Nothing is said in the chat.
     */
    public function suggest(Request $request, ChatRoom $room, AgentRunner $runner): JsonResponse
    {
        Gate::authorize('post', $room);
        abort_unless($room->kind === ChatRoom::CUSTOMER && $room->project !== null, 404);

        $ref = (string) $request->validate(['agent' => ['required', 'string', 'max:16']])['agent'];
        $agent = Agent::query()->of($room->project)->where('ref_id', $ref)->first()
            ?? throw ValidationException::withMessages(['agent' => __('Choose one of the project\'s agents.')]);
        $asked = $room->messages()->where('role', ChatMessage::WORKFLOW)->where('meta->outside', true)->reorder('id', 'desc')->value('content');

        try {
            // Its tools look things up for the person asking, and change nothing: a person sends what it writes
            $answer = $runner->ask($agent, null, (string) ($asked ?? ''), $room, 'Write the reply itself, for someone on the team to send to the customer as it is: no JSON, no notes, nothing before or after it.', for: $request->user(), changes: false);
        } catch (AiFailed $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // An agent made to answer in JSON gives its reply in it
        $reply = is_array($answer['json']) && is_string($answer['json']['reply'] ?? null) ? $answer['json']['reply'] : $answer['text'];

        return response()->json([
            'text' => trim($reply),
            'agent' => $agent->name,
            'version' => $answer['version'],
            // What it read before it answered: to check the reply by before it is sent
            'knows' => $answer['knows'],
            'sources' => $answer['sources'],
            'actions' => $answer['actions'],
        ]);
    }

    /**
     * Make a ticket of what a customer asked: a bug to fix or a feature to
     * add, filed in the project and pointing back at the chat -- and at the
     * message, when it was made from one. A note in the chat says so.
     */
    public function promote(Request $request, ChatRoom $room): RedirectResponse
    {
        Gate::authorize('update', $room);
        abort_unless($room->kind === ChatRoom::CUSTOMER && $room->project !== null, 404);

        $validated = $request->validate([
            'type' => ['required', Rule::in(Ticket::TYPES)],
            'title' => ['required', 'string', 'max:200'],
            'body' => ['nullable', 'string', 'max:20000'],
            'message' => ['nullable', 'integer', Rule::exists('chat_messages', 'id')->where('chat_room_id', $room->id)],
        ]);

        $ticket = Ticket::file($room->project, $request->user(), [
            'type' => $validated['type'],
            'title' => trim($validated['title']),
            'body' => trim((string) ($validated['body'] ?? '')),
        ]);
        $ticket->forceFill(['asked_in_room_id' => $room->id, 'asked_in_message_id' => $validated['message'] ?? null])->save();

        $room->say($request->user(), "Made ticket #{$ticket->number}: {$ticket->title}", ['internal' => true, 'ticket' => $ticket->number]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Ticket #:number made.', ['number' => $ticket->number])]);

        return back();
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
        $agents = ChatRoom::agentsOn();
        $room->loadMissing(['assignee:id,name', 'contact.rooms']);

        return Inertia::render('chats/Show', [
            // Whether one's own room can be given an agent at all
            'agents' => $agents,
            'room' => [
                ...$room->only(['ref_id', 'kind', 'model', 'system_prompt']),
                'title' => $room->titleFor($user),
                'connection' => $room->aiConnection?->ref_id,
                'ready' => $room->hasAgent(),
                // One's own room is set up by its owner; a group is renamed and deleted by who started it
                'mine' => $own && $room->user_id === $user->id,
                'manage' => Gate::allows('update', $room),
                'leave' => Gate::allows('leave', $room),
                // Who may take it away for good: for a customer's chat, fewer than may look after it
                'remove' => Gate::allows('delete', $room),
                // Viewers of a project read its chats with customers, and write nothing in them
                'post' => Gate::allows('post', $room),
                'project' => $room->project_id !== null ? $room->project?->only(['ref_id', 'name']) : null,
                // A customer's chat: where it stands, who has taken it, its tags, and who it can be given to
                'desk' => $room->kind === ChatRoom::CUSTOMER ? [
                    ...$room->desk(),
                    'people' => self::answering($room->project),
                    // WhatsApp and Instagram take an answer only for a day after they last wrote
                    'answer_until' => $room->answerUntil(),
                    // Who can write a reply for a person to send
                    'agents' => Agent::query()->of($room->project)->orderBy('name')->get(['ref_id', 'name'])->map->only(['ref_id', 'name'])->values(),
                    // What came of what they asked
                    'tickets' => $room->askedTickets()->get(['number', 'type', 'status', 'title'])
                        ->map(fn (Ticket $ticket) => [...$ticket->only(['number', 'type', 'status', 'title']), 'url' => route('projects.tickets.index', [$room->project, 'ticket' => $ticket->number])])
                        ->values(),
                ] : null,
                // Who a customer is on every service, and their other chats
                'contact' => $room->kind === ChatRoom::CUSTOMER ? $room->contact?->card() : null,
                // What it is linked to outside: what is written in it may go back there
                'link' => $room->linkShown(),
                // Its face and what is known about it: kept by a workflow, or noted by hand
                'picture' => $room->picture,
                // A customer's notes are kept with them, once, beside what a workflow keeps of this chat
                'details' => (object) [...$room->contact->details ?? [], ...$room->details ?? []],
                // The names among them a workflow keeps, which are not changed by hand
                'kept' => $room->details_kept ?? [],
                'messages' => $room->messages()->count(),
                'created_at' => $room->created_at,
            ],
            'messages' => $shown->map(fn (ChatMessage $message) => $message->toChat()),
            'hasEarlier' => $latest->count() > self::PAGE,
            'people' => $own ? [] : $room->people()->map(fn (User $person) => $person->only(['id', 'name']))->values(),
            'connections' => $own && $agents ? $user->connections()->ai()->orderBy('name')->get()
                ->map(fn (Connection $connection) => [
                    ...$connection->only(['ref_id', 'name']),
                    'kind' => $connection->service,
                    'default_model' => $connection->setting('default_model'),
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
     * The files that can be sent in the room without uploading them again:
     * those in the Drive the room's files are kept in -- one's own for one's
     * own chat, the project's for a project's -- newest first, by name.
     */
    public function files(Request $request, ChatRoom $room): JsonResponse
    {
        Gate::authorize('post', $room);

        $validated = $request->validate(['q' => ['nullable', 'string', 'max:255']]);

        $files = $room->driveOwner()?->driveFiles()
            ->when(filled($validated['q'] ?? null), fn ($query) => $query->whereLike('name', '%'.addcslashes((string) $validated['q'], '%_\\').'%'))
            ->latest()
            ->latest('id')
            ->limit(60)
            ->get();

        return response()->json(['files' => ($files ?? collect())->map(fn (DriveFile $file) => $file->card())->values()]);
    }

    /**
     * Rename a room, or change the agent that answers in one's own.
     */
    public function update(Request $request, ChatRoom $room): RedirectResponse
    {
        Gate::authorize('update', $room);

        // What is known about the room, noted by hand: the whole list as it now stands
        if ($request->has('details')) {
            $noted = $request->validate([
                'details' => ['present', 'array', 'max:'.ChatRoom::MAX_DETAILS],
                'details.*' => ['nullable', 'string', 'max:'.ChatRoom::MAX_DETAIL],
            ])['details'];

            foreach (array_keys($noted) as $name) {
                if (preg_match(ChatRoom::DETAIL_NAME, (string) $name) !== 1) {
                    throw ValidationException::withMessages(['details' => __('A detail\'s name can only have letters, digits and _, so a workflow can read it.')]);
                }
            }

            // A customer's are noted on them, for every chat of theirs; what a workflow keeps stays with the chat
            if ($room->kind === ChatRoom::CUSTOMER && $room->contact !== null) {
                $room->contact->noteByHand(array_diff_key($noted, array_flip($room->details_kept ?? [])));

                return back();
            }

            // What a workflow keeps is not theirs to change: only the rest is taken from what was sent
            $room->noteByHand($noted);

            return back();
        }

        // A customer's chat: renamed, and moved, taken and tagged by whoever answers for the project
        if ($room->kind === ChatRoom::CUSTOMER) {
            $validated = $request->validate([
                'title' => ['sometimes', 'required', 'string', 'max:80'],
                'status' => ['sometimes', Rule::in(ChatRoom::STATUSES)],
                'assigned_to' => ['sometimes', 'nullable', 'integer', Rule::in(self::answering($room->project)->pluck('id'))],
                'tags' => ['sometimes', 'array', 'max:'.ChatRoom::MAX_TAGS],
                'tags.*' => ['string', 'max:'.ChatRoom::MAX_TAG],
                'ai_answers' => ['sometimes', 'boolean'],
            ]);

            if (array_key_exists('tags', $validated)) {
                $validated['tags'] = ChatRoom::tagsOf($validated['tags']) ?: null;
            }

            $room->forceFill($validated)->save();
            $room->unsetRelation('assignee');
            Live::tell(new ChatRoomChanged($room));

            return back();
        }

        // A group is only ever renamed: no agent answers among people
        if ($room->kind === ChatRoom::GROUP) {
            $room->update($request->validate(['title' => ['required', 'string', 'max:80']]));

            return back();
        }

        $validated = $request->validate([
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            // An agent is set up only while rooms may have one
            ...ChatRoom::agentsOn() ? [
                // A connection is only ever one of the user's own, and one that answers chats
                'connection' => ['sometimes', 'nullable', 'string', Rule::exists('connections', 'ref_id')
                    ->where('user_id', $request->user()->id)
                    ->whereIn('service', Services::ai())],
                'model' => ['sometimes', 'nullable', 'string', 'max:255'],
                'system_prompt' => ['sometimes', 'nullable', 'string', 'max:10000'],
            ] : [],
        ]);

        if (array_key_exists('title', $validated)) {
            $validated['title'] = (string) $validated['title'];
        }

        if (array_key_exists('connection', $validated)) {
            $validated['ai_connection_id'] = $validated['connection']
                ? $request->user()->connections()->where('ref_id', $validated['connection'])->value('id')
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

        $project = $room->project_id !== null ? $room->project : null;
        $customers = $room->kind === ChatRoom::CUSTOMER;
        $room->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Chat deleted.')]);

        return match (true) {
            $project === null => to_route('chats.index'),
            $customers => to_route('projects.inbox', $project),
            default => to_route('projects.chat', $project),
        };
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
            ->with(['aiConnection:id,name,service,settings', 'members:id,name', 'project:id,name', 'latestMessage.author:id,name'])
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
                // One's own room is read as it is written -- unless it is linked to a
                // chat outside, where someone else writes too
                'unread' => $room->kind === ChatRoom::PERSONAL && $room->link === null ? 0 : $room->unread,
                'last' => $room->latestMessage?->preview($user),
                'picture' => $room->picture,
                'linked' => $room->link['platform'] ?? $room->link['service'] ?? null,
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
