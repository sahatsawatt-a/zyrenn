<?php

namespace App\Mcp\Tools;

use App\Models\Chat\ChatMessage;
use App\Models\Chat\ChatRoom;
use App\Models\Project;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;

/**
 * Base for the tools on a project's chats with customers -- its inbox. They
 * are a project's alone, so every call names its project: anyone in it
 * reads them; its owners and editors answer in them and look after them.
 */
abstract class ChatTool extends UserTool
{
    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            ...parent::schema($schema),
            'project' => $schema->string()->max(255)->description('The project whose inbox it is, by ref_id or name (see list-projects).')->required(),
        ];
    }

    /**
     * The project the call names. $changes when it answers in a chat or changes one.
     */
    protected function targetProject(Request $request, bool $changes = false): Project
    {
        $named = trim((string) $request->get('project', ''));

        if ($named === '') {
            throw ValidationException::withMessages([
                'project' => 'Chats with customers belong to a project: pass its ref_id or name as "project" (see list-projects).',
            ]);
        }

        $project = $this->projectNamed($this->targetUser($request), $named);
        $role = $project->getRelationValue('pivot')?->getAttribute('role');

        if ($changes && ! in_array($role, [Project::OWNER, Project::EDITOR], true)) {
            throw ValidationException::withMessages([
                'project' => "The user is a {$role} in \"{$project->name}\": only its owners and editors answer customers and look after their chats.",
            ]);
        }

        return $project;
    }

    /**
     * One of the project's chats with customers, by its ref_id.
     */
    protected function chatNamed(Project $project, Request $request): ChatRoom
    {
        $ref = trim((string) $request->get('chat', ''));
        $room = $ref === '' ? null : ChatRoom::query()->customers($project)->where('ref_id', $ref)->first();

        return $room ?? throw ValidationException::withMessages([
            'chat' => "\"{$project->name}\" has no chat with a customer \"{$ref}\". See list-chats.",
        ]);
    }

    /**
     * The chat argument, as every tool on one chat takes it.
     */
    protected function chatArgument(JsonSchema $schema): Type
    {
        return $schema->string()->max(16)->description('The chat\'s ref_id, as list-chats gives it.')->required();
    }

    /**
     * A chat as a tool answers with it: where it stands, who has it, who it
     * is with and the service it is on.
     *
     * @return array<string, mixed>
     */
    protected function described(ChatRoom $room, ?User $reader = null): array
    {
        return [
            'chat' => $room->ref_id,
            'title' => $room->title,
            'service' => $room->link['platform'] ?? $room->link['service'] ?? null,
            'status' => $room->status,
            'assignee' => $room->assignee?->email,
            'tags' => $room->tags ?? [],
            'customer' => $room->contact?->name,
            'last' => $reader !== null ? $room->latestMessage?->preview($reader) : null,
            'updated_at' => $room->updated_at?->toIso8601String(),
            'url' => route('chats.show', $room),
        ];
    }

    /**
     * One message as a tool reads it: who said it -- the customer, someone
     * in the project, a workflow -- what, and whether it was a note.
     *
     * @return array<string, mixed>
     */
    protected function message(ChatMessage $message): array
    {
        return array_filter([
            'id' => $message->id,
            'from' => match (true) {
                $message->isFromOutside() => 'customer',
                $message->role === ChatMessage::USER => 'team',
                default => 'workflow',
            },
            'name' => $message->role === ChatMessage::USER ? $message->author?->name : ($message->meta['name'] ?? null),
            'text' => $message->content,
            // A note among the project: the customer never saw it
            'note' => $message->isInternal() ?: null,
            'file' => $message->meta['file'] ?? null,
            'place' => ChatMessage::placeOf($message->meta['place'] ?? null),
            'at' => $message->created_at?->toIso8601String(),
        ], fn ($part) => $part !== null && $part !== '');
    }
}
