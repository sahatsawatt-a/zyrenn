<?php

namespace App\Support\Workflow\Steps;

use App\Support\Workflow\Template;

/**
 * Ask for approval: waits for someone who can edit the workflow to answer,
 * then carries on down "approved" or "rejected", giving out who answered
 * and how. Left unanswered past its time, it is rejected.
 */
final class Approval implements Step
{
    private const EXPIRES = ['1h' => 3600, '1d' => 86400, '7d' => 604800];

    public function run(StepContext $step): StepResult
    {
        if ($step->waited === null) {
            $expires = self::EXPIRES[$step->setting('expires')] ?? null;

            return StepResult::wait(
                'approval',
                $expires === null ? null : now()->addSeconds($expires),
                ['message' => Template::text($step->template('message'))],
            );
        }

        $answer = $step->waited['answer'] ?? null;
        $approved = ($answer['approved'] ?? false) === true;

        return new StepResult([
            'approved' => $approved,
            'by' => $answer['by'] ?? null,
            'note' => $answer['note'] ?? null,
            'at' => $answer['at'] ?? now()->toIso8601String(),
            // Nobody answered in time
            'expired' => $answer === null,
            'input' => $step->input,
        ], $approved ? 'approved' : 'rejected');
    }
}
