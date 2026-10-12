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
use Illuminate\Support\Carbon;
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
    /** How many of what an outside chat sent are looked through for the last one sent. */
    private const LOOK_BACK = 20;

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
                // What the other end of a linked chat last sent, for answering it there
                'reply' => ($message->meta['outside'] ?? false) ? null : self::answering($room, $message),
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
     * What an outside chat last sent before a message, as it came: the
     * token for replying to it (LINE's, taken once and only for a while
     * after), when that was, its id there, and the token for quoting it.
     * Null in a room nothing outside has written in.
     *
     * @return array{token: mixed, at: string|null, to: mixed, quote: mixed}|null
     */
    private static function answering(ChatRoom $room, ChatMessage $message): ?array
    {
        $carried = $room->messages()
            ->where('role', ChatMessage::WORKFLOW)
            ->where('id', '<', $message->id)
            ->where('meta->outside', true)
            ->reorder('id', 'desc')
            ->first();
        $run = isset($carried?->meta['run']) ? WorkflowRun::where('ref_id', $carried->meta['run'])->first() : null;
        $heard = $run?->trigger_data['reply'] ?? null;

        return ! is_array($heard) ? null : [
            'token' => $heard['token'] ?? null,
            // When the app heard of it, which is what a token's time runs from
            'at' => $run->created_at?->toIso8601String(),
            'to' => $heard['to'] ?? null,
            'quote' => $heard['quote'] ?? null,
        ];
    }

    /**
     * Someone has read the room as far as a message: told, once, of the
     * latest thing an outside chat sent that they have now seen -- with a
     * token for marking it read where it was sent (LINE's), when what they
     * have seen came with one.
     */
    public static function read(ChatRoom $room, User $reader, int $upTo): void
    {
        try {
            $listeners = self::listening($room)->filter(fn (WorkflowListener $listener) => ($listener->settings['on'] ?? 'message') === 'read');

            if ($listeners->isEmpty()) {
                return;
            }

            // What a workflow carried in from outside, under its sender's name: the latest, and a few before it
            $seen = $room->messages()
                ->where('role', ChatMessage::WORKFLOW)
                ->where('id', '<=', $upTo)
                ->where('meta->outside', true)
                // A room's messages come oldest first unless told otherwise
                ->reorder('id', 'desc')
                ->limit(self::LOOK_BACK)
                ->get();
            $carried = $seen->first();
            $runs = WorkflowRun::whereIn('ref_id', $seen->pluck('meta.run')->filter()->unique())->pluck('trigger_data', 'ref_id');
            $heard = $runs[$carried?->meta['run'] ?? ''] ?? null;

            if ($carried === null || ! is_array($heard)) {
                return;
            }

            // LINE's token marks a message read and all sent before it. So it is that of the last one
            // sent in the same chat: not always the latest to come, as LINE sends again, late, what did
            // not get through -- and not the latest at all when that is a tap, which comes with none
            $last = $seen
                ->map(fn (ChatMessage $message) => [$message, $runs[$message->meta['run'] ?? ''] ?? null])
                ->filter(fn (array $pair) => isset($pair[1]['reply']['read']) && ($pair[1]['chat']['id'] ?? null) === ($heard['chat']['id'] ?? null))
                ->sortByDesc(fn (array $pair) => Carbon::parse($pair[1]['at'] ?? $pair[0]->created_at)->getTimestampMs())
                ->first();

            Ingest::fire("chat:{$room->ref_id}", "read:{$carried->id}", [
                'service' => 'chat',
                'type' => 'read',
                'room' => ['ref' => $room->ref_id, 'title' => $room->title, 'key' => $room->external_key, 'link' => $room->link, 'picture' => $room->picture, 'details' => $room->details ?? []],
                'reader' => ['id' => $reader->id, 'name' => $reader->name],
                'message' => ['id' => $carried->id, 'content' => $carried->content],
                // As the outside message came; reply.read is LINE's token for marking read all that was seen
                'reply' => ['token' => null, 'to' => $heard['reply']['to'] ?? null, 'read' => $last[1]['reply']['read'] ?? null],
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
