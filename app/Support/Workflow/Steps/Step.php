<?php

namespace App\Support\Workflow\Steps;

/**
 * What a kind of step does when it runs. It is given its settings and what
 * came before it, and answers with what it gives out and which way the run
 * goes on -- or throws StepFailed, saying why.
 *
 * A step may be run more than once for the same run (a worker that dies and
 * is replaced), so one that changes something outside says whether that is
 * safe to repeat.
 */
interface Step
{
    /**
     * @throws StepFailed
     */
    public function run(StepContext $step): StepResult;
}
