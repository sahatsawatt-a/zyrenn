<?php

namespace App\Support\Workflow;

use App\Models\Chat\ChatMessage;
use App\Models\Chat\ChatRoom;
use App\Models\Project;
use App\Models\User;
use App\Models\Workflow\WorkflowNodeRun;
use App\Models\Workflow\WorkflowRun;
use App\Models\Workflow\WorkflowVersion;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Telling someone when a workflow that runs by itself needs them: a run that
 * failed for good, a send that may not have gone out, steps no worker has
 * picked up. Told in a chat of their own, "Workflow alerts", so it comes
 * with the unread count the sidebar already shows.
 *
 * A run is told of once. A workflow failing again and again is told of once
 * in a while, not every time; runs started by hand are never told of -- the
 * one who pressed Run is watching.
 */
final class Alerts
{
    public const ROOM_KEY = 'alerts:workflows';

    /** One workflow's failures, told at most this often. */
    private const QUIET_MINUTES = 30;

    /** Steps waiting this long for a worker are stuck. */
    public const STUCK_MINUTES = 10;

    /**
     * A run that ended failed, once what ended it is saved.
     */
    public static function failed(WorkflowRun $run): void
    {
        DB::afterCommit(function () use ($run) {
            $run = $run->fresh(['workflow', 'version']);

            if ($run === null || ! self::wanted($run) || ! self::quiet("failed:{$run->workflow_id}")) {
                return;
            }

            self::tell($run, "“{$run->workflow->title}” failed: ".($run->error ?? 'a step failed').'.', 'failed');
        });
    }

    /**
     * A step that sent something and can't tell whether it went: someone
     * must say whether to send it again.
     */
    public static function asks(WorkflowNodeRun $step): void
    {
        DB::afterCommit(function () use ($step) {
            $run = $step->run()->with(['workflow', 'version'])->first();

            if ($run === null || $run->version->kind !== WorkflowVersion::PUBLISHED) {
                return;
            }

            self::tell($run, "“{$run->workflow->title}” may not have sent something, and waits for you to say whether to send it again.", 'asks');
        });
    }

    /**
     * Runs with steps no worker has taken for a while: told once each.
     *
     * @return int how many runs were told of
     */
    public static function stuck(): int
    {
        $runs = WorkflowRun::query()
            ->whereNull('alerted_at')
            ->whereIn('status', [RunStatus::Queued->value, RunStatus::Running->value])
            ->whereHas('steps', fn ($steps) => $steps
                ->where('status', NodeStatus::Queued->value)
                ->where('queued_at', '<', now()->subMinutes(self::STUCK_MINUTES)))
            ->with(['workflow', 'version'])
            ->limit(100)
            ->get();

        foreach ($runs as $run) {
            self::tell($run, "“{$run->workflow->title}” has waited over ".self::STUCK_MINUTES.' minutes for a worker to take its next step. Is the queue worker running?', 'stuck');
        }

        return $runs->count();
    }

    /**
     * Whether a run is one to tell of: it ran by itself, from what is
     * published, and no one was told of it yet.
     */
    private static function wanted(WorkflowRun $run): bool
    {
        return $run->alerted_at === null
            && $run->started_by === null
            && $run->trigger !== 'manual'
            // One of many a "For each" started: its step says how each went
            && $run->caller_node_run_id === null
            && $run->version->kind === WorkflowVersion::PUBLISHED;
    }

    /**
     * Whether nothing like it was told lately; if so, it is now.
     */
    private static function quiet(string $what): bool
    {
        return Cache::add("workflows:alerted:{$what}", true, now()->addMinutes(self::QUIET_MINUTES));
    }

    private static function tell(WorkflowRun $run, string $text, string $kind): void
    {
        $to = self::recipient($run);

        if ($to === null) {
            return;
        }

        self::room($to)->messages()->create([
            'role' => ChatMessage::WORKFLOW,
            'content' => $text,
            'meta' => [
                'workflow' => $run->workflow->ref_id,
                'name' => 'Workflow alerts',
                'run' => $run->ref_id,
                'alert' => $kind,
            ],
        ]);

        WorkflowRun::whereKey($run->id)->update(['alerted_at' => now()]);
    }

    /**
     * Who hears of it: whose workflow it is, or for a project's, whoever it
     * acts as -- while they are still in the project.
     */
    private static function recipient(WorkflowRun $run): ?User
    {
        $workflow = $run->workflow;

        if ($workflow->project_id === null) {
            return $workflow->user;
        }

        $who = $run->actor();

        return $who !== null && $workflow->project instanceof Project && $workflow->project->roleOf($who) !== null ? $who : null;
    }

    private static function room(User $user): ChatRoom
    {
        $found = $user->chatRooms()->where('external_key', self::ROOM_KEY)->first();

        if ($found !== null) {
            return $found;
        }

        try {
            $room = new ChatRoom;
            $room->kind = ChatRoom::PERSONAL;
            $room->user_id = $user->id;
            $room->title = 'Workflow alerts';
            $room->external_key = self::ROOM_KEY;
            $room->save();

            return $room;
        } catch (UniqueConstraintViolationException) {
            return $user->chatRooms()->where('external_key', self::ROOM_KEY)->firstOrFail();
        }
    }
}
