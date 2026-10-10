<?php

namespace App\Events;

use App\Models\Workflow\Workflow;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * A workflow's draft was saved, published or deleted, told to everyone else
 * who has it open. Not the draft itself, just its new revision and who made
 * it: an editor with nothing unsaved loads it again; one with changes of its
 * own finds out on its next save, which asks which to keep.
 */
class WorkflowChanged implements ShouldBroadcastNow
{
    use InteractsWithSockets;

    public function __construct(
        public Workflow $workflow,
        public string $change,
    ) {}

    public function broadcastOn(): PresenceChannel
    {
        return new PresenceChannel("workflows.{$this->workflow->ref_id}");
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
            'revision' => $this->workflow->revision,
            'edited_by' => $this->workflow->editor()->value('name'),
        ];
    }
}
