<?php

namespace App\Jobs;

use App\Support\Workflow\Engine;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\TimeoutExceededException;
use Throwable;

/**
 * One step of a workflow run, for a worker to run. Written in the same
 * transaction that marks the step queued (App\Support\Workflow\Engine).
 *
 * A workflow retries its own steps, by its own rules; the job itself may be
 * handed out a second time only because the worker that took it died. That
 * second copy runs the step if it was never taken, and otherwise does
 * nothing -- a step left running is for the sweep (Engine::recover).
 */
class RunWorkflowStep implements ShouldQueue
{
    use Queueable;

    /** Kept under the queue's retry_after, so a step is never handed out twice while it runs. */
    public int $timeout = 150;

    public int $tries = 2;

    /** A step that runs out of time has failed; it is not tried again by the queue. */
    public bool $failOnTimeout = true;

    public function __construct(public int $nodeRunId) {}

    public function handle(): void
    {
        Engine::runStep($this->nodeRunId);
    }

    /**
     * The job gave up. When it ran out of time the step was its own, and
     * failed; when it was turned away as a second copy, the step may still
     * be running elsewhere, and is failed only once that worker's hold on it
     * has lapsed.
     */
    public function failed(?Throwable $exception): void
    {
        Engine::abandon(
            $this->nodeRunId,
            $exception instanceof TimeoutExceededException
                ? 'It ran out of time ('.$this->timeout.' seconds).'
                : 'Its worker stopped before it finished.',
            mine: $exception instanceof TimeoutExceededException,
        );
    }
}
