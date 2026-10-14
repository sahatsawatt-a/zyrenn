<?php

namespace App\Mcp\Tools\Chats;

use App\Mcp\Tools\ChatTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Write in one of a project\'s chats with a customer, as the user. An answer goes to the customer on their service through the project\'s workflow, as one typed in the chat does, and leaves the chat waiting on them. With "note": true it is a note for the project alone: the customer never sees it, and no workflow hears it.')]
class SayInChat extends ChatTool
{
    protected function arguments(JsonSchema $schema): array
    {
        return [
            'chat' => $this->chatArgument($schema),
            'text' => $schema->string()->max(20000)->description('What to say.')->required(),
            'note' => $schema->boolean()->description('true for a note among the project, never sent to the customer.'),
        ];
    }

    public function handle(Request $request): ResponseFactory
    {
        $project = $this->targetProject($request, changes: true);
        $room = $this->chatNamed($project, $request);
        $validated = $request->validate([
            'text' => ['required', 'string', 'max:20000'],
            'note' => ['sometimes', 'boolean'],
        ]);

        $said = $room->say($this->targetUser($request), trim($validated['text']), ['internal' => (bool) ($validated['note'] ?? false)]);

        return Response::structured([
            'said' => $this->message($said),
            ...$this->described($room->refresh()),
        ]);
    }
}
