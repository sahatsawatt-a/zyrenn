<?php

namespace App\Support\Workflow\Steps;

/**
 * Run by hand: gives out what the run was started with, or, for a test run
 * from the editor, the test data written in its settings.
 */
final class ManualTrigger implements Step
{
    public function run(StepContext $step): StepResult
    {
        if ($step->run->trigger_data !== null) {
            return new StepResult($step->run->trigger_data);
        }

        $payload = trim((string) $step->setting('payload'));

        if ($payload === '') {
            return new StepResult([]);
        }

        $data = json_decode($payload, true);

        if (! is_array($data)) {
            throw new StepFailed('The test data is not a JSON object.');
        }

        return new StepResult($data);
    }
}
