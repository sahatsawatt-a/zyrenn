<?php

namespace App\Support\Workflow\Steps;

use App\Support\Formula\Formula;
use App\Support\Formula\FormulaError;
use App\Support\Workflow\NodeTypes;
use App\Support\Workflow\Template;

/**
 * Switch: tries its ways in order and carries on down the first whose
 * formula is true -- "otherwise" when none is -- passing on what it was
 * given.
 */
final class SwitchStep implements Step
{
    public function run(StepContext $step): StepResult
    {
        foreach ((array) $step->setting('ways') as $way) {
            try {
                $yes = StepContext::truthy(Template::value(Formula::evaluate((string) $way['value'], $step->scope)));
            } catch (FormulaError $e) {
                throw new StepFailed("\"{$way['name']}\": {$e->getMessage()}");
            }

            $step->workedOut($way['name'], $yes);

            if ($yes) {
                return new StepResult($step->input, $way['name']);
            }
        }

        return new StepResult($step->input, NodeTypes::OTHERWISE);
    }
}
