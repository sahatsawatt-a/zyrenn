<?php

namespace App\Support\Workflow;

use App\Models\Chat\ChatRoom;
use App\Models\Connection;
use App\Models\Drive\DriveFolder;
use App\Models\Owner;
use App\Models\Table\Table;
use App\Models\Table\TableColumn;
use App\Models\Workflow\Workflow;
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
            'hooks' => Hooks::url('w/'),
            'timezone' => config('app.timezone'),
        ];
    }
}
