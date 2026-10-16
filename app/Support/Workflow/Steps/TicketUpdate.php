<?php

namespace App\Support\Workflow\Steps;

use App\Models\Ticket\Ticket;

/**
 * Change a ticket, found by its number: move it along, or change its
 * priority, whom it is given to, what it says. What is left as it is -- or
 * written as nothing -- stays as it was. On hold or dropped says why.
 */
final class TicketUpdate implements Step
{
    /** Said for the assignee: given to nobody. */
    private const NOBODY = 'nobody';

    public function run(StepContext $step): StepResult
    {
        $project = TicketSteps::project($step);
        $ticket = TicketSteps::ticket($step, $project);

        $status = $step->setting('status');

        if (in_array($status, Ticket::STATUSES, true)) {
            $ticket->status = $status;

            if (in_array($status, Ticket::NEEDS_REASON, true)) {
                $ticket->reason = mb_substr(trim((string) $step->template('reason')), 0, 500);
            }
        }

        if (in_array($step->setting('priority'), Ticket::PRIORITIES, true)) {
            $ticket->priority = $step->setting('priority');
        }

        $assignee = trim((string) $step->template('assignee'));

        if ($assignee !== '') {
            $ticket->assigned_to = mb_strtolower($assignee) === self::NOBODY ? null : TicketSteps::member($project, $assignee)?->id;
        }

        foreach (['title' => 200, 'body' => 20000] as $field => $most) {
            $text = trim((string) $step->template($field));

            if ($text !== '') {
                $ticket->{$field} = mb_substr($text, 0, $most);
            }
        }

        TicketSteps::saved(fn () => tap($ticket)->save());

        return new StepResult(TicketSteps::out($ticket->refresh()));
    }
}
