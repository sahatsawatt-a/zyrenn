<?php

namespace App\Support\Workflow;

use App\Models\Workflow\WorkflowSeen;
use App\Models\Workflow\WorkflowVersion;
use App\Support\Workflow\Steps\StepContext;

/**
 * What a step has given out before, for the steps that give out only what
 * is new: a feed read, "Only new items". Each thing is known by a key -- an
 * item's id, its link -- kept as a hash, for that step of that workflow, the
 * newest MOST of them. A run that gives a thing out again (a step run again
 * after its worker died) still has it as new. A test run reads what was seen
 * but keeps nothing, so trying a draft never uses anything up.
 */
final class Seen
{
    /** The most kept for one step: the oldest beyond it are let go. */
    public const MOST = 5000;

    /**
     * Of these keys, the ones the step has not given out before, in order and
     * each once.
     *
     * @param  list<string>  $keys
     * @return list<string>
     */
    public static function unseen(StepContext $step, array $keys): array
    {
        $hashes = array_map('sha1', $keys);
        $known = [];

        foreach (array_chunk(array_unique($hashes), 500) as $some) {
            WorkflowSeen::query()
                ->where('workflow_id', $step->run->workflow_id)
                ->where('node_id', (string) $step->node['id'])
                ->whereIn('hash', $some)
                ->where(fn ($query) => $query->whereNull('workflow_run_id')->orWhere('workflow_run_id', '!=', $step->run->id))
                ->pluck('hash')
                ->each(function (string $hash) use (&$known) {
                    $known[$hash] = true;
                });
        }

        $new = [];

        foreach ($keys as $at => $key) {
            if (! isset($known[$hashes[$at]])) {
                $known[$hashes[$at]] = true;
                $new[] = $key;
            }
        }

        return $new;
    }

    /**
     * Remember these as given out -- on a live run; a test run keeps nothing.
     *
     * @param  list<string>  $keys
     */
    public static function keep(StepContext $step, array $keys): void
    {
        if ($keys === [] || $step->run->version->kind === WorkflowVersion::TEST) {
            return;
        }

        $now = now()->format('Y-m-d H:i:s.v');
        $workflow = $step->run->workflow_id;
        $node = (string) $step->node['id'];

        foreach (array_chunk(array_values(array_unique(array_map('sha1', $keys))), 500) as $some) {
            WorkflowSeen::query()->insertOrIgnore(array_map(fn (string $hash) => [
                'workflow_id' => $workflow,
                'node_id' => $node,
                'hash' => $hash,
                'workflow_run_id' => $step->run->id,
                'seen_at' => $now,
            ], $some));
        }

        $oldest = WorkflowSeen::query()
            ->where('workflow_id', $workflow)
            ->where('node_id', $node)
            ->orderByDesc('id')
            ->skip(self::MOST)
            ->value('id');

        if ($oldest !== null) {
            WorkflowSeen::query()->where('workflow_id', $workflow)->where('node_id', $node)->where('id', '<=', $oldest)->delete();
        }
    }
}
