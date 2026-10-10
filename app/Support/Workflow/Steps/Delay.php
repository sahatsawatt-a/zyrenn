<?php

namespace App\Support\Workflow\Steps;

/**
 * Delay: the first time, works out how long and waits; when the time comes,
 * passes on what it was given. No worker is held meanwhile -- the step is
 * run again by a job put off until then.
 */
final class Delay implements Step
{
    private const MAX_SECONDS = 30 * 24 * 3600;

    private const SECONDS = ['seconds' => 1, 'minutes' => 60, 'hours' => 3600, 'days' => 86400];

    public function run(StepContext $step): StepResult
    {
        if ($step->waited !== null) {
            return new StepResult($step->input);
        }

        $amount = $step->template('amount');

        if (! is_numeric($amount) || (float) $amount < 0) {
            throw new StepFailed('How long should be a number of '.$step->setting('unit').', not '.json_encode($amount).'.');
        }

        $seconds = (int) round((float) $amount * (self::SECONDS[$step->setting('unit')] ?? 60));

        if ($seconds > self::MAX_SECONDS) {
            throw new StepFailed('A delay can be at most 30 days.');
        }

        return StepResult::wait('delay', now()->addSeconds($seconds));
    }
}
