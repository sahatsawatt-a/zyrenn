<?php

namespace App\Mcp\Tools\Chats;

use App\Mcp\Tools\ChatTool;
use App\Models\Chat\ChatRoom;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('List a project\'s chats with customers -- its inbox, from every service they write on -- latest first, each with its status (open: to be answered; waiting: answered; closed), who has it, its tags, who it is with, and what was said last. The open ones unless "status" says otherwise. get-chat reads one.')]
class ListChats extends ChatTool
{
    private const MOST = 100;

    protected function arguments(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()->enum([...ChatRoom::STATUSES, 'all'])->description('"open" (the default), "waiting", "closed" or "all".'),
            'service' => $schema->string()->enum(ChatRoom::LINKS)->description('Only those on one service.'),
            'assignee' => $schema->string()->max(255)->description('Only those given to this member, by email; "me" for the user\'s own, "" for those given to nobody.'),
            'search' => $schema->string()->max(80)->description('Only those whose name, or their customer\'s, has this in it.'),
        ];
    }

    public function handle(Request $request): ResponseFactory
    {
        $project = $this->targetProject($request);
        $user = $this->targetUser($request);

        $validated = $request->validate([
            'status' => ['sometimes', 'in:'.implode(',', [...ChatRoom::STATUSES, 'all'])],
            'service' => ['sometimes', 'in:'.implode(',', ChatRoom::LINKS)],
            'assignee' => ['sometimes', 'nullable', 'string', 'max:255'],
            'search' => ['sometimes', 'string', 'max:80'],
        ]);
        $status = $validated['status'] ?? ChatRoom::OPEN;
        $search = trim($validated['search'] ?? '');

        $rooms = ChatRoom::query()->customers($project)
            ->when($status !== 'all', fn (Builder $rooms) => $rooms->where('status', $status))
            ->when(isset($validated['service']), fn (Builder $rooms) => $rooms->where('link->service', $validated['service']))
            ->when(array_key_exists('assignee', $validated), fn (Builder $rooms) => match (trim((string) $validated['assignee'])) {
                '' => $rooms->whereNull('assigned_to'),
                'me' => $rooms->where('assigned_to', $user->id),
                default => $rooms->whereHas('assignee', fn (Builder $who) => $who->where('email', mb_strtolower(trim((string) $validated['assignee'])))),
            })
            ->when($search !== '', function (Builder $rooms) use ($search) {
                $like = '%'.addcslashes($search, '%_\\').'%';
                $rooms->where(fn (Builder $named) => $named->whereLike('title', $like)
                    ->orWhereHas('contact', fn (Builder $contact) => $contact->whereLike('name', $like)));
            })
            ->with(['assignee:id,email', 'contact:id,name', 'latestMessage.author:id,name'])
            ->latest('updated_at')
            ->latest('id')
            ->limit(self::MOST)
            ->get();

        return Response::structured([
            'project' => $project->only(['ref_id', 'name']),
            'chats' => $rooms->map(fn (ChatRoom $room) => $this->described($room, $user))->all(),
            'counts' => ChatRoom::query()->customers($project)->toBase()->selectRaw('status, count(*) as held')->groupBy('status')->pluck('held', 'status'),
            'url' => route('projects.inbox', $project),
        ]);
    }
}
