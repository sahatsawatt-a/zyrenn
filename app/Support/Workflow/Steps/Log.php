<?php

namespace App\Support\Workflow\Steps;

use App\Support\Workflow\Template;

/**
 * Log: writes a line in the run's log, and passes on what it was given.
 */
final class Log implements Step
{
    public function run(StepContext $step): StepResult
    {
        $step->log((string) ($step->setting('level') ?? 'info'), Template::text($step->template('message')));

        return new StepResult($step->input);
    }
}
