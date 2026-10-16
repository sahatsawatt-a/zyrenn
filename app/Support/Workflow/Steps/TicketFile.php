<?php

namespace App\Support\Workflow\Steps;

use App\Models\Ticket\Ticket;

/**
 * File a ticket: a bug or a feature in the project, filed open with the next
 * number there, as whoever the workflow acts as. Given out as it is.
 */
final class TicketFile implements Step
{
    public function run(StepContext $step): StepResult
    {
        $project = TicketSteps::project($step);
        $title = trim((string) $step->template('title'));

        if ($title === '') {
            throw new StepFailed('Its title came out empty.');
        }

        $ticket = TicketSteps::saved(fn () => Ticket::file($project, $step->actor(), [
            'type' => in_array($step->setting('type'), Ticket::TYPES, true) ? $step->setting('type') : Ticket::BUG,
            'title' => mb_substr($title, 0, 200),
            'body' => mb_substr((string) $step->template('body'), 0, 20000),
            'unit' => mb_substr(trim((string) $step->template('unit')), 0, 64),
            'priority' => in_array($step->setting('priority'), Ticket::PRIORITIES, true) ? $step->setting('priority') : 'normal',
            'assigned_to' => TicketSteps::member($project, (string) $step->template('assignee'))?->id,
        ]));

        return new StepResult(TicketSteps::out($ticket->refresh()));
    }
}
