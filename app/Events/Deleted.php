<?php

namespace App\Events;

use App\Models\Board\Board;
use App\Models\Note\Note;
use App\Support\Live\Collab;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * A note or board was deleted, told to whoever still has it open so their
 * page closes rather than editing into nothing.
 */
class Deleted implements ShouldBroadcastNow
{
    use InteractsWithSockets;

    public function __construct(public Note|Board $thing) {}

    public function broadcastOn(): PresenceChannel
    {
        return new PresenceChannel(Collab::documentName($this->thing));
    }

    public function broadcastAs(): string
    {
        return 'deleted';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [];
    }
}
