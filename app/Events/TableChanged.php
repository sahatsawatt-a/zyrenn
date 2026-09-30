<?php

namespace App\Events;

use App\Models\Table\Table;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * Something changed in a table, told to everyone else who has it open so
 * their grid follows along. Sent at once: there is no queue worker, and a
 * change that arrives late is worse than none.
 *
 * - "row": a row as it now is, new or changed -- the grid puts it in place
 * - "rows.deleted": rows that are gone, by id
 * - "table": its title and density, as they now are
 * - "reload": its columns changed, and maybe every row; the grid loads it again
 * - "deleted": the table itself is gone
 */
class TableChanged implements ShouldBroadcastNow
{
    use InteractsWithSockets;

    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public Table $table,
        public string $change,
        public array $details = [],
    ) {}

    public function broadcastOn(): PresenceChannel
    {
        return new PresenceChannel("tables.{$this->table->ref_id}");
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
        return ['change' => $this->change, ...$this->details];
    }
}
