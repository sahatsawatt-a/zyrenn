<?php

namespace App\Support\Workflow\Inbound;

use App\Models\Workflow\WorkflowListener;
use App\Support\Workflow\Ingest;
use App\Support\Workflow\Listeners;
use App\Support\Workflow\RunStatus;
use Cron\CronExpression;
use DateTimeZone;
use Throwable;

/**
 * Times come round, every minute (workflows:schedules): each switched-on
 * schedule that is due starts a run -- once for that minute, whatever
 * happens, since the minute is its id. One whose last run is still going
 * lets it finish, and this time is skipped.
 */
final class Schedules
{
    /**
     * @return int how many runs were started
     */
    public static function tick(): int
    {
        $started = 0;

        foreach (Listeners::forKind('schedule') as $listener) {
            try {
                $started += self::due($listener);
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $started;
    }

    private static function due(WorkflowListener $listener): int
    {
        $settings = $listener->settings ?? [];
        $zone = $settings['timezone'] ?? '';
        $zone = in_array($zone, DateTimeZone::listIdentifiers(), true) ? $zone : (string) config('app.timezone');
        $cron = (string) ($settings['cron'] ?? '');

        if (! CronExpression::isValidExpression($cron)) {
            return 0;
        }

        $now = now($zone)->startOfMinute();

        if (! (new CronExpression($cron))->isDue($now)) {
            return 0;
        }

        $going = $listener->workflow->runs()
            ->where('trigger_node_id', $listener->node_id)
            ->whereIn('status', [RunStatus::Queued->value, RunStatus::Running->value, RunStatus::Waiting->value])
            ->exists();

        if ($going) {
            return 0;
        }

        return Ingest::fire(
            "schedule:{$listener->workflow_id}:{$listener->node_id}",
            $now->utc()->format('Y-m-d H:i'),
            ['service' => 'schedule', 'type' => 'time', 'at' => $now->toIso8601String(), 'timezone' => $zone],
            collect([$listener]),
        );
    }
}
