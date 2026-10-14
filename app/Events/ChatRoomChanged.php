<?php

namespace App\Events;

use App\Models\Chat\ChatRoom;
use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * A customer's chat was opened, closed, taken by someone or tagged. Whoever
 * has it open hears it on the room's channel; everyone in its project hears
 * it on their own, so their inbox and its count follow.
 */
class ChatRoomChanged implements ShouldBroadcastNow
{
    use InteractsWithSockets;

    public function __construct(public ChatRoom $room) {}

    /**
     * @return list<Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PresenceChannel("chats.{$this->room->ref_id}"),
            ...$this->room->people()->map(fn (User $person) => new PrivateChannel("App.Models.User.{$person->id}"))->all(),
        ];
    }

    public function broadcastAs(): string
    {
        return 'chat.room';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['room' => $this->room->ref_id, ...$this->room->desk()];
    }
}
