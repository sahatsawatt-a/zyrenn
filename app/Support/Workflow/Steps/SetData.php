<?php

namespace App\Support\Workflow\Steps;

/**
 * Set data: gives out the values it names, each worked out from what came
 * before. A value that is a single {{ formula }} keeps its kind -- a number,
 * a list, a record.
 */
final class SetData implements Step
{
    public function run(StepContext $step): StepResult
    {
        $output = [];

        foreach ((array) $step->setting('fields') as $field) {
            $output[$field['name']] = $step->template($field['name'], $field['value']);
        }

        return new StepResult($output);
    }
}
