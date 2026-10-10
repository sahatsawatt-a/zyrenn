<?php

namespace App\Support\Workflow;

use App\Models\Project;
use App\Models\User;
use App\Models\Workflow\WorkflowDelivery;
use App\Models\Workflow\WorkflowNodeRun;
use App\Models\Workflow\WorkflowRun;
use Illuminate\Database\Eloquent\Builder;

/**
 * How an owner's workflows have been doing lately -- or everyone's, for
 * whoever runs the app: how many runs and how they ended, how long they
 * took, how long steps waited for a worker, what was tried again, what may
 * not have gone out, and what is stuck now.
 */
final class Health
{
    /** The most runs and steps timings are worked out from. */
    private const SAMPLE = 5000;

    /**
     * @return array{
     *     hours: int,
     *     runs: array{total: int, succeeded: int, failed: int, cancelled: int, going: int, waiting: int},
     *     duration_ms: array{p50: int|null, p95: int|null},
     *     queue_ms: array{p50: int|null, p95: int|null},
     *     retried: int,
     *     unsure: int,
     *     stuck: int,
     *     failures: array<int, array{workflow: string, title: string, run: string, error: string|null, at: string|null}>
     * }
     */
    public static function of(User|Project|null $owner, int $hours = 24): array
    {
        $since = now()->subHours($hours);
        $runs = fn (): Builder => self::owned(WorkflowRun::query(), $owner);
        $steps = fn (): Builder => WorkflowNodeRun::query()->whereHas('run', fn (Builder $run) => self::owned($run, $owner));

        $counts = $runs()->where('created_at', '>=', $since)
            ->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')
            ->map(fn ($n) => (int) $n);

        $durations = $runs()->where('created_at', '>=', $since)
            ->whereNotNull('started_at')->whereNotNull('finished_at')
            ->latest('id')->limit(self::SAMPLE)->get(['started_at', 'finished_at'])
            ->map(fn (WorkflowRun $run) => (int) $run->started_at?->diffInMilliseconds($run->finished_at))
            ->values()
            ->all();

        $waits = $steps()->where('created_at', '>=', $since)
            ->whereNotNull('queued_at')->whereNotNull('started_at')
            ->latest('id')->limit(self::SAMPLE)->get(['queued_at', 'started_at'])
            ->map(fn (WorkflowNodeRun $step) => max(0, (int) $step->queued_at?->diffInMilliseconds($step->started_at, false)))
            ->values()
            ->all();

        return [
            'hours' => $hours,
            'runs' => [
                'total' => (int) $counts->sum(),
                'succeeded' => $counts['succeeded'] ?? 0,
                'failed' => $counts['failed'] ?? 0,
                'cancelled' => $counts['cancelled'] ?? 0,
                'going' => ($counts['queued'] ?? 0) + ($counts['running'] ?? 0),
                'waiting' => $counts['waiting'] ?? 0,
            ],
            'duration_ms' => self::percentiles($durations),
            'queue_ms' => self::percentiles($waits),
            'retried' => $steps()->where('created_at', '>=', $since)->where('attempt', '>', 1)->count(),
            'unsure' => WorkflowDelivery::where('status', 'unknown')
                ->whereHas('step.run', fn (Builder $run) => self::owned($run, $owner))->count(),
            'stuck' => $steps()->where(fn (Builder $q) => $q
                ->where(fn (Builder $q) => $q->where('status', NodeStatus::Queued->value)->where('queued_at', '<', now()->subMinutes(Alerts::STUCK_MINUTES)))
                ->orWhere(fn (Builder $q) => $q->where('status', NodeStatus::Running->value)->where('lease_until', '<', now())))
                ->count(),
            'failures' => $runs()->where('status', RunStatus::Failed->value)->where('created_at', '>=', $since)
                ->with('workflow:id,ref_id,title')->latest('id')->limit(5)->get()
                ->map(fn (WorkflowRun $run) => [
                    'workflow' => $run->workflow->ref_id,
                    'title' => $run->workflow->title,
                    'run' => $run->ref_id,
                    'error' => $run->error,
                    'at' => $run->finished_at?->toIso8601String(),
                ])->values()->all(),
        ];
    }

    /**
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<T>  $runs
     * @return Builder<T>
     */
    private static function owned(Builder $runs, User|Project|null $owner): Builder
    {
        return match (true) {
            $owner instanceof Project => $runs->whereHas('workflow', fn (Builder $w) => $w->where('project_id', $owner->id)),
            $owner instanceof User => $runs->whereHas('workflow', fn (Builder $w) => $w->where('user_id', $owner->id)->whereNull('project_id')),
            default => $runs,
        };
    }

    /**
     * @param  array<int, int>  $values
     * @return array{p50: int|null, p95: int|null}
     */
    private static function percentiles(array $values): array
    {
        if ($values === []) {
            return ['p50' => null, 'p95' => null];
        }

        $values = array_values($values);
        sort($values);
        $at = fn (float $p) => $values[(int) min(count($values) - 1, floor($p * (count($values) - 1) + 0.5))];

        return ['p50' => $at(0.5), 'p95' => $at(0.95)];
    }
}
