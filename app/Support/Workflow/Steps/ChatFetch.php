<?php

namespace App\Support\Workflow\Steps;

use App\Models\Chat\ChatMessage;

/**
 * Read a room: its latest messages, oldest first, each with who wrote it.
 */
final class ChatFetch implements Step
{
    public function run(StepContext $step): StepResult
    {
        $room = ChatSend::room($step, $step->text('room'));
        $limit = max(1, min(200, (int) $step->setting('limit')));

        // The latest, however the room orders them for itself; then oldest first
        $messages = $room->messages()->with('author:id,name')->reorder()->orderByDesc('id')->limit($limit)->get()->reverse()->values();

        return new StepResult([
            'room' => $room->ref_id,
            'messages' => $messages->map(fn (ChatMessage $message) => [
                'id' => $message->id,
                'role' => $message->role,
                'author' => $message->role === ChatMessage::USER
                    ? $message->author?->name
                    : ($message->meta['name'] ?? ($message->role === ChatMessage::ASSISTANT ? 'AI' : null)),
                'content' => $message->content,
                'at' => $message->created_at?->toIso8601String(),
            ])->all(),
        ]);
    }
}
