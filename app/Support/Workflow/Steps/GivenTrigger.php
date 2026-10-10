<?php

namespace App\Support\Workflow\Steps;

/**
 * A trigger that fired by itself -- a chat message, a time, a webhook, a
 * bot's message: gives out what came in, in the one shape every service's
 * comes in (App\Support\Workflow\Inbound\Envelope).
 */
final class GivenTrigger implements Step
{
    public function run(StepContext $step): StepResult
    {
        return new StepResult($step->run->trigger_data ?? []);
    }
}
