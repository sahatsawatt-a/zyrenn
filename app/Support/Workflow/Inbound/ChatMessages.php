<?php

namespace App\Support\Workflow\Inbound;

use App\Models\Chat\ChatMessage;
use App\Models\Workflow\WorkflowListener;
use App\Models\Workflow\WorkflowRun;
use App\Support\Workflow\Ingest;
use App\Support\Workflow\Listeners;
use App\Support\Workflow\References;
use Throwable;

/**
 * A message written in a chat room, for the workflows listening there. One
 * a workflow wrote starts only those that ask for workflows' messages too,
 * and counts as caused by the run that wrote it -- so workflows answering
 * each other stop, a few runs deep. Nothing here ever stops a message being
 * said: a failure is reported, and the message stands.
 */
final class ChatMessages
{
    public static function arrived(ChatMessage $message): void
    {
        try {
            $room = $message->room;
            $listeners = Listeners::for('chat_room', $room->ref_id);

            // A linked chat is heard too by workflows listening to every chat
            // linked to its service -- their owner's own chats only
            if ($room->link !== null) {
                $listeners = $listeners->concat(
                    Listeners::for('chat_room', References::LINKED.':'.$room->link['service'])
                        ->filter(fn (WorkflowListener $listener) => References::room($listener->workflow->owner(), $room->ref_id) !== null),
                );
            }

            if ($listeners->isEmpty()) {
                return;
            }

            $origin = isset($message->meta['run'])
                ? WorkflowRun::where('ref_id', $message->meta['run'])->first()
                : null;

            $envelope = [
                'service' => 'chat',
                'type' => 'message',
                'room' => [
                    'ref' => $room->ref_id,
                    'title' => $room->title,
                    'key' => $room->external_key,
                    'link' => $room->link,
                ],
                'message' => [
                    'id' => $message->id,
                    'role' => $message->role,
                    'content' => $message->content,
                    'author' => $message->role === ChatMessage::USER
                        ? $message->author?->name
                        : ($message->meta['name'] ?? null),
                    'at' => $message->created_at?->toIso8601String(),
                ],
                // As other services give it, so a step reads trigger.text alike
                'text' => $message->content,
            ];

            Ingest::fire(
                "chat:{$room->ref_id}",
                (string) $message->id,
                $envelope,
                $listeners,
                fn (WorkflowListener $listener) => $message->role === ChatMessage::USER
                    || ($listener->settings['include'] ?? 'people') === 'all',
                $origin,
            );
        } catch (Throwable $e) {
            report($e);
        }
    }
}
