<?php

namespace App\Support\Workflow\Steps;

/**
 * Answer the webhook: what a run started by a webhook set to wait for its
 * answer gives back to whoever called it -- a status, and a body worked out
 * here. The webhook answers with the first of these to be done; in a run
 * started some other way it only gives out what it would have answered.
 */
final class WebhookAnswer implements Step
{
    public function run(StepContext $step): StepResult
    {
        $status = (int) ($step->setting('status') ?: 200);

        if ($status < 200 || $status > 599) {
            throw new StepFailed("{$status} is not a status to answer with: 200 to 599.");
        }

        return new StepResult(['status' => $status, 'body' => $step->template('body')]);
    }
}
