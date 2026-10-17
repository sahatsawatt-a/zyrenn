<?php

namespace App\Mcp\Tools\Tickets;

use App\Mcp\Tools\TicketTool;
use App\Models\Ticket\Ticket;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Change a ticket of a project, sending only what changes: move it along with "status" ("in_progress" when work starts, "on_hold" while it waits on something first, "done" when it is finished, "dropped" when it will not be done -- those two with a "reason"), or change what it says, its priority, who has it, its pictures and videos, how to make a bug happen again and the browser check that does.')]
class UpdateTicket extends TicketTool
{
    protected function arguments(JsonSchema $schema): array
    {
        return [
            'number' => $schema->integer()->description('The ticket\'s number in its project, e.g. 12 for #12.')->required(),
            'status' => $schema->string()->enum(Ticket::STATUSES)->description('"open", "in_progress", "on_hold", "done", or "dropped".'),
            'reason' => $schema->string()->max(500)->description('Needed for "on_hold" (what it waits on) and "dropped" (why not); cleared when it moves on.'),
            ...$this->ticketArguments($schema, filing: false),
        ];
    }

    public function handle(Request $request): ResponseFactory
    {
        $project = $this->targetProject($request, changes: true);
        $validated = $request->validate(['number' => ['required', 'integer', 'min:1']]);

        $ticket = $this->ticketNumbered($project, (int) $validated['number']);
        $ticket->update($this->ticketDetails($request, $project, filing: false, ticket: $ticket));

        return Response::structured($this->described($ticket->refresh()));
    }
}
