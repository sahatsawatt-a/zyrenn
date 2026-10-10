<?php

namespace App\Support\Workflow;

use App\Models\AuditEvent;
use App\Models\Workflow\InboundEvent;
use App\Models\Workflow\WorkflowRun;
use Illuminate\Support\Collection;

/**
 * Letting go of what is old, a little at a time so no one table is locked
 * for long: runs that ended -- with their steps, logs and sends -- what came
 * in by webhook, and the record of who changed what. A run still going or
 * waiting is kept however old it is.
 */
final class Retention
{
    private const BATCH = 500;

    /**
     * @return array{runs: int, events: int, audit: int}
     */
    public static function prune(): array
    {
        $runs = self::inBatches(fn () => WorkflowRun::query()
            ->whereIn('status', [RunStatus::Succeeded->value, RunStatus::Failed->value, RunStatus::Cancelled->value])
            ->where('finished_at', '<', now()->subDays(self::days('keep_runs_days', 30)))
            ->limit(self::BATCH)
            ->pluck('id'), fn ($ids) => WorkflowRun::whereKey($ids)->delete());

        $events = self::inBatches(fn () => InboundEvent::query()
            ->where('received_at', '<', now()->subDays(self::days('keep_events_days', 14)))
            ->limit(self::BATCH)
            ->pluck('id'), fn ($ids) => InboundEvent::whereKey($ids)->delete());

        $audit = self::inBatches(fn () => AuditEvent::query()
            ->where('created_at', '<', now()->subDays(self::days('keep_audit_days', 365)))
            ->limit(self::BATCH)
            ->pluck('id'), fn ($ids) => AuditEvent::whereKey($ids)->delete());

        return ['runs' => $runs, 'events' => $events, 'audit' => $audit];
    }

    private static function days(string $key, int $usual): int
    {
        return max(1, (int) config("services.workflows.{$key}", $usual));
    }

    /**
     * @param  callable(): Collection<int, int>  $next
     * @param  callable(Collection<int, int>): int  $delete
     */
    private static function inBatches(callable $next, callable $delete): int
    {
        $total = 0;

        while (($ids = $next())->isNotEmpty()) {
            $total += $delete($ids);
        }

        return $total;
    }
}
