<?php

namespace App\Http\Controllers\Chat;

use App\Http\Controllers\Controller;
use App\Models\Chat\ChatMessage;
use App\Models\Chat\ChatRoom;
use App\Support\Chat\AiFailed;
use App\Support\Chat\ChatEngine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChatMessageController extends Controller
{
    /**
     * Say something in the room, and stream the agent's answer back as it is written.
     *
     * The answer is a server-sent stream of events: "user" (what was kept of
     * the message), "delta" for each piece of the answer, then "done" -- or
     * "error" with a reason. A room without an agent simply ends after "user".
     */
    public function store(Request $request, ChatRoom $room, ChatEngine $engine): StreamedResponse
    {
        Gate::authorize('update', $room);

        $validated = $request->validate([
            'content' => ['required', 'string', 'max:20000'],
        ]);

        $said = $room->messages()->create([
            'role' => ChatMessage::USER,
            'user_id' => $request->user()->id,
            'content' => $validated['content'],
        ]);

        // A room is named after what it was first about, until it is named
        if ($room->title === '') {
            $room->update(['title' => mb_strimwidth(preg_replace('/\s+/', ' ', trim($said->content)), 0, 60, '…')]);
        }

        return response()->stream(function () use ($room, $said, $engine) {
            // Keep what the agent wrote even if the page was closed on it
            ignore_user_abort(true);

            $send = function (string $event, array $data) {
                echo "event: {$event}\ndata: ".json_encode($data)."\n\n";

                if (ob_get_level() > 0) {
                    ob_flush();
                }

                flush();
            };

            $send('user', ['message' => $said->toChat(), 'title' => $room->title]);

            if (! $room->hasAgent()) {
                $send('done', ['message' => null]);

                return;
            }

            try {
                $answer = $engine->reply($room, function (string $piece) use ($send) {
                    $send('delta', ['text' => $piece]);

                    return ! connection_aborted();
                });

                $send('done', ['message' => $answer?->toChat()]);
            } catch (AiFailed $e) {
                $send('error', ['message' => $e->getMessage()]);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            // So a proxy hands each piece on as it comes
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
