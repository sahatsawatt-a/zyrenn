<?php

namespace App\Support\Workflow\Steps;

use App\Models\Workflow\WorkflowRun;
use App\Support\Workflow\Engine;
use App\Support\Workflow\NodeStatus;
use App\Support\Workflow\References;

/**
 * For each: another workflow run once for each item of a list -- a run of
 * its own each, started by its "When run by another workflow" trigger --
 * a few at a time. It waits while they go; each that ends wakes it to start
 * the next, and once all have ended it gives out how each went.
 *
 * What it has started is counted from the runs themselves, never from what
 * it kept, so a worker dying partway never starts one twice.
 */
final class ForEachStep implements Step
{
    /** The most items one step runs for. */
    public const MAX_ITEMS = 100;

    /** The most it lets go at once. */
    private const MOST_AT_ONCE = 20;

    public function run(StepContext $step): StepResult
    {
        $target = References::callable($step->owner(), (string) $step->setting('workflow'))
            ?? throw new StepFailed('Choose one of this workflow\'s own published workflows that starts "When run by another workflow".');

        if ($target->id === $step->run->workflow_id) {
            throw new StepFailed('A workflow can\'t run itself this way.');
        }

        if ($step->run->depth + 1 > Engine::MAX_DEPTH) {
            throw new StepFailed('Runs that start runs go at most '.Engine::MAX_DEPTH.' deep.');
        }

        // The list, worked out on its first go and kept while it waits
        $items = $step->waited['items'] ?? $this->items($step);
        $atOnce = max(1, min(self::MOST_AT_ONCE, (int) ($step->setting('at_once') ?: 5)));

        $runs = WorkflowRun::where('caller_node_run_id', $step->nodeRun->id)->orderBy('caller_index')->get();
        $going = $runs->filter(fn (WorkflowRun $run) => ! $run->status->isFinal())->count();
        $started = $runs->count();

        while ($going < $atOnce && $started < count($items)) {
            Engine::start(
                $target,
                $target->publishedVersion,
                null,
                'call',
                References::callTrigger($target),
                [
                    'service' => 'workflow',
                    'item' => $items[$started],
                    'index' => $started + 1,
                    'of' => count($items),
                    'from' => ['workflow' => $step->run->workflow->ref_id, 'run' => $step->run->ref_id],
                ],
                $step->run,
                $step->nodeRun,
                $started,
            );
            $started++;
            $going++;
        }

        // Woken by each that ends; once a minute too, in case a wake was missed
        if ($going > 0 || $started < count($items)) {
            return StepResult::wait('children', now()->addMinute(), ['items' => $items]);
        }

        $runs = WorkflowRun::where('caller_node_run_id', $step->nodeRun->id)->orderBy('caller_index')->get();

        return new StepResult([
            'results' => $runs->map(fn (WorkflowRun $run) => [
                'item' => $items[$run->caller_index] ?? null,
                'status' => $run->status->value,
                'run' => $run->ref_id,
                'output' => self::lastOutput($run),
                'error' => $run->error,
            ])->values()->all(),
            'count' => $runs->count(),
            'succeeded' => $runs->where('status.value', 'succeeded')->count(),
            'failed' => $runs->filter(fn (WorkflowRun $run) => $run->status->value !== 'succeeded')->count(),
        ]);
    }

    /**
     * @return list<mixed>
     */
    private function items(StepContext $step): array
    {
        if (trim((string) $step->setting('items')) === '') {
            return [$step->input];
        }

        $items = $step->formula('items');

        if (! is_array($items) || ! array_is_list($items)) {
            throw new StepFailed('Items should be a list, like node("find").rows.');
        }

        if (count($items) > self::MAX_ITEMS) {
            throw new StepFailed('Items has '.count($items).' in it; a step runs for at most '.self::MAX_ITEMS.'.');
        }

        return $items;
    }

    /**
     * What a run's last step to finish gave out.
     */
    private static function lastOutput(WorkflowRun $run): mixed
    {
        return $run->steps()
            ->where('status', NodeStatus::Succeeded->value)
            ->orderByDesc('finished_at')
            ->orderByDesc('id')
            ->first()?->output;
    }
}
