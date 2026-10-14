<?php

namespace App\Mcp\Tools\Chats;

use App\Events\ChatRoomChanged;
use App\Mcp\Tools\ChatTool;
use App\Models\Chat\ChatRoom;
use App\Models\Project;
use App\Support\Live\Live;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Change one of a project\'s chats with a customer, sending only what changes: its status ("open" to be answered, "waiting" answered, "closed"), who has it (an owner\'s or editor\'s email, "" for nobody), and tags to add or take off.')]
class UpdateChat extends ChatTool
{
    protected function arguments(JsonSchema $schema): array
    {
        return [
            'chat' => $this->chatArgument($schema),
            'status' => $schema->string()->enum(ChatRoom::STATUSES)->description('"open", "waiting" or "closed".'),
            'assignee' => $schema->string()->max(255)->description('The email of an owner or editor of the project; "" for nobody.'),
            'add_tags' => $schema->array()->items($schema->string()->max(ChatRoom::MAX_TAG))->description('Tags to put on it.'),
            'remove_tags' => $schema->array()->items($schema->string()->max(ChatRoom::MAX_TAG))->description('Tags to take off.'),
        ];
    }

    public function handle(Request $request): ResponseFactory
    {
        $project = $this->targetProject($request, changes: true);
        $room = $this->chatNamed($project, $request);
        $validated = $request->validate([
            'status' => ['sometimes', 'in:'.implode(',', ChatRoom::STATUSES)],
            'assignee' => ['sometimes', 'nullable', 'string', 'max:255'],
            'add_tags' => ['sometimes', 'array', 'max:'.ChatRoom::MAX_TAGS],
            'remove_tags' => ['sometimes', 'array', 'max:'.ChatRoom::MAX_TAGS],
        ]);

        if (isset($validated['status'])) {
            $room->status = $validated['status'];
        }

        if (array_key_exists('assignee', $validated)) {
            $room->assigned_to = $this->answering($project, trim((string) $validated['assignee']));
        }

        $tags = ChatRoom::tagsOf(collect($room->tags ?? [])
            ->merge(ChatRoom::tagsOf($validated['add_tags'] ?? []))
            ->diff(ChatRoom::tagsOf($validated['remove_tags'] ?? []))
            ->values()
            ->all());
        $room->tags = $tags ?: null;

        if ($room->isDirty()) {
            $room->save();
            $room->unsetRelation('assignee');
            Live::tellEveryone(new ChatRoomChanged($room));
        }

        return Response::structured($this->described($room->load(['assignee:id,email', 'contact:id,name'])));
    }

    /**
     * An owner or editor of the project, by email; nobody, for "".
     */
    private function answering(Project $project, string $email): ?int
    {
        if ($email === '') {
            return null;
        }

        return $project->members()
            ->wherePivotIn('role', [Project::OWNER, Project::EDITOR])
            ->where('email', mb_strtolower($email))
            ->value('users.id')
            ?? throw ValidationException::withMessages(['assignee' => "Nobody who answers for \"{$project->name}\" -- an owner or an editor -- has the email {$email}."]);
    }
}
