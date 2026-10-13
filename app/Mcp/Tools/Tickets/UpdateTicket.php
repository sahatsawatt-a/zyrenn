<?php

namespace App\Mcp\Tools\Tickets;

use App\Mcp\Tools\TicketTool;
use App\Models\Ticket\Ticket;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Change a ticket of a project, sending only what changes: move it along with "status" ("in_progress" when work starts, "done" when it is finished, "dropped" when it will not be done), or change what it says, its priority, who has it, its pictures.')]
class UpdateTicket extends TicketTool
{
    protected function arguments(JsonSchema $schema): array
    {
        return [
            'number' => $schema->integer()->description('The ticket\'s number in its project, e.g. 12 for #12.')->required(),
            'status' => $schema->string()->enum(Ticket::STATUSES)->description('"open", "in_progress", "done", or "dropped".'),
            ...$this->ticketArguments($schema, filing: false),
        ];
    }

    public function handle(Request $request): ResponseFactory
    {
        $project = $this->targetProject($request, changes: true);
        $validated = $request->validate(['number' => ['required', 'integer', 'min:1']]);

        $ticket = $this->ticketNumbered($project, (int) $validated['number']);
        $ticket->update($this->ticketDetails($request, $project, filing: false));

        return Response::structured($this->described($ticket->refresh()));
    }
}
