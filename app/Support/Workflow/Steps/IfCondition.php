<?php

namespace App\Support\Workflow\Steps;

/**
 * If: works out its condition and carries on down "true" or "false",
 * passing on what it was given.
 */
final class IfCondition implements Step
{
    public function run(StepContext $step): StepResult
    {
        $answer = StepContext::truthy($step->formula('condition'));

        return new StepResult($step->input, $answer ? 'true' : 'false');
    }
}
