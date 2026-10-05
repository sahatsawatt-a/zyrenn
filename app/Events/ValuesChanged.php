<?php

namespace App\Events;

use App\Models\Note\Note;
use App\Support\Live\Collab;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * Something a note's live values read -- a trip, a table -- changed: whoever
 * has the note open asks for them again.
 */
class ValuesChanged implements ShouldBroadcastNow
{
    use InteractsWithSockets;

    public function __construct(public Note $note) {}

    public function broadcastOn(): PresenceChannel
    {
        return new PresenceChannel(Collab::documentName($this->note));
    }

    public function broadcastAs(): string
    {
        return 'values.changed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [];
    }
}
