<?php

namespace App\Support\Workflow;

use App\Models\Agent\Agent;
use App\Models\Chat\ChatRoom;
use App\Models\Connection;
use App\Models\Drive\DriveFolder;
use App\Models\Knowledge\KnowledgeBase;
use App\Models\Owner;
use App\Models\Project;
use App\Models\Table\Table;
use App\Models\Table\TableColumn;
use App\Models\User;
use App\Models\Workflow\Workflow;
use App\Support\Workflow\Code\CodeLibraries;
use App\Support\Workflow\Code\CodeSettings;
use App\Support\Workflow\Inbound\Hooks;

/**
 * What an owner's workflow steps may point at, by ref_id: its connections
 * (names and kinds, never secrets), chat rooms, tables with their columns,
 * and the workflows a "For each" may run. The editor's dropdowns and the MCP
 * tools offer the same.
 */
final class Choices
{
    /**
     * @return array<string, mixed>
     */
    public static function for(Owner $owner, ?Workflow $except = null): array
    {
        return [
            'connections' => $owner->connections()->orderBy('name')->get(['ref_id', 'name', 'service'])
                ->map(fn (Connection $connection) => [
                    ...$connection->only(['ref_id', 'name', 'service']),
                    'label' => $connection->label(),
                    'ai' => $connection->isAi(),
                ])->values(),
            'rooms' => References::rooms($owner)->latest('updated_at')->limit(200)->get(['ref_id', 'title'])
                ->map(fn (ChatRoom $room) => ['ref_id' => $room->ref_id, 'title' => $room->title ?: 'Untitled chat'])->values(),
            'tables' => $owner->tables()->with('columns')->orderBy('title')->get()
                ->map(fn (Table $table) => [
                    'ref_id' => $table->ref_id,
                    'title' => $table->title ?: 'Untitled table',
                    'columns' => $table->columns->reject(fn (TableColumn $column) => $column->is_primary)
                        ->map(fn (TableColumn $column) => $column->only(['name', 'label', 'type']))->values(),
                ])->values(),
            // The Drive's folders, each by its path
            'folders' => collect(DriveFolder::pathsById($owner->driveFolders()->get(['id', 'parent_id', 'name']), '/'))
                ->sort(SORT_NATURAL | SORT_FLAG_CASE)->values(),
            // What a "For each" may run: published, started "When run by another workflow"
            'workflows' => $owner->workflows()->when($except, fn ($q) => $q->whereKeyNot($except->id))
                ->whereNotNull('published_version_id')
                ->with('publishedVersion')->orderBy('title')->get()
                ->filter(fn (Workflow $other) => References::callTrigger($other) !== null)
                ->map(fn (Workflow $other) => ['ref_id' => $other->ref_id, 'title' => $other->title ?: 'Untitled workflow'])
                ->values(),
            // Who answers: a model with its instructions, at its latest
            'agents' => Agent::query()->of($owner)->orderBy('name')->get(['ref_id', 'name', 'model', 'version'])
                ->map(fn (Agent $agent) => ['ref_id' => $agent->ref_id, 'title' => $agent->name, 'model' => $agent->model, 'version' => $agent->version])->values(),
            // What an AI may answer from
            'knowledge' => KnowledgeBase::query()->of($owner)->orderBy('title')->get(['ref_id', 'title'])
                ->map(fn (KnowledgeBase $base) => $base->only(['ref_id', 'title']))->values(),
            // Whose tickets a step may work: a project's workflow, its own
            // project's; one's own workflow, any project one may change
            'projects' => ($owner instanceof Project
                ? collect([$owner])
                : ($owner instanceof User
                    ? $owner->projects()->wherePivotIn('role', [Project::OWNER, Project::EDITOR])->orderBy('name')->get()
                    : collect()))
                ->map(fn (Project $project) => ['ref_id' => $project->ref_id, 'name' => $project->name])->values(),
            // What "Run code" may use: the libraries the app's admins added, by language
            'libraries' => CodeLibraries::choices(),
            'python' => CodeSettings::pythonOn(),
            'hooks' => Hooks::url('w/'),
            'timezone' => config('app.timezone'),
        ];
    }
}
