<?php

namespace App\Http\Controllers\Chat;

use App\Http\Controllers\Controller;
use App\Models\Chat\AiConnection;
use App\Models\Chat\ChatMessage;
use App\Models\Chat\ChatRoom;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ChatRoomController extends Controller
{
    /**
     * List the user's rooms, the latest first.
     */
    public function index(Request $request): Response
    {
        return Inertia::render('chats/Index', [
            'rooms' => $request->user()->chatRooms()
                ->with('aiConnection:id,name')
                ->withCount('messages')
                ->latest('updated_at')
                ->get()
                ->map(fn (ChatRoom $room) => [
                    ...$room->only(['ref_id', 'title', 'updated_at']),
                    'messages' => $room->messages_count,
                    'agent' => $room->hasAgent() ? $room->aiConnection->name.' · '.$room->agentModel() : null,
                ])
                ->values(),
            'hasConnections' => $request->user()->aiConnections()->exists(),
        ]);
    }

    /**
     * Start a room, answered by the connection the user made last, and open it.
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
     * Show the room: what was said, and how it is set up.
     */
    public function show(Request $request, ChatRoom $room): Response
    {
        Gate::authorize('view', $room);

        return Inertia::render('chats/Show', [
            'room' => [
                ...$room->only(['ref_id', 'title', 'model', 'system_prompt']),
                'connection' => $room->aiConnection?->ref_id,
                'ready' => $room->hasAgent(),
            ],
            'messages' => $room->messages->map(fn (ChatMessage $message) => $message->toChat())->values(),
            'connections' => $request->user()->aiConnections()->orderBy('name')->get()
                ->map(fn (AiConnection $connection) => [
                    ...$connection->only(['ref_id', 'name', 'kind', 'default_model']),
                    'label' => $connection->label(),
                ])
                ->values(),
        ]);
    }

    /**
     * Rename the room, or change the agent that answers in it.
     */
    public function update(Request $request, ChatRoom $room): RedirectResponse
    {
        Gate::authorize('update', $room);

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
     * Delete the room, and everything said in it.
     */
    public function destroy(ChatRoom $room): RedirectResponse
    {
        Gate::authorize('delete', $room);

        $room->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Chat deleted.')]);

        return to_route('chats.index');
    }
}
