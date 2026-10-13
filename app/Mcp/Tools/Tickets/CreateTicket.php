<?php

namespace App\Mcp\Tools\Tickets;

use App\Mcp\Tools\TicketTool;
use App\Models\Ticket\Ticket;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('File a ticket in a project: a bug to fix or a feature to add. It is given the project\'s next number and starts open.')]
class CreateTicket extends TicketTool
{
    protected function arguments(JsonSchema $schema): array
    {
        return $this->ticketArguments($schema, filing: true);
    }

    public function handle(Request $request): ResponseFactory
    {
        $project = $this->targetProject($request, changes: true);
        $details = $this->ticketDetails($request, $project, filing: true);

        // A ticket is filed open: where it goes from there is update-ticket's to say
        unset($details['status']);

        return Response::structured($this->described(
            Ticket::file($project, $this->targetUser($request), $details),
        ));
    }
}
