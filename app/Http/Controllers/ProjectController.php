<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Making, renaming and deleting projects. What is in one is browsed through
 * the notes, boards, tables and Drive routes, under /p/{project}.
 */
class ProjectController extends Controller
{
    /**
     * Create a project, run by the user who made it, and open it.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $project = Project::start($request->user(), $validated['name']);

        return to_route('projects.notes.index', $project);
    }

    /**
     * A project opens on its dashboard.
     */
    public function show(Project $project): RedirectResponse
    {
        Gate::authorize('view', $project);

        return to_route('projects.dashboard', $project);
    }

    /**
     * The project's settings: its name, who is in it and in what role, and
     * leaving or deleting it.
     */
    public function edit(Request $request, Project $project): Response
    {
        Gate::authorize('view', $project);

        return Inertia::render('projects/Settings', [
            'settings' => [
                ...$project->only(['ref_id', 'name', 'created_at']),
                'members' => $project->members()->orderBy('name')->get()->map(fn (User $member) => [
                    // The membership's, for changing or ending it
                    'ref_id' => $member->getRelationValue('pivot')?->getAttribute('ref_id'),
                    'name' => $member->name,
                    'email' => $member->email,
                    'role' => $member->getRelationValue('pivot')?->getAttribute('role'),
                    'is_me' => $member->is($request->user()),
                ]),
                'can' => [
                    'update' => $request->user()->can('update', $project),
                    'manage_members' => $request->user()->can('manageMembers', $project),
                    'delete' => $request->user()->can('delete', $project),
                ],
            ],
        ]);
    }

    /**
     * Rename the project.
     */
    public function update(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('update', $project);

        $project->update($request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]));

        return back();
    }

    /**
     * Delete the project with everything in it, for every member.
     */
    public function destroy(Project $project): RedirectResponse
    {
        Gate::authorize('delete', $project);

        $project->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Project deleted.')]);

        return to_route('notes.index');
    }
}
