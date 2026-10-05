<?php

namespace App\Events;

use App\Models\Map\Trip;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * A trip was saved or deleted, told to everyone else who has it open. Not
 * the trip itself -- a long one is more than a socket message should carry
 * -- just its new revision and who made it: a page with nothing unsaved
 * loads it again; one with changes of its own asks which to keep.
 *
 * - "saved": it has a new revision
 * - "deleted": the trip itself is gone
 */
class TripChanged implements ShouldBroadcastNow
{
    use InteractsWithSockets;

    public function __construct(
        public Trip $trip,
        public string $change,
    ) {}

    public function broadcastOn(): PresenceChannel
    {
        return new PresenceChannel("trips.{$this->trip->ref_id}");
    }

    public function broadcastAs(): string
    {
        return 'changed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'change' => $this->change,
            'revision' => $this->trip->revision,
            'edited_by' => $this->trip->editor()->value('name'),
        ];
    }
}
