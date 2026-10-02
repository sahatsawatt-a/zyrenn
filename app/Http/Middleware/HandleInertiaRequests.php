<?php

namespace App\Http\Middleware;

use App\Models\Chat\ChatRoom;
use App\Models\Project;
use App\Support\Live\Collab;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
            ],
            // Resolved as the page renders, after route binding
            'project' => fn () => $this->currentProject($request),
            // Messages from others not yet read, in every room the user shares
            'unreadChats' => fn () => $request->user() ? ChatRoom::unreadTotal($request->user()) : 0,
            'projects' => fn () => $request->user()?->projects()->orderBy('name')->get()
                ->map(fn (Project $project) => $project->only(['ref_id', 'name'])),
            // Whether notes and boards are edited live, through the collaboration server
            'collab' => Collab::enabled(),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }

    /**
     * The project the page is in, with the user's role there: the one in the
     * URL, or the one the note, board, table or file being shown belongs to.
     * Null on the user's own things.
     *
     * @return array{ref_id: string, name: string, role: string|null}|null
     */
    private function currentProject(Request $request): ?array
    {
        $user = $request->user();
        $project = $request->route('project');

        if (! $project instanceof Project) {
            $project = collect($request->route()?->parameters() ?? [])
                ->first(fn ($bound) => $bound instanceof Model && $bound->getAttribute('project_id') !== null)
                ?->getRelationValue('project');
        }

        if (! $project instanceof Project || ! $user) {
            return null;
        }

        return [...$project->only(['ref_id', 'name']), 'role' => $project->roleOf($user)];
    }
}
