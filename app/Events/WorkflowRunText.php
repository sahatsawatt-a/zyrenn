<?php

namespace App\Events;

use App\Models\Workflow\WorkflowNodeRun;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * An AI step's answer as it is written, told to everyone with its workflow
 * open: the words since the last message, and where in the answer they
 * start, so a missed message shows as a gap rather than as wrong text. Never
 * saved -- the step's output, once it ends, is the answer.
 */
class WorkflowRunText implements ShouldBroadcastNow
{
    public function __construct(
        public string $workflow,
        public string $run,
        public WorkflowNodeRun $step,
        public int $at,
        public string $text,
    ) {}

    public function broadcastOn(): PresenceChannel
    {
        return new PresenceChannel("workflows.{$this->workflow}");
    }

    public function broadcastAs(): string
    {
        return 'run.text';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'run' => $this->run,
            'node_id' => $this->step->node_id,
            'attempt' => $this->step->attempt,
            'at' => $this->at,
            'text' => $this->text,
        ];
    }
}
