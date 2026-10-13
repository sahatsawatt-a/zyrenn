<?php

namespace App\Mcp\Tools\Tickets;

use App\Mcp\Tools\TicketTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Read one ticket of a project in full: what it asks for, its status, who has it, and its pictures -- each a Drive file\'s URL (get-file reads it, by the ref_id at its end) or a link.')]
class GetTicket extends TicketTool
{
    protected function arguments(JsonSchema $schema): array
    {
        return [
            'number' => $schema->integer()->description('The ticket\'s number in its project, e.g. 12 for #12.')->required(),
        ];
    }

    public function handle(Request $request): ResponseFactory
    {
        $project = $this->targetProject($request);
        $validated = $request->validate(['number' => ['required', 'integer', 'min:1']]);

        return Response::structured($this->described($this->ticketNumbered($project, (int) $validated['number'])));
    }
}
