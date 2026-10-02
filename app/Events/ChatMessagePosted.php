<?php

namespace App\Events;

use App\Models\Chat\ChatMessage;
use App\Models\Chat\ChatRoom;
use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * Something was said in a room shared with others. Whoever has the room open
 * hears it on the room's channel and shows it; everyone else in it hears it
 * on their own channel, so their unread count goes up wherever they are.
 * Sent at once, as TableChanged is: there is no queue worker.
 */
class ChatMessagePosted implements ShouldBroadcastNow
{
    use InteractsWithSockets;

    public function __construct(
        public ChatRoom $room,
        public ChatMessage $message,
    ) {}

    /**
     * @return list<Channel>
     */
    public function broadcastOn(): array
    {
        $others = $this->room->people()
            ->reject(fn (User $person) => $person->id === $this->message->user_id)
            ->map(fn (User $person) => new PrivateChannel("App.Models.User.{$person->id}"))
            ->values()
            ->all();

        return [new PresenceChannel("chats.{$this->room->ref_id}"), ...$others];
    }

    public function broadcastAs(): string
    {
        return 'chat.message';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['room' => $this->room->ref_id, 'message' => $this->message->toChat()];
    }
}
