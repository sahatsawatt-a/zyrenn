<?php

namespace App\Jobs;

use App\Models\Browser\BrowserRun;
use App\Support\Browser\SuiteRun;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Runs a suite's checks, one after another, away from whoever asked: a slow
 * run filmed for a demo takes a minute or more. What each step did is written
 * down as each check ends, so the run can be followed as it goes; pictures and
 * films kept in the Drive go in a folder of the run's own.
 *
 * It has a queue and a worker of its own (`checks`, the checks-worker
 * service): a filmed demo can take ten minutes, and workflow steps -- a
 * customer waiting for an answer -- must not wait behind it.
 */
class RunBrowserChecks implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 1;

    public const QUEUE = 'checks';

    public function __construct(public int $runId)
    {
        $this->onQueue(self::QUEUE);
    }

    public function handle(): void
    {
        $run = BrowserRun::with('suite')->find($this->runId);

        if ($run !== null) {
            SuiteRun::carryOut($run);
        }
    }

    public function failed(?Throwable $problem): void
    {
        // One someone asked to stop ends stopped, whatever brought it down
        BrowserRun::whereKey($this->runId)->whereIn('state', [BrowserRun::WAITING, BrowserRun::RUNNING])
            ->whereNotNull('stop_asked_at')
            ->update(['state' => BrowserRun::STOPPED, 'ended_at' => now()]);
        BrowserRun::whereKey($this->runId)->whereIn('state', [BrowserRun::WAITING, BrowserRun::RUNNING])
            ->update(['state' => BrowserRun::FAILED, 'ended_at' => now()]);
    }
}
