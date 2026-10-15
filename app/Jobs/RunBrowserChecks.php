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
 */
class RunBrowserChecks implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(public int $runId) {}

    public function handle(): void
    {
        $run = BrowserRun::with('suite')->find($this->runId);

        if ($run !== null) {
            SuiteRun::carryOut($run);
        }
    }

    public function failed(?Throwable $problem): void
    {
        BrowserRun::whereKey($this->runId)->whereIn('state', [BrowserRun::WAITING, BrowserRun::RUNNING])
            ->update(['state' => BrowserRun::FAILED, 'ended_at' => now()]);
    }
}
