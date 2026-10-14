<?php

namespace App\Mcp\Tools\Chats;

use App\Mcp\Tools\ChatTool;
use App\Models\Chat\ChatMessage;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Read one of a project\'s chats with a customer: where it stands, who has it, its tags, who the customer is (their name, what is noted about them, and their chats on other services), until when the service takes an answer, and its latest messages, oldest first -- each from "customer", "team" (someone in the project; "note": true for a note the customer never saw) or "workflow". "before" pages back.')]
class GetChat extends ChatTool
{
    protected function arguments(JsonSchema $schema): array
    {
        return [
            'chat' => $this->chatArgument($schema),
            'limit' => $schema->integer()->min(1)->max(200)->description('How many of the latest messages; 30 unless said.'),
            'before' => $schema->integer()->description('Only messages before this one, by id: to read further back.'),
        ];
    }

    public function handle(Request $request): ResponseFactory
    {
        $project = $this->targetProject($request);
        $room = $this->chatNamed($project, $request);
        $validated = $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'before' => ['sometimes', 'integer', 'min:1'],
        ]);
        $limit = (int) ($validated['limit'] ?? 30);

        $messages = $room->messages()
            ->with('author:id,name')
            ->when(isset($validated['before']), fn (Builder $older) => $older->where('id', '<', $validated['before']))
            ->reorder('id', 'desc')
            ->limit($limit + 1)
            ->get();
        $room->loadMissing(['assignee:id,email', 'contact.rooms']);

        return Response::structured([
            ...$this->described($room),
            'customer' => $room->contact === null ? null : [
                'name' => $room->contact->name,
                'key' => $room->contact->key,
                'notes' => $room->contact->details ?? [],
                'chats' => array_map(fn (array $chat) => ['chat' => $chat['ref_id'], 'service' => $chat['service'], 'status' => $chat['status']], $room->contact->card()['rooms']),
            ],
            // What a workflow keeps of this service: their id there, their language
            'details' => $room->details ?? [],
            'answer_until' => $room->answerUntil()?->toIso8601String(),
            'messages' => $messages->take($limit)->reverse()->values()->map(fn (ChatMessage $message) => $this->message($message))->all(),
            'earlier' => $messages->count() > $limit,
        ]);
    }
}
