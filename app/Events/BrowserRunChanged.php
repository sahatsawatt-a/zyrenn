<?php

namespace App\Events;

use App\Models\Browser\BrowserRun;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * A run of browser checks moved on -- started, a check or sign-in done, asked
 * to stop, ended -- told to everyone with its suite open. Where it stands and
 * a line for each check, small enough for any socket: no steps and no
 * pictures, which the page asks for once there is something new to show.
 * Each message is the whole of it as saved when it was sent, numbered
 * (seq), so one that arrives late is simply older and a missed one is made
 * up by the next.
 */
class BrowserRunChanged implements ShouldBroadcastNow
{
    /**
     * Where it stands, read as the event is made: the run goes on changing after.
     *
     * @var array<string, mixed>
     */
    private readonly array $told;

    private readonly string $suite;

    public function __construct(BrowserRun $run)
    {
        $this->suite = $run->suite->ref_id;
        $this->told = self::where($run);
    }

    public function broadcastOn(): PresenceChannel
    {
        return new PresenceChannel("checks.{$this->suite}");
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
        return $this->told;
    }

    /**
     * @return array<string, mixed>
     */
    private static function where(BrowserRun $run): array
    {
        return [
            'run' => $run->ref_id,
            'state' => $run->state,
            // Only a try: shown while it is followed, never listed with the suite's runs
            'trial' => (bool) $run->trial,
            'stop_asked' => $run->stopAsked() && ! $run->ended(),
            // When it was sent: one process tells of a run at a time, in order
            'seq' => (int) floor(microtime(true) * 1000),
            // What its row in the list shows, for a run started elsewhere
            'speed' => $run->settings['speed'],
            'record' => (bool) $run->settings['record'],
            'started_by' => $run->started_by,
            'created_at' => $run->created_at?->toIso8601String(),
            // A path: made in a worker, an absolute one would name APP_URL's
            // host rather than the one the page is open on
            'url' => route('checks.runs.show', $run, absolute: false),
            'checks' => array_map(fn (array $check) => [
                'id' => $check['id'],
                'ok' => (bool) $check['ok'],
                'done' => count($check['steps']),
            ], $run->results ?? []),
        ];
    }
}
