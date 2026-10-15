<?php

namespace App\Support\Workflow;

use App\Models\Workflow\InboundEvent;
use App\Models\Workflow\WorkflowListener;
use App\Models\Workflow\WorkflowRun;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Where everything that starts workflows by itself comes in: a webhook, a
 * bot's message, a chat message, a time come round. In one transaction it
 * is kept by its sender's own id -- so the same thing sent twice starts
 * nothing the second time -- and a run is started for each workflow that
 * listens for it. Whoever sent it is answered only after that commits, and
 * nothing slow happens here: the runs themselves are for the workers.
 */
final class Ingest
{
    /**
     * @param  string  $source  where it came from, e.g. "telegram:<connection ref>"
     * @param  string  $eventId  the sender's own id for it
     * @param  array<string, mixed>  $envelope  what the triggers give out
     * @param  Collection<int, WorkflowListener>  $listeners
     * @param  (Closure(WorkflowListener): bool)|null  $wants  whether a listener's own settings take it
     * @param  WorkflowRun|null  $origin  the run that caused it, when one did
     * @param  (Closure(WorkflowRun): void)|null  $started  told of each run started, for a caller that waits on it
     * @return int how many runs were started (0 for something already seen)
     */
    public static function fire(
        string $source,
        string $eventId,
        array $envelope,
        Collection $listeners,
        ?Closure $wants = null,
        ?WorkflowRun $origin = null,
        ?Closure $started = null,
    ): int {
        return DB::transaction(function () use ($source, $eventId, $envelope, $listeners, $wants, $origin, $started) {
            $kept = DB::table('inbound_events')->insertOrIgnore([
                'source' => mb_substr($source, 0, 120),
                'event_id' => mb_substr($eventId, 0, 191),
                'envelope' => json_encode($envelope),
                'received_at' => now()->format('Y-m-d H:i:s.v'),
            ]);

            // Already seen: whoever sent it again is answered all the same
            if ($kept === 0) {
                return 0;
            }

            // Runs that set each other off end somewhere
            if ($origin !== null && $origin->depth + 1 > Engine::MAX_DEPTH) {
                return 0;
            }

            $count = 0;

            foreach ($listeners as $listener) {
                if ($wants !== null && ! $wants($listener)) {
                    continue;
                }

                $run = Engine::start(
                    $listener->workflow,
                    $listener->version,
                    null,
                    substr($listener->kind === 'chat_room' ? 'chat' : $listener->kind, 0, 20),
                    $listener->node_id,
                    $envelope,
                    $origin,
                );
                $count++;

                if ($started !== null) {
                    $started($run);
                }
            }

            InboundEvent::where('source', $source)->where('event_id', $eventId)->update(['runs' => $count]);

            return $count;
        });
    }
}
