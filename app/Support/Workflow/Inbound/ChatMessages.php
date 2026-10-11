<?php

namespace App\Support\Workflow\Inbound;

use App\Models\Chat\ChatMessage;
use App\Models\Chat\ChatRoom;
use App\Models\User;
use App\Models\Workflow\WorkflowListener;
use App\Models\Workflow\WorkflowRun;
use App\Support\Workflow\Ingest;
use App\Support\Workflow\Listeners;
use App\Support\Workflow\References;
use Illuminate\Support\Collection;
use Throwable;

/**
 * A message written in a chat room, for the workflows listening there --
 * and a room read, for those set to hear that. One
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
            $listeners = self::listening($room);

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
                    // What is kept with the room: its picture, and details by name
                    'picture' => $room->picture,
                    'details' => $room->details ?? [],
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
                // A file sent with it is in the Drive already: its ref_id, for a step to send on
                'files' => isset($message->meta['file']) ? [$message->meta['file']] : [],
                // What it was, as other services say it, and the place it showed -- always there, so a Switch can go by them
                'kind' => match (true) {
                    isset($message->meta['place']) => 'location',
                    isset($message->meta['file']) => 'file',
                    default => 'text',
                },
                'location' => $message->meta['place'] ?? null,
            ];

            Ingest::fire(
                "chat:{$room->ref_id}",
                (string) $message->id,
                $envelope,
                $listeners,
                fn (WorkflowListener $listener) => ($listener->settings['on'] ?? 'message') === 'message'
                    && ($message->role === ChatMessage::USER || ($listener->settings['include'] ?? 'people') === 'all'),
                $origin,
            );
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Someone has read the room as far as a message: told, once, of the
     * latest thing an outside chat sent that they have now seen -- with the
     * token that message came with for marking it read where it was sent
     * (LINE's), when it came with one.
     */
    public static function read(ChatRoom $room, User $reader, int $upTo): void
    {
        try {
            $listeners = self::listening($room)->filter(fn (WorkflowListener $listener) => ($listener->settings['on'] ?? 'message') === 'read');

            if ($listeners->isEmpty()) {
                return;
            }

            // What a workflow carried in from outside, under its sender's name
            $carried = $room->messages()
                ->where('role', ChatMessage::WORKFLOW)
                ->where('id', '<=', $upTo)
                ->where('meta->outside', true)
                // A room's messages come oldest first unless told otherwise
                ->reorder('id', 'desc')
                ->first();
            $heard = isset($carried?->meta['run'])
                ? WorkflowRun::where('ref_id', $carried->meta['run'])->value('trigger_data')
                : null;

            if ($carried === null || ! is_array($heard)) {
                return;
            }

            Ingest::fire("chat:{$room->ref_id}", "read:{$carried->id}", [
                'service' => 'chat',
                'type' => 'read',
                'room' => ['ref' => $room->ref_id, 'title' => $room->title, 'key' => $room->external_key, 'link' => $room->link, 'picture' => $room->picture, 'details' => $room->details ?? []],
                'reader' => ['id' => $reader->id, 'name' => $reader->name],
                'message' => ['id' => $carried->id, 'content' => $carried->content],
                // As the outside message came: reply.read is LINE's token for marking it read
                'reply' => ['token' => null, 'to' => $heard['reply']['to'] ?? null, 'read' => $heard['reply']['read'] ?? null],
                'text' => '',
                'files' => [],
            ], $listeners);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * The workflows listening in a room: there by name, or -- a linked chat
     * -- to every chat linked to its service, their owner's own chats only.
     *
     * @return Collection<int, WorkflowListener>
     */
    private static function listening(ChatRoom $room): Collection
    {
        $listeners = Listeners::for('chat_room', $room->ref_id);

        if ($room->link === null) {
            return $listeners;
        }

        return $listeners->concat(
            Listeners::for('chat_room', References::LINKED.':'.$room->link['service'])
                ->filter(fn (WorkflowListener $listener) => References::room($listener->workflow->owner(), $room->ref_id) !== null),
        );
    }
}
