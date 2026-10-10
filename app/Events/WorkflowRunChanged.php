<?php

namespace App\Events;

use App\Models\Workflow\WorkflowNodeRun;
use App\Models\Workflow\WorkflowRun;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * A run moved on, told to everyone with its workflow open. Only where it
 * and each of its steps stand -- enough to colour the canvas -- never what a
 * step was given or gave out; the editor asks for that. Each message is the
 * whole picture as saved when it was sent, with the time of its newest
 * change (seq), so one that arrives late is simply older, and a missed one
 * is made up by the next.
 */
class WorkflowRunChanged implements ShouldBroadcastNow
{
    public function __construct(public WorkflowRun $run) {}

    public function broadcastOn(): PresenceChannel
    {
        return new PresenceChannel("workflows.{$this->run->workflow->ref_id}");
    }

    public function broadcastAs(): string
    {
        return 'run.changed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $steps = $this->run->steps()->orderBy('id')->get(['node_id', 'status', 'handle', 'updated_at']);
        $newest = max(
            $this->run->updated_at?->getTimestampMs() ?? 0,
            ...$steps->map(fn (WorkflowNodeRun $step) => $step->updated_at?->getTimestampMs() ?? 0)->all(),
        );

        return [
            'run' => $this->run->ref_id,
            'status' => $this->run->status->value,
            'seq' => $newest,
            'steps' => $steps->map(fn (WorkflowNodeRun $step) => [
                'node_id' => $step->node_id,
                'status' => $step->status->value,
                'handle' => $step->handle,
            ])->all(),
        ];
    }
}
