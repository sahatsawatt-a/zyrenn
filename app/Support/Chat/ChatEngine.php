<?php

namespace App\Support\Chat;

use App\Models\Chat\ChatMessage;
use App\Models\Chat\ChatRoom;
use Closure;

/**
 * Answers a room: reads what was said, asks the room's agent, and keeps the
 * answer as a message like any other. How the words get to the browser is
 * not its business -- it hands each piece to a callback -- so the same
 * engine can sit behind a stream today and a broadcast later.
 */
class ChatEngine
{
    /** How much of a conversation is sent along; older messages stay in the room, unsent. */
    public const HISTORY = 40;

    public function __construct(private AiClient $ai) {}

    /**
     * Have the agent answer the room's latest message.
     *
     * @param  Closure(string): mixed  $onText  given each piece as it arrives; return false to stop
     * @return ChatMessage|null the answer, or null when nothing was said before it failed
     *
     * @throws ChatNotReady when the room has no agent
     * @throws AiFailed when the model could not answer; whatever came first is kept
     */
    public function reply(ChatRoom $room, Closure $onText): ?ChatMessage
    {
        $connection = $room->aiConnection;
        $model = $room->agentModel();

        if ($connection === null || $model === null) {
            throw new ChatNotReady('This room has no agent to answer.');
        }

        $said = '';
        $failure = null;

        try {
            foreach ($this->ai->stream($connection, $model, $this->prompt($room)) as $piece) {
                $said .= $piece;

                if ($onText($piece) === false) {
                    break;
                }
            }
        } catch (AiFailed $e) {
            $failure = $e;
        }

        $answer = $said === '' ? null : $room->messages()->create([
            'role' => ChatMessage::ASSISTANT,
            'content' => $said,
            'meta' => array_filter(['model' => $model, 'error' => $failure?->getMessage()]),
        ]);

        if ($failure !== null) {
            throw $failure;
        }

        return $answer;
    }

    /**
     * What the model is shown: the room's instructions, then the latest of what was said.
     *
     * @return list<array{role: string, content: string}>
     */
    private function prompt(ChatRoom $room): array
    {
        $messages = [];

        foreach ($room->messages()->reorder('id', 'desc')->limit(self::HISTORY)->get()->reverse() as $message) {
            $messages[] = ['role' => $message->role, 'content' => $message->content];
        }

        return $room->system_prompt
            ? [['role' => 'system', 'content' => $room->system_prompt], ...$messages]
            : $messages;
    }
}
