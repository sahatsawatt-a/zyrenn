<?php

namespace App\Events;

use App\Models\Board\Board;
use App\Models\Prototype\PrototypeComment;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * A comment on a prototype was made, changed or made a ticket, told to
 * everyone who has the prototype open -- on its board or in Play -- on the
 * board's own channel. Small enough to carry whole.
 */
class PrototypeCommentChanged implements ShouldBroadcastNow
{
    use InteractsWithSockets;

    public function __construct(
        public Board $board,
        public PrototypeComment $comment,
    ) {}

    public function broadcastOn(): PresenceChannel
    {
        return new PresenceChannel("boards.{$this->board->ref_id}");
    }

    public function broadcastAs(): string
    {
        return 'prototype.comment';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['comment' => $this->comment->loadMissing(['user:id,name', 'ticket:id,number,status'])->shown()];
    }
}
