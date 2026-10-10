<?php

namespace App\Support\Workflow\Steps;

use App\Models\Workflow\WorkflowNodeRun;
use App\Support\Workflow\NodeStatus;

/**
 * Merge: ways brought back together. Waiting for all, it gives out what
 * each way that came gave, by its step's key; carrying on with the first,
 * what that one gave. Either way, a way not taken gives nothing.
 */
final class MergeStep implements Step
{
    public function run(StepContext $step): StepResult
    {
        /** @var array{edges: list<array{source: string, sourceHandle: string, target: string}>} $definition */
        $definition = $step->run->version->definition;
        $ways = collect($definition['edges'])->where('target', $step->node['id']);

        // What came, in the order it arrived
        $came = $step->run->steps()
            ->whereIn('node_id', $ways->pluck('source')->unique()->all())
            ->where('status', NodeStatus::Succeeded->value)
            ->orderBy('finished_at')
            ->orderBy('id')
            ->get()
            ->filter(fn (WorkflowNodeRun $parent) => $ways->contains(fn (array $edge) => $edge['source'] === $parent->node_id && $edge['sourceHandle'] === $parent->handle))
            ->values();

        if ($step->setting('wait') === 'first') {
            return new StepResult($came->first()?->output);
        }

        return new StepResult($came->mapWithKeys(fn (WorkflowNodeRun $parent) => [$parent->node_key => $parent->output])->all());
    }
}
